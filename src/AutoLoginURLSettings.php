<?php
/**
 * @file
 * Contains \Drupal\auto_login_url\Controller\AutoLoginUrlMainController.
 */

namespace Drupal\auto_login_url;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class AutoLoginURLSettings extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'auto_login_url_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('auto_login_url.settings');

    // Secret word.
    $form['auto_login_url_secret'] = array(
      '#type' => 'textfield',
      '#title' => t('Secret word'),
      '#required' => TRUE,
      '#default_value' => $config->get('secret'),
      '#description' => t('Secret word to create hashes that are stored in DB.
        Every time this changes all previous URLs are invalidated.'),
    );

    // Expiration.
    $form['auto_login_url_expiration'] = array(
      '#type' => 'textfield',
      '#title' => t('Expiration'),
      '#required' => TRUE,
      '#default_value' => $config->get('expiration'),
      '#description' => t('Expiration of URLs in seconds.'),
      //'#element_validate' => array('element_validate_integer_positive'),
    );

    // Delete URLs on use.
    $form['auto_login_url_delete_on_use'] = array(
      '#type' => 'checkbox',
      '#title' => t('Delete on use'),
      '#default_value' => $config->get('delete'),
      '#description' => t('Auto delete URLs after use.'),
    );

    return parent::buildForm($form, $form_state);
  }

  /**
   * Implements \Drupal\Core\Form\FormInterface::validateForm().
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    if ($form_state->getValue('auto_login_url_expiration') < 1) {
      $form_state->setErrorByName('auto_login_url_expiration', $this->t('Expiration must be positive integer.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    $config = $this->config('auto_login_url.settings');
    $config->set('secret', $values['auto_login_url_secret'])->save();
    $config->set('expiration', $values['auto_login_url_expiration'])->save();
    $config->set('delete', $values['auto_login_url_delete_on_use'])->save();

    parent::submitForm($form, $form_state);
  }
}
