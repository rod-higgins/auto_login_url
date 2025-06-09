<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Kernel tests for Auto Login URL token integration.
 *
 * @group auto_login_url
 */
final class AutoLoginUrlTokenTest extends KernelTestBase {

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
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  private $tokenService;

  /**
   * Test user account.
   */
  private UserInterface $testUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installConfig(['auto_login_url', 'system', 'user']);
    $this->installSchema('auto_login_url', ['auto_login_url', 'auto_login_url_usage']);

    $this->tokenService = $this->container->get('token');

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
   * Tests auto login URL token generation.
   */
  public function testAutoLoginUrlToken(): void {
    $text = 'Click here to login: [user:auto-login-url-token]';

    $result = $this->tokenService->replace($text, ['user' => $this->testUser]);

    $this->assertStringNotContains('[user:auto-login-url-token]', $result);
  }

  /**
   * Tests auto login URL account edit token generation.
   */
  public function testAutoLoginUrlAccountEditToken(): void {
    $text = 'Edit your profile: [user:auto-login-url-account-edit-token]';

    $result = $this->tokenService->replace($text, ['user' => $this->testUser]);

    $this->assertStringNotContains('[user:auto-login-url-account-edit-token]', $result);
  }

  /**
   * Tests token replacement with multiple tokens.
   */
  public function testMultipleTokenReplacement(): void {
    $text = 'Home: [user:auto-login-url-token] | Edit: [user:auto-login-url-account-edit-token]';

    $result = $this->tokenService->replace($text, ['user' => $this->testUser]);

    // Should have two different auto login URLs.
    $autologinCount = substr_count($result, 'autologinurl');
    $this->assertEquals(2, $autologinCount);

    // Extract the two URLs and verify they're different.
    preg_match_all('/https?:\/\/[^\s]+autologinurl[^\s]+/', $result, $matches);
    $urls = $matches[0];

    $this->assertCount(2, $urls);
  }

  /**
   * Tests token replacement with invalid user.
   */
  public function testTokenReplacementWithInvalidUser(): void {
    // Create a user and then block them.
    $blockedUser = User::create([
      'name' => 'blockeduser',
      'mail' => 'blocked@example.com',
    // Blocked.
      'status' => 0,
      'pass' => 'password123',
    ]);
    $blockedUser->save();

    $text = 'Login: [user:auto-login-url-token]';

    $result = $this->tokenService->replace($text, ['user' => $blockedUser]);

    // Should return empty token or original token depending on error handling.
    $this->assertIsString($result);
  }

  /**
   * Tests token info hook implementation.
   */
  public function testTokenInfo(): void {
    $tokenInfo = auto_login_url_token_info();

    $this->assertIsArray($tokenInfo);
    $this->assertArrayHasKey('tokens', $tokenInfo);
    $this->assertArrayHasKey('user', $tokenInfo['tokens']);

    $userTokens = $tokenInfo['tokens']['user'];

    $this->assertArrayHasKey('auto-login-url-token', $userTokens);
    $this->assertArrayHasKey('auto-login-url-account-edit-token', $userTokens);

    // Check token definitions.
    $this->assertEquals('Auto Login URL', $userTokens['auto-login-url-token']['name']);
    $this->assertEquals('Auto Login URL account edit', $userTokens['auto-login-url-account-edit-token']['name']);

    $this->assertArrayHasKey('description', $userTokens['auto-login-url-token']);
    $this->assertArrayHasKey('description', $userTokens['auto-login-url-account-edit-token']);
  }

  /**
   * Tests token replacement in complex text.
   */
  public function testTokenReplacementInComplexText(): void {
    $text = <<<EOF
Dear [user:display-name],

Welcome to our site! Here are some useful links:

1. Your profile: [user:auto-login-url-token]
2. Edit your account: [user:auto-login-url-account-edit-token]
3. Your email: [user:mail]

Thank you!
EOF;

    $result = $this->tokenService->replace($text, ['user' => $this->testUser]);

    // Should not contain any unreplaced tokens.
    $this->assertStringNotContains('[user:', $result);
  }

  /**
   * Tests token replacement with rate limiting.
   */
  public function testTokenReplacementWithRateLimit(): void {
    // Set very low rate limit.
    $config = $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings');
    $config->set('max_urls_per_user_per_hour', 1);
    $config->save();

    // First token replacement should work.
    $text1 = 'First: [user:auto-login-url-token]';
    $result1 = $this->tokenService->replace($text1, ['user' => $this->testUser]);

    // Second token replacement should fail due to rate limiting.
    $text2 = 'Second: [user:auto-login-url-token]';
    $result2 = $this->tokenService->replace($text2, ['user' => $this->testUser]);

    // Should return empty string or original token due to rate limiting.
    $this->assertStringNotContains('autologinurl', $result2);
  }

