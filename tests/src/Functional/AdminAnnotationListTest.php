<?php

namespace Drupal\Tests\instruckt_drupal\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for the admin annotation list page.
 *
 * @group instruckt_drupal
 */
class AdminAnnotationListTest extends BrowserTestBase {

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
   * Returns the BrowserKit client from the current Mink session.
   */
  private function getBrowserKitClient(): \Symfony\Component\BrowserKit\AbstractBrowser {
    return $this->getSession()->getDriver()->getClient();
  }

  /**
   * Makes a JSON request using the BrowserKit client (maintains session cookies).
   */
  private function jsonRequest(string $method, string $path, array $data = [], string $xsrfToken = ''): array {
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($xsrfToken !== '') {
      $server['HTTP_X_XSRF_TOKEN'] = $xsrfToken;
    }
    $this->getBrowserKitClient()->request(
      $method,
      $this->buildUrl($path),
      [], [],
      $server,
      json_encode($data)
    );
    return [
      'status' => $this->getSession()->getStatusCode(),
      'body' => json_decode($this->getSession()->getPage()->getContent(), TRUE) ?? [],
    ];
  }

  /**
   * Gets the XSRF-TOKEN cookie value after making a GET request.
   */
  private function getXsrfToken(): string {
    $this->drupalGet('/instruckt/annotations');
    $jar = $this->getBrowserKitClient()->getCookieJar();
    foreach ($jar->allValues($this->buildUrl('/')) as $name => $value) {
      if ($name === 'XSRF-TOKEN') {
        return $value;
      }
    }
    return '';
  }

  /**
   * Tests admin user with permission can access the page (200).
   */
  public function testAdminWithPermissionCanAccessPage(): void {
    $user = $this->drupalCreateUser(['administer instruckt_drupal']);
    $this->drupalLogin($user);
    $this->drupalGet('/admin/content/instruckt');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests authenticated user without permission gets 403.
   */
  public function testUserWithoutPermissionIsDenied(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $this->drupalGet('/admin/content/instruckt');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests anonymous user is denied (redirected to login → effectively 403).
   */
  public function testAnonymousUserIsDenied(): void {
    $this->drupalGet('/admin/content/instruckt');
    // Anonymous users are redirected to login; status is 403 in BrowserTestBase.
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests the page renders the expected table headers.
   */
  public function testPageRendersTableHeaders(): void {
    $user = $this->drupalCreateUser(['administer instruckt_drupal']);
    $this->drupalLogin($user);
    $this->drupalGet('/admin/content/instruckt');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('ID');
    $this->assertSession()->pageTextContains('URL');
    $this->assertSession()->pageTextContains('Comment');
    $this->assertSession()->pageTextContains('Status');
    $this->assertSession()->pageTextContains('Created');
  }

  /**
   * Tests the page shows "No annotations found." when empty.
   */
  public function testPageShowsEmptyMessage(): void {
    $user = $this->drupalCreateUser(['administer instruckt_drupal']);
    $this->drupalLogin($user);
    $this->drupalGet('/admin/content/instruckt');
    $this->assertSession()->pageTextContains('No annotations found.');
  }

  /**
   * Tests annotation created via API appears on the admin list page.
   */
  public function testTableShowsAnnotationAfterCreate(): void {
    $admin = $this->drupalCreateUser([
      'administer instruckt_drupal',
      'access instruckt_drupal toolbar',
    ]);
    $this->drupalLogin($admin);
    $token = $this->getXsrfToken();
    $this->assertNotEmpty($token);

    $this->jsonRequest('POST', '/instruckt/annotations', [
      'x' => 10, 'y' => 20,
      'comment' => 'Admin list test comment',
      'element' => '.foo',
      'url' => 'http://example.com/page',
    ], $token);

    $this->drupalGet('/admin/content/instruckt');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Admin list test comment');
    $this->assertSession()->pageTextContains('http://example.com/page');
    $this->assertSession()->pageTextContains('pending');
  }

}
