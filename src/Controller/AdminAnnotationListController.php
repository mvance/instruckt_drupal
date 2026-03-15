<?php

namespace Drupal\instruckt_drupal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\Core\Utility\TableSort;
use Drupal\instruckt_drupal\Service\InstrucktStore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read-only admin page listing all stored annotations.
 */
class AdminAnnotationListController extends ControllerBase {

  public function __construct(
    private readonly InstrucktStore $store,
    private readonly RequestStack $requestStack,
    private readonly PagerManagerInterface $pagerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('instruckt_drupal.store'),
      $container->get('request_stack'),
      $container->get('pager.manager'),
    );
  }

  /**
   * Renders the admin annotation list table.
   */
  public function list(): array {
    $header = [
      'id'         => ['data' => $this->t('ID'), 'specifier' => 'id', 'field' => 'id'],
      'url'        => ['data' => $this->t('URL'), 'specifier' => 'url', 'field' => 'url'],
      'comment'    => ['data' => $this->t('Comment'), 'specifier' => 'comment', 'field' => 'comment'],
      'status'     => ['data' => $this->t('Status'), 'specifier' => 'status', 'field' => 'status'],
      'created_at' => ['data' => $this->t('Created'), 'specifier' => 'created_at', 'field' => 'created_at'],
      'screenshot' => ['data' => $this->t('Screenshot'), 'specifier' => 'screenshot', 'field' => 'screenshot'],
    ];

    $request = $this->requestStack->getCurrentRequest();
    $context = TableSort::getContextFromRequest($header, $request);

    $annotations = $this->store->getAnnotations();

    $field = $context['sql'] ?? 'id';
    $sort  = $context['sort'] ?? 'asc';
    usort($annotations, function (array $a, array $b) use ($field, $sort): int {
      $cmp = strcmp((string) ($a[$field] ?? ''), (string) ($b[$field] ?? ''));
      return $sort === 'desc' ? -$cmp : $cmp;
    });

    $pageSize    = 25;
    $total       = count($annotations);
    $currentPage = $this->pagerManager->createPager($total, $pageSize)->getCurrentPage();
    $annotations = array_slice($annotations, $currentPage * $pageSize, $pageSize);

    $rows = [];
    foreach ($annotations as $annotation) {
      $id  = $annotation['id'] ?? '';
      $url = $annotation['url'] ?? '';

      $idCell = Link::createFromRoute($id, 'instruckt_drupal.admin.annotation.view', ['id' => $id])->toRenderable();

      $urlCell = $url
        ? Link::fromTextAndUrl($url, Url::fromUri($url, ['attributes' => ['target' => '_blank']]))->toRenderable()
        : ['#plain_text' => ''];

      $screenshotRel = $annotation['screenshot'] ?? '';
      $screenshotCell = $screenshotRel
        ? Link::createFromRoute(
            $this->t('View'),
            'instruckt_drupal.admin.annotation.screenshot',
            ['id' => $id],
            ['attributes' => ['target' => '_blank']]
          )->toRenderable()
        : ['#plain_text' => '—'];

      $rows[] = [
        ['data' => $idCell],
        ['data' => $urlCell],
        $annotation['comment'] ?? '',
        $annotation['status'] ?? '',
        $annotation['created_at'] ?? '',
        ['data' => $screenshotCell],
      ];
    }

    return [
      'table' => [
        '#type'     => 'table',
        '#header'   => $header,
        '#rows'     => $rows,
        '#empty'    => $this->t('No annotations found.'),
        '#attached' => ['library' => ['core/drupal.tableheader']],
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * Renders the detail view for a single annotation.
   */
  public function view(string $id): array {
    $annotations = $this->store->getAnnotations();
    $annotation  = NULL;
    foreach ($annotations as $a) {
      if (($a['id'] ?? '') === $id) {
        $annotation = $a;
        break;
      }
    }

    if ($annotation === NULL) {
      throw new NotFoundHttpException();
    }

    $rows = [];
    foreach ($annotation as $key => $value) {
      $display = is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : (string) ($value ?? '');
      $rows[]  = [['data' => $key, 'header' => TRUE], $display];
    }

    $screenshotRel = $annotation['screenshot'] ?? '';
    if ($screenshotRel !== '') {
      $screenshotUrl = Url::fromRoute(
        'instruckt_drupal.admin.annotation.screenshot',
        ['id' => $id]
      )->toString();
      $rows[] = [
        ['data' => 'screenshot_preview', 'header' => TRUE],
        [
          'data' => [
            '#markup' => '<img src="' . htmlspecialchars($screenshotUrl, ENT_QUOTES, 'UTF-8')
            . '" style="max-width:100%;height:auto;" alt="Screenshot" />',
          ],
        ],
      ];
    }

    return [
      '#type'  => 'table',
      '#rows'  => $rows,
      '#empty' => $this->t('No data.'),
    ];
  }

  /**
   * Serves the screenshot for a single annotation (admin-permissioned).
   */
  public function viewScreenshot(string $id): Response {
    $annotations = $this->store->getAnnotations();
    $annotation  = NULL;
    foreach ($annotations as $a) {
      if (($a['id'] ?? '') === $id) {
        $annotation = $a;
        break;
      }
    }

    if ($annotation === NULL) {
      throw new NotFoundHttpException();
    }

    $relPath = $annotation['screenshot'] ?? '';
    if ($relPath === '') {
      throw new NotFoundHttpException();
    }

    $realPath = $this->store->getScreenshotRealPath($relPath);
    if (!$realPath) {
      throw new NotFoundHttpException();
    }

    $filename    = basename($relPath);
    $contentType = str_ends_with($filename, '.svg') ? 'image/svg+xml' : 'image/png';
    return new BinaryFileResponse($realPath, 200, [
      'Content-Type'           => $contentType,
      'Cache-Control'          => 'private, max-age=3600',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

}
