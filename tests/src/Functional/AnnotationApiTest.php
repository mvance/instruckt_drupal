<?php

namespace Drupal\Tests\instruckt_drupal\Functional;

use Symfony\Component\BrowserKit\AbstractBrowser;
use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for the instruckt annotation CRUD API.
 *
 * @group instruckt_drupal
 */
class AnnotationApiTest extends BrowserTestBase {

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
   * Returns the BrowserKit client from the current Mink session.
   */
  private function getBrowserKitClient(): AbstractBrowser {
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
    // GET /instruckt/annotations triggers InstrucktCsrfSubscriber to set the cookie.
    $this->drupalGet('/instruckt/annotations');
    $jar = $this->getBrowserKitClient()->getCookieJar();
    foreach ($jar->allValues($this->baseUrl) as $name => $value) {
      if ($name === 'XSRF-TOKEN') {
        return $value;
      }
    }
    return '';
  }

  /**
   * Tests GET /instruckt/annotations returns 200 with empty array initially.
   */
  public function testGetAnnotationsReturnsEmptyArray(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $this->drupalGet('/instruckt/annotations');
    $this->assertSession()->statusCodeEquals(200);
    $body = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertIsArray($body);
    $this->assertEmpty($body);
  }

  /**
   * Tests POST with valid payload creates an annotation and returns 201.
   */
  public function testCreateAnnotationWithValidPayload(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $token = $this->getXsrfToken();
    $this->assertNotEmpty($token, 'XSRF-TOKEN cookie must be set for authenticated user.');

    $result = $this->jsonRequest('POST', '/instruckt/annotations', [
      'x' => 100.0,
      'y' => 200.0,
      'comment' => 'This button is misaligned',
      'element' => '.submit-btn',
      'url' => 'http://example.com/contact',
    ], $token);

    $this->assertSame(201, $result['status']);
    $this->assertArrayHasKey('id', $result['body']);
    $this->assertSame('pending', $result['body']['status']);
    // JSON decode gives int 100, not float 100.0.
    $this->assertEquals(100.0, $result['body']['x']);
    $this->assertSame('This button is misaligned', $result['body']['comment']);
    $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $result['body']['id']);
    $this->assertArrayHasKey('created_by', $result['body']);
    $this->assertNotEmpty($result['body']['created_by']);
  }

  /**
   * Tests POST without CSRF token returns 403.
   */
  public function testCreateAnnotationWithoutCsrfTokenReturns403(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);

    $result = $this->jsonRequest('POST', '/instruckt/annotations', [
      'x' => 10,
      'y' => 20,
      'comment' => 'Test',
      'element' => '.a',
      'url' => 'http://example.com',
    // No XSRF token.
    ]);

    $this->assertSame(403, $result['status']);
  }

  /**
   * Tests POST with missing required fields returns 400.
   */
  public function testCreateAnnotationMissingFieldsReturns400(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $token = $this->getXsrfToken();

    $result = $this->jsonRequest('POST', '/instruckt/annotations', [
      'x' => 10,
      // Missing y, comment, element, url.
    ], $token);

    $this->assertSame(400, $result['status']);
    $this->assertArrayHasKey('error', $result['body']);
  }

  /**
   * Tests POST with invalid intent enum value returns 400.
   */
  public function testCreateAnnotationInvalidIntentReturns400(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $token = $this->getXsrfToken();

    $result = $this->jsonRequest('POST', '/instruckt/annotations', [
      'x' => 10,
      'y' => 20,
      'comment' => 'Test',
      'element' => '.a',
      'url' => 'http://example.com',
      'intent' => 'invalid_intent',
    ], $token);

    $this->assertSame(400, $result['status']);
  }

  /**
   * Tests PATCH update of annotation status returns 200 with updated data.
   */
  public function testUpdateAnnotationStatus(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $token = $this->getXsrfToken();

    // Create an annotation first.
    $created = $this->jsonRequest('POST', '/instruckt/annotations', [
      'x' => 50,
      'y' => 60,
      'comment' => 'Resolve me',
      'element' => '.x',
      'url' => 'http://example.com',
    ], $token);
    $this->assertSame(201, $created['status']);
    $id = $created['body']['id'];

    // Patch it to resolved.
    $updated = $this->jsonRequest('PATCH', "/instruckt/annotations/$id", [
      'status' => 'resolved',
    ], $token);

    $this->assertSame(200, $updated['status']);
    $this->assertSame('resolved', $updated['body']['status']);
    $this->assertSame('human', $updated['body']['resolved_by']);
    $this->assertNotNull($updated['body']['resolved_at']);
  }

  /**
   * Tests PATCH on a non-existent ID returns 404.
   */
  public function testUpdateNonExistentAnnotationReturns404(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $token = $this->getXsrfToken();

    // 26-char valid Crockford base32 ULID that doesn't exist in the store.
    $result = $this->jsonRequest('PATCH', '/instruckt/annotations/01AAAAAAAAAAAAAAAAAAAAAAAA', [
      'status' => 'resolved',
    ], $token);

    $this->assertSame(404, $result['status']);
  }

  /**
   * Tests GET /instruckt/annotations returns annotation after creation.
   */
  public function testGetAnnotationsAfterCreate(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $token = $this->getXsrfToken();

    $this->jsonRequest('POST', '/instruckt/annotations', [
      'x' => 1,
      'y' => 2,
      'comment' => 'Created',
      'element' => '.el',
      'url' => 'http://example.com',
    ], $token);

    $this->drupalGet('/instruckt/annotations');
    $body = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertCount(1, $body);
    $this->assertSame('Created', $body[0]['comment']);
    $this->assertArrayHasKey('created_by', $body[0]);
    $this->assertNotEmpty($body[0]['created_by']);
  }

}
