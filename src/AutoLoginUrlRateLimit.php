<?php

declare(strict_types=1);

namespace Drupal\auto_login_url;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Rate limiting service for auto login URLs.
 */
final class AutoLoginUrlRateLimit {

  private ConfigFactoryInterface $configFactory;
  private StateInterface $state;

  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state) {
    $this->configFactory = $config_factory;
    $this->state = $state;
  }

  /**
   * Check if creation rate limit is exceeded for a user.
   */
  public function checkCreationLimit(int $uid): bool {
    $limit = 10; // 10 URLs per hour per user
    $window = 3600; // 1 hour
    
    $key = "auto_login_url.create_rate.{$uid}";
    $attempts = $this->state->get($key, []);
    
    // Clean old attempts
    $cutoff = time() - $window;
    $attempts = array_filter($attempts, fn($timestamp) => $timestamp > $cutoff);
    
    return count($attempts) < $limit;
  }

  /**
   * Register a creation attempt.
   */
  public function registerCreation(int $uid): void {
    $key = "auto_login_url.create_rate.{$uid}";
    $attempts = $this->state->get($key, []);
    $attempts[] = time();
    
    // Keep only last 20 attempts to prevent memory issues
    if (count($attempts) > 20) {
      $attempts = array_slice($attempts, -20);
    }
    
    $this->state->set($key, $attempts);
  }
}