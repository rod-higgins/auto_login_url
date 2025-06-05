<?php

declare(strict_types=1);

namespace Drupal\auto_login_url;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\auto_login_url\Exception\AutoLoginUrlException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for creating auto login URLs.
 *
 * @package Drupal\auto_login_url
 */
final class AutoLoginUrlCreate {

  /**
   * Maximum attempts to generate a unique hash.
   */
  private const MAX_HASH_GENERATION_ATTEMPTS = 10;

  /**
   * The database connection.
   */
  private Connection $connection;

  /**
   * The config factory service.
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The Auto Login Url General service.
   */
  private AutoLoginUrlGeneral $autoLoginUrlGeneral;

  /**
   * The logger channel.
   */
  private LoggerChannelInterface $logger;

  /**
   * The rate limiting service.
   */
  private AutoLoginUrlRateLimit $rateLimiter;

  /**
   * The request stack service.
   */
  private RequestStack $requestStack;

  /**
   * Constructs an AutoLoginUrlCreate object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\auto_login_url\AutoLoginUrlGeneral $auto_login_url_general
   *   The Auto Login Url General service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\auto_login_url\AutoLoginUrlRateLimit $rate_limiter
   *   The rate limiting service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   */
  public function __construct(
    Connection $connection,
    ConfigFactoryInterface $config_factory,
    AutoLoginUrlGeneral $auto_login_url_general,
    LoggerChannelFactoryInterface $logger_factory,
    AutoLoginUrlRateLimit $rate_limiter,
    RequestStack $request_stack,
  ) {
    $this->connection = $connection;
    $this->configFactory = $config_factory;
    $this->autoLoginUrlGeneral = $auto_login_url_general;
    $this->logger = $logger_factory->get('auto_login_url');
    $this->rateLimiter = $rate_limiter;
    $this->requestStack = $request_stack;
  }

