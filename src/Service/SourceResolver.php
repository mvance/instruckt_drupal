<?php

namespace Drupal\instruckt_drupal\Service;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Theme\Registry as ThemeRegistry;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Resolves Twig template names to their filesystem paths.
 *
 * Scoped to Twig only. The instruckt JS sends {framework, component} where
 * framework may be 'livewire', 'vue', 'svelte', 'react', or 'blade'. In the
 * Drupal context only 'twig' (and 'blade' as an alias) are meaningful.
 *
 * theme.registry is injected (not called statically) for testability.
 */
class SourceResolver {

  private const TWIG_FRAMEWORKS = ['twig', 'blade'];

  public function __construct(
    private readonly ThemeManagerInterface $themeManager,
    private readonly ThemeExtensionList $themeList,
    private readonly ModuleExtensionList $moduleList,
    private readonly ThemeRegistry $themeRegistry,
    // Injected rather than using \Drupal::root() statically to preserve testability.
    private readonly string $appRoot,
  ) {}

  /**
   * Resolves a framework component to its source file.
   *
   * @param string $framework
   *   Framework identifier, e.g. 'twig', 'blade', 'livewire', 'vue'.
   * @param string $component
   *   Twig template machine name, e.g. 'node--article--teaser'.
   *
   * @return array{framework: string, component: string, source_file: string|null, supported: bool}
   *   Resolved source information including canonical framework name and file path.
   */
  public function resolve(string $framework, string $component): array {
    if (!in_array($framework, self::TWIG_FRAMEWORKS, TRUE)) {
      return [
        'framework'   => $framework,
        'component'   => $component,
        'source_file' => NULL,
        'source_line' => NULL,
        'supported'   => FALSE,
        'message'     => "Framework '$framework' is not supported by instruckt_drupal. Only Twig templates can be resolved.",
      ];
    }

    // Normalize 'blade' alias → 'twig' in the response and for storage.
    // The instruckt JS may send 'blade' for Laravel-style components; in the
    // Drupal context this is always Twig. Storing the canonical name prevents
    // ambiguity in clients and annotations.
    $canonicalFramework = 'twig';

    return [
      'framework'   => $canonicalFramework,
      'component'   => $component,
      'source_file' => $this->findTwigTemplate($component),
    // Line resolution requires static analysis; not implemented.
      'source_line' => NULL,
      'supported'   => TRUE,
    ];
  }

  /**
   * Searches for a Twig template file by machine name.
   */
  private function findTwigTemplate(string $templateName): ?string {
    // Normalize: Drupal template filenames use hyphens; dots may appear in
    // the component name sent by the JS.
    $templateName = str_replace('.', '-', $templateName);
    $templateFile = $templateName . '.html.twig';

    // 1. Active theme registry (most authoritative — respects overrides).
    $themeRegistry = $this->themeRegistry->get();
    $registryKey = str_replace('-', '_', $templateName);
    if (isset($themeRegistry[$registryKey]['path'])) {
      $candidate = $themeRegistry[$registryKey]['path'] . '/' . $templateFile;
      if (file_exists($candidate)) {
        return $this->relativePath($candidate);
      }
    }

    // 2. Active theme and its base theme chain.
    $activeTheme = $this->themeManager->getActiveTheme();
    $themesToSearch = [$activeTheme->getName()];
    foreach ($activeTheme->getBaseThemeExtensions() as $base) {
      $themesToSearch[] = $base->getName();
    }
    foreach ($themesToSearch as $themeName) {
      $themePath = $this->themeList->getPath($themeName);
      $templatesDir = $themePath . '/templates';
      // Use RecursiveDirectoryIterator so templates in deeply nested subdirs
      // (e.g. templates/content/node, templates/layout/page, or any custom depth)
      // are found without hardcoding directory names.
      if (is_dir($templatesDir)) {
        $rit = new \RecursiveIteratorIterator(
          new \RecursiveDirectoryIterator($templatesDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($rit as $file) {
          if ($file->getFilename() === $templateFile) {
            return $this->relativePath($file->getPathname());
          }
        }
      }
    }

    // 3. Enabled modules (for module-provided templates, searched non-recursively
    // since modules conventionally keep templates in a flat templates/ directory).
    foreach (array_keys($this->moduleList->getAllInstalledInfo()) as $moduleName) {
      $candidate = $this->moduleList->getPath($moduleName) . '/templates/' . $templateFile;
      if (file_exists($candidate)) {
        return $this->relativePath($candidate);
      }
    }

    return NULL;
  }

  /**
   * Returns the path relative to the application root.
   */
  private function relativePath(string $absolutePath): string {
    // Use $this->appRoot (injected) rather than \Drupal::root() (static).
    $root = rtrim($this->appRoot, '/') . '/';
    return str_starts_with($absolutePath, $root)
      ? substr($absolutePath, strlen($root))
      : $absolutePath;
  }

}
