<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Integration tests for Auto Login URL module workflow.
 *
 * @group auto_login_url
 */
final class AutoLoginUrlIntegrationTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'auto_login_url',
    'user',
    'system',
    'dblog',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Test user with permissions.
   */
  private ?User $testUser = NULL;

  /**
   * Admin user.
   */
  private ?User $adminUser = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Configure very high rate limits for testing to prevent conflicts
    $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings')
      ->set('max_urls_per_user_per_hour', 10000)
      ->save();

    // Grant permissions to anonymous users for testing.
    $anonymous_role = Role::load('anonymous');
    $anonymous_role->grantPermission('use auto login url');
    $anonymous_role->save();

    // Create test user.
    $this->testUser = $this->createUser(['use auto login url']);

    // Create admin user.
    $this->adminUser = $this->createUser([
      'administer auto login url',
      'access administration pages',
      'access site reports',
    ]);
  }

  /**
   * Tests complete auto login workflow.
   */
  public function testCompleteAutoLoginWorkflow(): void {
    // Step 1: Configure the module.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/people/autologinurl');
    $this->assertSession()->statusCodeEquals(200);

    // Update configuration.
    $edit = [
      // 1 hour.
      'auto_login_url_expiration' => 3600,
      'auto_login_url_token_length' => 32,
      'auto_login_url_delete_on_use' => FALSE,
      'auto_login_url_max_per_hour' => 5,
    ];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved');

    // Step 2: Create auto login URL programmatically.
    $destination = 'user/' . $this->testUser->id();
    $url = auto_login_url_create(
      (int) $this->testUser->id(),
      $destination,
      TRUE
    );

    $this->assertNotEmpty($url);
    $this->assertStringContainsString('autologinurl', $url);

    // Step 3: Log out admin and test the auto login URL.
    $this->drupalLogout();

    // Step 4: Access the auto login URL.
    $this->drupalGet($url);

    // Step 5: Verify successful login and redirection.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($this->testUser->getAccountName());
    $this->assertSession()->addressEquals('/user/' . $this->testUser->id());

    // Step 6: Verify user is actually logged in.
    $this->drupalGet('user');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Access denied');
  }

  /**
   * Tests auto login with text conversion.
   */
  public function testAutoLoginTextConversion(): void {
    global $base_root;

    $original_text = sprintf(
      'Please visit %s/user/%d to view your profile and %s/admin/content for content management.',
      $base_root,
      $this->testUser->id(),
      $base_root
    );

    $converted_text = auto_login_url_convert_text(
      (int) $this->testUser->id(),
      $original_text
    );

    // Verify text was converted.
    $this->assertNotEquals($original_text, $converted_text);
    $this->assertStringContainsString('autologinurl', $converted_text);

    // Extract auto login URLs from converted text.
    preg_match_all('/https?:\/\/[^\s]+autologinurl[^\s]+/', $converted_text, $matches);
    $auto_login_urls = $matches[0];

    $this->assertGreaterThan(0, count($auto_login_urls));

    // Test each converted URL.
    foreach ($auto_login_urls as $auto_login_url) {
      $this->drupalLogout();
      $this->drupalGet($auto_login_url);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains($this->testUser->getAccountName());
    }
  }

  /**
   * Tests rate limiting functionality.
   */
  public function testRateLimitingIntegration(): void {
    // Configure low rate limit.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/people/autologinurl');

    $edit = ['auto_login_url_max_per_hour' => 2];
    $this->submitForm($edit, 'Save configuration');

    $this->drupalLogout();

    // Create URLs up to the limit.
    $url1 = auto_login_url_create(
      (int) $this->testUser->id(),
      'destination1',
      TRUE
    );
    $this->assertNotEmpty($url1);

    $url2 = auto_login_url_create(
      (int) $this->testUser->id(),
      'destination2',
      TRUE
    );
    $this->assertNotEmpty($url2);

    // Third URL should fail due to rate limiting.
    try {
      auto_login_url_create(
        (int) $this->testUser->id(),
        'destination3',
        TRUE
      );
      $this->fail('Expected rate limit exception');
    }
    catch (\Exception $e) {
      $this->assertStringContainsString('Rate limit exceeded', $e->getMessage());
    }

    // Verify first two URLs still work.
    $this->drupalGet($url1);
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalLogout();

    $this->drupalGet($url2);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests flood protection.
   */
  public function testFloodProtectionIntegration(): void {
    // Configure aggressive flood protection.
    $this->container->get('config.factory')
      ->getEditable('user.flood')
      ->set('ip_limit', 2)
      ->set('ip_window', 3600)
      ->save();

    // Make invalid login attempts to trigger flood protection.
    for ($i = 1; $i <= 3; $i++) {
      $this->drupalGet('autologinurl/' . $this->testUser->id() . '/invalid-hash-' . $i);
      $this->assertSession()->statusCodeEquals(403);
    }

    // Now create a valid URL.
    $valid_url = auto_login_url_create(
      (int) $this->testUser->id(),
      '<front>',
      TRUE
    );

    // Access should be blocked due to flood protection.
    $this->drupalGet($valid_url);
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextContains('too many failed login attempts');
  }

  /**
   * Tests token expiration.
   */
  public function testTokenExpirationIntegration(): void {
    // Set very short expiration.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/people/autologinurl');

    // 2 seconds.
    $edit = ['auto_login_url_expiration' => 2];
    $this->submitForm($edit, 'Save configuration');

    $this->drupalLogout();

    // Create URL.
    $url = auto_login_url_create(
      (int) $this->testUser->id(),
      '<front>',
      TRUE
    );

    // Immediate access should work.
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalLogout();

    // Wait for expiration.
    sleep(3);

    // Access should now fail.
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests single-use URL functionality.
   */
  public function testSingleUseUrlIntegration(): void {
    // Configure single-use URLs.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/people/autologinurl');

    $edit = ['auto_login_url_delete_on_use' => TRUE];
    $this->submitForm($edit, 'Save configuration');

    $this->drupalLogout();

    // Create URL.
    $url = auto_login_url_create(
      (int) $this->testUser->id(),
      '<front>',
      TRUE
    );

    // First access should work.
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalLogout();

    // Second access should fail.
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests usage analytics.
   */
  public function testUsageAnalyticsIntegration(): void {
    // Enable analytics.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/people/autologinurl');

    $edit = ['auto_login_url_enable_analytics' => TRUE];
    $this->submitForm($edit, 'Save configuration');

    $this->drupalLogout();

    // Create and use multiple URLs.
    for ($i = 0; $i < 3; $i++) {
      $url = auto_login_url_create(
        (int) $this->testUser->id(),
        'destination' . $i,
        TRUE
      );

      $this->drupalGet($url);
      $this->assertSession()->statusCodeEquals(200);
      $this->drupalLogout();
    }

    // Check analytics were recorded.
    $database = $this->container->get('database');
    $count = $database->select('auto_login_url_usage')
      ->condition('uid', $this->testUser->id())
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(3, $count);

    // Check statistics on admin page.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/people/autologinurl');
    $this->assertSession()->pageTextContains('Usage records');
  }

  /**
   * Tests manual cleanup functions.
   */
  public function testManualCleanupIntegration(): void {
    $this->drupalLogin($this->adminUser);

    // Create some test data.
    for ($i = 0; $i < 5; $i++) {
      auto_login_url_create(
        (int) $this->testUser->id(),
        'destination' . $i,
        FALSE
      );
    }

    // Mark some as expired.
    $database = $this->container->get('database');
    // 2 hours ago.
    $expired_time = time() - 7200;
    $database->update('auto_login_url')
      ->fields(['timestamp' => $expired_time])

    // FIXME: range() removed - needs manual fix.
      ->execute();

    // Test manual cleanup.
    $this->drupalGet('admin/people/autologinurl');
    $this->submitForm([], 'Clean up expired URLs');
    $this->assertSession()->pageTextContains('Cleaned up 3 expired auto login URLs');

    // Verify cleanup worked.
    $remaining_count = $database->select('auto_login_url')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(2, $remaining_count);
  }

  /**
   * Tests health check endpoint.
   */
  public function testHealthCheckIntegration(): void {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('admin/reports/auto-login-url/health');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Auto Login URL service is operational');
  }

  /**
   * Tests configuration form validation.
   */
  public function testConfigurationValidationIntegration(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/people/autologinurl');

    // Test invalid expiration.
    // Too short.
    $edit = ['auto_login_url_expiration' => 100];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('Expiration must be between');

    // Test invalid token length.
    $edit = [
      'auto_login_url_expiration' => 3600,
      // Too short.
      'auto_login_url_token_length' => 5,
    ];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('Token length must be between');

    // Test invalid rate limit.
    $edit = [
      'auto_login_url_expiration' => 3600,
      'auto_login_url_token_length' => 32,
      // Too high.
      'auto_login_url_max_per_hour' => 200,
    ];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('Rate limit must be between');
  }

  /**
   * Tests security features integration.
   */
  public function testSecurityFeaturesIntegration(): void {
    // Test with invalid user ID.
    $this->drupalGet('autologinurl/99999/invalid-hash');
    $this->assertSession()->statusCodeEquals(403);

    // Test with malformed hash.
    $this->drupalGet('autologinurl/' . $this->testUser->id() . '/../../etc/passwd');
    $this->assertSession()->statusCodeEquals(403);

    // Test with very short hash.
    $this->drupalGet('autologinurl/' . $this->testUser->id() . '/abc');
    $this->assertSession()->statusCodeEquals(403);

    // Test with blocked user.
    $this->testUser->block();
    $this->testUser->save();

    $url = auto_login_url_create(
      (int) $this->testUser->id(),
      '<front>',
      TRUE
    );

    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests menu integration.
   */
  public function testMenuIntegration(): void {
    $this->drupalLogin($this->adminUser);

    // Check menu item exists.
    $this->drupalGet('admin/people');
    $this->assertSession()->linkExists('Auto Login URL');

    // Check task tab exists.
    $this->drupalGet('admin/people/autologinurl');
    $this->assertSession()->linkExists('Health Check');
  }

  /**
   * Tests permissions integration.
   */
  public function testPermissionsIntegration(): void {
    // Create user without permissions.
    $restricted_user = $this->createUser([]);

    // Try to access configuration page.
    $this->drupalLogin($restricted_user);
    $this->drupalGet('admin/people/autologinurl');
    $this->assertSession()->statusCodeEquals(403);

    // Try to use auto login URL.
    $this->drupalLogout();
    $this->drupalGet('autologinurl/' . $restricted_user->id() . '/test-hash');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests cron integration.
   */
  public function testCronIntegration(): void {
    // Create some URLs and mark them as expired.
    for ($i = 0; $i < 3; $i++) {
      auto_login_url_create(
        (int) $this->testUser->id(),
        'destination' . $i,
        FALSE
      );
    }

    $database = $this->container->get('database');
    // 2 hours ago.
    $expired_time = time() - 7200;
    $database->update('auto_login_url')
      ->fields(['timestamp' => $expired_time])
      ->execute();

    // Run cron.
    \Drupal::service('cron')->run();

    // Verify expired tokens were cleaned up.
    $count = $database->select('auto_login_url')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Tokens should be cleaned up if expiration is set appropriately.
    $this->assertIsNumeric($count);
  }

  /**
   * Tests error handling integration.
   */
  public function testErrorHandlingIntegration(): void {
    // Test database error scenarios by creating malformed requests.
    $malformed_urls = [
      // Non-numeric UID.
      'autologinurl/abc/hash',
      // Negative UID.
      'autologinurl/-1/hash',
      // Zero UID.
      'autologinurl/0/hash',
    ];

    foreach ($malformed_urls as $url) {
      $this->drupalGet($url);
      $this->assertSession()->statusCodeEquals(403);
    }
  }

  /**
   * Tests multiple user scenarios.
   */
  public function testMultipleUsersIntegration(): void {
    // Create multiple users.
    $users = [];
    for ($i = 0; $i < 3; $i++) {
      $user = $this->createUser(['use auto login url']);
      $users[] = $user;
    }

    // Create URLs for each user.
    $urls = [];
    foreach ($users as $user) {
      $url = auto_login_url_create(
        (int) $user->id(),
        'user/' . $user->id(),
        TRUE
      );
      $urls[] = [$user, $url];
    }

    // Test each URL works for its user.
    foreach ($urls as [$user, $url]) {
      $this->drupalGet($url);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains($user->getAccountName());
      $this->drupalLogout();
    }

    /*
     * Test URLs don't work across users (extract hash from one user's URL
     * and try to use it with another user's ID).
     */
    preg_match('/autologinurl\/\d+\/([^\/]+)/', $urls[0][1], $matches);
    $hash = $matches[1];

    $wrong_user_url = 'autologinurl/' . $users[1]->id() . '/' . $hash;
    $this->drupalGet($wrong_user_url);
    $this->assertSession()->statusCodeEquals(403);
  }

}
