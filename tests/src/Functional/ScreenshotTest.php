<?php

namespace Drupal\Tests\instruckt_drupal\Functional;

use Symfony\Component\BrowserKit\AbstractBrowser;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests screenshot upload and serving for instruckt annotations.
 *
 * @group instruckt_drupal
 */
class ScreenshotTest extends BrowserTestBase {

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
   * Returns the BrowserKit client for direct HTTP requests.
   */
  private function getBrowserKitClient(): AbstractBrowser {
    return $this->getSession()->getDriver()->getClient();
  }

  /**
   * Performs a JSON API request and returns the decoded response.
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
   * Returns the XSRF-TOKEN cookie value after making a GET request.
   */
  private function getXsrfToken(): string {
    $this->drupalGet('/instruckt/annotations');
    $cookie = $this->getBrowserKitClient()->getCookieJar()->get('XSRF-TOKEN');
    return $cookie ? $cookie->getValue() : '';
  }

  /**
   * Tests that a PNG screenshot is accepted and its URL is returned.
   */
  public function testPngScreenshotUploadedAndReturned(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $token = $this->getXsrfToken();

    // Minimal 1x1 white PNG.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI6QAAAABJRU5ErkJggg==');
    $pngB64 = base64_encode($png);

    $result = $this->jsonRequest('POST', '/instruckt/annotations', [
      'x' => 10,
      'y' => 20,
      'comment' => 'With screenshot',
      'element' => '.img',
      'url' => 'http://example.com',
      'screenshot' => 'data:image/png;base64,' . $pngB64,
    ], $token);

    $this->assertSame(201, $result['status']);
    $this->assertNotNull($result['body']['screenshot'] ?? NULL);
    $this->assertStringContainsString('screenshots/', $result['body']['screenshot']);
  }

  /**
   * Tests that an SVG screenshot is accepted.
   */
  public function testSvgScreenshotUploadedAndReturned(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $token = $this->getXsrfToken();

    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>';
    $svgB64 = base64_encode($svg);

    $result = $this->jsonRequest('POST', '/instruckt/annotations', [
      'x' => 5,
      'y' => 5,
      'comment' => 'SVG screenshot',
      'element' => '.el',
      'url' => 'http://example.com',
      'screenshot' => 'data:image/svg+xml;base64,' . $svgB64,
    ], $token);

    $this->assertSame(201, $result['status']);
    $this->assertNotNull($result['body']['screenshot'] ?? NULL);
    $this->assertStringEndsWith('.svg', $result['body']['screenshot']);
  }

  /**
   * Tests that an invalid screenshot MIME type returns 400.
   */
  public function testInvalidScreenshotMimeReturns400(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $token = $this->getXsrfToken();

    $result = $this->jsonRequest('POST', '/instruckt/annotations', [
      'x' => 5,
      'y' => 5,
      'comment' => 'Bad screenshot',
      'element' => '.el',
      'url' => 'http://example.com',
      'screenshot' => 'data:image/gif;base64,' . base64_encode('GIF89a'),
    ], $token);

    $this->assertSame(400, $result['status']);
  }

  /**
   * Tests serving a screenshot via GET /instruckt/screenshots/{filename}.
   */
  public function testServeScreenshotReturns200(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $token = $this->getXsrfToken();

    // Create annotation with screenshot.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI6QAAAABJRU5ErkJggg==');
    $result = $this->jsonRequest('POST', '/instruckt/annotations', [
      'x' => 10,
      'y' => 10,
      'comment' => 'Screenshot to serve',
      'element' => '.x',
      'url' => 'http://example.com',
      'screenshot' => 'data:image/png;base64,' . base64_encode($png),
    ], $token);

    $this->assertSame(201, $result['status']);
    $screenshotPath = $result['body']['screenshot'];

    // Extract filename from path like 'screenshots/ULID.png'.
    $filename = basename($screenshotPath);
    $this->drupalGet('/instruckt/screenshots/' . $filename);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that an invalid filename returns 400.
   */
  public function testServeScreenshotWithInvalidFilenameReturns400(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $this->drupalGet('/instruckt/screenshots/../etc/passwd');
    // This will result in a 404 or 400 — either is acceptable (no traversal).
    $status = $this->getSession()->getStatusCode();
    $this->assertContains($status, [400, 404]);
  }

}
