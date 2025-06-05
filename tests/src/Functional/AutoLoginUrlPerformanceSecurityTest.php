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
    $startTime = microtime(TRUE);
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

    $endTime = microtime(TRUE);
    $duration = $endTime - $startTime;

    // Should complete within reasonable time (less than 10 seconds for 50 URLs).
    $this->assertLessThan(10.0, $duration, 'URL creation took too long: ' . $duration . ' seconds');

    // All URLs should be unique.
    $this->assertEquals(count($urls), count(array_unique($urls)));

    // All URLs should be valid format.
    foreach ($urls as $url) {
      $this->assertStringContains('autologinurl', $url);
      $this->assertStringContains((string) $this->testUser->id(), $url);
    }
  }

  /**
   * Tests performance of hash generation and uniqueness checking.
   */
  public function testHashGenerationPerformance(): void {
    // Create many URLs to test hash collision avoidance performance.
    $startTime = microtime(TRUE);
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

    $endTime = microtime(TRUE);
    $duration = $endTime - $startTime;

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
    $validUrl = auto_login_url_create(
      (int) $this->testUser->id(),
      '<front>',
      TRUE
    );

    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $validUrl, $matches);
    $validHash = $matches[2];

    // Test timing consistency with different invalid hashes.
    $invalidHashes = [
      'short',
      'medium-length-hash',
      'very-long-hash-that-is-much-longer-than-typical',
      str_repeat('a', 64),
      str_repeat('z', 32),
    ];

    $times = [];

    foreach ($invalidHashes as $invalidHash) {
      $startTime = microtime(TRUE);
      $this->drupalGet('autologinurl/' . $this->testUser->id() . '/' . $invalidHash);
      $endTime = microtime(TRUE);
      
      $times[] = $endTime - $startTime;
      
      // Should always return 403 for invalid hashes.
      $this->assertSession()->statusCodeEquals(403);
    }

    // Timing should be relatively consistent (within 200ms variation).
    $minTime = min($times);
    $maxTime = max($times);
    $this->assertLessThan(0.2, $maxTime - $minTime, 'Timing variation too high: ' . ($maxTime - $minTime) . ' seconds');
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
      } else {
        // After 3 attempts, should be blocked by flood protection.
        $this->assertSession()->statusCodeEquals(403);
        $this->assertSession()->pageTextContains('too many failed login attempts');
      }
    }

    // Even valid URL should now be blocked.
    $validUrl = auto_login_url_create($uid, '<front>', TRUE);
    $this->drupalGet($validUrl);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests SQL injection resistance.
   */
  public function testSqlInjectionResistance(): void {
    $sqlInjectionAttempts = [
      "'; DROP TABLE auto_login_url; --",
      "' OR '1'='1",
      "'; INSERT INTO auto_login_url VALUES (999, 999, 'hack', 'hack', 0); --",
      "' UNION SELECT * FROM users --",
      "%27%20OR%20%271%27%3D%271",
    ];

    foreach ($sqlInjectionAttempts as $injection) {
      // Test in URL hash parameter.
      $this->drupalGet('autologinurl/' . $this->testUser->id() . '/' . urlencode($injection));
      $this->assertSession()->statusCodeEquals(403);

      // Test in UID parameter.
      $this->drupalGet('autologinurl/' . urlencode($injection) . '/valid-hash');
      $this->assertSession()->statusCodeEquals(403);
    }

    // Verify database integrity.
    $database = $this->container->get('database');
    $tableExists = $database->schema()->tableExists('auto_login_url');
    $this->assertTrue($tableExists, 'auto_login_url table should still exist');

    $userCount = $database->select('users')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertGreaterThan(0, $userCount, 'Users table should be intact');
  }

  /**
   * Tests XSS prevention in error messages and logs.
   */
  public function testXssResistance(): void {
    $xssAttempts = [
      '<script>alert("xss")</script>',
      'javascript:alert("xss")',
      '"><script>alert("xss")</script>',
      '%3Cscript%3Ealert%28%22xss%22%29%3C%2Fscript%3E',
    ];

    foreach ($xssAttempts as $xssPayload) {
      $this->drupalGet('autologinurl/' . $this->testUser->id() . '/' . urlencode($xssPayload));
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
    $pathTraversalAttempts = [
      '../../../etc/passwd',
      '....//....//....//etc/passwd',
      '..%2F..%2F..%2Fetc%2Fpasswd',
      '..\\..\\..\\windows\\system32\\config\\sam',
    ];

    foreach ($pathTraversalAttempts as $traversal) {
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

    // Fourth attempt should fail.
    try {
      auto_login_url_create(
        (int) $this->testUser->id(),
        'rate-limit-test-exceed',
        TRUE
      );
      $this->fail('Expected rate limit exception');
    }
    catch (\Exception $e) {
      $this->assertStringContains('Rate limit exceeded', $e->getMessage());
    }
  }

  /**
   * Tests database cleanup performance.
   */
  public function testDatabaseCleanupPerformance(): void {
    // Create many expired URLs.
    $database = $this->container->get('database');
    $expiredTime = time() - 7200; // 2 hours ago

    for ($i = 0; $i < 100; $i++) {
      auto_login_url_create(
        (int) $this->testUser->id(),
        'cleanup-test-' . $i,
        FALSE
      );
    }

    // Mark them all as expired.
    $database->update('auto_login_url')
      ->fields(['timestamp' => $expiredTime])
      ->execute();

    // Test cleanup performance.
    $startTime = microtime(TRUE);
    
    /** @var \Drupal\auto_login_url\AutoLoginUrlLogin $loginService */
    $loginService = $this->container->get('auto_login_url.login');
    $deletedCount = $loginService->cleanupExpiredTokens();
    
    $endTime = microtime(TRUE);
    $duration = $endTime - $startTime;

    // Should complete quickly and delete all expired tokens.
    $this->assertLessThan(5.0, $duration);
    $this->assertEquals(100, $deletedCount);

    // Verify cleanup was effective.
    $remainingCount = $database->select('auto_login_url')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $remainingCount);
  }

  /**
   * Tests memory usage during bulk operations.
   */
  public function testMemoryUsageDuringBulkOperations(): void {
    $initialMemory = memory_get_usage();

    // Create many URLs.
    for ($i = 0; $i < 200; $i++) {
      auto_login_url_create(
        (int) $this->testUser->id(),
        'memory-test-' . $i,
        FALSE
      );

      // Check memory usage periodically.
      if ($i % 50 === 0) {
        $currentMemory = memory_get_usage();
        $memoryIncrease = $currentMemory - $initialMemory;
        
        // Memory increase should be reasonable (less than 10MB for 200 URLs).
        $this->assertLessThan(10 * 1024 * 1024, $memoryIncrease, 
          "Memory usage too high after {$i} URLs: " . ($memoryIncrease / 1024 / 1024) . "MB");
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
      } else {
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
    $allChars = implode('', $hashes);
    $charCounts = array_count_values(str_split($allChars));
    
    // Should have good character variety.
    $this->assertGreaterThan(30, count($charCounts), 'Not enough character variety');

    // No character should dominate (appear more than 20% of the time).
    $totalChars = strlen($allChars);
    foreach ($charCounts as $char => $count) {
      $percentage = ($count / $totalChars) * 100;
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
    $startTime = microtime(TRUE);
    $successCount = 0;
    $errorCount = 0;

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
          $successCount++;
        } else {
          $errorCount++;
        }
        $this->drupalLogout();
      }
      catch (\Exception $e) {
        $errorCount++;
      }
    }

    $endTime = microtime(TRUE);
    $duration = $endTime - $startTime;

    // Should handle load reasonably well.
    $this->assertLessThan(30.0, $duration, 'High load test took too long');
    $this->assertGreaterThan(40, $successCount, 'Too many failures under load');
    $this->assertLessThan(10, $errorCount, 'Too many errors under load');
  }

}