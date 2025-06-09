<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Simple functional tests for Auto Login URL module.
 *
 * @group auto_login_url
 */
final class SimpleAutoLoginUrlTest extends BrowserTestBase {

  /**
   * The modules to load to run the test.
   *
   * @var array
   */
  protected static $modules = [
    'auto_login_url',
    'user',
    'system',
  ];

  /**
   * The default theme for testing.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * Test user with auto login permissions.
   *
   * @var \Drupal\user\Entity\User|null
   */
  private ?User $testUser = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Configure very high rate limits to prevent test conflicts.
    $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings')
      ->set('max_urls_per_user_per_hour', 1000)
      ->save();

    // Grant auto login permissions to anonymous users for testing.
    $anonymous_role = Role::load('anonymous');
    $anonymous_role->grantPermission('use auto login url');
    $anonymous_role->save();

    // Create test user.
    $this->testUser = $this->createUser(['use auto login url']);
  }

  /**
   * Tests basic auto login URL functionality.
   */
  public function testBasicAutoLoginUrl(): void {
    // Create an auto login URL for the test user.
    $url = auto_login_url_create(
      (int) $this->testUser->id(),
      'user/' . $this->testUser->id(),
      TRUE
    );

    $this->assertNotEmpty($url, 'Auto login URL was created successfully');

    // Access the auto login URL.
    $this->drupalGet($url);

    // Verify successful login.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($this->testUser->getAccountName());

    // Verify we're on the correct destination page.
    $this->assertSession()->addressEquals('/user/' . $this->testUser->id());
  }

  /**
   * Tests auto login URL with different destinations.
   */
  public function testAutoLoginUrlDestinations(): void {
    $destinations = [
      '<front>',
      'user/' . $this->testUser->id() . '/edit',
      'admin/content',
    ];

    foreach ($destinations as $destination) {
      // Create auto login URL with specific destination.
      $url = auto_login_url_create(
        (int) $this->testUser->id(),
        $destination,
        TRUE
      );

      $this->drupalGet($url);
      $this->assertSession()->statusCodeEquals(200);

      // Verify user is logged in.
      $this->assertSession()->pageTextContains($this->testUser->getAccountName());
    }
  }

  /**
   * Tests configuration form access.
   */
  public function testConfigurationFormAccess(): void {
    // Create admin user.
    $admin_user = $this->createUser(['administer auto login url']);
    $this->drupalLogin($admin_user);

    // Access configuration form.
    $this->drupalGet('admin/people/autologinurl');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Security Settings');
  }

  /**
   * Tests basic security features.
   */
  public function testBasicSecurity(): void {
    // Test with invalid user ID.
    $this->drupalGet('autologinurl/99999/invalid-hash');
    $this->assertSession()->statusCodeEquals(403);

    // Test with malformed hash.
    $this->drupalGet('autologinurl/' . $this->testUser->id() . '/invalid-hash');
    $this->assertSession()->statusCodeEquals(403);
  }

}
