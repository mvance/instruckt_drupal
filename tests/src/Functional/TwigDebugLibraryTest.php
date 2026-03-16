<?php

namespace Drupal\Tests\instruckt_drupal\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;

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
   * {@inheritdoc}
   *
   * The hook_install() callback grants 'access instruckt_drupal toolbar' to
   * the authenticated role. Revoke it here so the no-permission user test is
   * not confounded by the role-level grant.
   */
  protected function setUp(): void {
    parent::setUp();
    $role = Role::load('authenticated');
    if ($role && $role->hasPermission('access instruckt_drupal toolbar')) {
      $role->revokePermission('access instruckt_drupal toolbar');
      $role->save();
    }
  }

  /**
   * Tests that the twig-debug library is attached for an authorized user.
   */
  public function testTwigDebugLibraryAttachedForAuthorizedUser(): void {
    $user = $this->drupalCreateUser(['access instruckt_drupal toolbar']);
    $this->drupalLogin($user);
    $this->drupalGet('<front>');
    $this->assertSession()->responseContains('instruckt-twig-debug.js');
  }

  /**
   * Tests that the twig-debug library is absent for a user without permission.
   */
  public function testTwigDebugLibraryAbsentWithoutPermission(): void {
    $user = $this->drupalCreateUser([]);
    $this->drupalLogin($user);
    $this->drupalGet('<front>');
    $this->assertSession()->responseNotContains('instruckt-twig-debug.js');
  }

}
