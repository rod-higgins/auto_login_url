<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Unit\Form;

use Drupal\auto_login_url\AutoLoginUrlGeneral;
use Drupal\auto_login_url\AutoLoginUrlRateLimit;
use Drupal\auto_login_url\Form\ConfigForm;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for ConfigForm.
 *
 * @group auto_login_url
 * @coversDefaultClass \Drupal\auto_login_url\Form\ConfigForm
 */
final class ConfigFormTest extends UnitTestCase {

  /**
   * The mocked config factory.
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The mocked general service.
   */
  private AutoLoginUrlGeneral $autoLoginUrlGeneral;

  /**
   * The mocked rate limiter.
   */
  private AutoLoginUrlRateLimit $rateLimiter;

  /**
   * The mocked logger factory.
   */
  private LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The mocked logger channel.
   */
  private LoggerChannelInterface $logger;

  /**
   * The form under test.
   */
  private ConfigForm $configForm;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->autoLoginUrlGeneral = $this->createMock(AutoLoginUrlGeneral::class);
    $this->rateLimiter = $this->createMock(AutoLoginUrlRateLimit::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);

    $this->loggerFactory->method('get')
      ->with('auto_login_url')
      ->willReturn($this->logger);

    $this->configForm = new ConfigForm(
      $this->configFactory,
      $this->autoLoginUrlGeneral,
      $this->rateLimiter,
      $this->loggerFactory
    );

    // Mock messenger for form validation.
    $messenger = $this->createMock(MessengerInterface::class);
    $this->configForm->setMessenger($messenger);
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId(): void {
    $this->assertEquals('auto_login_url_settings', $this->configForm->getFormId());
  }

  /**
   * @covers ::getEditableConfigNames
   */
  public function testGetEditableConfigNames(): void {
    $expected = ['auto_login_url.settings'];
    $this->assertEquals($expected, $this->configForm->getEditableConfigNames());
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildForm(): void {
    // Mock config.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['token_length', 64],
      ['expiration', 2592000],
      ['delete', FALSE],
      ['validate_ip_address', FALSE],
      ['enable_usage_analytics', TRUE],
      ['max_urls_per_user_per_hour', 10],
    ]);

    $this->configFactory->method('config')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    // Mock rate limiter statistics.
    $this->rateLimiter->method('getStatistics')
      ->willReturn([
        'total_users_with_attempts' => 5,
        'total_recent_attempts' => 25,
        'users_near_limit' => 2,
        'rate_limit' => 10,
        'time_window_hours' => 1,
      ]);

    $formState = $this->createMock(FormStateInterface::class);
    $form = [];

    $result = $this->configForm->buildForm($form, $formState);

    // Verify form structure.
    $this->assertIsArray($result);
    $this->assertArrayHasKey('security', $result);
    $this->assertArrayHasKey('expiration', $result);
    $this->assertArrayHasKey('rate_limiting', $result);
    $this->assertArrayHasKey('behavior', $result);
    $this->assertArrayHasKey('statistics', $result);

