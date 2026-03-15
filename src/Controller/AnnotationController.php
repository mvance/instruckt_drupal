<?php

namespace Drupal\instruckt_drupal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\instruckt_drupal\Service\InstrucktStore;
use Drupal\instruckt_drupal\Service\SourceResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for instruckt_drupal annotation API endpoints.
 */
class AnnotationController extends ControllerBase {

  // Reusable 503 response for when the module is administratively disabled.
  private function disabledResponse(): JsonResponse {
    return new JsonResponse(['error' => 'Instruckt is disabled'], 503, ['Cache-Control' => 'no-store']);
  }

  public function __construct(
    private readonly InstrucktStore $store,
    private readonly SourceResolver $sourceResolver,
    private readonly LoggerChannelInterface $logger,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('instruckt_drupal.store'),
      $container->get('instruckt_drupal.source_resolver'),
      $container->get('logger.channel.instruckt_drupal'),
    );
  }

  public function list(): JsonResponse {
    if (!$this->config('instruckt_drupal.settings')->get('enabled')) {
      return $this->disabledResponse();
    }
    // no-store: annotation storage is shared across all authorized users.
    // Prevent proxies or browser from caching a snapshot that would be stale
    // for other users. Annotations are returned in insertion order.
    return new JsonResponse($this->store->getAnnotations(), 200, [
      'Cache-Control' => 'no-store',
    ]);
  }

  public function createAnnotation(Request $request): JsonResponse {
    if (!$this->config('instruckt_drupal.settings')->get('enabled')) {
      return $this->disabledResponse();
    }
    // Enforce JSON Content-Type before attempting to decode body.
    // instruckt JS always sends application/json, so a missing/wrong type
    // indicates a misconfigured client — return 415 explicitly.
    if (!str_contains($request->headers->get('Content-Type', ''), 'application/json')) {
      return new JsonResponse(['error' => 'Content-Type must be application/json'], 415);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON'], 400);
    }

    // Required fields (matching Laravel's validation).
    $errors = [];
    foreach (['x', 'y', 'comment', 'element', 'url'] as $field) {
      if (!isset($data[$field]) || ($field === 'comment' && $data[$field] === '')) {
        $errors[] = "Missing required field: $field";
      }
    }
    if ($errors) {
      return new JsonResponse(['error' => implode('; ', $errors)], 400);
    }

    // PHP 8 TypeError guard: mb_strlen()/filter_var() on a non-string is a fatal.
    // Validate type before any string operations.
    foreach (['url', 'element', 'comment'] as $stringField) {
      if (isset($data[$stringField]) && !is_string($data[$stringField])) {
        return new JsonResponse(['error' => "$stringField must be a string"], 400);
      }
    }
    foreach (['element_path', 'nearby_text', 'selected_text', 'css_classes'] as $optStringField) {
      if (isset($data[$optStringField]) && !is_string($data[$optStringField])) {
        return new JsonResponse(['error' => "$optStringField must be a string"], 400);
      }
    }

    // URL format, scheme, and length validation.
    // filter_var(FILTER_VALIDATE_URL) accepts dangerous schemes like javascript:
    // and file: — explicitly restrict to http/https only.
    if (!filter_var($data['url'], FILTER_VALIDATE_URL)) {
      return new JsonResponse(['error' => 'Invalid URL format'], 400);
    }
    $urlScheme = parse_url($data['url'], PHP_URL_SCHEME);
    if (!in_array($urlScheme, ['http', 'https'], TRUE)) {
      return new JsonResponse(['error' => 'URL must use http or https scheme'], 400);
    }
    if (mb_strlen($data['url']) > 2048) {
      return new JsonResponse(['error' => 'url exceeds 2048 characters'], 400);
    }
    if (mb_strlen($data['element'] ?? '') > 255) {
      return new JsonResponse(['error' => 'element exceeds 255 characters'], 400);
    }
    if (isset($data['element_path']) && mb_strlen($data['element_path']) > 2048) {
      return new JsonResponse(['error' => 'element_path exceeds 2048 characters'], 400);
    }
    if (isset($data['nearby_text']) && mb_strlen($data['nearby_text']) > 500) {
      return new JsonResponse(['error' => 'nearby_text exceeds 500 characters'], 400);
    }
    if (isset($data['selected_text']) && mb_strlen($data['selected_text']) > 500) {
      return new JsonResponse(['error' => 'selected_text exceeds 500 characters'], 400);
    }
    // Prevent unbounded payloads: css_classes is read on every getAnnotations() call.
    if (isset($data['css_classes']) && mb_strlen($data['css_classes']) > 1024) {
      return new JsonResponse(['error' => 'css_classes exceeds 1024 characters'], 400);
    }

    // Coordinate bounds: must be non-negative numbers.
    if (!is_numeric($data['x']) || (float) $data['x'] < 0) {
      return new JsonResponse(['error' => 'x must be a non-negative number'], 400);
    }
    if (!is_numeric($data['y']) || (float) $data['y'] < 0) {
      return new JsonResponse(['error' => 'y must be a non-negative number'], 400);
    }

    // bounding_box: all four fields required if object present; all must be >= 0.
    // Explicitly reject empty objects: {"bounding_box": {}} is invalid.
    if (isset($data['bounding_box'])) {
      if (!is_array($data['bounding_box']) || empty($data['bounding_box'])) {
        return new JsonResponse(['error' => 'bounding_box must contain x, y, width, and height'], 400);
      }
      foreach (['x', 'y', 'width', 'height'] as $bbField) {
        if (!isset($data['bounding_box'][$bbField]) || !is_numeric($data['bounding_box'][$bbField]) || (float) $data['bounding_box'][$bbField] < 0) {
          return new JsonResponse(['error' => "bounding_box.$bbField must be a non-negative number"], 400);
        }
      }
    }

    // Enum validation.
    if (isset($data['intent']) && !in_array($data['intent'], ['fix', 'change', 'question', 'approve'], TRUE)) {
      return new JsonResponse(['error' => 'Invalid intent value'], 400);
    }
    if (isset($data['severity']) && !in_array($data['severity'], ['blocking', 'important', 'suggestion'], TRUE)) {
      return new JsonResponse(['error' => 'Invalid severity value'], 400);
    }
    if (mb_strlen($data['comment'] ?? '') > 2000) {
      return new JsonResponse(['error' => 'Comment exceeds 2000 characters'], 400);
    }

    // Screenshot pre-validation: check MIME and size before hitting the store,
    // so we can return 400 (bad MIME) or 413 (too large) rather than silently
    // saving an annotation with screenshot = NULL.
    if (!empty($data['screenshot'])) {
      $dataUrl = $data['screenshot'];
      // PNG MUST be base64-encoded (instruckt's modern-screenshot always produces
      // base64 PNG). Accepting bare 'data:image/png' without ';base64' would cause
      // the store to misidentify it as URL-encoded and corrupt the binary data.
      // SVG may be base64 or URL-encoded — both are supported by the store.
      if (!str_starts_with($dataUrl, 'data:image/png;base64,') && !str_starts_with($dataUrl, 'data:image/svg+xml')) {
        return new JsonResponse(['error' => 'Screenshot must be a base64-encoded PNG or an SVG data URL'], 400);
      }
      // Determine extension and validate against allowed_screenshot_extensions config.
      // Do this here (4xx) rather than letting saveScreenshot() return NULL (which
      // would propagate as a 500). This prevents the ambiguity between I/O failure
      // and admin-configured extension policy.
      $screenshotExt = str_starts_with($dataUrl, 'data:image/svg+xml') ? 'svg' : 'png';
      $allowedExts = $this->config('instruckt_drupal.settings')->get('allowed_screenshot_extensions') ?? ['png', 'svg'];
      if (!in_array($screenshotExt, $allowedExts, TRUE)) {
        return new JsonResponse(['error' => sprintf("Screenshot extension '%s' is not allowed by site configuration.", $screenshotExt)], 415);
      }
      // Estimate decoded size: base64 expands ~33%, URL-encoded is similar.
      $maxSize = $this->config('instruckt_drupal.settings')->get('max_screenshot_size') ?? 5242880;
      $parts = explode(',', $dataUrl, 2);
      $rawData = $parts[1] ?? '';
      $isBase64 = str_contains($parts[0] ?? '', ';base64');
      if ($isBase64) {
        // Validate base64 character set without decoding (avoids double memory allocation
        // for large screenshots). If the payload contains non-base64 characters,
        // saveScreenshot() would return NULL → 500. Return 400 deterministically here.
        // Accepts optional padding (=, ==) and strips whitespace Drupal may have added.
        if (!preg_match('/^[a-zA-Z0-9+\/]*={0,2}$/', trim($rawData))) {
          return new JsonResponse(['error' => 'Screenshot contains invalid base64-encoded data'], 400);
        }
        $estimatedSize = (int) (strlen($rawData) * 3 / 4);
      }
      else {
        $estimatedSize = strlen(urldecode($rawData));
      }
      if ($estimatedSize > $maxSize) {
        return new JsonResponse(['error' => 'Screenshot exceeds maximum allowed size'], 413);
      }
    }

    // Server-side source resolution when framework context is provided.
    // Validate framework is an array first — a string value triggers PHP 8 TypeError.
    // Stored fields: framework, component, source_file, source_line, supported.
    // 'message' from the resolver is transient and not stored.
    if (isset($data['framework']) && !is_array($data['framework'])) {
      return new JsonResponse(['error' => 'framework must be an object'], 400);
    }
    // If framework is provided, BOTH 'framework' and 'component' fields are required.
    // A partial object (only one field) would be stored raw, violating the schema.
    if (isset($data['framework']) && is_array($data['framework'])) {
      if (empty($data['framework']['framework']) || empty($data['framework']['component'])) {
        return new JsonResponse(['error' => "framework object requires both 'framework' and 'component' fields"], 400);
      }
    }
    // created_by is server-controlled — always set from current user, never from client input.
    $data['created_by'] = $this->currentUser()->getDisplayName();

    if (!empty($data['framework']['framework']) && !empty($data['framework']['component'])) {
      $fw = $data['framework']['framework'];
      $comp = $data['framework']['component'];

      // Validate framework and component identifiers — same patterns as resolveSource().
      // Without this, the resolver's file_exists() calls could be used to probe filesystem paths.
      if (!is_string($fw) || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $fw)) {
        return new JsonResponse(['error' => 'Invalid framework identifier'], 400);
      }
      if (!is_string($comp) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_\-\.\/]*$/', $comp)) {
        return new JsonResponse(['error' => 'Invalid component identifier'], 400);
      }
      if (str_contains($comp, '..')) {
        return new JsonResponse(['error' => 'Invalid component identifier'], 400);
      }

      // Whitelist fields from resolver; include 'supported' so callers can distinguish
      // "unsupported framework (source_file=null, supported=false)" from
      // "supported but template not found (source_file=null, supported=true)".
      // Use the resolver's canonical framework name (normalizes 'blade' → 'twig').
      $enriched = $this->sourceResolver->resolve($fw, $comp);
      $data['framework'] = [
        'framework'   => $enriched['framework'],  // canonical: always 'twig' for supported
        'component'   => $comp,
        'source_file' => $enriched['source_file'],
        'source_line' => $enriched['source_line'],
        'supported'   => $enriched['supported'],
      ];
    }

    try {
      $annotation = $this->store->createAnnotation($data);
      if ($annotation === NULL) {
        $this->logger->error('AnnotationController::create: storage returned NULL for new annotation.');
        return new JsonResponse(['error' => 'Failed to save annotation'], 500);
      }
      return new JsonResponse($annotation, 201, [
        'Cache-Control' => 'no-store',
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('AnnotationController::create failed: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => 'An unexpected error occurred'], 500);
    }
  }

  public function update(Request $request, string $id): JsonResponse {
    if (!$this->config('instruckt_drupal.settings')->get('enabled')) {
      return $this->disabledResponse();
    }
    // Validate ULID format before any file I/O.
    if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id)) {
      return new JsonResponse(['error' => 'Invalid annotation ID format'], 400);
    }

    if (!str_contains($request->headers->get('Content-Type', ''), 'application/json')) {
      return new JsonResponse(['error' => 'Content-Type must be application/json'], 415);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON'], 400);
    }

    if (isset($data['status']) && !in_array($data['status'], ['pending', 'resolved', 'dismissed'], TRUE)) {
      return new JsonResponse(['error' => 'Invalid status value'], 400);
    }
    // PHP 8 TypeError guard: validate string type before mb_strlen().
    if (isset($data['comment']) && !is_string($data['comment'])) {
      return new JsonResponse(['error' => 'comment must be a string'], 400);
    }
    if (isset($data['comment']) && mb_strlen($data['comment']) > 2000) {
      return new JsonResponse(['error' => 'Comment exceeds 2000 characters'], 400);
    }
    // Validate thread structure: must be an array of {author, message, timestamp} entries.
    // Thread is replaced wholesale — the client sends the complete updated state.
    if (isset($data['thread'])) {
      if (!is_array($data['thread'])) {
        return new JsonResponse(['error' => 'thread must be an array'], 400);
      }
      // Limit thread size to prevent unbounded annotations.json growth.
      // At 2000 chars/entry × 100 entries = 200KB max thread payload per annotation.
      if (count($data['thread']) > 100) {
        return new JsonResponse(['error' => 'thread exceeds maximum of 100 entries'], 400);
      }
      foreach ($data['thread'] as $entry) {
        if (!is_array($entry) || !isset($entry['author'], $entry['message'], $entry['timestamp'])) {
          return new JsonResponse(['error' => 'Each thread entry must have author, message, and timestamp'], 400);
        }
        if (!in_array($entry['author'], ['human', 'agent'], TRUE)) {
          return new JsonResponse(['error' => "Thread entry author must be 'human' or 'agent'"], 400);
        }
        if (!is_string($entry['message']) || mb_strlen($entry['message']) > 2000) {
          return new JsonResponse(['error' => 'Thread entry message must be a string ≤ 2000 characters'], 400);
        }
        // Validate ISO-8601 timestamp format using a strict regex, then parse.
        // DateTimeImmutable constructor accepts relative strings like "next tuesday",
        // so we first enforce the structural shape with a regex before trusting the parse.
        // Accepted forms: "2024-03-11T12:34:56Z", "2024-03-11T12:34:56.789Z",
        //                 "2024-03-11T12:34:56+05:30", "2024-03-11T12:34:56.789-07:00".
        if (!is_string($entry['timestamp'])) {
          return new JsonResponse(['error' => 'Thread entry timestamp must be an ISO-8601 date-time string'], 400);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $entry['timestamp'])) {
          return new JsonResponse(['error' => 'Thread entry timestamp must be an ISO-8601 date-time string'], 400);
        }
        try {
          new \DateTimeImmutable($entry['timestamp']);
        } catch (\Exception $e) {
          return new JsonResponse(['error' => 'Thread entry timestamp must be an ISO-8601 date-time string'], 400);
        }
      }
    }

    // resolved_by and resolved_at are server-controlled — auto-populated inside
    // InstrucktStore::updateAnnotation() when status changes to resolved/dismissed.
    // Do NOT set them here; they are not in $allowed and would be silently dropped.
    $annotation = $this->store->updateAnnotation($id, $data);
    if ($annotation === FALSE) {
      // FALSE = storage/I/O error — must return 500, not 404.
      return new JsonResponse(['error' => 'Failed to update annotation'], 500);
    }
    if ($annotation === NULL) {
      return new JsonResponse(['error' => 'Annotation not found'], 404);
    }

    // Return full updated annotation, not just {success: true}.
    return new JsonResponse($annotation, 200, ['Cache-Control' => 'no-store']);
  }

  public function resolveSource(Request $request): JsonResponse {
    if (!$this->config('instruckt_drupal.settings')->get('enabled')) {
      return $this->disabledResponse();
    }
    if (!str_contains($request->headers->get('Content-Type', ''), 'application/json')) {
      return new JsonResponse(['error' => 'Content-Type must be application/json'], 415);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data) || empty($data['framework']) || empty($data['component'])) {
      return new JsonResponse(['error' => 'Missing framework or component'], 400);
    }

    // Validate framework and component to prevent arbitrary path probing.
    // Allowed: lowercase alphanumeric, hyphens, underscores, forward slashes, and dots.
    // e.g. "twig", "blade", "node--article--teaser", "paragraphs/my-para"
    // Explicitly reject ".." — the character class allows both "." and "/" which
    // combined form directory traversal strings like "../../../../etc/passwd".
    if (!is_string($data['framework']) || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $data['framework'])) {
      return new JsonResponse(['error' => 'Invalid framework identifier'], 400);
    }
    if (!is_string($data['component']) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_\-\.\/]*$/', $data['component'])) {
      return new JsonResponse(['error' => 'Invalid component identifier'], 400);
    }
    if (str_contains($data['component'], '..')) {
      return new JsonResponse(['error' => 'Invalid component identifier'], 400);
    }

    return new JsonResponse(
      $this->sourceResolver->resolve($data['framework'], $data['component']),
      200,
      ['Cache-Control' => 'no-store']
    );
  }

  public function serveScreenshot(string $filename): Response {
    if (!$this->config('instruckt_drupal.settings')->get('enabled')) {
      return $this->disabledResponse();
    }
    // Allow ULID-format filenames only: 26 chars from the Crockford base32 alphabet.
    // The original broader regex [a-zA-Z0-9_-]+ is intentionally tightened to prevent
    // path traversal probes and to match the actual ULID generation in InstrucktStore.
    if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}\.(png|svg)$/i', $filename)) {
      return new JsonResponse(['error' => 'Invalid filename'], 400);
    }

    $realPath = $this->store->getScreenshotRealPath('screenshots/' . $filename);
    if (!$realPath) {
      return new JsonResponse(['error' => 'Screenshot not found'], 404);
    }

    $contentType = str_ends_with($filename, '.svg') ? 'image/svg+xml' : 'image/png';
    return new BinaryFileResponse($realPath, 200, [
      'Content-Type'              => $contentType,
      'Cache-Control'             => 'private, max-age=3600',
      'X-Content-Type-Options'    => 'nosniff',
      // Sandboxes SVG script execution: prevents <script> tags in user-supplied
      // SVG files from running even when served inline.
      'Content-Security-Policy'   => "default-src 'none'",
    ]);
  }
}
