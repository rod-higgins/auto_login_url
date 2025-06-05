<?php

declare(strict_types=1);

namespace Drupal\auto_login_url;

use Drupal\Component\Utility\Random;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;

/**
 * General utilities service for Auto Login URL module.
 *
 * @package Drupal\auto_login_url
 */
final class AutoLoginUrlGeneral {

  /**
   * The flood identifier for failed login attempts.
   */
  public const FLOOD_IDENTIFIER = 'auto_login_url.failed_login_ip';

  /**
   * Maximum attempts to generate unique hash.
   */
  private const MAX_HASH_ATTEMPTS = 10;

  /**
   * The config factory service.
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The flood service.
   */
  private FloodInterface $flood;

  /**
   * The logger channel.
   */
  private LoggerChannelInterface $logger;

  /**
   * The request stack.
   */
  private RequestStack $requestStack;

  /**
   * The entity type manager.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs an AutoLoginUrlGeneral object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    FloodInterface $flood,
    LoggerChannelFactoryInterface $logger_factory,
    RequestStack $request_stack,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->configFactory = $config_factory;
    $this->flood = $flood;
    $this->logger = $logger_factory->get('auto_login_url');
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Checks if the current IP is blocked by flood protection.
   *
   * @return bool
   *   TRUE if the IP is blocked, FALSE otherwise.
   */
  public function checkFlood(): bool {
    $flood_config = $this->configFactory->get('user.flood');
    $ip_limit = (int) $flood_config->get('ip_limit');
    $ip_window = (int) $flood_config->get('ip_window');

    return !$this->flood->isAllowed(
      self::FLOOD_IDENTIFIER,
      $ip_limit,
      $ip_window
    );
  }

  /**
   * Registers a flood event for the current IP.
   *
   * @param string $hash
   *   The hash that was attempted.
   */
  public function registerFlood(string $hash): void {
    $flood_config = $this->configFactory->get('user.flood');
    $ip_window = (int) $flood_config->get('ip_window');

    // Register flood event.
    $this->flood->register(self::FLOOD_IDENTIFIER, $ip_window);

    // Log the failed attempt.
    $request = $this->requestStack->getCurrentRequest();
    $client_ip = $request instanceof Request ? $request->getClientIp() : 'unknown';

    $this->logger->error(
      'Failed Auto Login URL attempt from IP @ip with hash @hash',
      [
        '@ip' => $client_ip ?: 'unknown',
        '@hash' => substr($hash, 0, 8) . '...',
      ]
    );
  }

  /**
   * Gets or creates the secret key for auto login URLs.
   *
   * @return string
   *   The secret key.
   */
  public function getSecret(): string {
    $config = $this->configFactory->get('auto_login_url.settings');
    $secret = $config->get('secret');

    // Create secret if it doesn't exist.
    if (empty($secret)) {
      $secret = $this->generateSecureSecret();
      
      $this->configFactory->getEditable('auto_login_url.settings')
        ->set('secret', $secret)
        ->save();

      $this->logger->notice('Auto Login URL secret key was generated.');
    }

    return $secret;
  }

  /**
   * Gets the password hash for a user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return string
   *   The user's password hash, or empty string if user doesn't exist.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getUserHash(int $uid): string {
    if ($uid <= 0) {
      return '';
    }

    $user_storage = $this->entityTypeManager->getStorage('user');
    $user_exists = $user_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();

    if (empty($user_exists)) {
      return '';
    }

    $user = $user_storage->load($uid);
    if (!$user instanceof UserInterface) {
      return '';
    }

    $pass_field = $user->get('pass');
    if ($pass_field->isEmpty()) {
      return '';
    }

    return $pass_field->value ?? '';
  }

  /**
   * Validates that a user ID is valid and the user exists.
   *
   * @param int $uid
   *   The user ID to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  public function validateUserId(int $uid): bool {
    if ($uid <= 0) {
      return FALSE;
    }

    try {
      $user_storage = $this->entityTypeManager->getStorage('user');
      $exists = $user_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $uid)
        ->condition('status', 1)
        ->range(0, 1)
        ->execute();

      return !empty($exists);
    }
    catch (\Exception $e) {
      $this->logger->error('Error validating user ID @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Validates a hash token format.
   *
   * @param string $hash
   *   The hash to validate.
   *
   * @return bool
   *   TRUE if the hash format is valid, FALSE otherwise.
   */
  public function validateHashFormat(string $hash): bool {
    // Hash should be alphanumeric and of reasonable length.
    if (empty($hash) || strlen($hash) < 8 || strlen($hash) > 128) {
      return FALSE;
    }

    // Only allow base64 URL-safe characters.
    return preg_match('/^[A-Za-z0-9_-]+$/', $hash) === 1;
  }

  /**
   * Generates a cryptographically secure secret.
   *
   * @return string
   *   A secure random secret.
   */
  private function generateSecureSecret(): string {
    try {
      // Use random_bytes for cryptographic security.
      $random_bytes = random_bytes(48);
      return base64_encode($random_bytes);
    }
    catch (\Exception $e) {
      // Fallback to Drupal's Random class if random_bytes fails.
      $this->logger->warning('Failed to generate secret with random_bytes, falling back to Random class: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      $random_generator = new Random();
      return $random_generator->name(64);
    }
  }

  /**
   * Clears flood records for the current IP.
   *
   * This should only be used in specific circumstances like testing.
   */
  public function clearFlood(): void {
    $this->flood->clear(self::FLOOD_IDENTIFIER);
  }

  /**
   * Gets the current request's IP address safely.
   *
   * @return string
   *   The client IP address or 'unknown' if not available.
   */
  public function getClientIp(): string {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request) {
      return 'unknown';
    }

    $ip = $request->getClientIp();
    return $ip ?: 'unknown';
  }

}