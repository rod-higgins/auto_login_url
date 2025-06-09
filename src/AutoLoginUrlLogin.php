<?php

namespace Drupal\auto_login_url;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\UserSessionInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\user\UserAuthenticationInterface;
use Drupal\user\UserInterface;

/**
 * Service for handling auto login URL authentication.
 */
class AutoLoginUrlLogin {

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\auto_login_url\AutoLoginUrlGeneral $autoLoginUrlGeneral
   *   The general service.
   * @param \Drupal\user\UserAuthenticationInterface $userAuthentication
   *   The user authentication service.
   * @param \Drupal\Core\Session\UserSessionInterface $currentUser
   *   The current user session.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    Connection $connection,
    AutoLoginUrlGeneral $autoLoginUrlGeneral,
    UserAuthenticationInterface $userAuthentication,
    UserSessionInterface $currentUser,
    LoggerChannelFactoryInterface $loggerFactory,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->configFactory = $configFactory;
    $this->connection = $connection;
    $this->autoLoginUrlGeneral = $autoLoginUrlGeneral;
    $this->userAuthentication = $userAuthentication;
    $this->currentUser = $currentUser;
    $this->logger = $loggerFactory->get('auto_login_url');
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Attempts to log in a user with the provided hash.
   *
   * @param int $uid
   *   The user ID.
   * @param string $hash
   *   The authentication hash.
   *
   * @return string|false
   *   The destination URL on success, FALSE on failure.
   */
  public function login(int $uid, string $hash): string|false {
    // Validate inputs.
    if (!$this->validateLoginParameters($uid, $hash)) {
      return FALSE;
    }

    try {
      // Check if hash exists and is valid.
      $login_data = $this->validateAndRetrieveLoginData($uid, $hash);
      if ($login_data === FALSE) {
        return FALSE;
      }

      // Check if token has expired.
      if ($this->isTokenExpired($login_data['timestamp'])) {
        $this->logger->warning('Expired auto login token used for user @uid', ['@uid' => $uid]);
        $this->deleteLoginRecord($login_data['id']);
        return FALSE;
      }

      // Optional IP validation (if enabled in config).
      if (!$this->validateIpAddress($login_data)) {
        return FALSE;
      }

      // Load and validate user account.
      $account = $this->loadAndValidateUser($uid);
      if ($account === FALSE) {
        return FALSE;
      }

      // Perform the login.
      $this->performUserLogin($account);

      // Log usage for analytics (if enabled).
      $this->logUrlUsage($login_data);

      // Handle post-login cleanup.
      $this->handlePostLoginCleanup($login_data['id']);

      // Generate destination URL.
      $destination = $this->generateDestinationUrl($login_data['destination']);

      $this->logger->info('Successful auto login for user @uid to destination @dest', [
        '@uid' => $uid,
        '@dest' => substr($destination, 0, 100),
      ]);

      return $destination;
    }
    catch (\Exception $e) {
      $this->logger->error('Auto login failed for user @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Validates login parameters.
   *
   * @param int $uid
   *   The user ID.
   * @param string $hash
   *   The hash token.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  private function validateLoginParameters(int $uid, string $hash): bool {
    if (!$this->autoLoginUrlGeneral->validateUserId($uid)) {
      $this->logger->warning('Invalid user ID @uid attempted for auto login', ['@uid' => $uid]);
      return FALSE;
    }

    if (!$this->autoLoginUrlGeneral->validateHashFormat($hash)) {
      $this->logger->warning('Invalid hash format attempted for user @uid', ['@uid' => $uid]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Validates hash and retrieves login data from database.
   */
  private function validateAndRetrieveLoginData(int $uid, string $hash): array|false {
    $start_time = microtime(TRUE);

    try {
      // Generate the key for hash verification.
      $auto_login_url_secret = $this->autoLoginUrlGeneral->getSecret();
      $password = $this->autoLoginUrlGeneral->getUserHash($uid);
      $key = Settings::getHashSalt() . $auto_login_url_secret . $password;

      // Generate expected database hash.
      $expected_hash_db = Crypt::hmacBase64($hash, $key);

      // Query database for matching record.
      $result = $this->connection->select('auto_login_url', 'a')
        ->fields('a', ['id', 'uid', 'destination', 'timestamp', 'ip_address'])
        ->condition('uid', $uid)
        ->condition('hash', $expected_hash_db)
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();

      $processing_time = round((microtime(TRUE) - $start_time) * 1000, 2);

      if (empty($result)) {
        $this->logger->warning('No matching auto login record found for user @uid (processing time: @time ms)', [
          '@uid' => $uid,
          '@time' => $processing_time,
        ]);
        return FALSE;
      }

      // Enhanced security: Check if request IP matches creation IP (optional).
      $current_ip = $this->autoLoginUrlGeneral->getClientIp();
      if (!empty($result['ip_address']) && $result['ip_address'] !== $current_ip) {
        $this->logger->security('Auto login IP mismatch for user @uid: created from @create_ip, used from @current_ip', [
          '@uid' => $uid,
          '@create_ip' => $result['ip_address'],
          '@current_ip' => $current_ip,
        ]);
        // Note: Don't fail here as users may legitimately change networks.
      }

      // Use timing-safe comparison for additional security.
      $stored_hash = $this->getStoredHash($result['id']);
      if ($stored_hash === FALSE || !hash_equals($stored_hash, $expected_hash_db)) {
        $this->logger->warning('Hash verification failed for user @uid (processing time: @time ms)', [
          '@uid' => $uid,
          '@time' => $processing_time,
        ]);
        return FALSE;
      }

      $this->logger->info('Successful hash validation for user @uid (processing time: @time ms)', [
        '@uid' => $uid,
        '@time' => $processing_time,
      ]);

      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('Database error during login validation: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Validates IP address if IP validation is enabled.
   *
   * @param array $login_data
   *   The login data array.
   *
   * @return bool
   *   TRUE if validation passes, FALSE otherwise.
   */
  private function validateIpAddress(array $login_data): bool {
    $config = $this->configFactory->get('auto_login_url.settings');

    // Skip IP validation if not enabled or no IP stored.
    if (!$config->get('validate_ip_address') || empty($login_data['ip_address'])) {
      return TRUE;
    }

    $current_ip = $this->autoLoginUrlGeneral->getClientIp();

    if ($login_data['ip_address'] !== $current_ip) {
      $this->logger->warning('IP address validation failed for auto login. Expected: @expected, Got: @actual, User: @uid', [
        '@expected' => $login_data['ip_address'],
        '@actual' => $current_ip,
        '@uid' => $login_data['uid'],
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Logs URL usage for analytics if enabled.
   *
   * @param array $login_data
   *   The login data array.
   */
  private function logUrlUsage(array $login_data): void {
    $config = $this->configFactory->get('auto_login_url.settings');

    // Skip logging if analytics not enabled.
    if (!$config->get('enable_usage_analytics')) {
      return;
    }

    try {
      // Check if analytics table exists.
      if (!$this->connection->schema()->tableExists('auto_login_url_usage')) {
        return;
      }

      // Insert usage record for analytics.
      $this->connection->insert('auto_login_url_usage')
        ->fields([
          'original_id' => $login_data['id'],
          'uid' => $login_data['uid'],
          'used_timestamp' => time(),
          'ip_address' => $this->autoLoginUrlGeneral->getClientIp(),
        ])
        ->execute();
    }
    catch (\Exception $e) {
      // Don't fail login if logging fails.
      $this->logger->warning('Failed to log URL usage: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Retrieves stored hash for timing-safe comparison.
   *
   * @param string $record_id
   *   The record ID.
   *
   * @return string|false
   *   The stored hash or FALSE on failure.
   */
  private function getStoredHash(string $record_id): string|false {
    try {
      return $this->connection->select('auto_login_url', 'a')
        ->fields('a', ['hash'])
        ->condition('id', $record_id)
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to retrieve stored hash: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Checks if a token has expired.
   *
   * @param string $timestamp
   *   The token creation timestamp.
   *
   * @return bool
   *   TRUE if expired, FALSE otherwise.
   */
  private function isTokenExpired(string $timestamp): bool {
    $config = $this->configFactory->get('auto_login_url.settings');
    $expiration = (int) $config->get('expiration');

    return (time() - (int) $timestamp) > $expiration;
  }

  /**
   * Loads and validates a user account.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return \Drupal\user\UserInterface|false
   *   The user account or FALSE on failure.
   */
  private function loadAndValidateUser(int $uid): UserInterface|false {
    try {
      $user_storage = $this->entityTypeManager->getStorage('user');
      $account = $user_storage->load($uid);

      if (!$account instanceof UserInterface) {
        $this->logger->warning('Failed to load user account @uid', ['@uid' => $uid]);
        return FALSE;
      }

      if ($account->isBlocked()) {
        $this->logger->warning('Attempted auto login for blocked user @uid', ['@uid' => $uid]);
        return FALSE;
      }

      if (!$account->isActive()) {
        $this->logger->warning('Attempted auto login for inactive user @uid', ['@uid' => $uid]);
        return FALSE;
      }

      return $account;
    }
    catch (\Exception $e) {
      $this->logger->error('Error loading user @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Performs the user login using modern Drupal APIs.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account to log in.
   */
  private function performUserLogin(UserInterface $account): void {
    // Use the modern authentication service instead of deprecated function.
    $this->userAuthentication->finalize($account);

    // Update user's last login timestamp using entity API.
    $account->setLastLoginTime(time());
    $account->save();
  }

  /**
   * Handles post-login cleanup tasks.
   *
   * @param string $record_id
   *   The login record ID.
   */
  private function handlePostLoginCleanup(string $record_id): void {
    $config = $this->configFactory->get('auto_login_url.settings');

    // Delete the login record if configured to do so.
    if ($config->get('delete')) {
      $this->deleteLoginRecord($record_id);
    }
  }

  /**
   * Deletes a login record from the database.
   *
   * @param string $record_id
   *   The record ID to delete.
   */
  private function deleteLoginRecord(string $record_id): void {
    try {
      $this->connection->delete('auto_login_url')
        ->condition('id', $record_id)
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete login record @id: @message', [
        '@id' => $record_id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Generates the destination URL after login.
   *
   * @param string $destination
   *   The raw destination string.
   *
   * @return string
   *   The formatted destination URL.
   */
  private function generateDestinationUrl(string $destination): string {
    $destination = urldecode($destination);

    // Check if it's already an absolute URL.
    if (str_starts_with($destination, 'http://') || str_starts_with($destination, 'https://')) {
      return $destination;
    }

    // Generate absolute internal URL.
    try {
      // Remove leading slash if present for Url::fromUri.
      $internal_path = ltrim($destination, '/');
      return Url::fromUri('internal:/' . $internal_path, ['absolute' => TRUE])->toString();
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to generate destination URL for @dest, using front page: @message', [
        '@dest' => $destination,
        '@message' => $e->getMessage(),
      ]);

      // Fallback to front page.
      return Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
    }
  }

  /**
   * Cleans up expired tokens from the database.
   *
   * This method can be called during cron or other maintenance tasks.
   *
   * @return int
   *   The number of expired tokens removed.
   */
  public function cleanupExpiredTokens(): int {
    $config = $this->configFactory->get('auto_login_url.settings');
    $expiration = (int) $config->get('expiration');
    $cutoff_time = time() - $expiration;

    try {
      $deleted = $this->connection->delete('auto_login_url')
        ->condition('timestamp', $cutoff_time, '<=')
        ->execute();

      if ($deleted > 0) {
        $this->logger->info('Cleaned up @count expired auto login tokens', ['@count' => $deleted]);
      }

      return $deleted;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to cleanup expired tokens: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

}
