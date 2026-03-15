<?php

namespace Drupal\Tests\instruckt_drupal\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests that the twig-debug library attaches based on user permission.
 *
 * @group instruckt_drupal
 */
class TwigDebugLibraryTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['instruckt_drupal'];

  /**
   * Default theme.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that the twig-debug library is attached for an authorized user.
   */
  public function testTwigDebugLibraryAttachedForAuthorizedUser(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $this->drupalGet('/');
    $this->assertSession()->responseContains('instruckt-twig-debug.js');
  }

  /**
   * Tests that the twig-debug library is absent for a user without permission.
   */
  public function testTwigDebugLibraryAbsentWithoutPermission(): void {
    $user = $this->drupalCreateUser([]);
    $this->drupalLogin($user);
    $this->drupalGet('/');
    $this->assertSession()->responseNotContains('instruckt-twig-debug.js');
  }

}