  /**
   * Creates an auto login URL for a user.
   *
   * @param int $uid
   *   The user ID.
   * @param string $destination
   *   The destination URL after login.
   * @param bool $absolute
   *   Whether to generate an absolute URL.
   *
   * @return string
   *   The auto login URL.
   *
   * @throws \Drupal\auto_login_url\Exception\AutoLoginUrlException
   *   Thrown when URL creation fails.
   */
  public function create(int $uid, string $destination, bool $absolute = FALSE): string {
    // Check rate limiting first.
    if (!$this->rateLimiter->checkCreationLimit($uid)) {
      $this->logger->warning('Rate limit exceeded for user @uid attempting to create auto login URL', [
        '@uid' => $uid,
      ]);
      throw new AutoLoginUrlException('Rate limit exceeded. Too many auto login URLs created recently.');
    }

    // Validate inputs.
    $this->validateCreateParameters($uid, $destination);

    try {
      $config = $this->configFactory->get('auto_login_url.settings');
      $token_length = (int) $config->get('token_length');

      // Generate unique hash.
      $hash_data = $this->generateUniqueHash($uid, $destination, $token_length);

      // Store in database with enhanced tracking.
      $this->storeHashInDatabase($uid, $hash_data['hash_db'], $destination);

      // Register successful creation for rate limiting.
      $this->rateLimiter->registerCreation($uid);

      // Generate and return URL.
      $url = Url::fromRoute(
        'auto_login_url.login',
        ['uid' => $uid, 'hash' => $hash_data['hash_token']],
        ['absolute' => $absolute]
      )->toString();

      $this->logger->info('Auto login URL created for user @uid with destination @dest', [
        '@uid' => $uid,
        '@dest' => substr($destination, 0, 100),
      ]);

      return $url;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create auto login URL for user @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
      throw new AutoLoginUrlException('Failed to create auto login URL: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Converts text by replacing links with auto login versions.
   *
   * @param int $uid
   *   The user ID.
   * @param string $text
   *   The text containing links to convert.
   *
   * @return string
   *   The text with converted auto login links.
   *
   * @throws \Drupal\auto_login_url\Exception\AutoLoginUrlException
   *   Thrown when conversion fails.
   */
  public function convertText(int $uid, string $text): string {
    if (!$this->autoLoginUrlGeneral->validateUserId($uid)) {
      throw new AutoLoginUrlException('Invalid user ID provided for text conversion');
    }

    try {
      global $base_root;

      if (empty($base_root)) {
        $this->logger->warning('Base root not available for text conversion');
        return $text;
      }

      // Pattern to match URLs but not images.
      $pattern = '/' . preg_quote($base_root, '/') . '\/[^\s"\'<>]*/';

      // Create converter object.
      $converter = new AutoLoginUrlTextConverter($uid, $this);

      // Replace URLs with auto login versions.
      $converted_text = preg_replace_callback(
        $pattern,
        [$converter, 'convertUrl'],
        $text
      );

      if ($converted_text === NULL) {
        throw new AutoLoginUrlException('Failed to process text for auto login conversion');
      }

      return $converted_text;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to convert text for user @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
      throw new AutoLoginUrlException('Failed to convert text: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Validates parameters for URL creation.
   *
   * @param int $uid
   *   The user ID.
   * @param string $destination
   *   The destination URL.
   *
   * @throws \Drupal\auto_login_url\Exception\AutoLoginUrlException
   *   Thrown when validation fails.
   */
  private function validateCreateParameters(int $uid, string $destination): void {
    if (!$this->autoLoginUrlGeneral->validateUserId($uid)) {
      throw new AutoLoginUrlException('Invalid or non-existent user ID: ' . $uid);
    }

    if (empty($destination) || strlen($destination) > 1000) {
      throw new AutoLoginUrlException('Invalid destination URL');
    }

    // Basic URL validation.
    $destination = trim($destination);
    if ($destination !== filter_var($destination, FILTER_SANITIZE_URL)) {
      throw new AutoLoginUrlException('Destination contains invalid characters');
    }
  }

  /**
   * Generates a unique hash for the auto login URL.
   *
   * @param int $uid
   *   The user ID.
   * @param string $destination
   *   The destination URL.
   * @param int $token_length
   *   The desired token length.
   *
   * @return array
   *   Array containing 'hash_token' and 'hash_db'.
   *
   * @throws \Drupal\auto_login_url\Exception\AutoLoginUrlException
   *   Thrown when unique hash generation fails.
   */
  private function generateUniqueHash(int $uid, string $destination, int $token_length): array {
    $auto_login_url_secret = $this->autoLoginUrlGeneral->getSecret();
    $password = $this->autoLoginUrlGeneral->getUserHash($uid);

    // Create cryptographic key.
    $key = Settings::getHashSalt() . $auto_login_url_secret . $password;

    for ($attempt = 0; $attempt < self::MAX_HASH_GENERATION_ATTEMPTS; $attempt++) {
      // Generate cryptographically secure random data.
      $entropy = $this->generateSecureEntropy($uid, $destination, $attempt);

      // Generate hash token.
      $hash_token = $this->generateHashToken($entropy, $key, $token_length);

      // Generate database hash for storage.
      $hash_db = Crypt::hmacBase64($hash_token, $key);

      // Check uniqueness.
      if ($this->isHashUnique($hash_db)) {
        return [
          'hash_token' => $hash_token,
          'hash_db' => $hash_db,
        ];
      }
    }

    throw new AutoLoginUrlException('Failed to generate unique hash after ' . self::MAX_HASH_GENERATION_ATTEMPTS . ' attempts');
  }

  /**
   * Generates secure entropy for hash creation.
   *
   * @param int $uid
   *   The user ID.
   * @param string $destination
   *   The destination URL.
   * @param int $attempt
   *   The attempt number.
   *
   * @return string
   *   The entropy string.
   */
  private function generateSecureEntropy(int $uid, string $destination, int $attempt): string {
    try {
      // Use random_bytes for cryptographic security.
      $random_bytes = random_bytes(32);
      $entropy_parts = [
        $uid,
        $destination,
        time(),
        $attempt,
        bin2hex($random_bytes),
        uniqid('', TRUE),
      // Add process ID for additional entropy.
        getmypid(),
      ];

      return implode('|', $entropy_parts);
    }
    catch (\Exception $e) {
      // Enhanced fallback with better logging.
      $this->logger->warning('random_bytes failed, using fallback entropy generation: @message', [
        '@message' => $e->getMessage(),
      ]);

      $entropy_parts = [
        $uid,
        $destination,
      // Use high-resolution time.
        hrtime(TRUE),
        $attempt,
        uniqid('', TRUE),
        mt_rand(),
        memory_get_usage(),
      ];

      return implode('|', $entropy_parts);
    }
  }

  /**
   * Generates a hash token from entropy.
   *
   * @param string $entropy
   *   The entropy string.
   * @param string $key
   *   The cryptographic key.
   * @param int $token_length
   *   The desired token length.
   *
   * @return string
   *   The generated hash token.
   */
  private function generateHashToken(string $entropy, string $key, int $token_length): string {
    $hash = Crypt::hmacBase64($entropy, $key);

    // Ensure we don't exceed the hash length.
    $max_length = min($token_length, strlen($hash));

    return substr($hash, 0, $max_length);
  }

  /**
   * Checks if a hash is unique in the database.
   *
   * @param string $hash_db
   *   The database hash to check.
   *
   * @return bool
   *   TRUE if unique, FALSE if exists.
   */
  private function isHashUnique(string $hash_db): bool {
    try {
      $result = $this->connection->select('auto_login_url', 'alu')
        ->fields('alu', ['hash'])
        ->condition('hash', $hash_db)
        ->range(0, 1)
        ->execute()
        ->fetchField();

      return $result === FALSE;
    }
    catch (\Exception $e) {
      $this->logger->error('Database error checking hash uniqueness: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Stores the hash in the database with enhanced tracking.
   *
   * @param int $uid
   *   The user ID.
   * @param string $hash_db
   *   The database hash.
   * @param string $destination
   *   The destination URL.
   *
   * @throws \Exception
   *   Thrown when database insertion fails.
   */
  private function storeHashInDatabase(int $uid, string $hash_db, string $destination): void {
    $request = $this->requestStack->getCurrentRequest();

    $this->connection->insert('auto_login_url')
      ->fields([
        'uid' => $uid,
        'hash' => $hash_db,
        'destination' => $destination,
        'timestamp' => time(),
        'ip_address' => $request ? $request->getClientIp() : NULL,
        'user_agent' => $request ? substr($request->headers->get('User-Agent', ''), 0, 255) : NULL,
      ])
      ->execute();
  }

}
