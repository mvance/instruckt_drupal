<?php

namespace Drupal\Tests\instruckt_drupal\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\instruckt_drupal\Service\InstrucktStore;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\instruckt_drupal\Service\InstrucktStore
 * @group instruckt_drupal
 */
class InstrucktStoreTest extends UnitTestCase {

  private InstrucktStore $store;
  private string $tempDir;

  protected function setUp(): void {
    parent::setUp();
    $this->tempDir = sys_get_temp_dir() . '/instruckt_test_' . uniqid();
    mkdir($this->tempDir, 0755, TRUE);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(function (string $key) {
      return match ($key) {
        'storage_path' => $this->tempDir,
        'allowed_screenshot_extensions' => ['png', 'svg'],
        'max_screenshot_size' => 5242880,
        default => NULL,
      };
    });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('prepareDirectory')->willReturnCallback(function (string &$path): bool {
      if (!is_dir($path)) {
        mkdir($path, 0755, TRUE);
      }
      return TRUE;
    });
    $fileSystem->method('realpath')->willReturnCallback(fn($p) => realpath($p) ?: NULL);
    $fileSystem->method('saveData')->willReturnCallback(function (string $data, string $uri): string|false {
      return file_put_contents($uri, $data) !== FALSE ? $uri : FALSE;
    });
    $fileSystem->method('delete')->willReturnCallback(function (string $uri): void {
      if (file_exists($uri)) {
        unlink($uri);
      }
    });

    $logger = $this->createMock(LoggerChannelInterface::class);

    $this->store = new InstrucktStore($configFactory, $fileSystem, $logger);
  }

  protected function tearDown(): void {
    parent::tearDown();
    if (is_dir($this->tempDir)) {
      $screenshotsDir = $this->tempDir . '/screenshots';
      if (is_dir($screenshotsDir)) {
        array_map('unlink', glob($screenshotsDir . '/*') ?: []);
        rmdir($screenshotsDir);
      }
      array_map('unlink', glob($this->tempDir . '/*') ?: []);
      rmdir($this->tempDir);
    }
  }

