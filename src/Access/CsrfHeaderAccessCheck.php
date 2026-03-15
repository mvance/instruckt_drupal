<?php

namespace Drupal\instruckt_drupal\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\instruckt_drupal\Service\InstrucktStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Validates the X-XSRF-TOKEN header against Drupal's CSRF token system.
 *
 * Registered as an access_check service with applies_to: _csrf_header.
 * Routes that declare _csrf_header: 'TRUE' are protected by this check.
 */
class CsrfHeaderAccessCheck implements AccessInterface {

  public function __construct(private readonly CsrfTokenGenerator $csrfToken) {}

  public function access(Route $route, Request $request): AccessResultInterface {
    if ($route->getRequirement('_csrf_header') !== 'TRUE') {
      return AccessResult::neutral();
    }

    $token = $request->headers->get('X-XSRF-TOKEN', '');

    // Return forbidden (not neutral) when token is absent. All routes with
    // _csrf_header: 'TRUE' are state-changing POST/PATCH endpoints. There are
    // no legitimate non-JS callers for these endpoints: MCP tools call
    // InstrucktStore directly (not via HTTP), and Drush uses stdio transport.
    // neutral() + _permission:allowed = request succeeds without CSRF, so
    // we must use forbidden() to close that gap.
    if ($token === '') {
      return AccessResult::forbidden('Missing CSRF token')->setCacheMaxAge(0);
    }

    return $this->csrfToken->validate($token, InstrucktStore::CSRF_TOKEN_ID)
      ? AccessResult::allowed()->setCacheMaxAge(0)
      : AccessResult::forbidden('Invalid CSRF token')->setCacheMaxAge(0);
  }
}
