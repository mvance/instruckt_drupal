<?php

namespace Drupal\instruckt_drupal\Plugin\Mcp;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\instruckt_drupal\Service\InstrucktStore;
use Drupal\mcp\Attribute\Mcp;
use Drupal\mcp\Plugin\McpPluginBase;
use Drupal\mcp\ServerFeatures\Tool;
use Drupal\mcp\ServerFeatures\ToolAnnotations;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MCP plugin exposing instruckt annotation tools.
 *
 * Provides three MCP tools:
 *   - instruckt_get_all_pending  (read-only)
 *   - instruckt_get_screenshot   (read-only)
 *   - instruckt_resolve          (destructive)
 */
#[Mcp(
  id: 'instruckt-drupal',
  name: new TranslatableMarkup('Instruckt Drupal'),
  description: new TranslatableMarkup('Tools for reading and resolving Instruckt annotations.'),
)]
class InstrucktPlugin extends McpPluginBase {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    AccountProxyInterface $currentUser,
    private readonly InstrucktStore $store,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    // McpPluginBase stores currentUser via setter in its create() factory, but
    // InstrucktPlugin overrides create() with a constructor-injection pattern.
    // Explicitly assign the inherited protected property here.
    $this->currentUser = $currentUser;
  }

  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('instruckt_drupal.store'),
      $container->get('config.factory'),
    );
  }

  public function checkRequirements(): bool {
    return TRUE;
  }

  public function getRequirementsDescription(): string {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getTools(): array {
    return [
      new Tool(
        name: 'instruckt_get_all_pending',
        description: 'Retrieve all pending Instruckt annotations with full metadata.',
        inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
        annotations: new ToolAnnotations(readOnlyHint: TRUE),
      ),
      new Tool(
        name: 'instruckt_get_screenshot',
        description: 'Get the base64-encoded screenshot for an annotation by its ID.',
        inputSchema: [
          'type'       => 'object',
          'properties' => [
            'annotation_id' => [
              'type'        => 'string',
              'description' => 'The ULID of the annotation whose screenshot to retrieve.',
            ],
          ],
          'required'   => ['annotation_id'],
        ],
        annotations: new ToolAnnotations(readOnlyHint: TRUE),
      ),
      new Tool(
        name: 'instruckt_resolve',
        description: 'Mark an annotation as resolved by the agent. Deletes its screenshot.',
        inputSchema: [
          'type'       => 'object',
          'properties' => [
            'id' => [
              'type'        => 'string',
              'description' => 'The ULID of the annotation to resolve.',
            ],
          ],
          'required'   => ['id'],
        ],
        annotations: new ToolAnnotations(
          destructiveHint: TRUE,
          idempotentHint: FALSE,
        ),
      ),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Returns an array of content items, each being an associative array with
   * 'type' => 'text'|'image'|'resource' and type-specific fields.
   */
  public function executeTool(string $toolId, mixed $arguments): array {
    // Tool-level authorization: drupal/mcp handles transport auth but tools must
    // also enforce Drupal permissions independently.
    // Respect the global enabled flag for all HTTP requests.
    if (!$this->configFactory->get('instruckt_drupal.settings')->get('enabled')) {
      return [['type' => 'text', 'text' => 'Instruckt is disabled.']];
    }
    if (!$this->currentUser->hasPermission('access instruckt_drupal toolbar')) {
      return [['type' => 'text', 'text' => 'Access denied: requires "access instruckt_drupal toolbar" permission.']];
    }

    return match ($toolId) {
      'instruckt_get_all_pending' => $this->getAllPending(),
      'instruckt_get_screenshot'  => $this->getScreenshot((array) $arguments),
      'instruckt_resolve'         => $this->resolve((array) $arguments),
      default => [['type' => 'text', 'text' => "Unknown tool ID: $toolId"]],
    };
  }

  public function getResources(): array { return []; }
  public function getResourceTemplates(): array { return []; }
  public function readResource(string $resourceId): array { return []; }

  private function getAllPending(): array {
    $pending = $this->store->getPendingAnnotations();
    $count = count($pending);
    return [['type' => 'text', 'text' => \json_encode(['annotations' => $pending, 'count' => $count])]];
  }

  private function getScreenshot(array $arguments): array {
    $annotationId = $arguments['annotation_id'] ?? '';
    if ($annotationId === '') {
      return [['type' => 'text', 'text' => 'annotation_id is required.']];
    }
    // Validate ULID format before any store lookup (prevents log noise / timing oracle).
    if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $annotationId)) {
      return [['type' => 'text', 'text' => 'Invalid annotation_id format.']];
    }

    // Find the annotation to get its screenshot path.
    $annotations = $this->store->getAnnotations();
    $annotation = NULL;
    foreach ($annotations as $a) {
      if ($a['id'] === $annotationId) {
        $annotation = $a;
        break;
      }
    }

    if ($annotation === NULL) {
      return [['type' => 'text', 'text' => 'Annotation not found.']];
    }

    $relPath = $annotation['screenshot'] ?? NULL;
    if (!$relPath) {
      return [['type' => 'text', 'text' => 'Annotation has no screenshot.']];
    }

    $realPath = $this->store->getScreenshotRealPath($relPath);
    if (!$realPath) {
      return [['type' => 'text', 'text' => 'Screenshot file not found on disk.']];
    }

    // Enforce max file size before loading into memory (default: 10MB for MCP tools).
    // Note: config max_screenshot_size (5MB) applies at upload; this is a separate
    // guard for MCP retrieval since files may pre-exist from older versions.
    $maxMcpSize = 10 * 1024 * 1024;
    if (filesize($realPath) > $maxMcpSize) {
      return [['type' => 'text', 'text' => 'Screenshot file exceeds 10MB limit for MCP retrieval.']];
    }

    $binary = file_get_contents($realPath);
    if ($binary === FALSE) {
      return [['type' => 'text', 'text' => 'Failed to read screenshot file.']];
    }

    $ext = pathinfo($realPath, PATHINFO_EXTENSION);
    $mimeType = $ext === 'svg' ? 'image/svg+xml' : 'image/png';

    return [[
      'type'      => 'image',
      'data'      => base64_encode($binary),
      'mimeType'  => $mimeType,
    ]];
  }

  private function resolve(array $arguments): array {
    $id = $arguments['id'] ?? '';
    if ($id === '') {
      return [['type' => 'text', 'text' => 'id is required.']];
    }
    // Validate ULID format before any store lookup.
    if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id)) {
      return [['type' => 'text', 'text' => 'Invalid id format.']];
    }

    // resolved_by and resolved_at are server-controlled inside updateAnnotation().
    // Pass 'agent' as the $resolvedBy parameter so the store records the actor correctly.
    $annotation = $this->store->updateAnnotation($id, ['status' => 'resolved'], 'agent');

    if ($annotation === FALSE) {
      return [['type' => 'text', 'text' => 'Storage error resolving annotation.']];
    }
    if ($annotation === NULL) {
      return [['type' => 'text', 'text' => 'Annotation not found.']];
    }

    return [['type' => 'text', 'text' => \json_encode($annotation)]];
  }
}
