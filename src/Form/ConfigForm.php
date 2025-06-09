<?php

declare(strict_types=1);

namespace Drupal\auto_login_url\Form;

use Drupal\auto_login_url\AutoLoginUrlGeneral;
use Drupal\auto_login_url\AutoLoginUrlRateLimit;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
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
   * Minimum rate limit per hour.
   */
  private const MIN_RATE_LIMIT = 1;

  /**
   * Maximum rate limit per hour.
   */
  private const MAX_RATE_LIMIT = 100;

  /**
   * The Auto Login Url General service.
   */
  private AutoLoginUrlGeneral $autoLoginUrlGeneral;

  /**
   * The rate limiting service.
   */
  private AutoLoginUrlRateLimit $rateLimiter;

  /**
   * The logger channel.
   */
  private LoggerChannelInterface $logger;

  /**
   * Constructs a ConfigForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed configuration manager.
   * @param \Drupal\auto_login_url\AutoLoginUrlGeneral $auto_login_url_general
   *   The Auto Login Url General service.
   * @param \Drupal\auto_login_url\AutoLoginUrlRateLimit $rate_limiter
   *   The rate limiting service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    AutoLoginUrlGeneral $auto_login_url_general,
    AutoLoginUrlRateLimit $rate_limiter,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->autoLoginUrlGeneral = $auto_login_url_general;
    $this->rateLimiter = $rate_limiter;
    $this->logger = $logger_factory->get('auto_login_url');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('auto_login_url.general'),
      $container->get('auto_login_url.rate_limit'),
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

    $form['security']['auto_login_url_validate_ip'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Validate IP address'),
      '#default_value' => $config->get('validate_ip_address') ?: FALSE,
      '#description' => $this->t('If checked, auto login URLs will only work from the same IP address where they were created. This provides additional security but may cause issues for users with dynamic IP addresses.'),
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

    // Rate limiting settings fieldset.
    $form['rate_limiting'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Rate Limiting'),
      '#description' => $this->t('Configure rate limiting for auto login URL creation.'),
    ];

    $form['rate_limiting']['auto_login_url_max_per_hour'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum URLs per user per hour'),
      '#required' => TRUE,
      '#default_value' => $config->get('max_urls_per_user_per_hour') ?: 10,
      '#min' => self::MIN_RATE_LIMIT,
      '#max' => self::MAX_RATE_LIMIT,
      '#description' => $this->t('Maximum number of auto login URLs that can be created per user per hour. This helps prevent abuse.'),
    ];

    // Add rate limiting statistics.
    $rate_stats = $this->rateLimiter->getStatistics();
    $form['rate_limiting']['rate_stats'] = [
      '#type' => 'details',
      '#title' => $this->t('Current Rate Limiting Statistics'),
      '#open' => FALSE,
    ];

    $form['rate_limiting']['rate_stats']['stats_display'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Users with recent attempts: @count', ['@count' => $rate_stats['total_users_with_attempts']]),
        $this->t('Total recent attempts: @count', ['@count' => $rate_stats['total_recent_attempts']]),
        $this->t('Users near limit (≥80%): @count', ['@count' => $rate_stats['users_near_limit']]),
        $this->t('Current rate limit: @limit per @hours hour(s)', [
          '@limit' => $rate_stats['rate_limit'],
          '@hours' => $rate_stats['time_window_hours'],
        ]),
      ],
    ];

    $form['rate_limiting']['clear_rate_limits'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear all rate limiting data'),
      '#submit' => ['::clearRateLimits'],
      '#limit_validation_errors' => [],
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

    $form['behavior']['auto_login_url_enable_analytics'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable usage analytics'),
      '#default_value' => $config->get('enable_usage_analytics') ?? TRUE,
      '#description' => $this->t('If checked, usage statistics will be tracked for analytics purposes. This data can help administrators monitor system usage.'),
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
        $this->t('Usage records (if analytics enabled): @count', ['@count' => $stats['usage_records']]),
      ],
    ];

    $form['statistics']['cleanup_expired'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clean up expired URLs'),
      '#submit' => ['::cleanupExpiredUrls'],
      '#limit_validation_errors' => [],
    ];

    $form['statistics']['cleanup_analytics'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clean up old analytics data'),
      '#submit' => ['::cleanupAnalyticsData'],
      '#limit_validation_errors' => [],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
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

    $rate_limit = (int) $form_state->getValue('auto_login_url_max_per_hour');
    if ($rate_limit < self::MIN_RATE_LIMIT || $rate_limit > self::MAX_RATE_LIMIT) {
      $form_state->setErrorByName('auto_login_url_max_per_hour',
        $this->t('Rate limit must be between @min and @max URLs per hour.', [
          '@min' => self::MIN_RATE_LIMIT,
          '@max' => self::MAX_RATE_LIMIT,
        ])
      );
    }

    // Enhanced secret key validation.
    $secret = trim((string) $form_state->getValue('auto_login_url_secret'));
    if (!empty($secret)) {
      if (strlen($secret) < 16) {
        $form_state->setErrorByName('auto_login_url_secret',
          $this->t('Secret key must be at least 16 characters long.')
        );
      }

      // Check for common weak patterns.
      $weak_patterns = ['password', 'secret', '1234567890123456'];
      if (preg_match('/^(.)\1+$/', $secret) || in_array(strtolower($secret), $weak_patterns)) {
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
      ->set('validate_ip_address', (bool) $values['auto_login_url_validate_ip'])
      ->set('enable_usage_analytics', (bool) $values['auto_login_url_enable_analytics'])
      ->set('max_urls_per_user_per_hour', (int) $values['auto_login_url_max_per_hour'])
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
        // This will generate a new one.
        $this->autoLoginUrlGeneral->getSecret();
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
      $login_service = $this->container()->get('auto_login_url.login');
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
   * Submit handler for cleaning up analytics data.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function cleanupAnalyticsData(array &$form, FormStateInterface $form_state): void {
    try {
      $database = $this->container()->get('database');

      if (!$database->schema()->tableExists('auto_login_url_usage')) {
        $this->messenger()->addWarning($this->t('Analytics table does not exist.'));
        return;
      }

      // Clean up analytics data older than 6 months.
      $cutoff_time = time() - (6 * 30 * 24 * 60 * 60);
      $deleted_count = $database->delete('auto_login_url_usage')
        ->condition('used_timestamp', $cutoff_time, '<=')
        ->execute();

      if ($deleted_count > 0) {
        $this->messenger()->addStatus(
          $this->t('Cleaned up @count old analytics records.', ['@count' => $deleted_count])
        );
        $this->logger->info('Manual cleanup of @count analytics records', ['@count' => $deleted_count]);
      }
      else {
        $this->messenger()->addStatus($this->t('No old analytics data found to clean up.'));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to clean up analytics data: @message', [
        '@message' => $e->getMessage(),
      ]));
      $this->logger->error('Failed to clean up analytics data: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Submit handler for clearing rate limiting data.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function clearRateLimits(array &$form, FormStateInterface $form_state): void {
    try {
      $this->rateLimiter->clearAllLimits();
      $this->messenger()->addStatus($this->t('All rate limiting data has been cleared.'));
      $this->logger->info('Rate limiting data manually cleared by administrator');
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to clear rate limiting data: @message', [
        '@message' => $e->getMessage(),
      ]));
      $this->logger->error('Failed to clear rate limiting data: @message', [
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
      $database = $this->container()->get('database');
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

      $usage_records = 0;
      if ($database->schema()->tableExists('auto_login_url_usage')) {
        $usage_records = (int) $database->select('auto_login_url_usage')
          ->countQuery()
          ->execute()
          ->fetchField();
      }

      return [
        'total_urls' => $total_urls,
        'active_urls' => $active_urls,
        'expired_urls' => $expired_urls,
        'usage_records' => $usage_records,
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
        'usage_records' => 0,
      ];
    }
  }

}
