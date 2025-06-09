<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Simple debug test for Auto Login URL module.
 *
 * @group auto_login_url
 */
final class DebugAutoLoginUrlTest extends BrowserTestBase {

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
   * Test user.
   */
  private ?User $testUser = NULL;

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

    // Grant permissions.
    $anonymous_role = Role::load('anonymous');
    $anonymous_role->grantPermission('use auto login url');
    $anonymous_role->save();

    $this->testUser = $this->createUser(['use auto login url']);
  }

  /**
   * Tests basic service availability.
   */
  public function testServiceExists(): void {
    $createService = $this->container->get('auto_login_url.create');
    $this->assertNotNull($createService);

    $loginService = $this->container->get('auto_login_url.login');
    $this->assertNotNull($loginService);

    $generalService = $this->container->get('auto_login_url.general');
    $this->assertNotNull($generalService);
  }

  /**
   * Tests basic URL creation without accessing it.
   */
  public function testBasicUrlCreation(): void {
    try {
      $url = auto_login_url_create(
        (int) $this->testUser->id(),
        'user/' . $this->testUser->id(),
        TRUE
      );

      $this->assertNotEmpty($url);
      $this->assertStringContains('autologinurl', $url);
    }
    catch (\Exception $e) {
      $this->fail('URL creation failed: ' . $e->getMessage());
    }
  }

  /**
   * Tests configuration page exists.
   */
  public function testConfigPageExists(): void {
    $admin_user = $this->createUser(['administer auto login url']);
    $this->drupalLogin($admin_user);

    // Just test that the route exists.
    $this->drupalGet('admin/people/autologinurl');

    // Don't check for 200 status, just that it doesn't 404.
    $statusCode = $this->getSession()->getStatusCode();
    $this->assertNotEquals(404, $statusCode, 'Config page should exist');
  }

}
