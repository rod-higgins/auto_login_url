<?php

namespace Drupal\auto_login_url;

use Drupal\auto_login_url\AutoLoginUrlGeneral;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Drupal\Core\Site\Settings;
use Drupal\user\Entity\User;

/**
 * Class AutoLoginUrlLogin.
 *
 * @package Drupal\auto_login_url
 */
class AutoLoginUrlLogin {

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The Auto Login Url General service.
   *
   * @var \Drupal\auto_login_url\AutoLoginUrlGeneral
   */
  protected $autoLoginUrlGeneral;

  /**
   * Constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Connection $connection, AutoLoginUrlGeneral $auto_login_url_general) {
    $this->configFactory = $config_factory;
    $this->connection = $connection;
    $this->autoLoginUrlGeneral = $auto_login_url_general;
  }

  /**
   * Get destination URL for autologin hash.
   *
   * @param int $uid
   *   User id.
   * @param string $hash
   *   Hash string.
   *
   * @return string|bool
   *   Destination or FALSE
   */
  public function login($uid, $hash) {

    $config = $this->configFactory->get('auto_login_url.settings');

    // Get ALU secret.
    $auto_login_url_secret = $this->autoLoginUrlGeneral->getSecret();

    // Get user password.
    $password = $this->autoLoginUrlGeneral->getUserHash($uid);

    // Create key.
    $key = Settings::getHashSalt() . $auto_login_url_secret . $password;

    // Get if the hash is in the db.
    $result = $this->connection->select('auto_login_url', 'a')
      ->fields('a', ['id', 'uid', 'destination'])
      ->condition('hash', Crypt::hmacBase64($hash, $key), '=')
      ->execute()
      ->fetchAssoc();

    if (!empty($result) && isset($result['uid'])) {
      $account = User::load($result['uid']);
      user_login_finalize($account);

      // Update the user table timestamp noting user has logged in.
      $this->connection->update('users_field_data')
        ->fields(['login' => time()])
        ->condition('uid', $result['uid'])
        ->execute();

      // Delete auto login URL, if option checked.
      if ($config->get('delete')) {
        $this->connection->delete('auto_login_url')
          ->condition('id', [$result['id']])
          ->execute();
      }

      // Get destination URL.
      $destination = urldecode($result['destination']);
      $destination = (strpos($destination, 'http://') !== FALSE || strpos($destination, 'https://') !== FALSE) ?
          $destination :
          Url::fromUri('internal:/' . $destination, ['absolute' => TRUE])->toString();

      return $destination;
    }

    return FALSE;
  }

}
