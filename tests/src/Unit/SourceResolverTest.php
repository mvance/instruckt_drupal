<?php

namespace Drupal\Tests\instruckt_drupal\Unit;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\Registry as ThemeRegistry;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\instruckt_drupal\Service\SourceResolver;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\instruckt_drupal\Service\SourceResolver
 * @group instruckt_drupal
 */
class SourceResolverTest extends UnitTestCase {

  private function buildResolver(array $themeRegistryData = []): SourceResolver {
    $activeTheme = $this->createMock(ActiveTheme::class);
    $activeTheme->method('getName')->willReturn('mytheme');
    $activeTheme->method('getBaseThemeExtensions')->willReturn([]);

    $themeManager = $this->createMock(ThemeManagerInterface::class);
    $themeManager->method('getActiveTheme')->willReturn($activeTheme);

    $themeList = $this->createMock(ThemeExtensionList::class);
    $themeList->method('getPath')->willReturn('/nonexistent/theme');

    $moduleList = $this->createMock(ModuleExtensionList::class);
    $moduleList->method('getAllInstalledInfo')->willReturn([]);
    $moduleList->method('getPath')->willReturn('/nonexistent/module');

    $themeRegistry = $this->createMock(ThemeRegistry::class);
    $themeRegistry->method('get')->willReturn($themeRegistryData);

    return new SourceResolver($themeManager, $themeList, $moduleList, $themeRegistry, '/app');
  }

  /**
   * @covers ::resolve
   */
  public function testUnsupportedFrameworkVueReturnsFalse(): void {
    $result = $this->buildResolver()->resolve('vue', 'MyComponent');
    $this->assertFalse($result['supported']);
    $this->assertNull($result['source_file']);
    $this->assertSame('vue', $result['framework']);
    $this->assertSame('MyComponent', $result['component']);
  }

  /**
   * @covers ::resolve
   */
  public function testUnsupportedFrameworkReactReturnsFalse(): void {
    $result = $this->buildResolver()->resolve('react', 'App');
    $this->assertFalse($result['supported']);
    $this->assertNull($result['source_file']);
  }

  /**
   * @covers ::resolve
   */
  public function testUnsupportedFrameworkLivewireReturnsFalse(): void {
    $result = $this->buildResolver()->resolve('livewire', 'Counter');
    $this->assertFalse($result['supported']);
  }

  /**
   * @covers ::resolve
   */
  public function testBladeAliasNormalizesToTwig(): void {
    $result = $this->buildResolver()->resolve('blade', 'node--article--teaser');
    $this->assertTrue($result['supported']);
    $this->assertSame('twig', $result['framework']);
    $this->assertSame('node--article--teaser', $result['component']);
  }

  /**
   * @covers ::resolve
   */
  public function testTwigFrameworkReturnsSupported(): void {
    $result = $this->buildResolver()->resolve('twig', 'node--article--teaser');
    $this->assertTrue($result['supported']);
    $this->assertSame('twig', $result['framework']);
  }

  /**
   * @covers ::resolve
   */
  public function testSourceLineIsAlwaysNull(): void {
    $result = $this->buildResolver()->resolve('twig', 'page');
    $this->assertNull($result['source_line']);
  }

  /**
   * @covers ::resolve
   */
  public function testComponentPassedThroughInResponse(): void {
    $result = $this->buildResolver()->resolve('twig', 'block--system-menu-block');
    $this->assertSame('block--system-menu-block', $result['component']);
  }

  /**
   * @covers ::resolve
   */
  public function testTemplateNotFoundReturnsNullSourceFile(): void {
    // All mocked paths are non-existent, so template can't be found.
    $result = $this->buildResolver()->resolve('twig', 'node--article');
    $this->assertTrue($result['supported']);
    $this->assertNull($result['source_file']);
  }

  /**
   * @covers ::resolve
   */
  public function testUnsupportedFrameworkIncludesMessage(): void {
    $result = $this->buildResolver()->resolve('svelte', 'App');
    $this->assertFalse($result['supported']);
    $this->assertArrayHasKey('message', $result);
    $this->assertStringContainsString('svelte', $result['message']);
  }

}
