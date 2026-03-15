<?php

namespace Drupal\instruckt_drupal\Cache\Context;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\Core\Routing\AdminContext;

/**
 * Cache context for whether the current route is an admin route.
 *
 * Cache context ID: 'route.is_admin'
 * Returns '1' on admin routes, '0' on non-admin routes.
 */
class RouteIsAdminCacheContext implements CacheContextInterface {

  public function __construct(private readonly AdminContext $adminContext) {}

  public static function getLabel() {
    return t('Is admin route');
  }

  public function getContext(): string {
    return $this->adminContext->isAdminRoute() ? '1' : '0';
  }

  public function getCacheableMetadata(): CacheableMetadata {
    return new CacheableMetadata();
  }

}
