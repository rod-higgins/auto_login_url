<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Unit\Controller;

use Drupal\auto_login_url\AutoLoginUrlGeneral;
use Drupal\auto_login_url\AutoLoginUrlLogin;
use Drupal\auto_login_url\Controller\AutoLoginUrlMainController;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Unit tests for AutoLoginUrlMainController.
 *
 * @group auto_login_url
 * @coversDefaultClass \Drupal\auto_login_url\Controller\AutoLoginUrlMainController
 */
final class AutoLoginUrlMainControllerTest extends UnitTestCase {

  /**
   * The mocked kill switch.
   */
  private KillSwitch $killSwitch;

  /**
   * The mocked general service.
   */
  private AutoLoginUrlGeneral $autoLoginUrlGeneral;

  /**
   * The mocked login service.
   */
  private AutoLoginUrlLogin $autoLoginUrlLogin;

  /**
   * The mocked logger factory.
   */
  private LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The mocked logger channel.
   */
  private LoggerChannelInterface $logger;

  /**
   * The controller under test.
   */
  private AutoLoginUrlMainController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->killSwitch = $this->createMock(KillSwitch::class);
    $this->autoLoginUrlGeneral = $this->createMock(AutoLoginUrlGeneral::class);
    $this->autoLoginUrlLogin = $this->createMock(AutoLoginUrlLogin::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);

    $this->loggerFactory->method('get')
      ->with('auto_login_url')
      ->willReturn($this->logger);

    $this->controller = new AutoLoginUrlMainController(
      $this->killSwitch,
      $this->autoLoginUrlGeneral,
      $this->autoLoginUrlLogin,
      $this->loggerFactory
    );

