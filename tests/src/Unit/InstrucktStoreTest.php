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

}
