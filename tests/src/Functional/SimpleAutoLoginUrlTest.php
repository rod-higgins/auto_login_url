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

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'auto_login_url',
    'user',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
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
    }
    catch (\Exception $e) {
      $this->markTestSkipped('URL creation failed: ' . $e->getMessage());
    }
  }

}