    // Mock messenger for error messages.
    $messenger = $this->createMock(MessengerInterface::class);
    $this->controller->setMessenger($messenger);
  }

  /**
   * @covers ::create
   */
  public function testCreate(): void {
    $container = $this->createMock(ContainerInterface::class);

    $container->method('get')->willReturnMap([
      ['page_cache_kill_switch', $this->killSwitch],
      ['auto_login_url.general', $this->autoLoginUrlGeneral],
      ['auto_login_url.login', $this->autoLoginUrlLogin],
      ['logger.factory', $this->loggerFactory],
    ]);

    $controller = AutoLoginUrlMainController::create($container);
    $this->assertInstanceOf(AutoLoginUrlMainController::class, $controller);
  }

  /**
   * @covers ::login
   */
  public function testLoginTriggersKillSwitch(): void {
    $this->killSwitch->expects($this->once())
      ->method('trigger');

    $this->autoLoginUrlGeneral->method('validateUserId')
      ->with(123)
      ->willReturn(FALSE);

    $this->expectException(BadRequestHttpException::class);
    $this->controller->login(123, 'valid-hash');
  }

  /**
   * @covers ::login
   */
  public function testLoginWithInvalidUserId(): void {
    $this->killSwitch->method('trigger');

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Invalid user ID');

    $this->controller->login(0, 'valid-hash');
  }

  /**
   * @covers ::login
   */
  public function testLoginWithNegativeUserId(): void {
    $this->killSwitch->method('trigger');

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Invalid user ID');

    $this->controller->login(-1, 'valid-hash');
  }

  /**
   * @covers ::login
   */
  public function testLoginWithInvalidHashFormat(): void {
    $this->killSwitch->method('trigger');

    $this->autoLoginUrlGeneral->method('validateHashFormat')
      ->with('invalid hash')
      ->willReturn(FALSE);

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Invalid hash format');

    $this->controller->login(123, 'invalid hash');
  }

  /**
   * @covers ::login
   */
  public function testLoginWithFloodProtection(): void {
    $this->killSwitch->method('trigger');

    $this->autoLoginUrlGeneral->method('validateHashFormat')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('checkFlood')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('getClientIp')->willReturn('192.168.1.1');

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Too many failed login attempts');

    $this->controller->login(123, 'valid-hash');
  }

  /**
   * @covers ::login
   */
  public function testLoginSuccessfulWithInternalDestination(): void {
    $this->setupSuccessfulLoginMocks();

    $this->autoLoginUrlLogin->method('login')
      ->with(123, 'valid-hash')
      ->willReturn('/user/123');

    $response = $this->controller->login(123, 'valid-hash');

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertEquals(302, $response->getStatusCode());
    $this->assertEquals('/user/123', $response->getTargetUrl());
  }

  /**
   * @covers ::login
   */
  public function testLoginSuccessfulWithExternalDestination(): void {
    $this->setupSuccessfulLoginMocks();

    $this->autoLoginUrlLogin->method('login')
      ->with(123, 'valid-hash')
      ->willReturn('https://external-site.com/dashboard');

    $response = $this->controller->login(123, 'valid-hash');

    $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
    $this->assertEquals(302, $response->getStatusCode());
    $this->assertEquals('https://external-site.com/dashboard', $response->getTargetUrl());
  }

  /**
   * @covers ::login
   */
  public function testLoginFailedWithInvalidCredentials(): void {
    $this->setupSuccessfulLoginMocks();

    $this->autoLoginUrlLogin->method('login')
      ->with(123, 'invalid-hash')
      ->willReturn(FALSE);

    $this->autoLoginUrlGeneral->method('validateUserId')
      ->with(123)
      ->willReturn(TRUE);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Invalid or expired login token');

    $this->controller->login(123, 'invalid-hash');
  }

  /**
   * @covers ::login
   */
  public function testLoginFailedWithNonExistentUser(): void {
    $this->setupSuccessfulLoginMocks();

    $this->autoLoginUrlLogin->method('login')
      ->with(99999, 'valid-hash')
      ->willReturn(FALSE);

    $this->autoLoginUrlGeneral->method('validateUserId')
      ->with(99999)
      ->willReturn(FALSE);

    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage('User not found');

    $this->controller->login(99999, 'valid-hash');
  }

  /**
   * @covers ::login
   */
  public function testLoginWithUnexpectedException(): void {
    $this->killSwitch->method('trigger');
    $this->autoLoginUrlGeneral->method('validateHashFormat')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('checkFlood')->willReturn(FALSE);

    $this->autoLoginUrlLogin->method('login')
      ->willThrowException(new \RuntimeException('Unexpected error'));

    $this->autoLoginUrlGeneral->expects($this->once())
      ->method('registerFlood')
      ->with('valid-hash');

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Login failed due to system error');

    $this->controller->login(123, 'valid-hash');
  }

  /**
   * @covers ::login
   */
  public function testLoginSanitizesDestination(): void {
    $this->setupSuccessfulLoginMocks();

    // Test with potentially dangerous destination.
    $this->autoLoginUrlLogin->method('login')
      ->willReturn('javascript:alert("xss")');

    $response = $this->controller->login(123, 'valid-hash');

    // Should sanitize to safe destination.
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertStringNotContains('javascript:', $response->getTargetUrl());
  }

  /**
   * @covers ::login
   */
  public function testLoginWithEmptyDestination(): void {
    $this->setupSuccessfulLoginMocks();

    $this->autoLoginUrlLogin->method('login')
      ->willReturn('');

    $response = $this->controller->login(123, 'valid-hash');

    // Should redirect to front page when destination is empty.
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertEquals('/', $response->getTargetUrl());
  }

  /**
   * @covers ::login
   */
  public function testLoginWithMalformedDestination(): void {
    $this->setupSuccessfulLoginMocks();

    // Test with destination that becomes empty after sanitization.
    $this->autoLoginUrlLogin->method('login')
      ->willReturn("\x00\x01\x02");

    $response = $this->controller->login(123, 'valid-hash');

    // Should fallback to front page.
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertEquals('/', $response->getTargetUrl());
  }

  /**
   * @covers ::healthCheck
   */
  public function testHealthCheck(): void {
    $result = $this->controller->healthCheck();

    $this->assertIsArray($result);
    $this->assertEquals('markup', $result['#type']);
    $this->assertStringContains('operational', $result['#markup']);
    $this->assertArrayHasKey('#cache', $result);
    $this->assertEquals(300, $result['#cache']['max-age']);
  }

  /**
   * @covers ::login
   */
  public function testLoginRegistersFloodOnFailure(): void {
    $this->setupSuccessfulLoginMocks();

    $this->autoLoginUrlLogin->method('login')
      ->willReturn(FALSE);

    $this->autoLoginUrlGeneral->method('validateUserId')
      ->willReturn(TRUE);

    $this->autoLoginUrlGeneral->expects($this->once())
      ->method('registerFlood')
      ->with('test-hash');

    $this->expectException(AccessDeniedHttpException::class);

    $this->controller->login(123, 'test-hash');
  }

  /**
   * @covers ::login
   */
  public function testLoginLogsSecurityEvents(): void {
    $this->killSwitch->method('trigger');

    $this->autoLoginUrlGeneral->method('validateHashFormat')->willReturn(FALSE);

    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Invalid hash format'),
        $this->arrayHasKey('@uid')
      );

    $this->expectException(BadRequestHttpException::class);

    $this->controller->login(123, 'invalid hash');
  }

  /**
   * @covers ::login
   */
  public function testLoginLogsSuccessfulAttempts(): void {
    $this->setupSuccessfulLoginMocks();

    $this->autoLoginUrlLogin->method('login')
      ->willReturn('/user/123');

    $this->logger->expects($this->once())
      ->method('info')
      ->with(
        $this->stringContains('Auto login URL created'),
        $this->arrayHasKey('@uid')
      );

    $this->controller->login(123, 'valid-hash');
  }

  /**
   * Tests URL creation for different destination types.
   */
  public function testDestinationUrlCreation(): void {
    $testCases = [
      // Internal paths.
      '/user/123' => RedirectResponse::class,
      'user/123' => RedirectResponse::class,
      '<front>' => RedirectResponse::class,
      'admin/content' => RedirectResponse::class,

      // External URLs.
      'https://example.com' => TrustedRedirectResponse::class,
      'http://external.org/page' => TrustedRedirectResponse::class,
    ];

    foreach ($testCases as $destination => $expectedClass) {
      $this->setupSuccessfulLoginMocks();

      $this->autoLoginUrlLogin->method('login')
        ->willReturn($destination);

      $response = $this->controller->login(123, 'valid-hash');
      $this->assertInstanceOf($expectedClass, $response, "Failed for destination: {$destination}");
    }
  }

  /**
   * Tests error handling with different exception types.
   */
  public function testErrorHandlingWithDifferentExceptionTypes(): void {
    $exceptionTests = [
      [
        'exception' => new \InvalidArgumentException('Invalid argument'),
        'expectedMessage' => 'Login failed due to system error',
      ],
      [
        'exception' => new \RuntimeException('Runtime error'),
        'expectedMessage' => 'Login failed due to system error',
      ],
      [
        'exception' => new \Exception('Generic error'),
        'expectedMessage' => 'Login failed due to system error',
      ],
    ];

    foreach ($exceptionTests as $test) {
      $this->setupSuccessfulLoginMocks();

      $this->autoLoginUrlLogin->method('login')
        ->willThrowException($test['exception']);

      $this->expectException(AccessDeniedHttpException::class);
      $this->expectExceptionMessage($test['expectedMessage']);

      try {
        $this->controller->login(123, 'test-hash');
      }
      catch (AccessDeniedHttpException $e) {
        // Reset mocks for next iteration.
        $this->setUp();
        throw $e;
      }
    }
  }

  /**
   * Tests that all validation steps are performed in correct order.
   */
  public function testValidationOrder(): void {
    $this->killSwitch->expects($this->once())
      ->method('trigger');

    // First validation: user ID.
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Invalid user ID');

    // Should not call further validation methods.
    $this->autoLoginUrlGeneral->expects($this->never())
      ->method('validateHashFormat');

    $this->autoLoginUrlGeneral->expects($this->never())
      ->method('checkFlood');

    $this->controller->login(0, 'hash');
  }

  /**
   * Tests handling of edge case destinations.
   */
  public function testEdgeCaseDestinations(): void {
    $edgeCases = [
      '/' => '/',
      '//' => '/',
      '///multiple/slashes' => '/multiple/slashes',
      'relative/path' => '/relative/path',
      './relative' => '/relative',
      '../parent' => '/parent',
    ];

    foreach ($edgeCases as $input => $expected) {
      $this->setupSuccessfulLoginMocks();

      $this->autoLoginUrlLogin->method('login')
        ->willReturn($input);

      $response = $this->controller->login(123, 'valid-hash');

      $this->assertInstanceOf(RedirectResponse::class, $response);
      $targetUrl = $response->getTargetUrl();

      // URL should be sanitized appropriately.
      $this->assertIsString($targetUrl);
      $this->assertNotEmpty($targetUrl);
    }
  }

  /**
   * Sets up mocks for successful login scenarios.
   */
  private function setupSuccessfulLoginMocks(): void {
    $this->killSwitch->method('trigger');
    $this->autoLoginUrlGeneral->method('validateHashFormat')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('checkFlood')->willReturn(FALSE);
  }

}
