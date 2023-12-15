<?php

namespace Drupal\auto_login_url;

use Drupal\Component\Utility\Random;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\Entity\User;
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
<<<<<<< HEAD
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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;


  /**
   * Constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory,
  FloodInterface $flood,
  LoggerChannelFactoryInterface $logger_factory,
  RequestStack $request_stack,
  EntityTypeManagerInterface $entity_type_manager) {
    $this->configFactory = $config_factory;
    $this->flood = $flood;
    $this->loggerFactory = $logger_factory;
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
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
    $password = '';
    $user_exists = $this->entityTypeManager->getStorage('user')->getQuery()
    ->accessCheck(FALSE)
    ->condition('uid', $uid)
    ->execute();

    if (!empty($user_exists)) {
      $password = User::load($uid)->pass->value;
    }
    return $password;
  }

}
