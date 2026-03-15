<?php

namespace Drupal\Tests\instruckt_drupal\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests that all instruckt routes enforce access permissions.
 *
 * @group instruckt_drupal
 */
class PermissionTest extends BrowserTestBase {

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
   * Tests that anonymous users receive 403 on all instruckt routes.
   */
  public function testAnonymousUserCannotAccessAnnotationList(): void {
    $this->drupalGet('/instruckt/annotations');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that users without the permission receive 403.
   */
  public function testUserWithoutPermissionCannotAccessAnnotationList(): void {
    $user = $this->drupalCreateUser([]);
    $this->drupalLogin($user);
    $this->drupalGet('/instruckt/annotations');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that anonymous POST returns 403.
   */
  public function testAnonymousUserCannotPostAnnotation(): void {
    // POST without login — should get 403 from permission check.
    $driver = $this->getSession()->getDriver();
    $client = $driver->getClient();
    $client->request(
      'POST',
      $this->buildUrl('/instruckt/annotations'),
      [], [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['x' => 1, 'y' => 1, 'comment' => 'x', 'element' => '.a', 'url' => 'http://example.com'])
    );
    $status = $this->getSession()->getStatusCode();
    $this->assertContains($status, [403, 400]); // 403 from permission, or 400 from missing CSRF
  }

  /**
   * Tests that a user WITH the permission can GET annotations (200).
   */
  public function testPermittedUserCanAccessAnnotationList(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $this->drupalGet('/instruckt/annotations');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that anonymous user cannot access the resolve-source endpoint.
   */
  public function testAnonymousUserCannotAccessResolveSource(): void {
    $driver = $this->getSession()->getDriver();
    $client = $driver->getClient();
    $client->request(
      'POST',
      $this->buildUrl('/instruckt/resolve-source'),
      [], [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['framework' => 'twig', 'component' => 'node--article'])
    );
    $status = $this->getSession()->getStatusCode();
    $this->assertContains($status, [403, 400]);
  }

}
