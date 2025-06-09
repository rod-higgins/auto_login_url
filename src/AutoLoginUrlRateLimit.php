<?php

declare(strict_types=1);

namespace Drupal\auto_login_url;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Rate limiting service for auto login URLs.
 *
 * @package Drupal\auto_login_url
 */
class AutoLoginUrlRateLimit {

  /**
   * Default rate limit (URLs per hour per user).
   */
  private const DEFAULT_RATE_LIMIT = 10;

  /**
   * Default time window (1 hour in seconds).
   */
  private const DEFAULT_TIME_WINDOW = 3600;

  /**
   * Maximum attempts to store (to prevent memory issues).
   */
  private const MAX_STORED_ATTEMPTS = 20;

  /**
   * The config factory service.
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The state service.
   */
  private StateInterface $state;

  /**
   * Constructs an AutoLoginUrlRateLimit object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state) {
    $this->configFactory = $config_factory;
    $this->state = $state;
  }

  /**
   * Check if creation rate limit is exceeded for a user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return bool
   *   TRUE if within limits, FALSE if exceeded.
   */
  public function checkCreationLimit(int $uid): bool {
    $config = $this->configFactory->get('auto_login_url.settings');
    $limit = (int) $config->get('max_urls_per_user_per_hour') ?: self::DEFAULT_RATE_LIMIT;
    $window = self::DEFAULT_TIME_WINDOW;

    $key = $this->getStateKey($uid);
    $attempts = $this->state->get($key, []);

    // Clean old attempts outside the time window.
    $cutoff = time() - $window;
    $attempts = array_filter($attempts, fn($timestamp) => $timestamp > $cutoff);

    // Update state with cleaned attempts.
    $this->state->set($key, $attempts);

    return count($attempts) < $limit;
  }

  /**
   * Register a creation attempt for rate limiting.
   *
   * @param int $uid
   *   The user ID.
   */
  public function registerCreation(int $uid): void {
    $key = $this->getStateKey($uid);
    $attempts = $this->state->get($key, []);
    $attempts[] = time();

    // Keep only the most recent attempts to prevent memory issues.
    if (count($attempts) > self::MAX_STORED_ATTEMPTS) {
      $attempts = array_slice($attempts, -self::MAX_STORED_ATTEMPTS);
    }

    $this->state->set($key, $attempts);
  }

  /**
   * Get the current rate limit configuration.
   *
   * @return array
   *   Array containing 'limit' and 'window' values.
   */
  public function getRateLimitConfig(): array {
    $config = $this->configFactory->get('auto_login_url.settings');

    return [
      'limit' => (int) $config->get('max_urls_per_user_per_hour') ?: self::DEFAULT_RATE_LIMIT,
      'window' => self::DEFAULT_TIME_WINDOW,
    ];
  }

  /**
   * Get the remaining attempts for a user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return int
   *   The number of remaining attempts within the current window.
   */
  public function getRemainingAttempts(int $uid): int {
    $config = $this->getRateLimitConfig();
    $key = $this->getStateKey($uid);
    $attempts = $this->state->get($key, []);

    // Clean old attempts.
    $cutoff = time() - $config['window'];
    $recent_attempts = array_filter($attempts, fn($timestamp) => $timestamp > $cutoff);

    return max(0, $config['limit'] - count($recent_attempts));
  }

  /**
   * Clear rate limiting data for a user (administrative function).
   *
   * @param int $uid
   *   The user ID.
   */
  public function clearUserLimit(int $uid): void {
    $key = $this->getStateKey($uid);
    $this->state->delete($key);
  }

  /**
   * Clear all rate limiting data (administrative function).
   */
  public function clearAllLimits(): void {
    // Get all state keys and delete those that match our pattern.
    $keys = $this->state->getMultiple([]);
    foreach ($keys as $key => $value) {
      if (str_starts_with($key, 'auto_login_url.create_rate.')) {
        $this->state->delete($key);
      }
    }
  }

  /**
   * Get statistics about rate limiting usage.
   *
   * @return array
   *   Array containing rate limiting statistics.
   */
  public function getStatistics(): array {
    $keys = $this->state->getMultiple([]);
    $rate_limit_keys = array_filter(
      array_keys($keys),
      fn($key) => str_starts_with($key, 'auto_login_url.create_rate.')
    );

    $total_users_with_attempts = count($rate_limit_keys);
    $total_attempts = 0;
    $users_near_limit = 0;

    $config = $this->getRateLimitConfig();
    $cutoff = time() - $config['window'];

    foreach ($rate_limit_keys as $key) {
      $attempts = $this->state->get($key, []);
      $recent_attempts = array_filter($attempts, fn($timestamp) => $timestamp > $cutoff);
      $total_attempts += count($recent_attempts);

      // Count users who are at 80% or more of their limit.
      if (count($recent_attempts) >= ($config['limit'] * 0.8)) {
        $users_near_limit++;
      }
    }

    return [
      'total_users_with_attempts' => $total_users_with_attempts,
      'total_recent_attempts' => $total_attempts,
      'users_near_limit' => $users_near_limit,
      'rate_limit' => $config['limit'],
      'time_window_hours' => $config['window'] / 3600,
    ];
  }

  /**
   * Cleanup old rate limiting data (maintenance function).
   *
   * This can be called during cron to clean up old state data.
   *
   * @return int
   *   The number of user records cleaned up.
   */
  public function cleanupOldData(): int {
    $keys = $this->state->getMultiple([]);
    $rate_limit_keys = array_filter(
      array_keys($keys),
      fn($key) => str_starts_with($key, 'auto_login_url.create_rate.')
    );

    $cleaned_up = 0;
    // Clean data older than 2 hours.
    $cutoff = time() - (self::DEFAULT_TIME_WINDOW * 2);

    foreach ($rate_limit_keys as $key) {
      $attempts = $this->state->get($key, []);
      $recent_attempts = array_filter($attempts, fn($timestamp) => $timestamp > $cutoff);

      if (empty($recent_attempts)) {
        // No recent attempts, delete the entire record.
        $this->state->delete($key);
        $cleaned_up++;
      }
      elseif (count($recent_attempts) < count($attempts)) {
        // Some old attempts, update with only recent ones.
        $this->state->set($key, array_values($recent_attempts));
      }
    }

    return $cleaned_up;
  }

  /**
   * Generates the state key for a user's rate limiting data.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return string
   *   The state key.
   */
  private function getStateKey(int $uid): string {
    return "auto_login_url.create_rate.{$uid}";
  }

}
