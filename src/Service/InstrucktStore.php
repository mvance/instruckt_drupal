<?php

namespace Drupal\instruckt_drupal\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\instruckt_drupal\Exception\InstrucktStorageException;

/**
 * Manages annotation and screenshot persistence for instruckt_drupal.
 */
class InstrucktStore {

  /**
   * CSRF token scope. Shared constant used by CsrfSubscriber and CsrfHeaderAccessCheck.
   * Using a named constant prevents typos across the codebase.
   */
  const CSRF_TOKEN_ID = 'instruckt_drupal';

  private string $storagePath;
  private \Drupal\Core\Config\ImmutableConfig $config;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerChannelInterface $logger,
  ) {
    $this->config = $configFactory->get('instruckt_drupal.settings');
    $this->storagePath = $this->config->get('storage_path');
  }

  private function annotationsPath(): string {
    return $this->storagePath . '/annotations.json';
  }

  private function screenshotsDir(): string {
    return $this->storagePath . '/screenshots';
  }

  private function now(): string {
    return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
  }

  /**
   * Generates a ULID string without requiring symfony/uid.
   *
   * Format: 10-char timestamp (ms) + 16-char random, Crockford Base32.
   */
  private function generateUlid(): string {
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $ms = (int) (microtime(TRUE) * 1000);
    $ts = '';
    for ($i = 9; $i >= 0; $i--) {
      $ts = $alphabet[$ms & 0x1F] . $ts;
      $ms >>= 5;
    }
    $rand = '';
    for ($i = 0; $i < 16; $i++) {
      $rand .= $alphabet[random_int(0, 31)];
    }
    return $ts . $rand;
  }

  // ---------------------------------------------------------------------------
  // Annotation read/write
  // ---------------------------------------------------------------------------

  /**
   * Returns all stored annotations.
   *
   * On parse failure (corrupted annotations.json), logs an error and returns []
   * so that read-only callers degrade gracefully. This means callers CANNOT
   * distinguish an empty store from a corrupted one from the return value alone;
   * they must consult the Drupal log. Write paths (withAnnotationsLocked) abort
   * on JSON parse error and throw InstrucktStorageException, preventing overwrites
   * on top of corrupted data.
   */
  public function getAnnotations(): array {
    $path = $this->annotationsPath();
    if (!file_exists($path)) {
      return [];
    }
    // LOCK_SH is no longer needed: writes use atomic rename(), so readers always
    // see a complete file. file_get_contents() is safe without a shared lock.
    $content = @file_get_contents($path);
    if ($content === FALSE || $content === '') {
      return [];
    }
    $data = json_decode($content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
      // Log at error level so site admins see the corruption in the Drupal logs.
      // We return [] rather than throwing because getAnnotations() is used in
      // read-only contexts (list, MCP) where partial degradation is preferable
      // to a hard failure. Write paths protect against overwriting corrupt data
      // by aborting in withAnnotationsLocked() instead.
      $this->logger->error('InstrucktStore: getAnnotations() parse failure — annotations.json may be corrupted. Returning empty array to avoid a hard failure; check and restore the file manually.');
      return [];
    }
    return $data;
  }

  public function getPendingAnnotations(): array {
    return array_values(array_filter(
      $this->getAnnotations(),
      fn($a) => ($a['status'] ?? 'pending') === 'pending',
    ));
  }

  /**
   * Executes a callback with an exclusive lock held across the full read-modify-write cycle.
   *
   * This prevents TOCTOU races where two concurrent requests read the same snapshot
   * and overwrite each other's writes.
   *
   * Contract: The callback receives the current annotations array BY REFERENCE
   * (array &$all) and mutates it in place. The callback's return value is passed
   * through as the return of withAnnotationsLocked().
   *
   * Lock file design: The lock is taken on a DEDICATED `annotations.lock` file,
   * NOT on annotations.json itself. This is critical for correctness with atomic
   * rename() writes: if we locked annotations.json and a writer renamed it, the
   * next waiter would acquire flock on the old inode, read stale content, and then
   * overwrite the new file with an update based on stale state — a lost-update race.
   * The .lock file's inode never changes, so all writers always compete on the same
   * inode. Reads (getAnnotations) are lock-free because rename() is atomic on POSIX.
   *
   * @throws InstrucktStorageException on any I/O or parse failure.
   *   Callers distinguish storage errors (exception) from domain-level NULL
   *   (e.g. annotation not found, which the callback sets via reference).
   */
  private function withAnnotationsLocked(callable $fn): mixed {
    // prepareDirectory() requires a variable (not expression) — PHP 8 pass-by-ref.
    $storagePathVar = $this->storagePath;
    $prepared = $this->fileSystem->prepareDirectory(
      $storagePathVar,
      FileSystemInterface::CREATE_DIRECTORY
    );
    if (!$prepared) {
      $this->logger->error('InstrucktStore: could not prepare storage directory @path', ['@path' => $this->storagePath]);
      throw new InstrucktStorageException("Could not prepare storage directory: {$this->storagePath}");
    }

    $storageDir = $this->fileSystem->realpath($this->storagePath);
    $lockPath = $storageDir . '/annotations.lock';
    $realPath = $storageDir . '/annotations.json';

    // Use a permanent lock file whose inode never changes.
    $lockFp = fopen($lockPath, 'c');
    if ($lockFp === FALSE) {
      $this->logger->error('InstrucktStore: could not open lock file @path', ['@path' => $lockPath]);
      throw new InstrucktStorageException("Could not open lock file: $lockPath");
    }
    if (!flock($lockFp, LOCK_EX)) {
      fclose($lockFp);
      $this->logger->error('InstrucktStore: failed to acquire write lock on @path', ['@path' => $lockPath]);
      throw new InstrucktStorageException("Could not acquire write lock: $lockPath");
    }

    // Read annotations.json fresh under the lock.
    $content = @file_get_contents($realPath);

    // Guard: abort on JSON parse failure to prevent overwriting a corrupted file
    // with an empty array, destroying all existing annotations.
    if ($content !== '' && $content !== FALSE) {
      $annotations = json_decode($content, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE || !is_array($annotations)) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        $err = json_last_error_msg();
        $this->logger->error(
          'InstrucktStore: annotations.json parse failure (@err). Aborting write to prevent data loss.',
          ['@err' => $err]
        );
        throw new InstrucktStorageException("annotations.json parse failure: $err");
      }
    } else {
      $annotations = [];
    }

    // Let the callback transform the array. Return value is passed through;
    // void callbacks return NULL which is a valid domain result (e.g. "not found").
    $result = $fn($annotations);

    // Encode BEFORE touching the file — fail fast on invalid UTF-8.
    try {
      $json = json_encode(
        array_values($annotations),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
      ) . "\n";
    } catch (\JsonException $e) {
      flock($lockFp, LOCK_UN);
      fclose($lockFp);
      $this->logger->error('InstrucktStore: JSON encode failed: @msg', ['@msg' => $e->getMessage()]);
      throw new InstrucktStorageException("JSON encode failed: " . $e->getMessage(), 0, $e);
    }

    // Atomic write: write to temp then rename(). rename() is atomic on POSIX —
    // readers never see a partial file. The lock ensures only one writer at a time.
    $tmpPath = $realPath . '.tmp';
    if (file_put_contents($tmpPath, $json) === FALSE) {
      flock($lockFp, LOCK_UN);
      fclose($lockFp);
      $this->logger->error('InstrucktStore: failed to write temp file @tmp', ['@tmp' => $tmpPath]);
      throw new InstrucktStorageException("Failed to write temp file: $tmpPath");
    }
    if (!rename($tmpPath, $realPath)) {
      @unlink($tmpPath);
      flock($lockFp, LOCK_UN);
      fclose($lockFp);
      $this->logger->error('InstrucktStore: failed to rename @tmp to @path', ['@tmp' => $tmpPath, '@path' => $realPath]);
      throw new InstrucktStorageException("Failed to rename $tmpPath to $realPath");
    }

    flock($lockFp, LOCK_UN);
    fclose($lockFp);

    return $result;
  }

  /**
   * Creates a new annotation. ID is always server-generated as a ULID.
   */
  public function createAnnotation(array $data): ?array {
    $id = $this->generateUlid();
    $now = $this->now();

    $screenshot = NULL;
    if (!empty($data['screenshot'])) {
      $screenshot = $this->saveScreenshot($id, $data['screenshot']);
      // If a screenshot was provided but could not be saved (I/O failure), fail
      // the entire annotation creation rather than silently dropping the screenshot.
      if ($screenshot === NULL) {
        return NULL;
      }
    }

    $annotation = [
      'id'            => $id,
      'url'           => $data['url'] ?? '',
      'x'             => (float) ($data['x'] ?? 0),
      'y'             => (float) ($data['y'] ?? 0),
      'comment'       => $data['comment'] ?? '',
      'element'       => $data['element'] ?? '',
      'element_path'  => $data['element_path'] ?? NULL,
      'css_classes'   => $data['css_classes'] ?? NULL,
      'nearby_text'   => $data['nearby_text'] ?? NULL,
      'selected_text' => $data['selected_text'] ?? NULL,
      'bounding_box'  => $data['bounding_box'] ?? NULL,
      'screenshot'    => $screenshot,
      'intent'        => $data['intent'] ?? 'fix',
      'severity'      => $data['severity'] ?? 'important',
      'status'        => 'pending',
      'framework'     => $data['framework'] ?? NULL,
      'thread'        => [],
      'resolved_by'   => NULL,
      'resolved_at'   => NULL,
      'created_at'    => $now,
      'updated_at'    => $now,
    ];

    try {
      $this->withAnnotationsLocked(function (array &$all) use ($annotation): void {
        $all[] = $annotation;
      });
    } catch (InstrucktStorageException $e) {
      // Storage failure: clean up the screenshot we already wrote (if any).
      if ($screenshot !== NULL) {
        $this->deleteScreenshot($screenshot);
      }
      return NULL;
    }

    return $annotation;
  }

  /**
   * Updates allowed fields on an annotation.
   *
   * Returns the updated annotation array on success, NULL if no annotation with
   * $id exists (caller returns 404), or FALSE if InstrucktStorageException was
   * caught (caller returns 500). Only 'status', 'comment', 'thread' are accepted
   * from $data; resolved_by and resolved_at are always server-controlled.
   *
   * @param string $id
   *   Annotation ULID.
   * @param array $data
   *   Fields to update.
   * @param string $resolvedBy
   *   Who is resolving: 'human' (HTTP controller) or 'agent' (MCP tool).
   *   Only used when status changes to 'resolved' or 'dismissed'.
   */
  public function updateAnnotation(string $id, array $data, string $resolvedBy = 'human'): array|false|null {
    // resolved_by and resolved_at are server-controlled — not client-settable.
    // They are auto-populated on resolution and cleared on reopen to prevent spoofing.
    $allowed = ['status', 'comment', 'thread'];
    $updated = NULL;
    $screenshotToDelete = NULL;

    try {
    $this->withAnnotationsLocked(function (array &$all) use ($id, $data, $allowed, $resolvedBy, &$updated, &$screenshotToDelete): void {
      foreach ($all as &$annotation) {
        if ($annotation['id'] !== $id) {
          continue;
        }
        foreach ($data as $key => $value) {
          if (in_array($key, $allowed, TRUE)) {
            // 'thread' is REPLACED wholesale, not appended — the client owns
            // the full thread array and sends the complete new state each time.
            $annotation[$key] = $value;
          }
        }
        $annotation['updated_at'] = $this->now();

        $newStatus = $data['status'] ?? NULL;
        if (in_array($newStatus, ['resolved', 'dismissed'], TRUE)) {
          $screenshotToDelete = $annotation['screenshot'] ?? NULL;
          // Nullify the reference so the stored annotation doesn't dangle.
          $annotation['screenshot'] = NULL;
          // Auto-populate resolution metadata server-side — never from client input.
          // resolved_by and resolved_at are intentionally NOT in $allowed;
          // they are set here unconditionally to prevent spoofing.
          // $resolvedBy distinguishes 'human' (HTTP) from 'agent' (MCP).
          $annotation['resolved_by'] = $resolvedBy;
          $annotation['resolved_at'] = $this->now();
        }
        // Clear resolution fields when reopening to pending.
        if ($newStatus === 'pending') {
          $annotation['resolved_by'] = NULL;
          $annotation['resolved_at'] = NULL;
        }

        $updated = $annotation;
        break;
      }
      unset($annotation);
    });
    } catch (InstrucktStorageException $e) {
      // Propagate storage errors as FALSE so callers distinguish:
      //   NULL  → annotation not found (404)
      //   FALSE → storage/I/O error (500)
      return FALSE;
    }

    // Delete screenshot outside the lock to avoid holding the lock during I/O.
    if ($screenshotToDelete !== NULL) {
      $this->deleteScreenshot($screenshotToDelete);
    }

    // $updated is NULL when no annotation with $id was found in the store.
    return $updated;
  }

  // ---------------------------------------------------------------------------
  // Screenshot handling
  // ---------------------------------------------------------------------------

  /**
   * Saves a data URL screenshot. Returns relative path or NULL on failure.
   *
   * Supports both base64-encoded PNG and URL-encoded SVG data URLs, matching
   * instruckt's use of modern-screenshot which may produce either format.
   */
  public function saveScreenshot(string $id, string $dataUrl): ?string {
    if (!str_starts_with($dataUrl, 'data:image/')) {
      return NULL;
    }

    $dir = $this->screenshotsDir();
    $this->fileSystem->prepareDirectory(
      $dir,
      FileSystemInterface::CREATE_DIRECTORY
    );

    $parts = explode(',', $dataUrl, 2);
    $header = $parts[0] ?? '';
    $rawData = $parts[1] ?? '';

    if (str_contains($header, ';base64')) {
      $binary = base64_decode($rawData, TRUE);  // strict mode
      $ext = str_contains($header, 'image/svg+xml') ? 'svg' : 'png';
    }
    else {
      // URL-encoded SVG (from snapdom/modern-screenshot)
      $binary = urldecode($rawData);
      $ext = 'svg';
    }

    if (!$binary) {
      return NULL;
    }

    // Enforce allowed extensions from config.
    $allowedExtensions = $this->config->get('allowed_screenshot_extensions') ?? ['png', 'svg'];
    if (!in_array($ext, $allowedExtensions, TRUE)) {
      $this->logger->warning('InstrucktStore: rejected screenshot with disallowed extension @ext', ['@ext' => $ext]);
      return NULL;
    }

    // Enforce max screenshot size from config (default: 5MB).
    $maxSize = $this->config->get('max_screenshot_size') ?? 5242880;
    if (strlen($binary) > $maxSize) {
      $this->logger->warning('InstrucktStore: rejected screenshot exceeding @max bytes', ['@max' => $maxSize]);
      return NULL;
    }

    $filename = "{$id}.{$ext}";
    // Use fileSystem->saveData() for Drupal-idiomatic file writing.
    $uri = $this->screenshotsDir() . '/' . $filename;
    if ($this->fileSystem->saveData($binary, $uri, FileSystemInterface::EXISTS_REPLACE) === FALSE) {
      $this->logger->error('InstrucktStore: failed to save screenshot to @uri', ['@uri' => $uri]);
      return NULL;
    }

    return "screenshots/{$filename}";
  }

  /**
   * Returns the real filesystem path for a screenshot, or NULL if not found.
   *
   * realpath() is used here because BinaryFileResponse requires a real path,
   * not a stream wrapper URI.
   */
  public function getScreenshotRealPath(string $relPath): ?string {
    $uri = $this->storagePath . '/' . $relPath;
    if (!file_exists($uri)) {
      return NULL;
    }
    return $this->fileSystem->realpath($uri) ?: NULL;
  }

  public function deleteScreenshot(?string $relPath): void {
    if (!$relPath) {
      return;
    }
    $uri = $this->storagePath . '/' . $relPath;
    if (file_exists($uri)) {
      // Use fileSystem->delete() for Drupal-idiomatic deletion with proper
      // stream wrapper support. Catch FileException so concurrent resolution
      // (e.g. two agents resolving simultaneously) does not bubble a 500.
      try {
        $this->fileSystem->delete($uri);
      } catch (\Drupal\Core\File\Exception\FileException $e) {
        $this->logger->warning('Could not delete screenshot @uri: @message', [
          '@uri' => $uri,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }
}
