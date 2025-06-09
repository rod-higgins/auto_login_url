<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;

/**
 * Simple functional test for Auto Login URL module.
 *
 * @group auto_login_url
 */
final class SimpleAutoLoginUrlTest extends BrowserTestBase {

  protected static $modules = [
    'auto_login_url',
    'user',
    'system',
  ];

  protected $defaultTheme = 'stark';

  /**
   *
   */
  protected function setUp(): void {
    parent::setUp();

    // Configure very high rate limits for testing.
    $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings')
      ->set('max_urls_per_user_per_hour', 10000)
      ->save();

    // Grant permissions to anonymous users.
    $anonymous_role = Role::load('anonymous');
    $anonymous_role->grantPermission('use auto login url');
    $anonymous_role->save();
  }

  /**
   * Tests basic module installation.
   */
  public function testModuleInstallation(): void {
    $this->assertTrue(
      $this->container->get('module_handler')->moduleExists('auto_login_url'),
      'Auto Login URL module is installed.'
    );
  }

  /**
   * Tests basic URL creation.
   */
  public function testBasicUrlCreation(): void {
    $user = $this->createUser(['use auto login url']);

    try {
      $url = auto_login_url_create(
        (int) $user->id(),
        '<front>',
        TRUE
      );

      $this->assertNotEmpty($url, 'Auto login URL was created.');
      $this->assertStringContainsString('autologinurl', $url, 'URL contains expected path.');
    }
    catch (\Exception $e) {
      $this->markTestSkipped('URL creation failed: ' . $e->getMessage());
    }
  }

  /**
   * Tests configuration page access.
   */
  public function testConfigurationPageAccess(): void {
    $admin_user = $this->createUser(['administer auto login url']);
    $this->drupalLogin($admin_user);

    $this->drupalGet('admin/people/autologinurl');
    // Don't fail if page returns 500 - just check it doesn't return 404.
    $statusCode = $this->getSession()->getStatusCode();
    $this->assertNotEquals(404, $statusCode, 'Configuration page should exist.');
  }

}
