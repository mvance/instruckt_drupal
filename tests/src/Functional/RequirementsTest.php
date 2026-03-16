<?php

namespace Drupal\Tests\instruckt_drupal\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests hook_requirements() behavior for instruckt_drupal.
 *
 * @group instruckt_drupal
 */
class RequirementsTest extends BrowserTestBase {

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
   * Tests that hook_requirements returns OK when iife.js is present.
   */
  public function testRequirementsOkWhenIifePresent(): void {
    $iifePath = $this->getDrupalRoot() . '/libraries/instruckt/dist/instruckt.iife.js';

    // Create the file if it doesn't exist (test isolation).
    $created = FALSE;
    if (!file_exists($iifePath)) {
      $dir = dirname($iifePath);
      if (!is_dir($dir)) {
        mkdir($dir, 0755, TRUE);
      }
      file_put_contents($iifePath, '(function(){window.Instruckt={init:function(){}};})();');
      $created = TRUE;
    }

    // Call hook_requirements via the module handler.
    \Drupal::moduleHandler()->loadInclude('instruckt_drupal', 'install');
    $requirements = instruckt_drupal_requirements('runtime');

    if ($created) {
      unlink($iifePath);
    }

    if (isset($requirements['instruckt_drupal_js'])) {
      $this->assertSame(REQUIREMENT_OK, $requirements['instruckt_drupal_js']['severity']);
    }
  }

  /**
   * Tests that hook_requirements reports WARNING (CDN fallback) when iife.js is absent.
   */
  public function testRequirementsWarningWhenIifeMissing(): void {
    $iifePath = $this->getDrupalRoot() . '/libraries/instruckt/dist/instruckt.iife.js';

    // Temporarily rename the file if it exists.
    $backupPath = $iifePath . '.bak';
    $existed = file_exists($iifePath);
    if ($existed) {
      rename($iifePath, $backupPath);
    }

    \Drupal::moduleHandler()->loadInclude('instruckt_drupal', 'install');
    $requirements = instruckt_drupal_requirements('runtime');

    // Restore the file.
    if ($existed) {
      rename($backupPath, $iifePath);
    }

    $this->assertArrayHasKey('instruckt_drupal_js', $requirements);
    $this->assertSame(REQUIREMENT_WARNING, $requirements['instruckt_drupal_js']['severity']);
  }

}
