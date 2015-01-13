<?php

/**
 * @file
 * Contains \Drupal\auto_login_url\Controller\AutoLoginUrlMainController.
 */

namespace Drupal\auto_login_url\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\auto_login_url\AutoLoginURLCreate;
use Drupal\Core\Entity\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AutoLoginUrlMainController extends ControllerBase {

  /**
   * Login method.
   */
  public function login($hash) {
    $config = $this->config('auto_login_url.settings');

    // Get if the hash is in the db.
    $result = db_select('auto_login_url', 'a')
      ->fields('a', array('id', 'uid', 'destination'))
      ->condition('hash', hash('sha256', $hash . $config->get('secret')), '=')
      ->execute()
      ->fetchAssoc();

    if (count($result) > 0 && isset($result['uid'])) {
      $userStorage = $this->entityManager()->getStorage('user');
      $account = $userStorage->load($result['uid']);
      user_login_finalize($account);

      // Update the user table timestamp noting user has logged in.
      db_update('users_field_data')
        ->fields(array('login' => time()))
        ->condition('uid', $result['uid'])
        ->execute();

      // Delete auto login URL, if option checked.
      if ($config->get('delete')) {
        db_delete('auto_login_url')->condition('id', array($result['id']))->execute();
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
      return $this->redirect('<front>');
    }
  }
}
