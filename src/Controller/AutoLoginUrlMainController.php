<?php

namespace Drupal\auto_login_url\Controller;

use Drupal\auto_login_url\AutoLoginUrlGeneral;
use Drupal\auto_login_url\AutoLoginUrlLogin;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Class AutoLoginUrlMainController.
 *
 * @package Drupal\auto_login_url\Controller
 */
class AutoLoginUrlMainController extends ControllerBase {

  /**
   * The kill switch.
   *
   * @var \Drupal\Core\PageCache\ResponsePolicy\KillSwitch
   */
  protected $killSwitch;

  /**
   * The Auto Login Url General service.
   *
   * @var \Drupal\auto_login_url\AutoLoginUrlGeneral
   */
  protected $autoLoginUrlGeneral;

  /**
   * The Auto Login Url Login service.
   *
   * @var \Drupal\auto_login_url\AutoLoginUrlLogin
   */
  protected $autoLoginUrlLogin;

  /**
   * Constructs a AutoLoginUrlMainController object.
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $kill_switch
   *   The kill switch.
   * @param \Drupal\auto_login_url\AutoLoginUrlGeneral $auto_login_url_general
   *   The Auto Login Url General service.
   * @param \Drupal\auto_login_url\AutoLoginUrlLogin $auto_login_url_login
   *   The Auto Login Url Login service.
   */
  public function __construct(KillSwitch $kill_switch, AutoLoginUrlGeneral $auto_login_url_general,AutoLoginUrlLogin $auto_login_url_login) {
    $this->killSwitch = $kill_switch;
    $this->autoLoginUrlGeneral = $auto_login_url_general;
    $this->autoLoginUrlLogin = $auto_login_url_login;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('page_cache_kill_switch'),
      $container->get('auto_login_url.general'),
      $container->get('auto_login_url.login')
    );
  }

  /**
   * Auto login method.
   *
   * @param int $uid
   *   The ID of the user.
   * @param string $hash
   *   The hash string on the URL.
   */
  public function login($uid, $hash) {

    // Disable page cache.
    $this->killSwitch->trigger();

    // Check for flood events.
    if ($this->autoLoginUrlGeneral->checkFlood()) {
      $this->messenger()->addError($this->t('Sorry, too many failed login attempts from your IP address. This IP address is temporarily blocked. Try again later.'));

      throw new AccessDeniedHttpException();
    }

    $destination = $this->autoLoginUrlLogin->login($uid, $hash);

    if ($destination) {
      return new RedirectResponse($destination);
    }
    else {
      // Register flood event.
      $this->autoLoginUrlGeneral->registerFlood($hash);

      throw new AccessDeniedHttpException();
    }
  }

}
