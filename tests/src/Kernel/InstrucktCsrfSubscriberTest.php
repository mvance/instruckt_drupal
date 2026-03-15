<?php

namespace Drupal\Tests\instruckt_drupal\Kernel;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\instruckt_drupal\EventSubscriber\InstrucktCsrfSubscriber;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests InstrucktCsrfSubscriber cookie-setting behavior.
 *
 * @coversDefaultClass \Drupal\instruckt_drupal\EventSubscriber\InstrucktCsrfSubscriber
 * @group instruckt_drupal
 */
class InstrucktCsrfSubscriberTest extends KernelTestBase {

  protected static $modules = [];

  private function buildSubscriber(bool $hasPermission, bool $moduleEnabled, string $existingCookie = ''): array {
    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('hasPermission')
      ->with('access instruckt_drupal toolbar')
      ->willReturn($hasPermission);

    $csrfToken = $this->createMock(CsrfTokenGenerator::class);
    $csrfToken->method('get')->willReturn('test-csrf-token-value');

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('enabled')->willReturn($moduleEnabled);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $subscriber = new InstrucktCsrfSubscriber($currentUser, $csrfToken, $configFactory);

    $request = Request::create('/some-page');
    if ($existingCookie !== '') {
      $request->cookies->set('XSRF-TOKEN', $existingCookie);
    }
    $response = new Response();
    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    return [$subscriber, $event, $response];
  }

  /**
   * @covers ::onResponse
   */
  public function testCookieIsSetForPermittedUser(): void {
    [$subscriber, $event, $response] = $this->buildSubscriber(TRUE, TRUE);
    $subscriber->onResponse($event);
    $cookies = $response->headers->getCookies();
    $cookieNames = array_map(fn(Cookie $c) => $c->getName(), $cookies);
    $this->assertContains('XSRF-TOKEN', $cookieNames);
  }

  /**
   * @covers ::onResponse
   */
  public function testCookieIsNotSetForUnpermittedUser(): void {
    [$subscriber, $event, $response] = $this->buildSubscriber(FALSE, TRUE);
    $subscriber->onResponse($event);
    $this->assertEmpty($response->headers->getCookies());
  }

  /**
   * @covers ::onResponse
   */
  public function testCookieIsNotSetWhenModuleDisabled(): void {
    [$subscriber, $event, $response] = $this->buildSubscriber(TRUE, FALSE);
    $subscriber->onResponse($event);
    $this->assertEmpty($response->headers->getCookies());
  }

  /**
   * @covers ::onResponse
   */
  public function testCookieIsNotResentWhenAlreadyFresh(): void {
    // Simulate a request where the cookie is already set to the current token.
    [$subscriber, $event, $response] = $this->buildSubscriber(TRUE, TRUE, 'test-csrf-token-value');
    $subscriber->onResponse($event);
    // Cookie should NOT be re-set since client already has the current value.
    $this->assertEmpty($response->headers->getCookies());
  }

  /**
   * @covers ::onResponse
   */
  public function testCookieIsResentWhenStale(): void {
    // Simulate a request with an outdated cookie value.
    [$subscriber, $event, $response] = $this->buildSubscriber(TRUE, TRUE, 'old-stale-token');
    $subscriber->onResponse($event);
    $cookies = $response->headers->getCookies();
    $this->assertNotEmpty($cookies);
    $tokenCookie = null;
    foreach ($cookies as $cookie) {
      if ($cookie->getName() === 'XSRF-TOKEN') {
        $tokenCookie = $cookie;
      }
    }
    $this->assertNotNull($tokenCookie);
    $this->assertSame('test-csrf-token-value', $tokenCookie->getValue());
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testSubscribedEvents(): void {
    $events = InstrucktCsrfSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey('kernel.response', $events);
  }

}
