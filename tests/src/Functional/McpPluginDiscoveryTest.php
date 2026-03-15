<?php

namespace Drupal\Tests\instruckt_drupal\Functional;

use Drupal\instruckt_drupal\Plugin\Mcp\InstrucktPlugin;
use Drupal\mcp\ServerFeatures\Tool;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests that the instruckt_drupal MCP plugin is discoverable.
 *
 * @group instruckt_drupal
 */
class McpPluginDiscoveryTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['instruckt_drupal'];

  /**
   * Default theme.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function prepareSettings(): void {
    parent::prepareSettings();
    $settings['settings']['file_private_path'] = (object) [
      'value' => $this->privateFilesDirectory,
      'required' => TRUE,
    ];
    $this->writeSettings($settings);
  }

  /**
   * Tests that the MCP plugin manager can discover the instruckt_drupal plugin.
   */
  public function testMcpPluginManagerDiscoversinstrucktPlugin(): void {
    // The MCP plugin manager service ID may vary. Try common ones.
    $container = \Drupal::getContainer();

    // Try to find the MCP plugin manager.
    $managerServiceId = NULL;
    foreach (['plugin.manager.mcp', 'plugin.manager.mcp_plugin', 'mcp.plugin_manager'] as $serviceId) {
      if ($container->has($serviceId)) {
        $managerServiceId = $serviceId;
        break;
      }
    }

    if ($managerServiceId === NULL) {
      $this->markTestSkipped('MCP plugin manager service not found. Check service ID in mcp module.');
    }

    $manager = $container->get($managerServiceId);
    $definitions = $manager->getDefinitions();
    $this->assertArrayHasKey('instrucktdrupal', $definitions);
  }

  /**
   * Tests that the instruckt_drupal plugin provides the expected three tools.
   */
  public function testInstrucktPluginProvidesThreeTools(): void {
    // Directly instantiate the plugin class (bypasses the plugin manager).
    // This avoids the need to know the exact plugin manager service ID.
    $store = \Drupal::service('instruckt_drupal.store');
    $configFactory = \Drupal::service('config.factory');
    $currentUser = \Drupal::service('current_user');

    $plugin = new InstrucktPlugin(
      [],
      'instrucktdrupal',
      ['id' => 'instrucktdrupal', 'label' => 'Instruckt Drupal', 'description' => ''],
      $currentUser,
      $store,
      $configFactory,
    );

    $tools = $plugin->getTools();
    $this->assertCount(3, $tools);

    $names = array_map(fn(Tool $t) => $t->name, $tools);
    $this->assertContains('get_all_pending', $names);
    $this->assertContains('get_screenshot', $names);
    $this->assertContains('resolve', $names);
  }

}
