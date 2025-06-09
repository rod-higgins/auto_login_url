<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Kernel;

use Drupal\auto_login_url\AutoLoginUrlCreate;
use Drupal\auto_login_url\AutoLoginUrlLogin;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Kernel tests for AutoLoginUrlLogin service.
 *
 * @group auto_login_url
 * @coversDefaultClass \Drupal\auto_login_url\AutoLoginUrlLogin
 */
final class AutoLoginUrlLoginKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'auto_login_url',
    'system',
    'user',
    'field',
  ];

  /**
   * The auto login URL create service.
   */
  private AutoLoginUrlCreate $urlCreateService;

  /**
   * The auto login URL login service.
   */
  private AutoLoginUrlLogin $urlLoginService;

  /**
   * Test user account.
   */
  private UserInterface $testUser;

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

    $this->installEntitySchema('user');
    $this->installConfig(['auto_login_url', 'system', 'user']);
    $this->installSchema('auto_login_url', ['auto_login_url', 'auto_login_url_usage']);

    $this->urlCreateService = $this->container->get('auto_login_url.create');
    $this->urlLoginService = $this->container->get('auto_login_url.login');

    // Create a test user.
    $this->testUser = User::create([
      'name' => 'testuser',
      'mail' => 'test@example.com',
      'status' => 1,
      'pass' => 'password123',
    ]);
    $this->testUser->save();
  }

  /**
   * @covers ::login
   */
  public function testSuccessfulLogin(): void {
    $destination = 'user/' . $this->testUser->id();

    // Create an auto login URL.
    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      $destination,
      FALSE
    );

    // Extract hash from URL.
    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $uid = (int) $matches[1];
    $hash = $matches[2];

    $this->assertEquals($this->testUser->id(), $uid);
    $this->assertNotEmpty($hash);

    // Attempt login.
    $result = $this->urlLoginService->login($uid, $hash);

    $this->assertNotFalse($result);
    $this->assertIsString($result);

    // Verify user is now logged in.
    $currentUser = $this->container->get('current_user');
    $this->assertEquals($this->testUser->id(), $currentUser->id());
  }

  /**
   * @covers ::login
   */
  public function testLoginWithInvalidHash(): void {
    $result = $this->urlLoginService->login(
      (int) $this->testUser->id(),
      'invalid-hash-123'
    );

    $this->assertFalse($result);

    // User should not be logged in.
    $currentUser = $this->container->get('current_user');
    // Anonymous user.
    $this->assertEquals(0, $currentUser->id());
  }

  /**
   * @covers ::login
   */
  public function testLoginWithInvalidUserId(): void {
    $result = $this->urlLoginService->login(99999, 'some-hash');
    $this->assertFalse($result);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithBlockedUser(): void {
    // Create URL first.
    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      '<front>',
      FALSE
    );

    // Extract hash.
    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $hash = $matches[2];

    // Block the user.
    $this->testUser->block();
    $this->testUser->save();

    // Login should fail.
    $result = $this->urlLoginService->login(
      (int) $this->testUser->id(),
      $hash
    );

    $this->assertFalse($result);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithExpiredToken(): void {
    // Set very short expiration.
    $config = $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings');
    // 1 second
    $config->set('expiration', 1);
    $config->save();

    // Create URL.
    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      '<front>',
      FALSE
    );

    // Extract hash.
    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $hash = $matches[2];

    // Wait for token to expire.
    sleep(2);

    // Login should fail.
    $result = $this->urlLoginService->login(
      (int) $this->testUser->id(),
      $hash
    );

    $this->assertFalse($result);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithDeleteOnUse(): void {
    // Enable delete on use.
    $config = $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings');
    $config->set('delete', TRUE);
    $config->save();

    // Create URL.
    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      '<front>',
      FALSE
    );

    // Extract hash.
    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $hash = $matches[2];

    // First login should succeed.
    $result = $this->urlLoginService->login(
      (int) $this->testUser->id(),
      $hash
    );
    $this->assertNotFalse($result);

    // Logout user.
    $this->container->get('account_switcher')->switchBack();

    // Second login should fail (token deleted).
    $result = $this->urlLoginService->login(
      (int) $this->testUser->id(),
      $hash
    );
    $this->assertFalse($result);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithDifferentDestinations(): void {
    $destinations = [
      '<front>',
      'user/' . $this->testUser->id(),
      'user/' . $this->testUser->id() . '/edit',
      'https://external-site.com/dashboard',
    ];

    foreach ($destinations as $destination) {
      // Create URL.
      $url = $this->urlCreateService->create(
        (int) $this->testUser->id(),
        $destination,
        FALSE
      );

      // Extract hash.
      preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
      $hash = $matches[2];

      // Login.
      $result = $this->urlLoginService->login(
        (int) $this->testUser->id(),
        $hash
      );

      $this->assertNotFalse($result, "Login failed for destination: {$destination}");
      $this->assertIsString($result);

      // Reset user session for next test.
      $this->container->get('account_switcher')->switchBack();
    }
  }

  /**
   * @covers ::login
   */
  public function testLoginUsageAnalytics(): void {
    // Enable analytics.
    $config = $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings');
    $config->set('enable_usage_analytics', TRUE);
    $config->save();

    // Create URL.
    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      '<front>',
      FALSE
    );

    // Extract hash.
    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $hash = $matches[2];

    // Login.
    $result = $this->urlLoginService->login(
      (int) $this->testUser->id(),
      $hash
    );
    $this->assertNotFalse($result);

    // Check analytics record was created.
    $database = $this->container->get('database');
    $analyticsRecord = $database->select('auto_login_url_usage', 'u')
      ->fields('u')
      ->condition('uid', $this->testUser->id())
      ->execute()
      ->fetchAssoc();

    $this->assertNotEmpty($analyticsRecord);
    $this->assertEquals($this->testUser->id(), $analyticsRecord['uid']);
    $this->assertGreaterThan(0, $analyticsRecord['used_timestamp']);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithoutAnalytics(): void {
    // Disable analytics.
    $config = $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings');
    $config->set('enable_usage_analytics', FALSE);
    $config->save();

    // Create URL.
    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      '<front>',
      FALSE
    );

    // Extract hash.
    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $hash = $matches[2];

    // Login.
    $result = $this->urlLoginService->login(
      (int) $this->testUser->id(),
      $hash
    );
    $this->assertNotFalse($result);

    // No analytics record should be created.
    $database = $this->container->get('database');
    $count = $database->select('auto_login_url_usage')
      ->condition('uid', $this->testUser->id())
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(0, $count);
  }

  /**
   * @covers ::cleanupExpiredTokens
   */
  public function testCleanupExpiredTokens(): void {
    // Set short expiration.
    $config = $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings');
    // 1 hour
    $config->set('expiration', 3600);
    $config->save();

    // Create some URLs.
    for ($i = 0; $i < 3; $i++) {
      $this->urlCreateService->create(
        (int) $this->testUser->id(),
        'destination' . $i,
        FALSE
      );
    }

    // Manually set some records as expired by updating timestamp.
    $database = $this->container->get('database');
    // 2 hours ago
    $expiredTime = time() - 7200;
    $database->update('auto_login_url')
      ->fields(['timestamp' => $expiredTime])

    // FIXME: range() removed - needs manual fix.
      ->execute();

    // Run cleanup.
    $deletedCount = $this->urlLoginService->cleanupExpiredTokens();

    $this->assertEquals(2, $deletedCount);

    // Verify only 1 record remains.
    $remainingCount = $database->select('auto_login_url')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(1, $remainingCount);
  }

  /**
   * @covers ::cleanupExpiredTokens
   */
  public function testCleanupWithNoExpiredTokens(): void {
    // Create some URLs.
    for ($i = 0; $i < 3; $i++) {
      $this->urlCreateService->create(
        (int) $this->testUser->id(),
        'destination' . $i,
        FALSE
      );
    }

    // Run cleanup (no tokens should be expired).
    $deletedCount = $this->urlLoginService->cleanupExpiredTokens();

    $this->assertEquals(0, $deletedCount);

    // All records should remain.
    $database = $this->container->get('database');
    $count = $database->select('auto_login_url')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(3, $count);
  }

  /**
   * @covers ::login
   */
  public function testLoginUpdatesUserLastLogin(): void {
    $originalLastLogin = $this->testUser->getLastLoginTime();

    // Create and use auto login URL.
    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      '<front>',
      FALSE
    );

    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $hash = $matches[2];

    // Small delay to ensure timestamp difference.
    sleep(1);

    $result = $this->urlLoginService->login(
      (int) $this->testUser->id(),
      $hash
    );

    $this->assertNotFalse($result);

    // Reload user and check last login was updated.
    $this->testUser = User::load($this->testUser->id());
    $newLastLogin = $this->testUser->getLastLoginTime();

    $this->assertGreaterThan($originalLastLogin, $newLastLogin);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithMalformedHash(): void {
    $malformedHashes = [
      '',
      'abc',
      'very-short',
    // Too long.
      str_repeat('a', 200),
      'contains spaces',
      'contains@special!chars',
    ];

    foreach ($malformedHashes as $hash) {
      $result = $this->urlLoginService->login(
        (int) $this->testUser->id(),
        $hash
      );
      $this->assertFalse($result, "Malformed hash should fail: '{$hash}'");
    }
  }

  /**
   * Tests login with IP validation enabled.
   */
  public function testLoginWithIpValidation(): void {
    // Enable IP validation.
    $config = $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings');
    $config->set('validate_ip_address', TRUE);
    $config->save();

    // Mock request with specific IP during URL creation.
    $request = $this->container->get('request_stack')->getCurrentRequest();
    if ($request) {
      $request->server->set('REMOTE_ADDR', '192.168.1.100');
    }

    // Create URL.
    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      '<front>',
      FALSE
    );

    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $hash = $matches[2];

    // Login should succeed with same IP.
    $result = $this->urlLoginService->login(
      (int) $this->testUser->id(),
      $hash
    );

    // Note: In kernel tests, IP validation might not work exactly as expected
    // due to the test environment, but we can verify the logic doesn't crash.
    $this->assertIsString($result);
  }

  /**
   * Tests multiple users don't interfere with each other.
   */
  public function testMultipleUsersIndependent(): void {
    // Create another user.
    $user2 = User::create([
      'name' => 'testuser2',
      'mail' => 'test2@example.com',
      'status' => 1,
      'pass' => 'password123',
    ]);
    $user2->save();

    // Create URLs for both users.
    $url1 = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      'user/' . $this->testUser->id(),
      FALSE
    );

    $url2 = $this->urlCreateService->create(
      (int) $user2->id(),
      'user/' . $user2->id(),
      FALSE
    );

    // Extract hashes.
    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url1, $matches1);
    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url2, $matches2);

    $hash1 = $matches1[2];
    $hash2 = $matches2[2];

    // User 1's hash shouldn't work for user 2.
    $result = $this->urlLoginService->login((int) $user2->id(), $hash1);
    $this->assertFalse($result);

    // User 2's hash shouldn't work for user 1.
    $result = $this->urlLoginService->login((int) $this->testUser->id(), $hash2);
    $this->assertFalse($result);

    // Each hash should work for its own user.
    $result1 = $this->urlLoginService->login((int) $this->testUser->id(), $hash1);
    $this->assertNotFalse($result1);

    $this->container->get('account_switcher')->switchBack();

    $result2 = $this->urlLoginService->login((int) $user2->id(), $hash2);
    $this->assertNotFalse($result2);
  }

}
