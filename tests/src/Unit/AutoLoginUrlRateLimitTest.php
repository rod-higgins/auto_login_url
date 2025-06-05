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
   * Current timestamp for testing.
   */
  private int $currentTime;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->state = $this->createMock(StateInterface::class);
    // Fixed timestamp for testing.
    $this->currentTime = 1640995200;

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

    // Simulate 5 attempts within the last hour.
    $attempts = [
    // 30 minutes ago
      $this->currentTime - 1800,
    // 20 minutes ago
      $this->currentTime - 1200,
    // 10 minutes ago
      $this->currentTime - 600,
    // 5 minutes ago
      $this->currentTime - 300,
    // 1 minute ago
      $this->currentTime - 60,
    ];

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn($attempts);

    // Expect the state to be updated - simplified expectation.
    $this->state->expects($this->once())
      ->method('set')
      ->with('auto_login_url.create_rate.123', $this->isType('array'));

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

    // Simulate 5 attempts within the last hour (at the limit).
    $attempts = [
      $this->currentTime - 1800,
      $this->currentTime - 1200,
      $this->currentTime - 600,
      $this->currentTime - 300,
      $this->currentTime - 60,
    ];

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn($attempts);

    // When limit is exceeded, should still call set but return false.
    $this->state->expects($this->once())
      ->method('set')
      ->with('auto_login_url.create_rate.123', $this->isType('array'));

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
    $old_attempts = [
    // 2 hours ago (expired)
      $this->currentTime - 7200,
    // 1.5 hours ago (expired)
      $this->currentTime - 5400,
    // 30 minutes ago (valid)
      $this->currentTime - 1800,
    // 10 minutes ago (valid)
      $this->currentTime - 600,
    ];

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn($old_attempts);

    // Should filter out expired attempts and add new one.
    $this->state->expects($this->once())
      ->method('set')
      ->with('auto_login_url.create_rate.123', $this->isType('array'));

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
      ->with('auto_login_url.create_rate.123', $this->isType('array'));

    $result = $this->rateLimiter->checkCreationLimit(123);
    $this->assertTrue($result);
  }

  /**
   * @covers ::registerCreation
   */
  public function testRegisterCreation(): void {
    $existing_attempts = [
      $this->currentTime - 1800,
      $this->currentTime - 600,
    ];

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn($existing_attempts);

    $this->state->expects($this->once())
      ->method('set')
      ->with('auto_login_url.create_rate.123', $this->isType('array'));

    $this->rateLimiter->registerCreation(123);
  }

  /**
   * @covers ::registerCreation
   */
  public function testRegisterCreationWithMaxAttempts(): void {
    // Create 25 existing attempts (over the max of 20).
    $existing_attempts = [];
    for ($i = 25; $i > 0; $i--) {
      $existing_attempts[] = $this->currentTime - ($i * 60);
    }

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn($existing_attempts);

    $this->state->expects($this->once())
      ->method('set')
      ->with('auto_login_url.create_rate.123', $this->isType('array'));

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

    $attempts = [
    // Valid.
      $this->currentTime - 1800,
    // Valid.
      $this->currentTime - 600,
    // Valid.
      $this->currentTime - 60,
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

    $attempts = array_fill(0, 5, $this->currentTime - 600);

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

    $attempts = array_fill(0, 5, $this->currentTime - 600);

    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn($attempts);

    $result = $this->rateLimiter->getRemainingAttempts(123);
    // Should not go negative.
    $this->assertEquals(0, $result);
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

    $all_keys = [
      'auto_login_url.create_rate.123' => [
    // Recent.
        $this->currentTime - 600,
    // Recent.
        $this->currentTime - 300,
      ],
      'auto_login_url.create_rate.456' => [
      // Recent.
        $this->currentTime - 1800,
      // Recent.
        $this->currentTime - 900,
      // Recent.
        $this->currentTime - 600,
      // Recent.
        $this->currentTime - 300,
      // Recent.
        $this->currentTime - 120,
      // Recent.
        $this->currentTime - 60,
      // Recent.
        $this->currentTime - 30,
      // Recent (8 recent = 80% of 10)
        $this->currentTime - 10,
      ],
      'auto_login_url.create_rate.789' => [
      // Old.
        $this->currentTime - 7200,
      ],
      'other.key' => 'value',
    ];

    $this->state->method('getMultiple')
      ->with([])
      ->willReturn($all_keys);

    $this->state->method('get')
      ->willReturnMap([
        ['auto_login_url.create_rate.123', [], $all_keys['auto_login_url.create_rate.123']],
        ['auto_login_url.create_rate.456', [], $all_keys['auto_login_url.create_rate.456']],
        ['auto_login_url.create_rate.789', [], $all_keys['auto_login_url.create_rate.789']],
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
  }

  /**
   * @covers ::cleanupOldData
   */
  public function testCleanupOldData(): void {
    // 2 hours ago
    $cutoff = $this->currentTime - (3600 * 2);

    $all_keys = [
      'auto_login_url.create_rate.123' => [
    // Very old.
        $cutoff - 3600,
    // Recent.
        $this->currentTime - 600,
      ],
      'auto_login_url.create_rate.456' => [
      // Very old.
        $cutoff - 7200,
      // Very old.
        $cutoff - 3600,
      ],
      'auto_login_url.create_rate.789' => [
      // Recent.
        $this->currentTime - 1800,
      // Recent.
        $this->currentTime - 600,
      ],
      'other.key' => 'value',
    ];

    $this->state->method('getMultiple')
      ->with([])
      ->willReturn($all_keys);

    $this->state->method('get')
      ->willReturnMap([
        ['auto_login_url.create_rate.123', [], $all_keys['auto_login_url.create_rate.123']],
        ['auto_login_url.create_rate.456', [], $all_keys['auto_login_url.create_rate.456']],
        ['auto_login_url.create_rate.789', [], $all_keys['auto_login_url.create_rate.789']],
      ]);

    // Expect some update operations.
    $this->state->expects($this->atLeastOnce())
      ->method('set');

    $this->state->expects($this->atLeastOnce())
      ->method('delete');

    $result = $this->rateLimiter->cleanupOldData();
    $this->assertIsInt($result);
    $this->assertGreaterThanOrEqual(0, $result);
  }

}
