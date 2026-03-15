<?php

namespace Drupal\Tests\instruckt_drupal\Kernel;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\instruckt_drupal\Plugin\Mcp\InstrucktPlugin;
use Drupal\instruckt_drupal\Service\InstrucktStore;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mcp\ServerFeatures\Tool;

/**
 * Tests InstrucktPlugin MCP tool definitions and dispatch.
 *
 * @coversDefaultClass \Drupal\instruckt_drupal\Plugin\Mcp\InstrucktPlugin
 * @group instruckt_drupal
 */
class InstrucktPluginTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [];

  // Valid 26-character Crockford Base32 ULID used across tests.
  private const VALID_ULID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

  /**
   * Builds the plugin with mocked dependencies.
   */
  private function buildPlugin(bool $enabled = TRUE, bool $hasPermission = TRUE, array $pendingAnnotations = []): InstrucktPlugin {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('enabled')->willReturn($enabled);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('hasPermission')
      ->with('access instruckt_drupal toolbar')
      ->willReturn($hasPermission);

    $store = $this->createMock(InstrucktStore::class);
    $store->method('getPendingAnnotations')->willReturn($pendingAnnotations);
    $store->method('getAnnotations')->willReturn($pendingAnnotations);

    return new InstrucktPlugin(
      [],
      'instrucktdrupal',
      ['id' => 'instrucktdrupal', 'label' => 'Instruckt Drupal', 'description' => 'Test'],
      $currentUser,
      $store,
      $configFactory,
    );
  }

  /**
   * Builds a plugin with a fully-configured store mock for per-test control.
   */
  private function buildPluginWithStore(
    InstrucktStore $store,
    bool $enabled = TRUE,
    bool $hasPermission = TRUE,
  ): InstrucktPlugin {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('enabled')->willReturn($enabled);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('hasPermission')
      ->with('access instruckt_drupal toolbar')
      ->willReturn($hasPermission);

    return new InstrucktPlugin(
      [],
      'instrucktdrupal',
      ['id' => 'instrucktdrupal', 'label' => 'Instruckt Drupal', 'description' => 'Test'],
      $currentUser,
      $store,
      $configFactory,
    );
  }

  // ---------------------------------------------------------------------------
  // Tool definitions
  // ---------------------------------------------------------------------------

  /**
   * @covers ::getTools
   */
  public function testGetToolsReturnsThreeTools(): void {
    $tools = $this->buildPlugin()->getTools();
    $this->assertCount(3, $tools);
    foreach ($tools as $tool) {
      $this->assertInstanceOf(Tool::class, $tool);
    }
  }

  /**
   * @covers ::getTools
   */
  public function testGetToolNamesMatchSpec(): void {
    $tools = $this->buildPlugin()->getTools();
    $names = array_map(fn(Tool $t) => $t->name, $tools);
    $this->assertContains('get_all_pending', $names);
    $this->assertContains('get_screenshot', $names);
    $this->assertContains('resolve', $names);
  }

  /**
   * @covers ::getTools
   */
  public function testGetAllPendingToolIsReadOnly(): void {
    $tools = $this->buildPlugin()->getTools();
    foreach ($tools as $tool) {
      if ($tool->name === 'get_all_pending') {
        $this->assertTrue($tool->annotations->readOnlyHint ?? FALSE);
      }
    }
  }

  /**
   * @covers ::getTools
   */
  public function testResolveToolIsDestructive(): void {
    $tools = $this->buildPlugin()->getTools();
    foreach ($tools as $tool) {
      if ($tool->name === 'resolve') {
        $this->assertTrue($tool->annotations->destructiveHint ?? FALSE);
      }
    }
  }

  // ---------------------------------------------------------------------------
  // Plugin ID and endpoint-facing tool names
  // ---------------------------------------------------------------------------

  /**
   * Plugin ID must satisfy drupal/mcp validation (letters, numbers, underscores only).
   */
  public function testPluginIdIsValidMcpId(): void {
    $this->assertMatchesRegularExpression(
      '/^[a-zA-Z0-9-]+$/',
      $this->buildPlugin()->getPluginId(),
      'Plugin ID must contain only letters, numbers, and hyphens.',
    );
  }

  /**
   * Tool names include the plugin ID prefix used by drupal/mcp.
   *
   * The MCP module prefixes tool names with the plugin ID when building the
   * tools/list response and when routing tools/call requests. This test locks
   * in the exact names that MCP clients must use.
   */
  public function testGeneratedToolNamesMatchEndpointNames(): void {
    $plugin = $this->buildPlugin();
    $actual = array_map(
      fn(Tool $t) => $plugin->generateToolId($plugin->getPluginId(), $t->name),
      $plugin->getTools(),
    );
    $this->assertSame([
      'instrucktdrupal_get_all_pending',
      'instrucktdrupal_get_screenshot',
      'instrucktdrupal_resolve',
    ], $actual);
  }

  // ---------------------------------------------------------------------------
  // executeTool — guard rails (disabled / no permission / unknown tool)
  // ---------------------------------------------------------------------------

  /**
   * @covers ::executeTool
   */
  public function testExecuteToolWhenDisabledReturnsDisabledMessage(): void {
    $result = $this->buildPlugin(FALSE)->executeTool('get_all_pending', []);
    $this->assertSame('text', $result[0]['type']);
    $this->assertStringContainsString('disabled', strtolower($result[0]['text']));
  }

  /**
   * @covers ::executeTool
   */
  public function testExecuteToolWithoutPermissionReturnsAccessDenied(): void {
    $result = $this->buildPlugin(TRUE, FALSE)->executeTool('get_all_pending', []);
    $this->assertSame('text', $result[0]['type']);
    $this->assertStringContainsString('Access denied', $result[0]['text']);
  }

  /**
   * @covers ::executeTool
   */
  public function testExecuteToolUnknownReturnsErrorText(): void {
    $result = $this->buildPlugin()->executeTool('nonexistent_tool', []);
    $this->assertIsArray($result);
    $this->assertCount(1, $result);
    $this->assertSame('text', $result[0]['type']);
    $this->assertStringContainsString('Unknown', $result[0]['text']);
  }

  // ---------------------------------------------------------------------------
  // get_all_pending
  // ---------------------------------------------------------------------------

  /**
   * @covers ::executeTool
   */
  public function testGetAllPendingReturnsPendingAnnotations(): void {
    $annotations = [
      ['id' => self::VALID_ULID, 'comment' => 'Test', 'status' => 'pending'],
    ];
    $result = $this->buildPlugin(TRUE, TRUE, $annotations)->executeTool('get_all_pending', []);
    $this->assertSame('text', $result[0]['type']);
    $decoded = json_decode($result[0]['text'], TRUE);
    $this->assertSame(1, $decoded['count']);
  }

  // ---------------------------------------------------------------------------
  // get_screenshot
  // ---------------------------------------------------------------------------

  /**
   * @covers ::executeTool
   */
  public function testGetScreenshotMissingAnnotationIdReturnsError(): void {
    $result = $this->buildPlugin()->executeTool('get_screenshot', []);
    $this->assertSame('text', $result[0]['type']);
    $this->assertSame('annotation_id is required.', $result[0]['text']);
  }

  /**
   * @covers ::executeTool
   */
  public function testGetScreenshotInvalidUlidReturnsError(): void {
    $result = $this->buildPlugin()->executeTool('get_screenshot', ['annotation_id' => 'not-a-valid-ulid!!']);
    $this->assertSame('text', $result[0]['type']);
    $this->assertSame('Invalid annotation_id format.', $result[0]['text']);
  }

  /**
   * @covers ::executeTool
   */
  public function testGetScreenshotAnnotationNotFoundReturnsError(): void {
    // Store returns no annotations (default empty array in buildPlugin).
    $result = $this->buildPlugin()->executeTool('get_screenshot', ['annotation_id' => self::VALID_ULID]);
    $this->assertSame('text', $result[0]['type']);
    $this->assertSame('Annotation not found.', $result[0]['text']);
  }

  /**
   * @covers ::executeTool
   */
  public function testGetScreenshotAnnotationWithNoScreenshotReturnsError(): void {
    $store = $this->createMock(InstrucktStore::class);
    $store->method('getAnnotations')->willReturn([
      ['id' => self::VALID_ULID, 'screenshot' => NULL],
    ]);

    $result = $this->buildPluginWithStore($store)
      ->executeTool('get_screenshot', ['annotation_id' => self::VALID_ULID]);
    $this->assertSame('text', $result[0]['type']);
    $this->assertSame('Annotation has no screenshot.', $result[0]['text']);
  }

  /**
   * @covers ::executeTool
   */
  public function testGetScreenshotFileNotOnDiskReturnsError(): void {
    $store = $this->createMock(InstrucktStore::class);
    $store->method('getAnnotations')->willReturn([
      ['id' => self::VALID_ULID, 'screenshot' => 'screenshots/' . self::VALID_ULID . '.png'],
    ]);
    $store->method('getScreenshotRealPath')->willReturn(NULL);

    $result = $this->buildPluginWithStore($store)
      ->executeTool('get_screenshot', ['annotation_id' => self::VALID_ULID]);
    $this->assertSame('text', $result[0]['type']);
    $this->assertSame('Screenshot file not found on disk.', $result[0]['text']);
  }

  /**
   * @covers ::executeTool
   */
  public function testGetScreenshotSuccessReturnsBase64Image(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'instruckt_test_') . '.png';
    file_put_contents($tmpFile, 'fake-png-data');

    $store = $this->createMock(InstrucktStore::class);
    $store->method('getAnnotations')->willReturn([
      ['id' => self::VALID_ULID, 'screenshot' => 'screenshots/' . self::VALID_ULID . '.png'],
    ]);
    $store->method('getScreenshotRealPath')->willReturn($tmpFile);

    $result = $this->buildPluginWithStore($store)
      ->executeTool('get_screenshot', ['annotation_id' => self::VALID_ULID]);

    unlink($tmpFile);

    $this->assertSame('image', $result[0]['type']);
    $this->assertSame(base64_encode('fake-png-data'), $result[0]['data']);
    $this->assertSame('image/png', $result[0]['mimeType']);
  }

  // ---------------------------------------------------------------------------
  // resolve
  // ---------------------------------------------------------------------------

  /**
   * @covers ::executeTool
   */
  public function testResolveMissingIdReturnsError(): void {
    $result = $this->buildPlugin()->executeTool('resolve', []);
    $this->assertSame('text', $result[0]['type']);
    $this->assertSame('id is required.', $result[0]['text']);
  }

  /**
   * @covers ::executeTool
   */
  public function testResolveInvalidUlidReturnsError(): void {
    $result = $this->buildPlugin()->executeTool('resolve', ['id' => 'not-a-valid-ulid!!']);
    $this->assertSame('text', $result[0]['type']);
    $this->assertSame('Invalid id format.', $result[0]['text']);
  }

  /**
   * @covers ::executeTool
   */
  public function testResolveStorageErrorReturnsError(): void {
    $store = $this->createMock(InstrucktStore::class);
    $store->method('updateAnnotation')->willReturn(FALSE);

    $result = $this->buildPluginWithStore($store)
      ->executeTool('resolve', ['id' => self::VALID_ULID]);
    $this->assertSame('text', $result[0]['type']);
    $this->assertSame('Storage error resolving annotation.', $result[0]['text']);
  }

  /**
   * @covers ::executeTool
   */
  public function testResolveAnnotationNotFoundReturnsError(): void {
    $store = $this->createMock(InstrucktStore::class);
    $store->method('updateAnnotation')->willReturn(NULL);

    $result = $this->buildPluginWithStore($store)
      ->executeTool('resolve', ['id' => self::VALID_ULID]);
    $this->assertSame('text', $result[0]['type']);
    $this->assertSame('Annotation not found.', $result[0]['text']);
  }

  /**
   * @covers ::executeTool
   */
  public function testResolveSuccessReturnsResolvedAnnotationJson(): void {
    $resolved = [
      'id'          => self::VALID_ULID,
      'status'      => 'resolved',
      'resolved_by' => 'agent',
    ];
    $store = $this->createMock(InstrucktStore::class);
    $store->method('updateAnnotation')
      ->with(self::VALID_ULID, ['status' => 'resolved'], 'agent')
      ->willReturn($resolved);

    $result = $this->buildPluginWithStore($store)
      ->executeTool('resolve', ['id' => self::VALID_ULID]);
    $this->assertSame('text', $result[0]['type']);
    $decoded = json_decode($result[0]['text'], TRUE);
    $this->assertSame('resolved', $decoded['status']);
    $this->assertSame('agent', $decoded['resolved_by']);
  }

  // ---------------------------------------------------------------------------
  // Miscellaneous
  // ---------------------------------------------------------------------------

  /**
   * @covers ::checkRequirements
   */
  public function testCheckRequirementsAlwaysTrue(): void {
    $this->assertTrue($this->buildPlugin()->checkRequirements());
  }

}