  /**
   * @covers ::createAnnotation
   */
  public function testUlidFormatIsValidCrockfordBase32(): void {
    $annotation = $this->store->createAnnotation([
      'x' => 10, 'y' => 20, 'comment' => 'Test', 'element' => '.foo', 'url' => 'http://example.com',
    ]);
    $this->assertNotNull($annotation);
    $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $annotation['id']);
  }

  /**
   * @covers ::createAnnotation
   */
  public function testCreateAnnotationReturnsCorrectShape(): void {
    $annotation = $this->store->createAnnotation([
      'x' => 10.5, 'y' => 20.5, 'comment' => 'Hello', 'element' => '.btn',
      'url' => 'http://example.com', 'intent' => 'fix', 'severity' => 'important',
      'created_by' => 'Test User',
    ]);
    $this->assertNotNull($annotation);
    $this->assertSame('pending', $annotation['status']);
    $this->assertSame(10.5, $annotation['x']);
    $this->assertSame(20.5, $annotation['y']);
    $this->assertSame('Hello', $annotation['comment']);
    $this->assertArrayHasKey('created_at', $annotation);
    $this->assertArrayHasKey('updated_at', $annotation);
    $this->assertNull($annotation['screenshot']);
    $this->assertIsArray($annotation['thread']);
    $this->assertEmpty($annotation['thread']);
    $this->assertNull($annotation['resolved_by']);
    $this->assertNull($annotation['resolved_at']);
    $this->assertArrayHasKey('created_by', $annotation);
    $this->assertSame('Test User', $annotation['created_by']);
  }

  /**
   * @covers ::getAnnotations
   */
  public function testGetAnnotationsEmptyWhenNoFile(): void {
    $this->assertSame([], $this->store->getAnnotations());
  }

  /**
   * @covers ::createAnnotation
   * @covers ::getAnnotations
   */
  public function testAnnotationsArePersistedInInsertionOrder(): void {
    $this->store->createAnnotation(['x' => 1, 'y' => 1, 'comment' => 'First', 'element' => '.a', 'url' => 'http://example.com']);
    $this->store->createAnnotation(['x' => 2, 'y' => 2, 'comment' => 'Second', 'element' => '.b', 'url' => 'http://example.com']);
    $all = $this->store->getAnnotations();
    $this->assertCount(2, $all);
    $this->assertSame('First', $all[0]['comment']);
    $this->assertSame('Second', $all[1]['comment']);
  }

  /**
   * @covers ::updateAnnotation
   */
  public function testUpdateAnnotationSetsResolutionMetadata(): void {
    $annotation = $this->store->createAnnotation([
      'x' => 1, 'y' => 1, 'comment' => 'Fix me', 'element' => '.x', 'url' => 'http://example.com',
    ]);
    $updated = $this->store->updateAnnotation($annotation['id'], ['status' => 'resolved']);
    $this->assertIsArray($updated);
    $this->assertSame('resolved', $updated['status']);
    $this->assertSame('human', $updated['resolved_by']);
    $this->assertNotNull($updated['resolved_at']);
  }

  /**
   * @covers ::updateAnnotation
   */
  public function testUpdateAnnotationWithAgentResolvedBy(): void {
    $annotation = $this->store->createAnnotation([
      'x' => 1, 'y' => 1, 'comment' => 'Agent fix', 'element' => '.x', 'url' => 'http://example.com',
    ]);
    $updated = $this->store->updateAnnotation($annotation['id'], ['status' => 'resolved'], 'agent');
    $this->assertIsArray($updated);
    $this->assertSame('agent', $updated['resolved_by']);
  }

  /**
   * @covers ::updateAnnotation
   */
  public function testUpdateAnnotationClearsResolutionOnReopen(): void {
    $annotation = $this->store->createAnnotation([
      'x' => 1, 'y' => 1, 'comment' => 'Fix', 'element' => '.x', 'url' => 'http://example.com',
    ]);
    $this->store->updateAnnotation($annotation['id'], ['status' => 'resolved']);
    $updated = $this->store->updateAnnotation($annotation['id'], ['status' => 'pending']);
    $this->assertIsArray($updated);
    $this->assertSame('pending', $updated['status']);
    $this->assertNull($updated['resolved_by']);
    $this->assertNull($updated['resolved_at']);
  }

  /**
   * @covers ::updateAnnotation
   */
  public function testUpdateAnnotationReturnsNullForMissingId(): void {
    $result = $this->store->updateAnnotation('01AAAAAAAAAAAAAAAAAAAAAAAAA', ['status' => 'resolved']);
    $this->assertNull($result);
  }

  /**
   * @covers ::saveScreenshot
   */
  public function testSavePngScreenshotReturnsRelativePath(): void {
    // Minimal 1x1 white PNG (valid binary).
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI6QAAAABJRU5ErkJggg==');
    $dataUrl = 'data:image/png;base64,' . base64_encode($png);
    mkdir($this->tempDir . '/screenshots', 0755, TRUE);
    $relPath = $this->store->saveScreenshot('01AAAAAAAAAAAAAAAAAAAAAAAAA', $dataUrl);
    $this->assertNotNull($relPath);
    $this->assertStringStartsWith('screenshots/', $relPath);
    $this->assertStringEndsWith('.png', $relPath);
  }

  /**
   * @covers ::saveScreenshot
   */
  public function testSaveSvgScreenshotReturnsRelativePath(): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>';
    $dataUrl = 'data:image/svg+xml;base64,' . base64_encode($svg);
    mkdir($this->tempDir . '/screenshots', 0755, TRUE);
    $relPath = $this->store->saveScreenshot('01AAAAAAAAAAAAAAAAAAAAAAAAA', $dataUrl);
    $this->assertNotNull($relPath);
    $this->assertStringEndsWith('.svg', $relPath);
  }

  /**
   * @covers ::saveScreenshot
   */
  public function testSaveScreenshotRejectsNonDataUrl(): void {
    $result = $this->store->saveScreenshot('01AAAAAAAAAAAAAAAAAAAAAAAAA', 'not-a-data-url');
    $this->assertNull($result);
  }

  /**
   * @covers ::getScreenshotRealPath
   */
  public function testGetScreenshotRealPathReturnsNullForMissingFile(): void {
    $result = $this->store->getScreenshotRealPath('screenshots/nonexistent.png');
    $this->assertNull($result);
  }

  /**
   * @covers ::deleteScreenshot
   */
  public function testDeleteScreenshotWithNullPathDoesNotError(): void {
    $this->store->deleteScreenshot(NULL);
    $this->assertTrue(TRUE); // Assert no exception thrown.
  }

  /**
   * @covers ::deleteScreenshot
   */
  public function testDeleteScreenshotWithMissingPathDoesNotError(): void {
    $this->store->deleteScreenshot('screenshots/missing.png');
    $this->assertTrue(TRUE); // Assert no exception thrown.
  }

  /**
   * @covers ::getPendingAnnotations
   */
  public function testGetPendingAnnotationsFiltersResolved(): void {
    $a1 = $this->store->createAnnotation([
      'x' => 1, 'y' => 1, 'comment' => 'Keep', 'element' => '.a', 'url' => 'http://example.com',
    ]);
    $a2 = $this->store->createAnnotation([
      'x' => 2, 'y' => 2, 'comment' => 'Resolve', 'element' => '.b', 'url' => 'http://example.com',
    ]);
    $this->store->updateAnnotation($a2['id'], ['status' => 'resolved']);
    $pending = $this->store->getPendingAnnotations();
    $this->assertCount(1, $pending);
    $this->assertSame($a1['id'], $pending[0]['id']);
  }

  // ---------------------------------------------------------------------------
  // Mutation-targeted tests (Step 5 of the mutation testing plan)
  // ---------------------------------------------------------------------------

  /**
   * Kills mutant #2: LogicalOr → LogicalAnd on line 91.
   *
   * JSON that decodes successfully but is not an array must return [].
   */
  public function testGetAnnotationsReturnsEmptyForNonArrayJson(): void {
    file_put_contents($this->tempDir . '/annotations.json', '"not-an-array"');
    $this->assertSame([], $this->store->getAnnotations());
  }

  /**
   * Kills mutant #3: UnwrapArrayValues removed on line 104.
   *
   * Resolving the FIRST annotation leaves the second at filtered key 1.
   * array_values() re-indexes it to 0; without it $pending[0] is undefined.
   */
  public function testGetPendingAnnotationsReIndexesKeysAfterFirstResolved(): void {
    $a1 = $this->store->createAnnotation([
      'x' => 1, 'y' => 1, 'comment' => 'First', 'element' => '.a', 'url' => 'http://example.com',
    ]);
    $a2 = $this->store->createAnnotation([
      'x' => 2, 'y' => 2, 'comment' => 'Second', 'element' => '.b', 'url' => 'http://example.com',
    ]);
    $this->store->updateAnnotation($a1['id'], ['status' => 'resolved']);
    $pending = $this->store->getPendingAnnotations();
    $this->assertCount(1, $pending);
    $this->assertArrayHasKey(0, $pending);
    $this->assertSame($a2['id'], $pending[0]['id']);
  }

  /**
   * Kills mutants: Coalesce / Decrement / Increment / CastFloat defaults.
   *
   * Omitting x, y, url, element, intent, severity exercises every ?? default
   * and the (float) cast. assertSame checks both value AND type.
   */
  public function testCreateAnnotationFieldDefaults(): void {
    $annotation = $this->store->createAnnotation(['comment' => 'Min']);
    $this->assertNotNull($annotation);
    $this->assertSame('', $annotation['url']);
    $this->assertSame(0.0, $annotation['x']);
    $this->assertSame(0.0, $annotation['y']);
    $this->assertSame('', $annotation['element']);
    $this->assertSame('fix', $annotation['intent']);
    $this->assertSame('important', $annotation['severity']);
  }

  /**
   * Kills swapped-coalesce mutants on url / element / intent / severity.
   *
   * Infection's Coalesce mutator swaps `$data['x'] ?? 'default'` to
   * `'default' ?? $data['x']`, which always evaluates to 'default'.
   * Asserting the non-default value confirms the provided value is used.
   */
  public function testCreateAnnotationUsesProvidedOptionalFields(): void {
    $annotation = $this->store->createAnnotation([
      'url'      => 'http://custom.com',
      'element'  => '.custom',
      'intent'   => 'question',
      'severity' => 'minor',
      'comment'  => 'Test',
    ]);
    $this->assertNotNull($annotation);
    $this->assertSame('http://custom.com', $annotation['url']);
    $this->assertSame('.custom', $annotation['element']);
    $this->assertSame('question', $annotation['intent']);
    $this->assertSame('minor', $annotation['severity']);
  }

  /**
   * Kills mutant #14: NotIdentical → Identical on line 347.
   *
   * When an annotation with a screenshot is resolved, the screenshot file
   * must be deleted. With the mutation (=== NULL), deletion is skipped.
   */
  public function testResolvedAnnotationScreenshotIsDeleted(): void {
    mkdir($this->tempDir . '/screenshots', 0755, TRUE);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI6QAAAABJRU5ErkJggg==');
    $dataUrl = 'data:image/png;base64,' . base64_encode($png);
    $annotation = $this->store->createAnnotation([
      'comment' => 'Shot', 'element' => '.x', 'url' => 'http://example.com',
      'screenshot' => $dataUrl,
    ]);
    $this->assertNotNull($annotation);
    $screenshotRelPath = $annotation['screenshot'];
    $this->assertNotNull($screenshotRelPath);
    $screenshotFullPath = $this->tempDir . '/' . $screenshotRelPath;
    $this->assertFileExists($screenshotFullPath);
    $this->store->updateAnnotation($annotation['id'], ['status' => 'resolved']);
    $this->assertFileDoesNotExist($screenshotFullPath);
  }

  /**
   * Kills mutant #15: MethodCallRemoval removes prepareDirectory on line 371.
   *
   * Calling saveScreenshot without pre-creating the screenshots directory
   * relies on prepareDirectory to create it. Without that call, saveData fails.
   */
  public function testSaveScreenshotCreatesDirectoryIfMissing(): void {
    // Do NOT mkdir screenshots — prepareDirectory mock must do it.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI6QAAAABJRU5ErkJggg==');
    $dataUrl = 'data:image/png;base64,' . base64_encode($png);
    $relPath = $this->store->saveScreenshot('01AAAAAAAAAAAAAAAAAAAAAAAAB', $dataUrl);
    $this->assertNotNull($relPath);
  }

  /**
   * Kills mutants #17, #18: ArrayItemRemoval / Coalesce on allowed_screenshot_extensions.
   *
   * When config returns NULL the default ['png', 'svg'] is used. Removing 'svg'
   * from the default rejects the SVG; removing 'png' rejects the PNG.
   */
  public function testSaveScreenshotUsesDefaultAllowedExtensionsForPng(): void {
    $store = $this->makeStore(['allowed_screenshot_extensions' => NULL]);
    mkdir($this->tempDir . '/screenshots', 0755, TRUE);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI6QAAAABJRU5ErkJggg==');
    $dataUrl = 'data:image/png;base64,' . base64_encode($png);
    $this->assertNotNull($store->saveScreenshot('01AAAAAAAAAAAAAAAAAAAAAAAAC', $dataUrl));
  }

  public function testSaveScreenshotUsesDefaultAllowedExtensionsForSvg(): void {
    $store = $this->makeStore(['allowed_screenshot_extensions' => NULL]);
    mkdir($this->tempDir . '/screenshots', 0755, TRUE);
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>';
    $dataUrl = 'data:image/svg+xml;base64,' . base64_encode($svg);
    $this->assertNotNull($store->saveScreenshot('01AAAAAAAAAAAAAAAAAAAAAAAAG', $dataUrl));
  }

  /**
   * Kills mutant #21: Coalesce removes ?? 5242880 on line 402.
   *
   * When config returns NULL the default (5 242 880) applies. Without the
   * coalesce, $maxSize = NULL and PHP coerces it to 0 in comparison, causing
   * every non-empty image to be rejected.
   */
  public function testSaveScreenshotUsesDefaultMaxSizeWhenConfigNull(): void {
    $store = $this->makeStore(['max_screenshot_size' => NULL]);
    mkdir($this->tempDir . '/screenshots', 0755, TRUE);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI6QAAAABJRU5ErkJggg==');
    $dataUrl = 'data:image/png;base64,' . base64_encode($png);
    $this->assertNotNull($store->saveScreenshot('01AAAAAAAAAAAAAAAAAAAAAAAAD', $dataUrl));
  }

  /**
   * Kills mutant #22: GreaterThan → GreaterThanOrEqual on line 403.
   *
   * A file of exactly max_screenshot_size bytes must be accepted (> not >=).
   * With >= the boundary file is rejected and the assertion fails.
   */
  public function testSaveScreenshotAllowsExactlyMaxSizeBytes(): void {
    // URL-encoded SVG: rawData after split is '<svg>' = 5 bytes.
    $store = $this->makeStore(['max_screenshot_size' => 5]);
    $relPath = $store->saveScreenshot('01AAAAAAAAAAAAAAAAAAAAAAAAE', 'data:image/svg+xml,<svg>');
    $this->assertNotNull($relPath); // 5 > 5 is false → allowed; 5 >= 5 would reject
  }

  /**
   * Kills mutants #23, #24: Concat / ConcatOperandRemoval on line 410.
   *
   * Mutants corrupt the URI used by saveData, writing the file to the wrong
   * location. The assertion that the file exists at the canonical path fails.
   */
  public function testSaveScreenshotSavesToCorrectFilesystemPath(): void {
    mkdir($this->tempDir . '/screenshots', 0755, TRUE);
    $id = '01AAAAAAAAAAAAAAAAAAAAAAAAF';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI6QAAAABJRU5ErkJggg==');
    $dataUrl = 'data:image/png;base64,' . base64_encode($png);
    $this->store->saveScreenshot($id, $dataUrl);
    $this->assertFileExists($this->tempDir . '/screenshots/' . $id . '.png');
  }

  /**
   * Kills mutants #26-30: Concat / ConcatOperandRemoval / LogicalNot on lines 426-427.
   *
   * getScreenshotRealPath must return the real path when the file exists.
   * URI corruption mutations cause file_exists to return false → returns NULL.
   * LogicalNot inverts the condition, returning NULL for existing files.
   */
  public function testGetScreenshotRealPathReturnsPathForExistingFile(): void {
    $screenshotsDir = $this->tempDir . '/screenshots';
    mkdir($screenshotsDir, 0755, TRUE);
    file_put_contents($screenshotsDir . '/test.png', 'dummy');
    $result = $this->store->getScreenshotRealPath('screenshots/test.png');
    $this->assertNotNull($result);
    $this->assertSame(realpath($this->tempDir . '/screenshots/test.png'), $result);
  }

  /**
   * Kills mutants #31-35: Concat / ConcatOperandRemoval / IfNegation on lines 437-438.
   *
   * deleteScreenshot must delete the file at the correct path. URI mutations
   * compute wrong paths so file_exists returns false → file is not deleted.
   * IfNegation inverts the condition, skipping deletion for existing files.
   */
  public function testDeleteScreenshotRemovesExistingFile(): void {
    $screenshotsDir = $this->tempDir . '/screenshots';
    mkdir($screenshotsDir, 0755, TRUE);
    $relPath = 'screenshots/delete_me.png';
    $fullPath = $this->tempDir . '/' . $relPath;
    file_put_contents($fullPath, 'content');
    $this->assertFileExists($fullPath);
    $this->store->deleteScreenshot($relPath);
    $this->assertFileDoesNotExist($fullPath);
  }

  // ---------------------------------------------------------------------------
  // Helper
  // ---------------------------------------------------------------------------

  /**
   * Creates an InstrucktStore with config value overrides (NULL = use default).
   */
  private function makeStore(array $configOverrides = []): InstrucktStore {
    $defaults = [
      'storage_path'                  => $this->tempDir,
      'allowed_screenshot_extensions' => ['png', 'svg'],
      'max_screenshot_size'           => 5242880,
    ];
    $configValues = array_merge($defaults, $configOverrides);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      fn(string $key) => $configValues[$key] ?? NULL
    );

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('prepareDirectory')->willReturnCallback(function (string &$path): bool {
      if (!is_dir($path)) {
        mkdir($path, 0755, TRUE);
      }
      return TRUE;
    });
    $fileSystem->method('realpath')->willReturnCallback(fn($p) => realpath($p) ?: NULL);
    $fileSystem->method('saveData')->willReturnCallback(
      function (string $data, string $uri): string|false {
        return file_put_contents($uri, $data) !== FALSE ? $uri : FALSE;
      }
    );
    $fileSystem->method('delete')->willReturnCallback(function (string $uri): void {
      if (file_exists($uri)) {
        unlink($uri);
      }
    });

    $logger = $this->createMock(LoggerChannelInterface::class);
    return new InstrucktStore($configFactory, $fileSystem, $logger);
  }

}