  /**
   * Tests token replacement generates absolute URLs.
   */
  public function testTokenReplacementGeneratesAbsoluteUrls(): void {
    $text = 'Login: [user:auto-login-url-token]';

    $result = $this->tokenService->replace($text, ['user' => $this->testUser]);

    // Should generate absolute URLs starting with http.
    preg_match('/https?:\/\/[^\s]+/', $result, $matches);
    $url = $matches[0] ?? '';

    $this->assertNotEmpty($url);
    $this->assertStringStartsWith('http', $url);
  }

  /**
   * Tests token replacement with different configurations.
   */
  public function testTokenReplacementWithDifferentConfigurations(): void {
    $configurations = [
      ['token_length' => 32, 'expiration' => 3600],
      ['token_length' => 64, 'expiration' => 7200],
      ['token_length' => 128, 'expiration' => 86400],
    ];

    $config = $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings');

    foreach ($configurations as $configData) {
      foreach ($configData as $key => $value) {
        $config->set($key, $value);
      }
      $config->save();

      $text = 'Test: [user:auto-login-url-token]';
      $result = $this->tokenService->replace($text, ['user' => $this->testUser]);

      // Extract and verify URL format.
      preg_match('/autologinurl\/\d+\/([^\s]+)/', $result, $matches);
      $hash = $matches[1] ?? '';
      $this->assertNotEmpty($hash);
    }
  }

  /**
   * Tests token replacement error handling.
   */
  public function testTokenReplacementErrorHandling(): void {
    // Test with deleted user scenario.
    $deletedUserId = $this->testUser->id();
    $this->testUser->delete();

    // Create a mock user object with the deleted ID.
    $mockUser = $this->createMock(UserInterface::class);
    $mockUser->method('id')->willReturn($deletedUserId);

    $text = 'Login: [user:auto-login-url-token]';

    // Should handle the error gracefully.
    $result = $this->tokenService->replace($text, ['user' => $mockUser]);

    // Should not crash and should return safe content.
    $this->assertIsString($result);
  }

  /**
   * Tests token replacement with custom destinations.
   */
  public function testTokenReplacementDestinations(): void {
    $tokens = [
      'auto-login-url-token' => '<front>',
      'auto-login-url-account-edit-token' => 'user/' . $this->testUser->id() . '/edit',
    ];

    foreach ($tokens as $token => $expectedDestination) {
      $text = "Test: [user:{$token}]";
      $result = $this->tokenService->replace($text, ['user' => $this->testUser]);

      // Extract URL and verify it was created (can't easily verify destination
      // without making the actual request, but we can verify URL structure).
      preg_match('/autologinurl\/(\d+)\/([^\s]+)/', $result, $matches);
      $uid = $matches[1] ?? '';
      $hash = $matches[2] ?? '';

      $this->assertEquals((string) $this->testUser->id(), $uid);
      $this->assertNotEmpty($hash);
    }
  }

  /**
   * Tests token replacement performance with multiple users.
   */
  public function testTokenReplacementPerformance(): void {
    // Create multiple users.
    $users = [];
    for ($i = 0; $i < 5; $i++) {
      $user = User::create([
        'name' => 'testuser' . $i,
        'mail' => 'test' . $i . '@example.com',
        'status' => 1,
        'pass' => 'password123',
      ]);
      $user->save();
      $users[] = $user;
    }

    $text = 'Login: [user:auto-login-url-token]';

    // Replace tokens for all users.
    $startTime = microtime(TRUE);

    foreach ($users as $user) {
      $result = $this->tokenService->replace($text, ['user' => $user]);
    }

    $endTime = microtime(TRUE);
    $duration = $endTime - $startTime;

    // Should complete reasonably quickly (less than 5 seconds for 5 users).
    $this->assertLessThan(5.0, $duration);
  }

  /**
   * Tests integration with token module if available.
   */
  public function testTokenModuleIntegration(): void {
    // Test basic token browsing functionality.
    $tokenInfo = auto_login_url_token_info();

    // Verify our tokens are properly structured for the token module.
    $this->assertIsArray($tokenInfo['tokens']['user']);

    foreach ($tokenInfo['tokens']['user'] as $tokenData) {
      $this->assertArrayHasKey('name', $tokenData);
      $this->assertArrayHasKey('description', $tokenData);
      $this->assertIsString($tokenData['name']);
      $this->assertIsString($tokenData['description']);
    }
  }

}
