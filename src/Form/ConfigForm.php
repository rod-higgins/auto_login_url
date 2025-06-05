<?php

declare(strict_types=1);

namespace Drupal\auto_login_url\Form;

use Drupal\auto_login_url\AutoLoginUrlGeneral;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Auto Login URL settings.
 *
 * @package Drupal\auto_login_url\Form
 */
final class ConfigForm extends ConfigFormBase {

  /**
   * Minimum allowed token length.
   */
  private const MIN_TOKEN_LENGTH = 8;

  /**
   * Maximum allowed token length.
   */
  private const MAX_TOKEN_LENGTH = 128;

  /**
   * Minimum expiration time (1 hour).
   */
  private const MIN_EXPIRATION = 3600;

  /**
   * Maximum expiration time (1 year).
   */
  private const MAX_EXPIRATION = 31536000;

  /**
   * The Auto Login Url General service.
   */
  private AutoLoginUrlGeneral $autoLoginUrlGeneral;

  /**
   * The logger channel.
   */
  private LoggerChannelInterface $logger;

  /**
   * Constructs a ConfigForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\auto_login_url\AutoLoginUrlGeneral $auto_login_url_general
   *   The Auto Login Url General service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AutoLoginUrlGeneral $auto_login_url_general,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    parent::__construct($config_factory);
    $this->autoLoginUrlGeneral = $auto_login_url_general;
    $this->logger = $logger_factory->get('auto_login_url');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('auto_login_url.general'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'auto_login_url_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['auto_login_url.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('auto_login_url.settings');

    // Security settings fieldset.
    $form['security'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Security Settings'),
      '#description' => $this->t('Configure security-related settings for auto login URLs.'),
    ];

    $form['security']['auto_login_url_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Secret Key'),
      '#description' => $this->t('Secret key used to generate secure hashes. Leave empty to auto-generate a new key. <strong>Warning:</strong> Changing this will invalidate all existing auto login URLs.'),
      '#placeholder' => $this->t('Leave empty to auto-generate'),
      '#attributes' => [
        'autocomplete' => 'new-password',
      ],
    ];

    $form['security']['regenerate_secret'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Regenerate secret key'),
      '#description' => $this->t('Check this to generate a new secret key. This will invalidate all existing auto login URLs.'),
      '#default_value' => FALSE,
    ];

    $form['security']['auto_login_url_token_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Token Length'),
      '#required' => TRUE,
      '#default_value' => $config->get('token_length') ?: 64,
      '#min' => self::MIN_TOKEN_LENGTH,
      '#max' => self::MAX_TOKEN_LENGTH,
      '#description' => $this->t('Length of generated URL tokens. Must be between @min and @max characters. <strong>Warning:</strong> Shorter tokens are less secure.', [
        '@min' => self::MIN_TOKEN_LENGTH,
        '@max' => self::MAX_TOKEN_LENGTH,
      ]),
    ];

    // Expiration settings fieldset.
    $form['expiration'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Expiration Settings'),
      '#description' => $this->t('Configure when auto login URLs expire.'),
    ];

    $form['expiration']['auto_login_url_expiration'] = [
      '#type' => 'number',
      '#title' => $this->t('Expiration Time (seconds)'),
      '#required' => TRUE,
      '#default_value' => $config->get('expiration') ?: 2592000,
      '#min' => self::MIN_EXPIRATION,
      '#max' => self::MAX_EXPIRATION,
      '#description' => $this->t('How long auto login URLs remain valid (in seconds). Default is 30 days (@default seconds). Must be between 1 hour (@min) and 1 year (@max).', [
        '@default' => 2592000,
        '@min' => self::MIN_EXPIRATION,
        '@max' => self::MAX_EXPIRATION,
      ]),
    ];

    $form['expiration']['expiration_examples'] = [
      '#type' => 'details',
      '#title' => $this->t('Common Expiration Times'),
      '#open' => FALSE,
    ];

    $form['expiration']['expiration_examples']['examples'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('1 hour: @seconds seconds', ['@seconds' => 3600]),
        $this->t('1 day: @seconds seconds', ['@seconds' => 86400]),
        $this->t('1 week: @seconds seconds', ['@seconds' => 604800]),
        $this->t('1 month: @seconds seconds', ['@seconds' => 2592000]),
        $this->t('3 months: @seconds seconds', ['@seconds' => 7776000]),
      ],
    ];

    // Behavior settings fieldset.
    $form['behavior'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Behavior Settings'),
      '#description' => $this->t('Configure how auto login URLs behave.'),
    ];

    $form['behavior']['auto_login_url_delete_on_use'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Delete URLs after use'),
      '#default_value' => $config->get('delete') ?: FALSE,
      '#description' => $this->t('If checked, auto login URLs will be deleted from the database after being used once. This provides better security but prevents URL reuse.'),
    ];

    // Statistics section.
    $form['statistics'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Statistics'),
      '#description' => $this->t('Information about auto login URLs in the system.'),
    ];

    $stats = $this->getStatistics();
    $form['statistics']['stats_display'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Active auto login URLs: @count', ['@count' => $stats['active_urls']]),
        $this->t('Expired auto login URLs: @count', ['@count' => $stats['expired_urls']]),
        $this->t('Total auto login URLs created: @count', ['@count' => $stats['total_urls']]),
      ],
    ];

    $form['statistics']['cleanup_expired'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clean up expired URLs'),
      '#submit' => ['::cleanupExpiredUrls'],
      '#limit_validation_errors' => [],
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $expiration = (int) $form_state->getValue('auto_login_url_expiration');
    if ($expiration < self::MIN_EXPIRATION || $expiration > self::MAX_EXPIRATION) {
      $form_state->setErrorByName('auto_login_url_expiration', 
        $this->t('Expiration must be between @min and @max seconds.', [
          '@min' => number_format(self::MIN_EXPIRATION),
          '@max' => number_format(self::MAX_EXPIRATION),
        ])
      );
    }

    $token_length = (int) $form_state->getValue('auto_login_url_token_length');
    if ($token_length < self::MIN_TOKEN_LENGTH || $token_length > self::MAX_TOKEN_LENGTH) {
      $form_state->setErrorByName('auto_login_url_token_length', 
        $this->t('Token length must be between @min and @max characters.', [
          '@min' => self::MIN_TOKEN_LENGTH,
          '@max' => self::MAX_TOKEN_LENGTH,
        ])
      );
    }

    // Enhanced secret key validation
    $secret = trim((string) $form_state->getValue('auto_login_url_secret'));
    if (!empty($secret)) {
      if (strlen($secret) < 16) {
        $form_state->setErrorByName('auto_login_url_secret', 
          $this->t('Secret key must be at least 16 characters long.')
        );
      }
      
      // Check for common weak patterns
      if (preg_match('/^(.)\1+$/', $secret) || in_array(strtolower($secret), ['password', 'secret', '1234567890123456'])) {
        $form_state->setErrorByName('auto_login_url_secret', 
          $this->t('Please choose a more secure secret key.')
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('auto_login_url.settings');
    $values = $form_state->getValues();

    // Handle secret key regeneration or update.
    $this->handleSecretKey($config, $values);

    // Save other configuration values.
    $config
      ->set('expiration', (int) $values['auto_login_url_expiration'])
      ->set('delete', (bool) $values['auto_login_url_delete_on_use'])
      ->set('token_length', (int) $values['auto_login_url_token_length'])
      ->save();

    $this->logger->info('Auto Login URL configuration updated');

    parent::submitForm($form, $form_state);
  }

  /**
   * Handles secret key regeneration or updates.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The configuration object.
   * @param array $values
   *   The form values.
   */
  private function handleSecretKey($config, array $values): void {
    $regenerate = (bool) $values['regenerate_secret'];
    $new_secret = trim((string) $values['auto_login_url_secret']);

    if ($regenerate || !empty($new_secret)) {
      if ($regenerate) {
        // Generate new secret.
        $config->set('secret', '');
        $this->autoLoginUrlGeneral->getSecret(); // This will generate a new one.
        $this->messenger()->addWarning(
          $this->t('A new secret key has been generated. All existing auto login URLs have been invalidated.')
        );
      }
      elseif (!empty($new_secret)) {
        // Use provided secret.
        $config->set('secret', $new_secret);
        $this->messenger()->addWarning(
          $this->t('Secret key has been updated. All existing auto login URLs have been invalidated.')
        );
      }

      $this->logger->notice('Auto Login URL secret key was updated');
    }
  }

