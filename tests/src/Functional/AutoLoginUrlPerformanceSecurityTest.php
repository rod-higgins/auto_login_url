<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Performance and security tests for Auto Login URL module.
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
    'dblog',
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
   * Admin user.
   */
  private ?User $adminUser = NULL;

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
    $this->adminUser = $this->createUser(['administer auto login url']);
  }

  /**
   * Tests performance with multiple concurrent URL creations.
   */
  public function testPerformanceMultipleUrlCreations(): void {
    $start_time = microtime(TRUE);
    $urls = [];

    // Create 50 URLs rapidly.
    for ($i = 0; $i < 50; $i++) {
      $url = auto_login_url_create(
        (int) $this->testUser->id(),
        'destination' . $i,
        TRUE
      );
      $urls[] = $url;
    }

    $end_time = microtime(TRUE);
    $duration = $end_time - $start_time;

    // Should complete within reasonable time
    // (less than 10 seconds for 50 URLs).
    $this->assertLessThan(10.0, $duration, 'URL creation took too long: ' . $duration . ' seconds');

    // All URLs should be unique.
    $this->assertEquals(count($urls), count(array_unique($urls)));

  }

  /**
   * Tests performance of hash generation and uniqueness checking.
   */
  public function testHashGenerationPerformance(): void {
    // Create many URLs to test hash collision avoidance performance.
    $start_time = microtime(TRUE);
    $hashes = [];

    for ($i = 0; $i < 100; $i++) {
      $url = auto_login_url_create(
        (int) $this->testUser->id(),
        'performance-test-' . $i,
        FALSE
      );

      // Extract hash from URL.
      preg_match('/autologinurl\/\d+\/([^\/]+)/', $url, $matches);
      $hash = $matches[1] ?? '';
      $hashes[] = $hash;
    }

    $end_time = microtime(TRUE);
    $duration = $end_time - $start_time;

    // Should complete within reasonable time.
    $this->assertLessThan(15.0, $duration);

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
   * Tests security against timing attacks.
   */
  public function testTimingAttackResistance(): void {
    // Create valid URL.
    $valid_url = auto_login_url_create(
      (int) $this->testUser->id(),
      '<front>',
      TRUE
    );

    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $valid_url, $matches);

    // Test timing consistency with different invalid hashes.
    $invalid_hashes = [
      'short',
      'medium-length-hash',
      'very-long-hash-that-is-much-longer-than-typical',
      str_repeat('a', 64),
      str_repeat('z', 32),
    ];

    $times = [];

    foreach ($invalid_hashes as $invalid_hash) {
      $start_time = microtime(TRUE);
      $this->drupalGet('autologinurl/' . $this->testUser->id() . '/' . $invalid_hash);
      $end_time = microtime(TRUE);

      $times[] = $end_time - $start_time;

      // Should always return 403 for invalid hashes.
      $this->assertSession()->statusCodeEquals(403);
    }

    // Timing should be relatively consistent (within 200ms variation).
    $min_time = min($times);
    $max_time = max($times);
    $this->assertLessThan(0.2, $max_time - $min_time, 'Timing variation too high: ' . ($max_time - $min_time) . ' seconds');
  }

  /**
   * Tests resistance to brute force attacks.
   */
  public function testBruteForceResistance(): void {
    // Configure aggressive flood protection.
    $this->container->get('config.factory')
      ->getEditable('user.flood')
      ->set('ip_limit', 3)
      ->set('ip_window', 3600)
      ->save();

    $uid = $this->testUser->id();

    // Make multiple invalid attempts.
    for ($i = 1; $i <= 5; $i++) {
      $this->drupalGet("autologinurl/{$uid}/brute-force-attempt-{$i}");

      if ($i <= 3) {
        $this->assertSession()->statusCodeEquals(403);
      }
      else {
        // After 3 attempts, should be blocked by flood protection.
        $this->assertSession()->statusCodeEquals(403);
        $this->assertSession()->pageTextContains('too many failed login attempts');
      }
    }

    // Even valid URL should now be blocked.
    $valid_url = auto_login_url_create($uid, '<front>', TRUE);
    $this->drupalGet($valid_url);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests SQL injection resistance.
   */
  public function testSqlInjectionResistance(): void {
    $sql_injection_attempts = [
      "'; DROP TABLE auto_login_url; --",
      "' OR '1'='1",
      "'; INSERT INTO auto_login_url VALUES (999, 999, 'hack', 'hack', 0); --",
      "' UNION SELECT * FROM users --",
      "%27%20OR%20%271%27%3D%271",
    ];

    foreach ($sql_injection_attempts as $injection) {
      // Test in URL hash parameter.
      $this->drupalGet('autologinurl/' . $this->testUser->id() . '/' . urlencode($injection));
      $this->assertSession()->statusCodeEquals(403);

      // Test in UID parameter.
      $this->drupalGet('autologinurl/' . urlencode($injection) . '/valid-hash');
      $this->assertSession()->statusCodeEquals(403);
    }

    // Verify database integrity.
    $database = $this->container->get('database');
    $table_exists = $database->schema()->tableExists('auto_login_url');
    $this->assertTrue($table_exists, 'auto_login_url table should still exist');

    $user_count = $database->select('users')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertGreaterThan(0, $user_count, 'Users table should be intact');
  }

  /**
   * Tests XSS prevention in error messages and logs.
   */
  public function testXssResistance(): void {
    $xss_attempts = [
      '<script>alert("xss")</script>',
      'javascript:alert("xss")',
      '"><script>alert("xss")</script>',
      '%3Cscript%3Ealert%28%22xss%22%29%3C%2Fscript%3E',
    ];

    foreach ($xss_attempts as $xss_payload) {
      $this->drupalGet('autologinurl/' . $this->testUser->id() . '/' . urlencode($xss_payload));
      $this->assertSession()->statusCodeEquals(403);

      // Verify no script tags in response.
      $this->assertSession()->responseNotContains('<script>');
      $this->assertSession()->responseNotContains('javascript:');
    }
  }

  /**
   * Tests path traversal resistance.
   */
  public function testPathTraversalResistance(): void {
    $path_traversal_attempts = [
      '../../../etc/passwd',
      '....//....//....//etc/passwd',
      '..%2F..%2F..%2Fetc%2Fpasswd',
      '..\\..\\..\\windows\\system32\\config\\sam',
    ];

    foreach ($path_traversal_attempts as $traversal) {
      $this->drupalGet('autologinurl/' . $this->testUser->id() . '/' . urlencode($traversal));
      $this->assertSession()->statusCodeEquals(403);
    }
  }

  /**
   * Tests rate limiting effectiveness.
   */
  public function testRateLimitingEffectiveness(): void {
    // Set low rate limit.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/people/autologinurl');

    $edit = ['auto_login_url_max_per_hour' => 3];
    $this->submitForm($edit, 'Save configuration');

    $this->drupalLogout();

    // Create URLs up to limit.
    $urls = [];
    for ($i = 0; $i < 3; $i++) {
      try {
        $url = auto_login_url_create(
          (int) $this->testUser->id(),
          'rate-limit-test-' . $i,
          TRUE
        );
        $urls[] = $url;
      }
      catch (\Exception $e) {
        $this->fail("Unexpected exception on attempt {$i}: " . $e->getMessage());
      }
    }

    $this->assertCount(3, $urls);

  }

  /**
   * Tests database cleanup performance.
   */
  public function testDatabaseCleanupPerformance(): void {
    // Create many expired URLs.
    $database = $this->container->get('database');
    // 2 hours ago.
    $expired_time = time() - 7200;

    for ($i = 0; $i < 100; $i++) {
      auto_login_url_create(
        (int) $this->testUser->id(),
        'cleanup-test-' . $i,
        FALSE
      );
    }

    // Mark them all as expired.
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
    $this->assertLessThan(5.0, $duration);
    $this->assertEquals(100, $deleted_count);

    // Verify cleanup was effective.
    $remaining_count = $database->select('auto_login_url')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $remaining_count);
  }

  /**
   * Tests memory usage during bulk operations.
   */
  public function testMemoryUsageDuringBulkOperations(): void {
    $initial_memory = memory_get_usage();

    // Create many URLs.
    for ($i = 0; $i < 200; $i++) {
      auto_login_url_create(
        (int) $this->testUser->id(),
        'memory-test-' . $i,
        FALSE
      );

      // Check memory usage periodically.
      if ($i % 50 === 0) {
        $current_memory = memory_get_usage();
        $memory_increase = $current_memory - $initial_memory;

        // Memory increase should be reasonable (less than 10MB for 200 URLs).
        $this->assertLessThan(10 * 1024 * 1024, $memory_increase,
          "Memory usage too high after {$i} URLs: " . ($memory_increase / 1024 / 1024) . "MB");
      }
    }
  }

  /**
   * Tests concurrent access handling.
   */
  public function testConcurrentAccessHandling(): void {
    // This test simulates concurrent access by rapidly creating and using URLs.
    $urls = [];

    // Create multiple URLs quickly.
    for ($i = 0; $i < 10; $i++) {
      $url = auto_login_url_create(
        (int) $this->testUser->id(),
        'concurrent-test-' . $i,
        TRUE
      );
      $urls[] = $url;
    }

    // Try to use all URLs rapidly.
    foreach ($urls as $index => $url) {
      $this->drupalGet($url);

      if ($index === 0) {
        // First URL should work.
        $this->assertSession()->statusCodeEquals(200);
        $this->drupalLogout();
      }
      else {
        // Subsequent URLs should also work (different tokens).
        $this->assertSession()->statusCodeEquals(200);
        $this->drupalLogout();
      }
    }
  }

  /**
   * Tests token entropy and randomness.
   */
  public function testTokenEntropyAndRandomness(): void {
    $hashes = [];

    // Generate many hashes to test randomness.
    for ($i = 0; $i < 100; $i++) {
      $url = auto_login_url_create(
        (int) $this->testUser->id(),
        'entropy-test-' . $i,
        FALSE
      );

      preg_match('/autologinurl\/\d+\/([^\/]+)/', $url, $matches);
      $hash = $matches[1] ?? '';
      $hashes[] = $hash;
    }

    // Test character distribution (should be relatively even).
    $all_chars = implode('', $hashes);
    $char_counts = array_count_values(str_split($all_chars));

    // Should have good character variety.
    $this->assertGreaterThan(30, count($char_counts), 'Not enough character variety');

    // No character should dominate (appear more than 20% of the time).
    $total_chars = strlen($all_chars);
    foreach ($char_counts as $char => $count) {
      $percentage = ($count / $total_chars) * 100;
      $this->assertLessThan(20, $percentage, "Character '{$char}' appears too frequently: {$percentage}%");
    }

    // Test for patterns (consecutive repeated characters).
    foreach ($hashes as $hash) {
      $this->assertNotMatchesRegularExpression('/(.)\1{3,}/', $hash,
        "Hash contains too many consecutive repeated characters: {$hash}");
    }
  }

  /**
   * Tests system behavior under high load.
   */
  public function testHighLoadBehavior(): void {
    // Simulate high load by creating many URLs and attempting logins rapidly.
    $start_time = microtime(TRUE);
    $success_count = 0;
    $error_count = 0;

    for ($i = 0; $i < 50; $i++) {
      try {
        $url = auto_login_url_create(
          (int) $this->testUser->id(),
          'load-test-' . $i,
          TRUE
        );

        // Immediately try to use the URL.
        $this->drupalGet($url);
        if ($this->getSession()->getStatusCode() === 200) {
          $success_count++;
        }
        else {
          $error_count++;
        }
        $this->drupalLogout();
      }
      catch (\Exception $e) {
        $error_count++;
      }
    }

    $end_time = microtime(TRUE);
    $duration = $end_time - $start_time;

    // Should handle load reasonably well.
    $this->assertLessThan(30.0, $duration, 'High load test took too long');
    $this->assertGreaterThan(40, $success_count, 'Too many failures under load');
    $this->assertLessThan(10, $error_count, 'Too many errors under load');
  }

}
