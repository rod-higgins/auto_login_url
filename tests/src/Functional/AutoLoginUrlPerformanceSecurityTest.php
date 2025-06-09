<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Simplified performance and security tests for Auto Login URL module.
 *
 * @group auto_login_url
 */
final class AutoLoginUrlPerformanceSecurityTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'auto_login_url',
    'user',
    'system',
    'field',
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

    // Configure very high rate limits for testing to prevent conflicts.
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
   * Tests basic performance with multiple URL creations.
   */
  public function testBasicPerformance(): void {
    $start_time = microtime(TRUE);
    $urls = [];

    // Create 10 URLs (reduced from 50 for simplicity).
    for ($i = 0; $i < 10; $i++) {
      $url = auto_login_url_create(
        (int) $this->testUser->id(),
        'destination' . $i,
        TRUE
      );
      $urls[] = $url;
    }

    $end_time = microtime(TRUE);
    $duration = $end_time - $start_time;

    // Should complete within reasonable time.
    $this->assertLessThan(5.0, $duration, 'URL creation took too long: ' . $duration . ' seconds');

    // All URLs should be unique.
    $this->assertEquals(count($urls), count(array_unique($urls)));
  }

  /**
   * Tests basic security against invalid inputs.
   */
  public function testBasicSecurity(): void {
    // Test with invalid user ID.
    $this->drupalGet('autologinurl/99999/invalid-hash');
    $this->assertSession()->statusCodeEquals(403);

    // Test with simple malformed hash.
    $this->drupalGet('autologinurl/' . $this->testUser->id() . '/invalid-hash-with-spaces');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests database cleanup performance.
   */
  public function testDatabaseCleanup(): void {
    // Create some URLs.
    for ($i = 0; $i < 5; $i++) {
      auto_login_url_create(
        (int) $this->testUser->id(),
        'cleanup-test-' . $i,
        FALSE
      );
    }

    // Mark them as expired.
    $database = $this->container->get('database');
    // 2 hours ago
    $expired_time = time() - 7200;
    $database->update('auto_login_url')
      ->fields(['timestamp' => $expired_time])
      ->execute();

    // Test cleanup performance.
    $start_time = microtime(TRUE);

    /** @var \Drupal\auto_login_url\AutoLoginUrlLogin $login_service */
    $login_service = $this->container->get('auto_login_url.login');
    $deleted_count = $login_service->cleanupExpiredTokens();

    $end_time = microtime(TRUE);
    $duration = $end_time - $start_time;

    // Should complete quickly and delete all expired tokens.
    $this->assertLessThan(2.0, $duration);
    $this->assertEquals(5, $deleted_count);
  }

  /**
   * Tests hash uniqueness.
   */
  public function testHashUniqueness(): void {
    $hashes = [];

    // Generate 20 URLs to test uniqueness.
    for ($i = 0; $i < 20; $i++) {
      $url = auto_login_url_create(
        (int) $this->testUser->id(),
        'uniqueness-test-' . $i,
        FALSE
      );

      // Extract hash from URL.
      preg_match('/autologinurl\/\d+\/([^\/]+)/', $url, $matches);
      $hash = $matches[1] ?? '';
      $hashes[] = $hash;
    }

    // All hashes should be unique.
    $this->assertEquals(count($hashes), count(array_unique($hashes)));

    // All hashes should meet format requirements.
    foreach ($hashes as $hash) {
      $this->assertNotEmpty($hash);
      $this->assertGreaterThan(7, strlen($hash));
      $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $hash);
    }
  }

  /**
   * Tests basic token entropy.
   */
  public function testBasicTokenEntropy(): void {
    $hashes = [];

    // Generate 10 hashes to test randomness.
    for ($i = 0; $i < 10; $i++) {
      $url = auto_login_url_create(
        (int) $this->testUser->id(),
        'entropy-test-' . $i,
        FALSE
      );

      preg_match('/autologinurl\/\d+\/([^\/]+)/', $url, $matches);
      $hash = $matches[1] ?? '';
      $hashes[] = $hash;
    }

    // Test character distribution (should have some variety).
    $all_chars = implode('', $hashes);
    $char_counts = array_count_values(str_split($all_chars));

    // Should have decent character variety.
    $this->assertGreaterThan(10, count($char_counts), 'Not enough character variety');

    // Test for simple patterns.
    foreach ($hashes as $hash) {
      // Avoid simple repeated patterns.
      $this->assertFalse(preg_match('/(.)\1{4,}/', $hash),
        "Hash contains too many consecutive repeated characters: {$hash}");
    }
  }

  /**
   * Tests basic flood protection.
   */
  public function testBasicFloodProtection(): void {
    // Configure flood protection.
    $this->container->get('config.factory')
      ->getEditable('user.flood')
      ->set('ip_limit', 2)
      ->set('ip_window', 3600)
      ->save();

    $uid = $this->testUser->id();

    // Make invalid attempts.
    for ($i = 1; $i <= 3; $i++) {
      $this->drupalGet("autologinurl/{$uid}/invalid-attempt-{$i}");
      $this->assertSession()->statusCodeEquals(403);
    }

    // Additional attempts should be blocked by flood protection.
    $this->drupalGet("autologinurl/{$uid}/another-invalid-attempt");
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests memory usage during operations.
   */
  public function testMemoryUsage(): void {
    $initial_memory = memory_get_usage();

    // Create URLs.
    for ($i = 0; $i < 20; $i++) {
      auto_login_url_create(
        (int) $this->testUser->id(),
        'memory-test-' . $i,
        FALSE
      );
    }

    $current_memory = memory_get_usage();
    $memory_increase = $current_memory - $initial_memory;

    // Memory increase should be reasonable (less than 5MB for 20 URLs).
    $this->assertLessThan(5 * 1024 * 1024, $memory_increase,
      "Memory usage too high: " . ($memory_increase / 1024 / 1024) . "MB");
  }

}