  /**
   * Submit handler for cleaning up expired URLs.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function cleanupExpiredUrls(array &$form, FormStateInterface $form_state): void {
    try {
      /** @var \Drupal\auto_login_url\AutoLoginUrlLogin $login_service */
      $login_service = \Drupal::service('auto_login_url.login');
      $deleted_count = $login_service->cleanupExpiredTokens();

      if ($deleted_count > 0) {
        $this->messenger()->addStatus(
          $this->t('Cleaned up @count expired auto login URLs.', ['@count' => $deleted_count])
        );
        $this->logger->info('Manual cleanup of @count expired auto login URLs', ['@count' => $deleted_count]);
      }
      else {
        $this->messenger()->addStatus($this->t('No expired auto login URLs found.'));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to clean up expired URLs: @message', [
        '@message' => $e->getMessage(),
      ]));
      $this->logger->error('Failed to clean up expired URLs: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Gets statistics about auto login URLs.
   *
   * @return array
   *   Array of statistics.
   */
  private function getStatistics(): array {
    try {
      $database = \Drupal::database();
      $config = $this->config('auto_login_url.settings');
      $expiration = (int) $config->get('expiration');
      $cutoff_time = time() - $expiration;

      $total_urls = (int) $database->select('auto_login_url')
        ->countQuery()
        ->execute()
        ->fetchField();

      $expired_urls = (int) $database->select('auto_login_url')
        ->condition('timestamp', $cutoff_time, '<=')
        ->countQuery()
        ->execute()
        ->fetchField();

      $active_urls = $total_urls - $expired_urls;

      return [
        'total_urls' => $total_urls,
        'active_urls' => $active_urls,
        'expired_urls' => $expired_urls,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get statistics: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return [
        'total_urls' => 0,
        'active_urls' => 0,
        'expired_urls' => 0,
      ];
    }
  }

}