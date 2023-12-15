<?php

namespace Drupal\auto_login_url;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;

/**
 * Class AutoLoginUrlCreate.
 *
 * @package Drupal\auto_login_url
 */
class AutoLoginUrlCreate {

  /**
   * Drupal\Core\Database\Connection definition.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Auto Login Url General service.
   *
   * @var \Drupal\auto_login_url\AutoLoginUrlGeneral
   */
  protected $autoLoginUrlGeneral;

  /**
   * Constructor.
   */
  public function __construct(Connection $connection, ConfigFactoryInterface $config_factory, AutoLoginUrlGeneral $auto_login_url_general) {
    $this->connection = $connection;
    $this->configFactory = $config_factory;
    $this->autoLoginUrlGeneral = $auto_login_url_general;
  }

  /**
   * Create an auto login hash on demand.
   *
   * @param int $uid
   *   User id.
   * @param string $destination
   *   Destination URL.
   * @param bool $absolute
   *   Absolute or relative link.
   *
   * @return string
   *   Auto Login URL.
   */
  public function create($uid, $destination, $absolute = FALSE) {
    $config = $this->configFactory->get('auto_login_url.settings');

    // Get ALU secret.
    $auto_login_url_secret = $this->autoLoginUrlGeneral->getSecret();

    // Get user password.
    $password = $this->autoLoginUrlGeneral->getUserHash($uid);

    // Create key.
    $key = Settings::getHashSalt() . $auto_login_url_secret . $password;

    // Repeat until the hash that is saved in DB is unique.
    $hash_helper = 0;

    do {
      $data = $uid . microtime(TRUE) . $destination . $hash_helper;

      // Generate hash.
      $hash = Crypt::hmacBase64($data, $key);

      // Get substring.
      $hash = substr($hash, 0, $config->get('token_length'));

      // Generate hash to save to DB.
      $hash_db = Crypt::hmacBase64($hash, $key);

      // Check hash is unique.
      $result = $this->connection->select('auto_login_url', 'alu')
        ->fields('alu', ['hash'])
        ->condition('alu.hash', $hash_db)
        ->execute()
        ->fetchAssoc();

      // Increment value in case there will be a next iteration.
      $hash_helper++;

    } while (isset($result['hash']));

    // Insert a new hash.
    $this->connection->insert('auto_login_url')
      ->fields(['uid', 'hash', 'destination', 'timestamp'])
      ->values([
        'uid' => $uid,
        'hash' => $hash_db,
        'destination' => $destination,
        'timestamp' => time(),
      ])
      ->execute();

    return Url::fromRoute('auto_login_url.login', ['uid' => $uid, 'hash' => $hash], ['absolute' => $absolute])->toString();
  }

  /**
   * Convert a whole text (E.g. mail with autologin links).
   *
   * @param int $uid
   *   User id.
   * @param string $text
   *   Text to change links to.
   *
   * @return string
   *   The text with changed links.
   */
  public function convertText($uid, $text) {

    global $base_root;
    // A pattern to convert links, but not images.
    // I am not very sure about that.
    $pattern = '/' . str_replace('/', '\\/', $base_root) . '\\/[^\s^"^\']*/';

    // Create a new object and pass the uid.
    $current_conversion = new AutoLoginUrlConvertTextClass($uid);

    // Replace text with regex/callback.
    $text = preg_replace_callback(
      $pattern,
      [&$current_conversion, 'replace'],
      $text);

    return $text;
  }

}
