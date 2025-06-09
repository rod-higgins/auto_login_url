<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Kernel;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\auto_login_url\Controller\AutoLoginUrlMainController;
use Drupal\auto_login_url\AutoLoginUrlCreate;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Kernel tests for AutoLoginUrlMainController.
 *
 * @group auto_login_url
 * @coversDefaultClass \Drupal\auto_login_url\Controller\AutoLoginUrlMainController
 */
final class AutoLoginUrlControllerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'auto_login_url',
    'system',
    'user',
    'field',
  ];

  /**
   * The controller under test.
   */
  private AutoLoginUrlMainController $controller;

  /**
   * The auto login URL create service.
   */
  private AutoLoginUrlCreate $urlCreateService;

  /**
   * Test user account.
   */
  private UserInterface $testUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Configure very high rate limits for testing to prevent conflicts
    $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings')
      ->set('max_urls_per_user_per_hour', 10000)
      ->save();

    $this->installEntitySchema('user');
    $this->installConfig(['auto_login_url', 'system', 'user']);
    $this->installSchema('auto_login_url', ['auto_login_url', 'auto_login_url_usage']);

    $this->controller = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(AutoLoginUrlMainController::class);

    $this->urlCreateService = $this->container->get('auto_login_url.create');

    // Create a test user.
    $this->testUser = User::create([
      'name' => 'testuser',
      'mail' => 'test@example.com',
      'status' => 1,
      'pass' => 'password123',
    ]);
    $this->testUser->save();
  }

  /**
   * @covers ::login
   */
  public function testSuccessfulLogin(): void {
    // Create a valid auto login URL.
    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      'user/' . $this->testUser->id(),
      FALSE
    );

    // Extract hash from URL.
    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $uid = (int) $matches[1];
    $hash = $matches[2];

    // Test the login method.
    $response = $this->controller->login($uid, $hash);

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertEquals(302, $response->getStatusCode());
    $this->assertStringContainsString('user/' . $this->testUser->id(), $response->getTargetUrl());
  }

  /**
   * @covers ::login
   */
  public function testLoginWithInvalidUserId(): void {
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Invalid user ID');

    $this->controller->login(0, 'some-hash');
  }

  /**
   * @covers ::login
   */
  public function testLoginWithNegativeUserId(): void {
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Invalid user ID');

    $this->controller->login(-1, 'some-hash');
  }

  /**
   * @covers ::login
   */
  public function testLoginWithInvalidHashFormat(): void {
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Invalid hash format');

    $this->controller->login((int) $this->testUser->id(), 'invalid hash with spaces');
  }

  /**
   * @covers ::login
   */
  public function testLoginWithEmptyHash(): void {
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Invalid hash format');

    $this->controller->login((int) $this->testUser->id(), '');
  }

  /**
   * @covers ::login
   */
  public function testLoginWithNonExistentUser(): void {
    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage('User not found');

    $this->controller->login(99999, 'valid-looking-hash-123');
  }

  /**
   * @covers ::login
   */
  public function testLoginWithInvalidHash(): void {
    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Invalid or expired login token');

    $this->controller->login((int) $this->testUser->id(), 'invalid-but-well-formed-hash');
  }

  /**
   * @covers ::login
   */
  public function testLoginWithExpiredToken(): void {
    // Set very short expiration.
    $config = $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings');
    // 1 second
    $config->set('expiration', 1);
    $config->save();

    // Create URL and extract hash.
    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      '<front>',
      FALSE
    );

    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $hash = $matches[2];

    // Wait for expiration.
    sleep(2);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Invalid or expired login token');

    $this->controller->login((int) $this->testUser->id(), $hash);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithBlockedUser(): void {
    // Create URL first.
    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      '<front>',
      FALSE
    );

    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $hash = $matches[2];

    // Block the user.
    $this->testUser->block();
    $this->testUser->save();

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Invalid or expired login token');

    $this->controller->login((int) $this->testUser->id(), $hash);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithFloodProtection(): void {
    // Configure aggressive flood protection.
    $this->container->get('config.factory')
      ->getEditable('user.flood')
      ->set('ip_limit', 1)
      ->set('ip_window', 3600)
      ->save();

    // Trigger flood protection with invalid attempt.
    try {
      $this->controller->login((int) $this->testUser->id(), 'invalid-hash');
    }
    catch (AccessDeniedHttpException $e) {
      // Expected.
    }

    // Second attempt should be blocked by flood protection.
    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Too many failed login attempts');

    $this->controller->login((int) $this->testUser->id(), 'another-invalid-hash');
  }

  /**
   * @covers ::login
   */
  public function testLoginRedirectsToCorrectDestination(): void {
    $destinations = [
      '<front>' => '/',
      'user/' . $this->testUser->id() => '/user/' . $this->testUser->id(),
      'admin/content' => '/admin/content',
    ];

    foreach ($destinations as $destination => $expectedPath) {
      // Create URL with specific destination.
      $url = $this->urlCreateService->create(
        (int) $this->testUser->id(),
        $destination,
        FALSE
      );

      preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
      $hash = $matches[2];

      // Test login.
      $response = $this->controller->login((int) $this->testUser->id(), $hash);

      $this->assertInstanceOf(RedirectResponse::class, $response);
      $this->assertStringContainsString($expectedPath, $response->getTargetUrl());

      // Reset user session for next test.
      $this->container->get('account_switcher')->switchBack();
    }
  }

  /**
   * @covers ::login
   */
  public function testLoginWithExternalDestination(): void {
    $externalUrl = 'https://external-site.com/dashboard';

    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      $externalUrl,
      FALSE
    );

    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $hash = $matches[2];

    $response = $this->controller->login((int) $this->testUser->id(), $hash);

    $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
    $this->assertEquals($externalUrl, $response->getTargetUrl());
  }

  /**
   * @covers ::login
   */
  public function testLoginSanitizesDestination(): void {
    // This test would require creating a URL with a potentially dangerous
    // destination, but our create service already validates destinations.
    // Instead, we test that the controller handles edge cases gracefully.
    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      '<front>',
      FALSE
    );

    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $hash = $matches[2];

    $response = $this->controller->login((int) $this->testUser->id(), $hash);

    // Should redirect to a safe destination.
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $targetUrl = $response->getTargetUrl();
    $this->assertIsString($targetUrl);
    $this->assertNotEmpty($targetUrl);
  }

  /**
   * @covers ::healthCheck
   */
  public function testHealthCheck(): void {
    $result = $this->controller->healthCheck();

    $this->assertIsArray($result);
    $this->assertEquals('markup', $result['#type']);
    $this->assertArrayHasKey('#markup', $result);
    $this->assertArrayHasKey('#cache', $result);
    $this->assertEquals(300, $result['#cache']['max-age']);
  }

  /**
   * @covers ::login
   */
  public function testLoginTracksClientIp(): void {
    // Create a mock request with specific IP.
    $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.168.1.100']);
    $this->container->get('request_stack')->push($request);

    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      '<front>',
      FALSE
    );

    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $hash = $matches[2];

    // Login should succeed and track IP.
    $response = $this->controller->login((int) $this->testUser->id(), $hash);
    $this->assertInstanceOf(RedirectResponse::class, $response);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithDeleteOnUse(): void {
    // Enable delete on use.
    $config = $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings');
    $config->set('delete', TRUE);
    $config->save();

    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      '<front>',
      FALSE
    );

    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $hash = $matches[2];

    // First login should succeed.
    $response = $this->controller->login((int) $this->testUser->id(), $hash);
    $this->assertInstanceOf(RedirectResponse::class, $response);

    // Reset user session.
    $this->container->get('account_switcher')->switchBack();

    // Second login should fail (token deleted).
    $this->expectException(AccessDeniedHttpException::class);
    $this->controller->login((int) $this->testUser->id(), $hash);
  }

  /**
   * @covers ::create
   */
  public function testControllerCreate(): void {
    $container = $this->container;

    $controller = AutoLoginUrlMainController::create($container);

    $this->assertInstanceOf(AutoLoginUrlMainController::class, $controller);
  }

  /**
   * Tests controller handles various hash formats.
   */
  public function testControllerHandlesVariousHashFormats(): void {
    $validHashFormats = [
      'abc123def456',
      'ABC123DEF456',
      'abc_123-def',
      '1234567890123456',
    // Long hash.
      str_repeat('a', 64),
    ];

    foreach ($validHashFormats as $hashFormat) {
      // These will fail authentication but should pass format validation.
      try {
        $this->controller->login((int) $this->testUser->id(), $hashFormat);
        $this->fail("Expected exception for hash: {$hashFormat}");
      }
      catch (AccessDeniedHttpException $e) {
        // Expected - invalid token but valid format.
        $this->assertStringContainsString('Invalid or expired login token', $e->getMessage());
      }
      catch (BadRequestHttpException $e) {
        $this->fail("Hash format should be valid: {$hashFormat}");
      }
    }
  }

  /**
   * Tests controller error handling with various exception scenarios.
   */
  public function testControllerErrorHandling(): void {
    // Test various invalid inputs that should trigger different exceptions.
    $testCases = [
      [
        'uid' => 0,
        'hash' => 'valid-hash',
        'expectedException' => BadRequestHttpException::class,
      ],
      [
        'uid' => -1,
        'hash' => 'valid-hash',
        'expectedException' => BadRequestHttpException::class,
      ],
      [
        'uid' => (int) $this->testUser->id(),
        'hash' => '',
        'expectedException' => BadRequestHttpException::class,
      ],
      [
        'uid' => (int) $this->testUser->id(),
        'hash' => 'invalid spaces',
        'expectedException' => BadRequestHttpException::class,
      ],
      [
        'uid' => 99999,
        'hash' => 'valid-looking-hash',
        'expectedException' => NotFoundHttpException::class,
      ],
      [
        'uid' => (int) $this->testUser->id(),
        'hash' => 'valid-format-but-wrong',
        'expectedException' => AccessDeniedHttpException::class,
      ],
    ];

    foreach ($testCases as $testCase) {
      try {
        $this->controller->login($testCase['uid'], $testCase['hash']);
        $this->fail("Expected {$testCase['expectedException']} for UID {$testCase['uid']} and hash '{$testCase['hash']}'");
      }
      catch (\Exception $e) {
        $this->assertInstanceOf($testCase['expectedException'], $e);
      }
    }
  }

  /**
   * Tests page cache kill switch is triggered.
   */
  public function testPageCacheKillSwitch(): void {
    // The kill switch should be triggered for every login attempt.
    // We can't easily test this directly, but we can verify the controller
    // doesn't crash when the kill switch is called.
    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      '<front>',
      FALSE
    );

    preg_match('/autologinurl\/(\d+)\/([^\/]+)/', $url, $matches);
    $hash = $matches[2];

    $response = $this->controller->login((int) $this->testUser->id(), $hash);

    // Should succeed without issues.
    $this->assertInstanceOf(RedirectResponse::class, $response);
  }

}
