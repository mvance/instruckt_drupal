<?php

namespace Drupal\Tests\instruckt_drupal\Kernel;

use Drupal\instruckt_drupal\EventSubscriber\InstrucktJsonExceptionSubscriber;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests InstrucktJsonExceptionSubscriber JSON error handling.
 *
 * @coversDefaultClass \Drupal\instruckt_drupal\EventSubscriber\InstrucktJsonExceptionSubscriber
 * @group instruckt_drupal
 */
class InstrucktJsonExceptionSubscriberTest extends KernelTestBase {

  protected static $modules = [];

  private InstrucktJsonExceptionSubscriber $subscriber;

  protected function setUp(): void {
    parent::setUp();
    $this->subscriber = new InstrucktJsonExceptionSubscriber();
  }

  private function makeEvent(string $path, \Throwable $exception): ExceptionEvent {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $request = Request::create($path);
    return new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
  }

  /**
   * @covers ::onException
   */
  public function testAccessDeniedOnInstrucktPathReturns403Json(): void {
    $event = $this->makeEvent('/instruckt/annotations', new AccessDeniedHttpException());
    $this->subscriber->onException($event);
    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertSame(403, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('error', $body);
  }

  /**
   * @covers ::onException
   */
  public function testNotFoundOnInstrucktPathReturns404Json(): void {
    $event = $this->makeEvent('/instruckt/annotations/BADID', new NotFoundHttpException());
    $this->subscriber->onException($event);
    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertSame(404, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('error', $body);
  }

  /**
   * @covers ::onException
   */
  public function testScreenshotPathIsIntercepted(): void {
    $event = $this->makeEvent('/instruckt/screenshots/somefile.png', new AccessDeniedHttpException());
    $this->subscriber->onException($event);
    $this->assertNotNull($event->getResponse());
  }

  /**
   * @covers ::onException
   */
  public function testNonInstrucktPathIsNotIntercepted(): void {
    $event = $this->makeEvent('/user/login', new AccessDeniedHttpException());
    $this->subscriber->onException($event);
    // No response should be set for non-instruckt paths.
    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onException
   */
  public function testAdminPathIsNotIntercepted(): void {
    $event = $this->makeEvent('/admin/config', new AccessDeniedHttpException());
    $this->subscriber->onException($event);
    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onException
   */
  public function testGenericExceptionBecomesJson500(): void {
    $event = $this->makeEvent('/instruckt/annotations', new \RuntimeException('oops'));
    $this->subscriber->onException($event);
    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertSame(500, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('error', $body);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testSubscribedEvents(): void {
    $events = InstrucktJsonExceptionSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey('kernel.exception', $events);
  }

}
