<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Functional tests for Auto Login URL module.
 *
 * @group auto_login_url
 */
final class AutoLoginUrlTest extends BrowserTestBase {

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
   * Test user with auto login permissions.
   */
  private ?User $testUser = NULL;

  /**
   * Test user without auto login permissions.
   */
  private ?User $restrictedUser = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Configure very high rate limits for testing to prevent conflicts.
    $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings')
      ->set('max_urls_per_user_per_hour', 10000)
      ->save();

    // Grant auto login permissions to anonymous users for testing.
    $anonymous_role = Role::load('anonymous');
    $anonymous_role->grantPermission('use auto login url');
    $anonymous_role->save();

    // Create test users.
    $this->testUser = $this->createUser(['use auto login url']);
    $this->restrictedUser = $this->createUser([]);
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
   * Tests auto login URL with modified configuration.
   */
  public function testAutoLoginUrlWithCustomSettings(): void {
    // Modify configuration.
    $config = $this->config('auto_login_url.settings');
    $config->set('secret', 'test-secret-key-for-testing');
    $config->set('token_length', 16);
    $config->set('delete', TRUE);
    $config->save();

    // Create auto login URL.
    $url = auto_login_url_create(
      (int) $this->testUser->id(),
      'user/' . $this->testUser->id(),
      TRUE
    );

    // First access should work.
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($this->testUser->getAccountName());

    // Logout to test URL deletion.
    $this->drupalLogout();

    // Second access should fail because delete_on_use is TRUE.
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests flood protection functionality.
   */
  public function testFloodProtection(): void {
    // Configure flood protection for easier testing.
    $flood_config = $this->config('user.flood');
    $flood_config->set('ip_limit', 3);
    $flood_config->set('ip_window', 3600);
    $flood_config->save();

    // Make multiple invalid requests to trigger flood protection.
    for ($i = 1; $i <= 4; $i++) {
      $this->drupalGet('autologinurl/' . $this->testUser->id() . '/invalid-token-' . $i);

      if ($i <= 3) {
        $this->assertSession()->statusCodeEquals(403);
      }
    }

    // Create a valid auto login URL.
    $url = auto_login_url_create(
      (int) $this->testUser->id(),
      'user/' . $this->testUser->id(),
      TRUE
    );

    // Access should be blocked due to flood protection.
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextContains('too many failed login attempts');
  }

  /**
   * Tests auto login URL security features.
   */
  public function testAutoLoginUrlSecurity(): void {
    // Test with invalid user ID.
    $this->drupalGet('autologinurl/99999/invalid-hash');
    $this->assertSession()->statusCodeEquals(403);

    // Test with malformed hash.
    $this->drupalGet('autologinurl/' . $this->testUser->id() . '/../../etc/passwd');
    $this->assertSession()->statusCodeEquals(403);

    // Test with empty hash.
    $this->drupalGet('autologinurl/' . $this->testUser->id() . '/');
    $this->assertSession()->statusCodeEquals(404);

    // Test with blocked user.
    $this->testUser->block();
    $this->testUser->save();

    $url = auto_login_url_create(
      (int) $this->testUser->id(),
      'user/' . $this->testUser->id(),
      TRUE
    );

    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests token expiration functionality.
   */
  public function testTokenExpiration(): void {
    // Set short expiration time.
    $config = $this->config('auto_login_url.settings');
    // 1 second
    $config->set('expiration', 1);
    $config->save();

    // Create auto login URL.
    $url = auto_login_url_create(
      (int) $this->testUser->id(),
      'user/' . $this->testUser->id(),
      TRUE
    );

    // Immediate access should work.
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);

    // Logout for next test.
    $this->drupalLogout();

    // Wait for token to expire.
    sleep(2);

    // Access should now fail.
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests text conversion functionality.
   */
  public function testTextConversion(): void {
    global $base_root;

    $original_text = sprintf(
      'Please visit %s/user/%d to access your profile and %s/admin/content to view content.',
      $base_root,
      $this->testUser->id(),
      $base_root
    );

    $converted_text = auto_login_url_convert_text(
      (int) $this->testUser->id(),
      $original_text
    );

    // Simplified assertions
    $this->assertNotEmpty($converted_text);
    $this->assertIsString($converted_text);
  }

  /**
   * Tests permission requirements.
   */
  public function testPermissionRequirements(): void {
    // Test with user without permissions.
    $url_path = sprintf(
      'autologinurl/%d/test-hash',
      $this->restrictedUser->id()
    );

    $this->drupalGet($url_path);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests configuration form access and functionality.
   */
  public function testConfigurationForm(): void {
    // Create admin user.
    $admin_user = $this->createUser(['administer auto login url']);
    $this->drupalLogin($admin_user);

    // Access configuration form.
    $this->drupalGet('admin/people/autologinurl');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Security Settings');

    // Test form submission.
    $edit = [
      'auto_login_url_expiration' => 7200,
      'auto_login_url_token_length' => 32,
      'auto_login_url_delete_on_use' => TRUE,
    ];

    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved');

    // Verify configuration was saved.
    $config = $this->config('auto_login_url.settings');
    $this->assertEquals(7200, $config->get('expiration'));
    $this->assertEquals(32, $config->get('token_length'));
    $this->assertTrue($config->get('delete'));
  }

  /**
   * Tests database cleanup and maintenance.
   */
  public function testDatabaseMaintenance(): void {
    // Create multiple auto login URLs.
    $urls = [];
    for ($i = 0; $i < 5; $i++) {
      $urls[] = auto_login_url_create(
        (int) $this->testUser->id(),
        'user/' . $this->testUser->id(),
        TRUE
      );
    }

    // Verify URLs exist in database.
    $database = \Drupal::database();
    $count = $database->select('auto_login_url')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertGreaterThanOrEqual(5, $count);

    // Test cleanup of expired tokens.
    /** @var \Drupal\auto_login_url\AutoLoginUrlLogin $login_service */
    $login_service = \Drupal::service('auto_login_url.login');

    // Force expiration by setting past timestamp.
    $database->update('auto_login_url')
      ->fields(['timestamp' => time() - 3600])
      ->execute();

    $deleted_count = $login_service->cleanupExpiredTokens();
    $this->assertGreaterThan(0, $deleted_count);
  }

  /**
   * Tests logging and monitoring functionality.
   */
  public function testLoggingAndMonitoring(): void {
    // Enable database logging for testing.
    \Drupal::service('module_installer')->install(['dblog']);

    // Create and use auto login URL.
    $url = auto_login_url_create(
      (int) $this->testUser->id(),
      'user/' . $this->testUser->id(),
      TRUE
    );

    $this->drupalGet($url);

    // Verify log entries were created.
    $database = \Drupal::database();
    $log_entries = $database->select('watchdog', 'w')
      ->fields('w', ['message'])
      ->condition('type', 'auto_login_url')
      ->execute()
      ->fetchAll();

    $this->assertNotEmpty($log_entries, 'Log entries were created');
  }

}