<?php

namespace Drupal\instruckt_drupal\EventSubscriber;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\instruckt_drupal\Service\InstrucktStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets the XSRF-TOKEN cookie for instruckt JS compatibility.
 *
 * The instruckt JS reads the XSRF-TOKEN cookie (Laravel convention) and sends
 * it as the X-XSRF-TOKEN header on state-changing requests. This subscriber
 * emits the cookie containing Drupal's CSRF token (scoped to 'instruckt_drupal')
 * so the unmodified instruckt JS works without patching.
 */
class InstrucktCsrfSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', 0]];
  }

  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $config = $this->configFactory->get('instruckt_drupal.settings');
    if (!$config->get('enabled')) {
      return;
    }

    if (!$this->currentUser->hasPermission('access instruckt_drupal toolbar')) {
      return;
    }

    $token = $this->csrfToken->get(InstrucktStore::CSRF_TOKEN_ID);

    // Only emit Set-Cookie if the cookie is absent or stale. Unconditionally
    // emitting Set-Cookie on every response disables Drupal's Dynamic Page Cache
    // for all authenticated users, causing severe performance regression.
    $existingCookie = $event->getRequest()->cookies->get('XSRF-TOKEN');
    if ($existingCookie === $token) {
      return;
    }

    // Not HttpOnly: the instruckt JS must read this cookie client-side.
    // Secure: auto-detected from the current request — true on HTTPS, false on HTTP.
    //   This means the module is secure-by-default on HTTPS environments (Lando, DDEV
    //   with TLS, production) and still works on plain HTTP dev setups.
    // SameSite=Lax: mitigates CSRF from cross-site navigations.
    // Path: base_path() (e.g. '/' for root installs, '/subdir/' for subdirectory installs).
    //   Using '/' alone would leak the cookie to sibling Drupal sites on the same domain;
    //   base_path() scopes it to this Drupal installation's path prefix.
    $secure = $event->getRequest()->isSecure();
    $event->getResponse()->headers->setCookie(
      new Cookie('XSRF-TOKEN', $token, 0, base_path(), null, $secure, false, false, 'Lax')
    );
  }
}
