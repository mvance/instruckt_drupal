<?php

namespace Drupal\instruckt_drupal\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Intercepts HTTP exceptions on /instruckt/* paths and returns JSON.
 *
 * Listens on KernelEvents::EXCEPTION (priority 0) — before Drupal's
 * DefaultExceptionSubscriber renders an HTML error page. By setting a response
 * here, we prevent the full theme/block render cycle for API routes.
 */
final class InstrucktJsonExceptionSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority 0 runs before Drupal's DefaultExceptionSubscriber (priority -50).
    return [KernelEvents::EXCEPTION => ['onException', 0]];
  }

  /**
   * Returns JSON error responses for exceptions on instruckt routes.
   */
  public function onException(ExceptionEvent $event): void {
    $request = $event->getRequest();
    // Only intercept /instruckt/* paths.
    if (!str_starts_with($request->getPathInfo(), '/instruckt')) {
      return;
    }
    $exception = $event->getThrowable();

    // Determine HTTP status and message.
    if ($exception instanceof HttpExceptionInterface) {
      $statusCode = $exception->getStatusCode();
      // Drupal uses 403 for all auth failures (unauthenticated + unauthorized).
      // 401 is not emitted; exclude it to avoid misleading clients.
      $messages = [
        400 => 'Bad request',
        403 => 'Access denied',
        404 => 'Resource not found',
        405 => 'Method not allowed',
        413 => 'Payload too large',
        415 => 'Unsupported media type',
      ];
      $message = $messages[$statusCode] ?? $exception->getMessage() ?: 'An error occurred';
    }
    else {
      // Non-HTTP exceptions (PHP errors, unexpected server errors) are 500s.
      // Return a generic message — do not expose internal exception details.
      $statusCode = 500;
      $message = 'An unexpected error occurred';
    }

    $event->setResponse(new JsonResponse(['error' => $message], $statusCode));
  }

}
