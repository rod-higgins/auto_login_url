<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Basic functional tests for Auto Login URL module.
 *
 * @group auto_login_url
 */
final class BasicAutoLoginUrlTest extends BrowserTestBase {

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

    // Configure high rate limits for testing.
    $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings')
      ->set('max_urls_per_user_per_hour', 1000)
      ->save();

    // Grant permissions.
    $anonymous_role = Role::load('anonymous');
    $anonymous_role->grantPermission('use auto login url');
    $anonymous_role->save();

    $this->testUser = $this->createUser(['use auto login url']);
  }

  /**
   * Tests basic URL creation.
   */
  public function testBasicUrlCreation(): void {
    $url = auto_login_url_create(
      (int) $this->testUser->id(),
      'user/' . $this->testUser->id(),
      TRUE
    );

    $this->assertNotEmpty($url);
    $this->assertTrue(str_contains($url, 'autologinurl'));
  }

  /**
   * Tests that admin config page exists.
   */
  public function testConfigPageExists(): void {
    $admin_user = $this->createUser(['administer auto login url']);
    $this->drupalLogin($admin_user);

    $this->drupalGet('admin/people/autologinurl');

    // Just verify we don't get a 404.
    $statusCode = $this->getSession()->getStatusCode();
    $this->assertNotEquals(404, $statusCode);
  }

}
