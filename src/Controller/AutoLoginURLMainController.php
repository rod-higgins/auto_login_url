<?php

/**
 * @file
 * Contains \Drupal\auto_login_url\Controller\AutoLoginUrlMainController.
 */

namespace Drupal\auto_login_url\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AutoLoginUrlMainController extends ControllerBase {

  /**
   * Login method.
   */
  public function login($hash) {
    $config = $this->config('auto_login_url.settings');
    $connection = \Drupal::database();

    // Check for flood events.
    $flood_config = $this->config('user.flood');
    $flood = \Drupal::flood();
    if (!$flood->isAllowed('user.failed_login_ip', $flood_config->get('ip_limit'), $flood_config->get('ip_window'))) {
      drupal_set_message($this->t('Sorry, too many failed login attempts from your IP address. This IP address is temporarily blocked. Try again later.'), 'error');

      throw new AccessDeniedHttpException();
    }

    // Get if the hash is in the db.
    $result = $connection->select('auto_login_url', 'a')
      ->fields('a', array('id', 'uid', 'destination'))
      ->condition('hash', hash('sha256', $hash . $config->get('secret')), '=')
      ->execute()
      ->fetchAssoc();

    if (count($result) > 0 && isset($result['uid'])) {
      $account = User::load($result['uid']);
      user_login_finalize($account);

      // Update the user table timestamp noting user has logged in.
      $connection->update('users_field_data')
        ->fields(array('login' => time()))
        ->condition('uid', $result['uid'])
        ->execute();

      // Delete auto login URL, if option checked.
      if ($config->get('delete')) {
        $connection->delete('auto_login_url')
          ->condition('id', array($result['id']))
          ->execute();
      }

      // Get destination URL.
      $destination = urldecode($result['destination']);
      $destination =
        strpos($destination, 'http://') !== FALSE
        || strpos($destination, 'https://') !== FALSE ?
          $destination : '/' . $destination;

      // I am using a Symfony class directly, which I am not sure I should.
      return new RedirectResponse($destination);
    }
    else {
      // Register flood event.
      $flood->register('user.failed_login_ip', $flood_config->get('ip_window'));

      // Log error.
      \Drupal::logger('auto_login_url')
        ->error('Failed Auto Login URL from ip: @ip and hash: @hash',
          array(
            '@ip' => \Drupal::request()->getClientIp(),
            '@hash' => $hash
          ));

      throw new AccessDeniedHttpException();
    }
  }
}
