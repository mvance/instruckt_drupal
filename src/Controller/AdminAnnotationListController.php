<?php

namespace Drupal\instruckt_drupal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\instruckt_drupal\Service\InstrucktStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read-only admin page listing all stored annotations.
 */
class AdminAnnotationListController extends ControllerBase {

  public function __construct(private readonly InstrucktStore $store) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('instruckt_drupal.store'));
  }

  public function list(): array {
    $annotations = $this->store->getAnnotations();

    $rows = [];
    foreach ($annotations as $annotation) {
      $rows[] = [
        $annotation['id'] ?? '',
        $annotation['url'] ?? '',
        $annotation['comment'] ?? '',
        $annotation['status'] ?? '',
        $annotation['created_at'] ?? '',
      ];
    }

    return [
      '#type' => 'table',
      '#header' => ['ID', 'URL', 'Comment', 'Status', 'Created'],
      '#rows' => $rows,
      '#empty' => $this->t('No annotations found.'),
    ];
  }

}
