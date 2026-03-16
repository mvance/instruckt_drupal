<?php

namespace Drupal\Tests\instruckt_drupal\Functional;

use Symfony\Component\BrowserKit\AbstractBrowser;
use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for the admin annotation list page.
 *
 * @group instruckt_drupal
 */
class AdminAnnotationListTest extends BrowserTestBase {

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
    $this->drupalGet('/instruckt/annotations');
    $cookie = $this->getBrowserKitClient()->getCookieJar()->get('XSRF-TOKEN');
    return $cookie ? $cookie->getValue() : '';
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
    $this->assertSession()->pageTextContains('Screenshot');
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
      'x' => 10,
      'y' => 20,
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

  /**
   * Tests that each row has a link to the annotation detail page.
   */
  public function testTableRowLinksToDetailPage(): void {
    $admin = $this->drupalCreateUser([
      'administer instruckt_drupal',
      'access instruckt_drupal toolbar',
    ]);
    $this->drupalLogin($admin);
    $token = $this->getXsrfToken();

    $response = $this->jsonRequest('POST', '/instruckt/annotations', [
      'x' => 5,
      'y' => 5,
      'comment' => 'Detail link test',
      'element' => '.bar',
      'url' => 'http://example.com/detail-link',
    ], $token);
    $id = $response['body']['id'] ?? '';
    $this->assertNotEmpty($id);

    $this->drupalGet('/admin/content/instruckt');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists($id);

    $this->clickLink($id);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Detail link test');
  }

  /**
   * Tests that sorting by status reorders rows.
   */
  public function testSortByStatus(): void {
    $admin = $this->drupalCreateUser([
      'administer instruckt_drupal',
      'access instruckt_drupal toolbar',
    ]);
    $this->drupalLogin($admin);
    $token = $this->getXsrfToken();

    $this->jsonRequest('POST', '/instruckt/annotations', [
      'x' => 1,
      'y' => 1,
      'comment' => 'Pending annotation',
      'element' => '.a',
      'url' => 'http://example.com/a',
    ], $token);

    $responseB = $this->jsonRequest('POST', '/instruckt/annotations', [
      'x' => 2,
      'y' => 2,
      'comment' => 'Resolved annotation',
      'element' => '.b',
      'url' => 'http://example.com/b',
    ], $token);
    $idB = $responseB['body']['id'] ?? '';

    // Resolve the second annotation.
    $this->jsonRequest('PATCH', '/instruckt/annotations/' . $idB, ['status' => 'resolved'], $token);

    // Sort ascending by status: "pending" < "resolved" alphabetically, so Pending comes first.
    $this->drupalGet('/admin/content/instruckt', ['query' => ['order' => 'status', 'sort' => 'asc']]);
    $this->assertSession()->statusCodeEquals(200);
    $page = $this->getSession()->getPage()->getContent();
    $posPending  = strpos($page, 'Pending annotation');
    $posResolved = strpos($page, 'Resolved annotation');
    $this->assertNotFalse($posPending);
    $this->assertNotFalse($posResolved);
    $this->assertLessThan($posResolved, $posPending, 'Pending should appear before Resolved when sorting status asc');
  }

  /**
   * Tests the annotation detail page shows annotation fields.
   */
  public function testDetailPageShowsAnnotationFields(): void {
    $admin = $this->drupalCreateUser([
      'administer instruckt_drupal',
      'access instruckt_drupal toolbar',
    ]);
    $this->drupalLogin($admin);
    $token = $this->getXsrfToken();

    $response = $this->jsonRequest('POST', '/instruckt/annotations', [
      'x' => 3,
      'y' => 3,
      'comment' => 'Detail page comment',
      'element' => '.detail',
      'url' => 'http://example.com/detail',
    ], $token);
    $id = $response['body']['id'] ?? '';
    $this->assertNotEmpty($id);

    $this->drupalGet('/admin/content/instruckt/' . $id);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Detail page comment');
    $this->assertSession()->pageTextContains('pending');
  }

  /**
   * Tests pager appears when more than 25 annotations exist.
   */
  public function testPagerAppearsWithManyAnnotations(): void {
    $admin = $this->drupalCreateUser([
      'administer instruckt_drupal',
      'access instruckt_drupal toolbar',
    ]);
    $this->drupalLogin($admin);
    $token = $this->getXsrfToken();

    for ($i = 1; $i <= 26; $i++) {
      $this->jsonRequest('POST', '/instruckt/annotations', [
        'x'       => $i,
        'y'       => $i,
        'comment' => "Pager annotation $i",
        'element' => ".el$i",
        'url'     => "http://example.com/page$i",
      ], $token);
    }

    $this->drupalGet('/admin/content/instruckt');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'nav.pager');

    // Page 1 must show exactly 25 rows.
    $rows = $this->getSession()->getPage()->findAll('css', 'table tbody tr');
    $this->assertCount(25, $rows, 'Page 1 should contain exactly 25 annotation rows.');

    // Page 2 (0-indexed as ?page=1) must show the remaining 1 row.
    $this->drupalGet('/admin/content/instruckt', ['query' => ['page' => 1]]);
    $this->assertSession()->statusCodeEquals(200);
    $rows = $this->getSession()->getPage()->findAll('css', 'table tbody tr');
    $this->assertCount(1, $rows, 'Page 2 should contain exactly 1 annotation row.');
  }

  /**
   * Tests the detail page returns 404 for an unknown ID.
   */
  public function testDetailPageReturns404ForUnknownId(): void {
    $admin = $this->drupalCreateUser(['administer instruckt_drupal']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/content/instruckt/BADID-DOES-NOT-EXIST');
    $this->assertSession()->statusCodeEquals(404);
  }

}
