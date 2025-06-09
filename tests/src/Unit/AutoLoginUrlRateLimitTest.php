<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Unit;

use Drupal\auto_login_url\AutoLoginUrlRateLimit;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Simplified unit tests for AutoLoginUrlRateLimit service.
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
   * @covers ::getRateLimitConfig
   */
  public function testGetRateLimitConfigWithDefaultValue(): void {
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
   * @covers ::getRateLimitConfig
   */
  public function testGetRateLimitConfigWithCustomValue(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_urls_per_user_per_hour')
      ->willReturn(25);

    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    $result = $this->rateLimiter->getRateLimitConfig();

    $expected = [
      'limit' => 25,
      'window' => 3600,
    ];

    $this->assertEquals($expected, $result);
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
   * @covers ::registerCreation
   */
  public function testRegisterCreationCallsState(): void {
    $this->state->method('get')
      ->with('auto_login_url.create_rate.123', [])
      ->willReturn([]);

    $this->state->expects($this->once())
      ->method('set')
      ->with('auto_login_url.create_rate.123', $this->isType('array'));

    $this->rateLimiter->registerCreation(123);
  }

  /**
   * @covers ::checkCreationLimit
   */
  public function testCheckCreationLimitWithEmptyHistory(): void {
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
      ->with('auto_login_url.create_rate.123', $this->isType('array'));

    $result = $this->rateLimiter->checkCreationLimit(123);
    $this->assertTrue($result);
  }

  /**
   * @covers ::getRemainingAttempts
   */
  public function testGetRemainingAttemptsWithEmptyHistory(): void {
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
   * @covers ::getStatistics
   */
  public function testGetStatisticsReturnsArray(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('max_urls_per_user_per_hour')
      ->willReturn(10);

    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    $this->state->method('getMultiple')
      ->with([])
      ->willReturn([]);

    $result = $this->rateLimiter->getStatistics();

    $this->assertIsArray($result);
    $this->assertArrayHasKey('total_users_with_attempts', $result);
    $this->assertArrayHasKey('total_recent_attempts', $result);
    $this->assertArrayHasKey('users_near_limit', $result);
    $this->assertArrayHasKey('rate_limit', $result);
    $this->assertArrayHasKey('time_window_hours', $result);
  }

  /**
   * @covers ::clearAllLimits
   */
  public function testClearAllLimitsWithNoData(): void {
    $this->state->method('getMultiple')
      ->with([])
      ->willReturn([]);

    // Should not call delete if no rate limiting keys exist.
    $this->state->expects($this->never())
      ->method('delete');

    $this->rateLimiter->clearAllLimits();
  }

  /**
   * @covers ::cleanupOldData
   */
  public function testCleanupOldDataReturnsInteger(): void {
    $this->state->method('getMultiple')
      ->with([])
      ->willReturn([]);

    $result = $this->rateLimiter->cleanupOldData();
    $this->assertIsInt($result);
    $this->assertGreaterThanOrEqual(0, $result);
  }

}
