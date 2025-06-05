<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Unit;

use Drupal\auto_login_url\AutoLoginUrlRateLimit;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for AutoLoginUrlRateLimit service.
 *
 * @group auto_login_url
 * @coversDefaultClass \Drupal\auto_login_url\AutoLoginUrlRateLimit
 */
final class AutoLoginUrlRateLimitTest extends UnitTestCase {

  /**
   * The mocked config factory.
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The mocked state service.
   */
  private StateInterface $state;

  /**
   * The service under test.
   */
  private AutoLoginUrlRateLimit $rateLimiter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->state = $this->createMock(StateInterface::class);

    $this->rateLimiter = new AutoLoginUrlRateLimit(
      $this->configFactory,
      $this->state
    );
  }

  /**
   * @covers ::checkCreationLimit
   */
  public function testCheckCreationLimitWithinLimit(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_urls_per_user_per_hour')
      ->willReturn(10);

    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    // Simulate 5 attempts within the last hour - using current time.
    $currentTime = time();
    $attempts = [
    // 30 minutes ago
      $currentTime - 1800,
    // 20 minutes ago
      $currentTime - 1200,
    // 10 minutes ago
      $currentTime - 600,
    // 5 minutes ago
      $currentTime - 300,
    // 1 minute ago
      $currentTime - 60,
    ];

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn($attempts);

    // Expect the state to be updated with new attempt added.
    $this->state->expects($this->once())
      ->method('set')
      ->with(
        'auto_login_url.create_rate.123',
        $this->callback(function ($value) use ($attempts) {
          // Should contain the original attempts plus the new one.
          $expected = count($attempts) + 1;
          return is_array($value) &&
                 count($value) === $expected;
        })
      );

    $result = $this->rateLimiter->checkCreationLimit(123);
    $this->assertTrue($result);
  }

  /**
   * @covers ::checkCreationLimit
   */
  public function testCheckCreationLimitExceeded(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_urls_per_user_per_hour')
      ->willReturn(5);

    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    // Simulate 5 attempts within the last hour - exactly at the limit.
    $currentTime = time();
    $attempts = [
      $currentTime - 1800,
      $currentTime - 1200,
      $currentTime - 600,
      $currentTime - 300,
      $currentTime - 60,
    ];

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn($attempts);

    // When at limit, adding one more should exceed it.
    $this->state->expects($this->once())
      ->method('set')
      ->with(
        'auto_login_url.create_rate.123',
        $this->callback(function ($value) use ($attempts) {
          // Should contain attempts plus new one (6 total, over limit)
          $expected = count($attempts) + 1;
          return is_array($value) &&
                 count($value) === $expected;
        })
      );

    $result = $this->rateLimiter->checkCreationLimit(123);
    $this->assertFalse($result);
  }

  /**
   * @covers ::checkCreationLimit
   */
  public function testCheckCreationLimitWithExpiredAttempts(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_urls_per_user_per_hour')
      ->willReturn(10);

    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    // Mix of old and recent attempts.
    $currentTime = time();
    $old_attempts = [
    // 2 hours ago (expired)
      $currentTime - 7200,
    // 1.5 hours ago (expired)
      $currentTime - 5400,
    // 30 minutes ago (valid)
      $currentTime - 1800,
    // 10 minutes ago (valid)
      $currentTime - 600,
    ];

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn($old_attempts);

    // Should filter out expired attempts and add new one.
    $this->state->expects($this->once())
      ->method('set')
      ->with(
        'auto_login_url.create_rate.123',
        $this->callback(function ($value) {
          // Should contain only recent attempts plus new one
          // Total: 2 recent + 1 new = 3.
          return is_array($value) && count($value) === 3;
        })
      );

    $result = $this->rateLimiter->checkCreationLimit(123);
    $this->assertTrue($result);
  }

  /**
   * @covers ::checkCreationLimit
   */
  public function testCheckCreationLimitWithDefaultConfig(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_urls_per_user_per_hour')
    // No config set.
      ->willReturn(NULL);

    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn([]);

    $this->state->expects($this->once())
      ->method('set')
      ->with(
        'auto_login_url.create_rate.123',
        $this->callback(function ($value) {
          // Should contain just the new attempt.
          return is_array($value) && count($value) === 1;
        })
      );

    $result = $this->rateLimiter->checkCreationLimit(123);
    $this->assertTrue($result);
  }

  /**
   * @covers ::registerCreation
   */
  public function testRegisterCreation(): void {
    $currentTime = time();
    $existing_attempts = [
      $currentTime - 1800,
      $currentTime - 600,
    ];

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn($existing_attempts);

    $this->state->expects($this->once())
      ->method('set')
      ->with(
        'auto_login_url.create_rate.123',
        $this->callback(function ($value) use ($existing_attempts) {
          // Should contain existing attempts plus new one.
          $expected = count($existing_attempts) + 1;
          return is_array($value) &&
                 count($value) === $expected;
        })
      );

    $this->rateLimiter->registerCreation(123);
  }

  /**
   * @covers ::registerCreation
   */
  public function testRegisterCreationWithMaxAttempts(): void {
    // Create 25 existing attempts (over the max of 20).
    $currentTime = time();
    $existing_attempts = [];
    for ($i = 25; $i > 0; $i--) {
      $existing_attempts[] = $currentTime - ($i * 60);
    }

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn($existing_attempts);

    $this->state->expects($this->once())
      ->method('set')
      ->with(
        'auto_login_url.create_rate.123',
        $this->callback(function ($value) {
          // Should be limited to reasonable number of attempts
          // Max: 20 + 1 new = 21.
          return is_array($value) && count($value) <= 21;
        })
      );

    $this->rateLimiter->registerCreation(123);
  }

  /**
   * @covers ::getRateLimitConfig
   */
  public function testGetRateLimitConfig(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_urls_per_user_per_hour')
      ->willReturn(15);

    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    $result = $this->rateLimiter->getRateLimitConfig();

    $expected = [
      'limit' => 15,
      'window' => 3600,
    ];

    $this->assertEquals($expected, $result);
  }

  /**
   * @covers ::getRateLimitConfig
   */
  public function testGetRateLimitConfigWithDefaults(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_urls_per_user_per_hour')
      ->willReturn(NULL);

    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    $result = $this->rateLimiter->getRateLimitConfig();

    $expected = [
    // Default value.
      'limit' => 10,
      'window' => 3600,
    ];

    $this->assertEquals($expected, $result);
  }

  /**
   * @covers ::getRemainingAttempts
   */
  public function testGetRemainingAttempts(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_urls_per_user_per_hour')
      ->willReturn(10);

    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    $currentTime = time();
    $attempts = [
    // Valid.
      $currentTime - 1800,
    // Valid.
      $currentTime - 600,
    // Valid.
      $currentTime - 60,
    ];

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn($attempts);

    $result = $this->rateLimiter->getRemainingAttempts(123);
    // 10 - 3 = 7
    $this->assertEquals(7, $result);
  }

  /**
   * @covers ::getRemainingAttempts
   */
  public function testGetRemainingAttemptsWhenAtLimit(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_urls_per_user_per_hour')
      ->willReturn(5);

    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    $currentTime = time();
    $attempts = array_fill(0, 5, $currentTime - 600);

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn($attempts);

    $result = $this->rateLimiter->getRemainingAttempts(123);
    $this->assertEquals(0, $result);
  }

  /**
   * @covers ::getRemainingAttempts
   */
  public function testGetRemainingAttemptsWhenOverLimit(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_urls_per_user_per_hour')
      ->willReturn(3);

    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    $currentTime = time();
    $attempts = array_fill(0, 5, $currentTime - 600);

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn($attempts);

    $result = $this->rateLimiter->getRemainingAttempts(123);
    // Should not go negative.
    $this->assertEquals(0, $result);
  }

  /**
   * @covers ::getRemainingAttempts
   */
  public function testGetRemainingAttemptsWithExpiredAttempts(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_urls_per_user_per_hour')
      ->willReturn(10);

    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    $currentTime = time();
    $attempts = [
    // Expired (2 hours ago)
      $currentTime - 7200,
    // Expired (1.5 hours ago)
      $currentTime - 5400,
    // Valid (30 minutes ago)
      $currentTime - 1800,
    // Valid (10 minutes ago)
      $currentTime - 600,
    ];

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn($attempts);

    $result = $this->rateLimiter->getRemainingAttempts(123);
    // 10 - 2 valid attempts = 8
    $this->assertEquals(8, $result);
  }

  /**
   * @covers ::clearUserLimit
   */
  public function testClearUserLimit(): void {
    $this->state->expects($this->once())
      ->method('delete')
      ->with('auto_login_url.create_rate.123');

    $this->rateLimiter->clearUserLimit(123);
  }

  /**
   * @covers ::clearAllLimits
   */
  public function testClearAllLimits(): void {
    $all_keys = [
      'auto_login_url.create_rate.123' => [],
      'auto_login_url.create_rate.456' => [],
      'other.state.key' => 'value',
      'auto_login_url.create_rate.789' => [],
    ];

    $this->state->method('getMultiple')
      ->with([])
      ->willReturn($all_keys);

    // Expect exactly 3 delete calls - one for each rate limiting key.
    $this->state->expects($this->exactly(3))
      ->method('delete');

    $this->rateLimiter->clearAllLimits();
  }

  /**
   * @covers ::getStatistics
   */
  public function testGetStatistics(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_urls_per_user_per_hour')
      ->willReturn(10);

    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    $currentTime = time();
    $all_keys = [
      'auto_login_url.create_rate.123' => [
    // Recent.
        $currentTime - 600,
    // Recent.
        $currentTime - 300,
      ],
      'auto_login_url.create_rate.456' => [
      // Recent.
        $currentTime - 1800,
      // Recent.
        $currentTime - 900,
      // Recent.
        $currentTime - 600,
      // Recent.
        $currentTime - 300,
      // Recent.
        $currentTime - 120,
      // Recent.
        $currentTime - 60,
      // Recent.
        $currentTime - 30,
      // Recent.
        $currentTime - 10,
      ],
      'auto_login_url.create_rate.789' => [
      // Old (expired)
        $currentTime - 7200,
      ],
      'other.key' => 'value',
    ];

    $this->state->method('getMultiple')
      ->with([])
      ->willReturn($all_keys);

    $this->state->method('get')
      ->willReturnMap([
        [
          'auto_login_url.create_rate.123',
          [],
          $all_keys['auto_login_url.create_rate.123'],
        ],
        [
          'auto_login_url.create_rate.456',
          [],
          $all_keys['auto_login_url.create_rate.456'],
        ],
        [
          'auto_login_url.create_rate.789',
          [],
          $all_keys['auto_login_url.create_rate.789'],
        ],
      ]);

    $result = $this->rateLimiter->getStatistics();

    $this->assertIsArray($result);
    $this->assertArrayHasKey('total_users_with_attempts', $result);
    $this->assertArrayHasKey('total_recent_attempts', $result);
    $this->assertArrayHasKey('users_near_limit', $result);
    $this->assertArrayHasKey('rate_limit', $result);
    $this->assertArrayHasKey('time_window_hours', $result);

    // Verify specific values.
    $this->assertEquals(3, $result['total_users_with_attempts']);
    $this->assertEquals(10, $result['rate_limit']);
    $this->assertEquals(1, $result['time_window_hours']);

    // Total recent attempts should be 10 (2 + 8 + 0 expired)
    $this->assertEquals(10, $result['total_recent_attempts']);
  }

  /**
   * @covers ::cleanupOldData
   */
  public function testCleanupOldData(): void {
    $currentTime = time();
    // 2 hours ago cutoff
    $cutoff = $currentTime - (3600 * 2);

    $all_keys = [
      'auto_login_url.create_rate.123' => [
    // Very old (should be removed)
        $cutoff - 3600,
    // Recent (should be kept)
        $currentTime - 600,
      ],
      'auto_login_url.create_rate.456' => [
      // Very old (should be removed)
        $cutoff - 7200,
      // Very old (should be removed)
        $cutoff - 3600,
      ],
      'auto_login_url.create_rate.789' => [
      // Recent (should be kept)
        $currentTime - 1800,
      // Recent (should be kept)
        $currentTime - 600,
      ],
      'other.key' => 'value',
    ];

    $this->state->method('getMultiple')
      ->with([])
      ->willReturn($all_keys);

    $this->state->method('get')
      ->willReturnMap([
        [
          'auto_login_url.create_rate.123',
          [],
          $all_keys['auto_login_url.create_rate.123'],
        ],
        [
          'auto_login_url.create_rate.456',
          [],
          $all_keys['auto_login_url.create_rate.456'],
        ],
        [
          'auto_login_url.create_rate.789',
          [],
          $all_keys['auto_login_url.create_rate.789'],
        ],
      ]);

    // Expect updates for keys that have data to keep.
    $this->state->expects($this->once())
      ->method('set')
      ->with(
        'auto_login_url.create_rate.123',
        $this->callback(function ($value) {
          // Should keep only the recent attempt.
          return is_array($value) && count($value) === 1;
        })
      );

    // Expect deletion for keys that have no recent data.
    $this->state->expects($this->once())
      ->method('delete')
      ->with('auto_login_url.create_rate.456');

    $result = $this->rateLimiter->cleanupOldData();
    $this->assertIsInt($result);
    $this->assertGreaterThanOrEqual(0, $result);
  }

  /**
   * Test edge case with no attempts.
   */
  public function testGetRemainingAttemptsWithNoAttempts(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_urls_per_user_per_hour')
      ->willReturn(10);

    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn([]);

    $result = $this->rateLimiter->getRemainingAttempts(123);
    $this->assertEquals(10, $result);
  }

  /**
   * Test check creation limit with no previous attempts.
   */
  public function testCheckCreationLimitWithNoAttempts(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_urls_per_user_per_hour')
      ->willReturn(10);

    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn([]);

    $this->state->expects($this->once())
      ->method('set')
      ->with(
        'auto_login_url.create_rate.123',
        $this->callback(function ($value) {
          return is_array($value) && count($value) === 1;
        })
      );

    $result = $this->rateLimiter->checkCreationLimit(123);
    $this->assertTrue($result);
  }

}