    // Verify specific form elements.
    $this->assertArrayHasKey('auto_login_url_token_length', $result['security']);
    $this->assertArrayHasKey('auto_login_url_expiration', $result['expiration']);
    $this->assertArrayHasKey('auto_login_url_max_per_hour', $result['rate_limiting']);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormWithValidValues(): void {
    $form = [];
    $formState = $this->createMock(FormStateInterface::class);
    
    $formState->method('getValue')->willReturnMap([
      ['auto_login_url_expiration', 7200],
      ['auto_login_url_token_length', 32],
      ['auto_login_url_max_per_hour', 15],
      ['auto_login_url_secret', ''],
    ]);

    // Should not set any errors for valid values.
    $formState->expects($this->never())->method('setErrorByName');

    $this->configForm->validateForm($form, $formState);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormWithInvalidExpiration(): void {
    $form = [];
    $formState = $this->createMock(FormStateInterface::class);
    
    $formState->method('getValue')->willReturnMap([
      ['auto_login_url_expiration', 1800], // Too short (30 minutes)
      ['auto_login_url_token_length', 32],
      ['auto_login_url_max_per_hour', 15],
      ['auto_login_url_secret', ''],
    ]);

    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('auto_login_url_expiration', $this->stringContains('Expiration must be between'));

    $this->configForm->validateForm($form, $formState);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormWithInvalidTokenLength(): void {
    $form = [];
    $formState = $this->createMock(FormStateInterface::class);
    
    $formState->method('getValue')->willReturnMap([
      ['auto_login_url_expiration', 7200],
      ['auto_login_url_token_length', 5], // Too short
      ['auto_login_url_max_per_hour', 15],
      ['auto_login_url_secret', ''],
    ]);

    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('auto_login_url_token_length', $this->stringContains('Token length must be between'));

    $this->configForm->validateForm($form, $formState);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormWithInvalidRateLimit(): void {
    $form = [];
    $formState = $this->createMock(FormStateInterface::class);
    
    $formState->method('getValue')->willReturnMap([
      ['auto_login_url_expiration', 7200],
      ['auto_login_url_token_length', 32],
      ['auto_login_url_max_per_hour', 150], // Too high
      ['auto_login_url_secret', ''],
    ]);

    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('auto_login_url_max_per_hour', $this->stringContains('Rate limit must be between'));

    $this->configForm->validateForm($form, $formState);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormWithWeakSecret(): void {
    $form = [];
    $formState = $this->createMock(FormStateInterface::class);
    
    $formState->method('getValue')->willReturnMap([
      ['auto_login_url_expiration', 7200],
      ['auto_login_url_token_length', 32],
      ['auto_login_url_max_per_hour', 15],
      ['auto_login_url_secret', 'password'], // Weak secret
    ]);

    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('auto_login_url_secret', $this->stringContains('Please choose a more secure secret key'));

    $this->configForm->validateForm($form, $formState);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormWithShortSecret(): void {
    $form = [];
    $formState = $this->createMock(FormStateInterface::class);
    
    $formState->method('getValue')->willReturnMap([
      ['auto_login_url_expiration', 7200],
      ['auto_login_url_token_length', 32],
      ['auto_login_url_max_per_hour', 15],
      ['auto_login_url_secret', 'short'], // Too short
    ]);

    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('auto_login_url_secret', $this->stringContains('Secret key must be at least 16 characters'));

    $this->configForm->validateForm($form, $formState);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormWithMultipleErrors(): void {
    $form = [];
    $formState = $this->createMock(FormStateInterface::class);
    
    $formState->method('getValue')->willReturnMap([
      ['auto_login_url_expiration', 100], // Too short
      ['auto_login_url_token_length', 5], // Too short
      ['auto_login_url_max_per_hour', 200], // Too high
      ['auto_login_url_secret', 'weak'], // Too weak
    ]);

    $formState->expects($this->exactly(4))
      ->method('setErrorByName');

    $this->configForm->validateForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormWithBasicConfig(): void {
    $form = [];
    $formState = $this->createMock(FormStateInterface::class);
    
    $values = [
      'auto_login_url_expiration' => 7200,
      'auto_login_url_delete_on_use' => TRUE,
      'auto_login_url_token_length' => 32,
      'auto_login_url_validate_ip' => FALSE,
      'auto_login_url_enable_analytics' => TRUE,
      'auto_login_url_max_per_hour' => 15,
      'auto_login_url_secret' => '',
      'regenerate_secret' => FALSE,
    ];

    $formState->method('getValues')->willReturn($values);

    // Mock config.
    $config = $this->createMock(Config::class);
    $config->method('set')->willReturnSelf();
    $config->expects($this->once())->method('save');

    $this->configFactory->method('config')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    // Mock parent submitForm.
    $parentForm = $this->getMockBuilder(ConfigForm::class)
      ->setConstructorArgs([
        $this->configFactory,
        $this->autoLoginUrlGeneral,
        $this->rateLimiter,
        $this->loggerFactory,
      ])
      ->onlyMethods(['messenger'])
      ->getMock();

    $messenger = $this->createMock(MessengerInterface::class);
    $parentForm->method('messenger')->willReturn($messenger);

    $parentForm->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormWithSecretRegeneration(): void {
    $form = [];
    $formState = $this->createMock(FormStateInterface::class);
    
    $values = [
      'auto_login_url_expiration' => 7200,
      'auto_login_url_delete_on_use' => FALSE,
      'auto_login_url_token_length' => 64,
      'auto_login_url_validate_ip' => FALSE,
      'auto_login_url_enable_analytics' => TRUE,
      'auto_login_url_max_per_hour' => 10,
      'auto_login_url_secret' => '',
      'regenerate_secret' => TRUE, // Regenerate secret
    ];

    $formState->method('getValues')->willReturn($values);

    // Mock config.
    $config = $this->createMock(Config::class);
    $config->method('set')->willReturnSelf();
    $config->expects($this->once())->method('save');

    $this->configFactory->method('config')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    // Mock secret generation.
    $this->autoLoginUrlGeneral->expects($this->once())
      ->method('getSecret');

    $parentForm = $this->getMockBuilder(ConfigForm::class)
      ->setConstructorArgs([
        $this->configFactory,
        $this->autoLoginUrlGeneral,
        $this->rateLimiter,
        $this->loggerFactory,
      ])
      ->onlyMethods(['messenger'])
      ->getMock();

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())
      ->method('addWarning')
      ->with($this->stringContains('A new secret key has been generated'));
    
    $parentForm->method('messenger')->willReturn($messenger);

    $parentForm->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormWithCustomSecret(): void {
    $form = [];
    $formState = $this->createMock(FormStateInterface::class);
    
    $values = [
      'auto_login_url_expiration' => 7200,
      'auto_login_url_delete_on_use' => FALSE,
      'auto_login_url_token_length' => 64,
      'auto_login_url_validate_ip' => FALSE,
      'auto_login_url_enable_analytics' => TRUE,
      'auto_login_url_max_per_hour' => 10,
      'auto_login_url_secret' => 'custom-secret-key-with-sufficient-length',
      'regenerate_secret' => FALSE,
    ];

    $formState->method('getValues')->willReturn($values);

    // Mock config.
    $config = $this->createMock(Config::class);
    $config->expects($this->once())
      ->method('set')
      ->with('secret', 'custom-secret-key-with-sufficient-length')
      ->willReturnSelf();
    $config->expects($this->once())->method('save');

    $this->configFactory->method('config')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    $parentForm = $this->getMockBuilder(ConfigForm::class)
      ->setConstructorArgs([
        $this->configFactory,
        $this->autoLoginUrlGeneral,
        $this->rateLimiter,
        $this->loggerFactory,
      ])
      ->onlyMethods(['messenger'])
      ->getMock();

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())
      ->method('addWarning')
      ->with($this->stringContains('Secret key has been updated'));
    
    $parentForm->method('messenger')->willReturn($messenger);

    $parentForm->submitForm($form, $formState);
  }

  /**
   * @covers ::clearRateLimits
   */
  public function testClearRateLimits(): void {
    $form = [];
    $formState = $this->createMock(FormStateInterface::class);

    $this->rateLimiter->expects($this->once())
      ->method('clearAllLimits');

    $parentForm = $this->getMockBuilder(ConfigForm::class)
      ->setConstructorArgs([
        $this->configFactory,
        $this->autoLoginUrlGeneral,
        $this->rateLimiter,
        $this->loggerFactory,
      ])
      ->onlyMethods(['messenger'])
      ->getMock();

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())
      ->method('addStatus')
      ->with($this->stringContains('All rate limiting data has been cleared'));
    
    $parentForm->method('messenger')->willReturn($messenger);

    $parentForm->clearRateLimits($form, $formState);
  }

  /**
   * @covers ::clearRateLimits
   */
  public function testClearRateLimitsWithError(): void {
    $form = [];
    $formState = $this->createMock(FormStateInterface::class);

    $this->rateLimiter->expects($this->once())
      ->method('clearAllLimits')
      ->willThrowException(new \Exception('Clear failed'));

    $parentForm = $this->getMockBuilder(ConfigForm::class)
      ->setConstructorArgs([
        $this->configFactory,
        $this->autoLoginUrlGeneral,
        $this->rateLimiter,
        $this->loggerFactory,
      ])
      ->onlyMethods(['messenger'])
      ->getMock();

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())
      ->method('addError')
      ->with($this->stringContains('Failed to clear rate limiting data'));
    
    $parentForm->method('messenger')->willReturn($messenger);

    $parentForm->clearRateLimits($form, $formState);
  }

  /**
   * @covers ::create
   */
  public function testCreate(): void {
    $container = $this->createMock(\Symfony\Component\DependencyInjection\ContainerInterface::class);
    
    $container->method('get')->willReturnMap([
      ['config.factory', $this->configFactory],
      ['auto_login_url.general', $this->autoLoginUrlGeneral],
      ['auto_login_url.rate_limit', $this->rateLimiter],
      ['logger.factory', $this->loggerFactory],
    ]);

    $form = ConfigForm::create($container);
    $this->assertInstanceOf(ConfigForm::class, $form);
  }

  /**
   * Tests form element structure and defaults.
   */
  public function testFormElementStructure(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['token_length', NULL], // Test default handling
      ['expiration', NULL],
      ['delete', NULL],
      ['validate_ip_address', NULL],
      ['enable_usage_analytics', NULL],
      ['max_urls_per_user_per_hour', NULL],
    ]);

    $this->configFactory->method('config')->willReturn($config);
    $this->rateLimiter->method('getStatistics')->willReturn([
      'total_users_with_attempts' => 0,
      'total_recent_attempts' => 0,
      'users_near_limit' => 0,
      'rate_limit' => 10,
      'time_window_hours' => 1,
    ]);

    $formState = $this->createMock(FormStateInterface::class);
    $form = [];

    $result = $this->configForm->buildForm($form, $formState);

    // Test default values are applied.
    $this->assertEquals(64, $result['security']['auto_login_url_token_length']['#default_value']);
    $this->assertEquals(2592000, $result['expiration']['auto_login_url_expiration']['#default_value']);
    $this->assertEquals(10, $result['rate_limiting']['auto_login_url_max_per_hour']['#default_value']);
  }

  /**
   * Tests form validation with edge case values.
   */
  public function testFormValidationEdgeCases(): void {
    $edgeCases = [
      // Exactly at minimum values.
      [
        'expiration' => 3600,
        'token_length' => 8,
        'rate_limit' => 1,
        'secret' => str_repeat('a', 16),
        'should_pass' => TRUE,
      ],
      // Exactly at maximum values.
      [
        'expiration' => 31536000,
        'token_length' => 128,
        'rate_limit' => 100,
        'secret' => '',
        'should_pass' => TRUE,
      ],
      // Just over maximum values.
      [
        'expiration' => 31536001,
        'token_length' => 129,
        'rate_limit' => 101,
        'secret' => '',
        'should_pass' => FALSE,
      ],
      // Just under minimum values.
      [
        'expiration' => 3599,
        'token_length' => 7,
        'rate_limit' => 0,
        'secret' => '',
        'should_pass' => FALSE,
      ],
    ];

    foreach ($edgeCases as $case) {
      $form = [];
      $formState = $this->createMock(FormStateInterface::class);
      
      $formState->method('getValue')->willReturnMap([
        ['auto_login_url_expiration', $case['expiration']],
        ['auto_login_url_token_length', $case['token_length']],
        ['auto_login_url_max_per_hour', $case['rate_limit']],
        ['auto_login_url_secret', $case['secret']],
      ]);

      if ($case['should_pass']) {
        $formState->expects($this->never())->method('setErrorByName');
      } else {
        $formState->expects($this->atLeastOnce())->method('setErrorByName');
      }

      $this->configForm->validateForm($form, $formState);
    }
  }

}