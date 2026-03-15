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

  protected static $modules = [];

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
      'instruckt-drupal',
      ['id' => 'instruckt-drupal', 'label' => 'Instruckt Drupal', 'description' => 'Test'],
      $currentUser,
      $store,
      $configFactory,
    );
  }

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
    $this->assertContains('instruckt_get_all_pending', $names);
    $this->assertContains('instruckt_get_screenshot', $names);
    $this->assertContains('instruckt_resolve', $names);
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

  /**
   * @covers ::executeTool
   */
  public function testExecuteToolWhenDisabledReturnsDisabledMessage(): void {
    $result = $this->buildPlugin(FALSE)->executeTool('instruckt_get_all_pending', []);
    $this->assertSame('text', $result[0]['type']);
    $this->assertStringContainsString('disabled', strtolower($result[0]['text']));
  }

  /**
   * @covers ::executeTool
   */
  public function testExecuteToolWithoutPermissionReturnsAccessDenied(): void {
    $result = $this->buildPlugin(TRUE, FALSE)->executeTool('instruckt_get_all_pending', []);
    $this->assertSame('text', $result[0]['type']);
    $this->assertStringContainsString('Access denied', $result[0]['text']);
  }

  /**
   * @covers ::executeTool
   */
  public function testGetAllPendingReturnsPendingAnnotations(): void {
    $annotations = [
      ['id' => '01AAAAAAAAAAAAAAAAAAAAAAAAA', 'comment' => 'Test', 'status' => 'pending'],
    ];
    $result = $this->buildPlugin(TRUE, TRUE, $annotations)->executeTool('instruckt_get_all_pending', []);
    $this->assertSame('text', $result[0]['type']);
    $decoded = json_decode($result[0]['text'], TRUE);
    $this->assertSame(1, $decoded['count']);
  }

  /**
   * @covers ::checkRequirements
   */
  public function testCheckRequirementsAlwaysTrue(): void {
    $this->assertTrue($this->buildPlugin()->checkRequirements());
  }

  /**
   * @covers ::getTools
   */
  public function testGetAllPendingToolIsReadOnly(): void {
    $tools = $this->buildPlugin()->getTools();
    foreach ($tools as $tool) {
      if ($tool->name === 'instruckt_get_all_pending') {
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
      if ($tool->name === 'instruckt_resolve') {
        $this->assertTrue($tool->annotations->destructiveHint ?? FALSE);
      }
    }
  }

}
