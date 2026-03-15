<?php

namespace Drupal\Tests\instruckt_drupal\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the Instruckt admin settings form.
 *
 * @group instruckt_drupal
 */
class SettingsFormTest extends BrowserTestBase {

  protected static $modules = ['instruckt_drupal'];
  protected $defaultTheme = 'stark';

  protected function prepareSettings(): void {
    parent::prepareSettings();
    $settings['settings']['file_private_path'] = (object) [
      'value' => $this->privateFilesDirectory,
      'required' => TRUE,
    ];
    $this->writeSettings($settings);
  }

  /**
   * Admin with the administer permission gets 200.
   */
  public function testAdminCanAccessSettingsForm(): void {
    $admin = $this->drupalCreateUser(['administer instruckt_drupal']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/development/instruckt');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * User without the permission gets 403.
   */
  public function testUserWithoutPermissionCannotAccessSettingsForm(): void {
    $user = $this->drupalCreateUser([]);
    $this->drupalLogin($user);
    $this->drupalGet('/admin/config/development/instruckt');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Anonymous user gets a non-200 response.
   */
  public function testAnonymousCannotAccessSettingsForm(): void {
    $this->drupalGet('/admin/config/development/instruckt');
    $this->assertSession()->statusCodeNotEquals(200);
  }

  /**
   * Form renders current config values correctly.
   */
  public function testFormRendersDefaultValues(): void {
    $admin = $this->drupalCreateUser(['administer instruckt_drupal']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/development/instruckt');
    $this->assertSession()->statusCodeEquals(200);

    // enabled checkbox should be checked.
    $this->assertSession()->checkboxChecked('enabled');

    // storage_path default.
    $this->assertSession()->fieldValueEquals('storage_path', 'private://_instruckt');

    // max_screenshot_size_mb: 5242880 bytes = 5 MB.
    $this->assertSession()->fieldValueEquals('max_screenshot_size_mb', '5');

    // Both format checkboxes checked.
    $this->assertSession()->checkboxChecked('allowed_screenshot_extensions[png]');
    $this->assertSession()->checkboxChecked('allowed_screenshot_extensions[svg]');
  }

  /**
   * Successful submission saves all four config keys correctly.
   */
  public function testSuccessfulSubmissionSavesConfig(): void {
    $admin = $this->drupalCreateUser(['administer instruckt_drupal']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/development/instruckt');

    $this->submitForm([
      'enabled' => FALSE,
      'storage_path' => 'private://custom_instruckt',
      'max_screenshot_size_mb' => '2.5',
      'allowed_screenshot_extensions[png]' => TRUE,
      'allowed_screenshot_extensions[svg]' => FALSE,
    ], 'Save configuration');

    $config = $this->config('instruckt_drupal.settings');
    $this->assertFalse((bool) $config->get('enabled'));
    $this->assertEquals('private://custom_instruckt', $config->get('storage_path'));
    // 2.5 MB = 2621440 bytes.
    $this->assertEquals(2621440, $config->get('max_screenshot_size'));
    $this->assertEquals(['png'], $config->get('allowed_screenshot_extensions'));
  }

  /**
   * Validation rejects empty storage_path.
   */
  public function testValidationRejectsEmptyStoragePath(): void {
    $admin = $this->drupalCreateUser(['administer instruckt_drupal']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/development/instruckt');

    $this->submitForm([
      'storage_path' => '',
      'max_screenshot_size_mb' => '5',
      'allowed_screenshot_extensions[png]' => TRUE,
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('Storage path must not be empty.');
  }

  /**
   * Validation rejects non-private:// storage path.
   */
  public function testValidationRejectsNonPrivateStoragePath(): void {
    $admin = $this->drupalCreateUser(['administer instruckt_drupal']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/development/instruckt');

    $this->submitForm([
      'storage_path' => 'public://_instruckt',
      'max_screenshot_size_mb' => '5',
      'allowed_screenshot_extensions[png]' => TRUE,
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('Storage path must use the');
  }

  /**
   * Validation rejects zero/negative max_screenshot_size_mb.
   */
  public function testValidationRejectsNonPositiveScreenshotSize(): void {
    $admin = $this->drupalCreateUser(['administer instruckt_drupal']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/development/instruckt');

    $this->submitForm([
      'storage_path' => 'private://_instruckt',
      'max_screenshot_size_mb' => '0',
      'allowed_screenshot_extensions[png]' => TRUE,
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('Maximum screenshot size must be a positive number.');
  }

  /**
   * Validation requires at least one extension checked.
   */
  public function testValidationRequiresAtLeastOneExtension(): void {
    $admin = $this->drupalCreateUser(['administer instruckt_drupal']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/development/instruckt');

    $this->submitForm([
      'storage_path' => 'private://_instruckt',
      'max_screenshot_size_mb' => '5',
      'allowed_screenshot_extensions[png]' => FALSE,
      'allowed_screenshot_extensions[svg]' => FALSE,
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('At least one screenshot format must be selected.');
  }

}
