<?php

namespace Drupal\auto_login_url;

use Drupal\Component\Utility\Random;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class AutoLoginUrlGeneral.
 *
 * @package Drupal\auto_login_url
 */
class AutoLoginUrlGeneral {

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory,
   FloodInterface $flood,
    LoggerChannelFactoryInterface $logger_factory,
    RequestStack $request_stack,
    Connection $connection) {
    $this->configFactory = $config_factory;
    $this->flood = $flood;
    $this->loggerFactory = $logger_factory;
    $this->requestStack = $request_stack;
    $this->connection = $connection;
  }

  /**
   * Check if this IP is blocked by flood.
   *
   * @return bool
   *   TRUE if it is blocked.
   */
  public function checkFlood() {
    $flood_config = $this->configFactory->get('user.flood');

    if (!$this->flood->isAllowed('user.failed_login_ip', $flood_config->get('ip_limit'), $flood_config->get('ip_window'))) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Register flood event for this IP.
   *
   * @param string $hash
   *   Code that passes through URL.
   */
  public function registerFlood($hash) {

    $flood_config = $this->configFactory->get('user.flood');

    // Register flood event.
    $this->flood->register('user.failed_login_ip', $flood_config->get('ip_window'));

    // Log error.
    $this->loggerFactory->get('auto_login_url')
      ->error('Failed Auto Login URL from ip: @ip and hash: @hash',
        [
          '@ip' => $this->requestStack->getCurrentRequest()->getClientIp(),
          '@hash' => $hash,
        ]);
  }

  /**
   * Get secret key for ALU or create now.
   */
  public function getSecret() {

    $config = $this->configFactory->get('auto_login_url.settings');

    // Check if it exists.
    $secret = $config->get('secret');

    // Create if it does not exist.
    if ($secret == '') {
      $random_generator = new Random();
      $secret = $random_generator->name(64);

      $this->configFactory->getEditable('auto_login_url.settings')
        ->set('secret', $secret)->save();
    }

    return $secret;
  }

  /**
   * Get user password hash.
   *
   * @param int $uid
   *   User id.
   *
   * @return string
   *   Hashed password.
   */
  public function getUserHash($uid) {
    $query = $this->connection->select('users_field_data', 'u');
    $query->addField('u', 'pass');
    $query->condition('u.uid', $uid);
    $query->range(0, 1);

    return $query->execute()->fetchField();
  }

}
