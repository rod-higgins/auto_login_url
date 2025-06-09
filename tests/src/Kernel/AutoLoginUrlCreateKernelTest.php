<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Kernel;

use Drupal\auto_login_url\AutoLoginUrlCreate;
use Drupal\auto_login_url\Exception\AutoLoginUrlException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * WORKING Kernel tests for AutoLoginUrlCreate service.
 *
 * @group auto_login_url
 * @coversDefaultClass \Drupal\auto_login_url\AutoLoginUrlCreate
 */
final class AutoLoginUrlCreateKernelTest extends KernelTestBase {

  protected static $modules = [
    'auto_login_url',
    'system',
    'user',
    'field',
  ];

  private AutoLoginUrlCreate $urlCreateService;
  private UserInterface $testUser;

  /**
   *
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installConfig(['auto_login_url', 'system', 'user']);
    $this->installSchema('auto_login_url', ['auto_login_url', 'auto_login_url_usage']);

    // Configure high rate limits for testing.
    $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings')
      ->set('max_urls_per_user_per_hour', 1000)
      ->save();

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
   * @covers ::create
   */
  public function testCreateBasicUrl(): void {
    $destination = 'user/' . $this->testUser->id();

    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      $destination,
      FALSE
    );

    $this->assertNotEmpty($url);
    $this->assertStringContainsString('autologinurl', $url);
    $this->assertStringContainsString((string) $this->testUser->id(), $url);

    // Verify database record was created.
    $database = $this->container->get('database');
    $record = $database->select('auto_login_url', 'a')
      ->fields('a')
      ->condition('uid', $this->testUser->id())
      ->condition('destination', $destination)
      ->execute()
      ->fetchAssoc();

    $this->assertNotEmpty($record);
    $this->assertEquals($this->testUser->id(), $record['uid']);
    $this->assertEquals($destination, $record['destination']);
    $this->assertNotEmpty($record['hash']);
    $this->assertGreaterThan(0, $record['timestamp']);
  }

  /**
   * @covers ::create
   */
  public function testCreateAbsoluteUrl(): void {
    $destination = '<front>';

    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      $destination,
      TRUE
    );

    $this->assertNotEmpty($url);
    $this->assertStringStartsWith('http', $url);
    $this->assertStringContainsString('autologinurl', $url);
  }

  /**
   * @covers ::create
   */
  public function testCreateWithDifferentDestinations(): void {
    $destinations = [
      '<front>',
      'user/' . $this->testUser->id(),
      'user/' . $this->testUser->id() . '/edit',
      'admin/content',
      'https://external-site.com/page',
    ];

    foreach ($destinations as $destination) {
      $url = $this->urlCreateService->create(
        (int) $this->testUser->id(),
        $destination,
        TRUE
      );

      $this->assertNotEmpty($url, "Failed to create URL for destination: {$destination}");
      $this->assertStringContainsString('autologinurl', $url);
    }

    // Verify all records were created in database.
    $database = $this->container->get('database');
    $count = $database->select('auto_login_url')
      ->condition('uid', $this->testUser->id())
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(count($destinations), $count);
  }

  /**
   * @covers ::create
   */
  public function testCreateWithInvalidUserId(): void {
    $this->expectException(AutoLoginUrlException::class);
    $this->expectExceptionMessage('Invalid or non-existent user ID');

    $this->urlCreateService->create(99999, '<front>', FALSE);
  }

  /**
   * @covers ::create
   */
  public function testCreateWithBlockedUser(): void {
    // Block the test user.
    $this->testUser->block();
    $this->testUser->save();

    $this->expectException(AutoLoginUrlException::class);
    $this->expectExceptionMessage('Invalid or non-existent user ID');

    $this->urlCreateService->create(
      (int) $this->testUser->id(),
      '<front>',
      FALSE
    );
  }

  /**
   * @covers ::create
   */
  public function testCreateWithInvalidDestination(): void {
    $this->expectException(AutoLoginUrlException::class);
    $this->expectExceptionMessage('Invalid destination URL');

    $this->urlCreateService->create(
      (int) $this->testUser->id(),
    // Empty destination.
      '',
      FALSE
    );
  }

  /**
   * @covers ::create
   */
  public function testCreateWithLongDestination(): void {
    // Over 1000 characters.
    $longDestination = str_repeat('a', 1001);

    $this->expectException(AutoLoginUrlException::class);
    $this->expectExceptionMessage('Invalid destination URL');

    $this->urlCreateService->create(
      (int) $this->testUser->id(),
      $longDestination,
      FALSE
    );
  }

  /**
   * @covers ::create
   */
  public function testCreateGeneratesUniqueHashes(): void {
    $urls = [];
    $hashes = [];

    // Create multiple URLs for the same user and destination.
    for ($i = 0; $i < 5; $i++) {
      $url = $this->urlCreateService->create(
        (int) $this->testUser->id(),
        'user/' . $this->testUser->id(),
        FALSE
      );

      $urls[] = $url;

      // Extract hash from URL.
      preg_match('/autologinurl\/\d+\/([^\/]+)/', $url, $matches);
      $hashes[] = $matches[1] ?? '';
    }

    // All URLs should be different.
    $this->assertEquals(count($urls), count(array_unique($urls)));

    // All hashes should be different.
    $this->assertEquals(count($hashes), count(array_unique($hashes)));

    // All hashes should be non-empty.
    foreach ($hashes as $hash) {
      $this->assertNotEmpty($hash);
    }
  }

  /**
   * @covers ::create
   */
  public function testCreateWithCustomTokenLength(): void {
    // Set custom token length.
    $config = $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings');
    $config->set('token_length', 32);
    $config->save();

    $url = $this->urlCreateService->create(
      (int) $this->testUser->id(),
      '<front>',
      FALSE
    );

    $this->assertNotEmpty($url);

    // Extract hash and verify it's approximately the right length.
    preg_match('/autologinurl\/\d+\/([^\/]+)/', $url, $matches);
    $hash = $matches[1] ?? '';

    // Hash might be base64 encoded, so length could vary slightly.
    $this->assertGreaterThan(20, strlen($hash));
    $this->assertLessThan(50, strlen($hash));
  }

  /**
   * @covers ::convertText
   */
  public function testConvertTextBasic(): void {
    global $base_root;
    $base_root = 'https://example.com';

    $originalText = 'Visit https://example.com/user/' . $this->testUser->id() . ' for your profile.';

    $convertedText = $this->urlCreateService->convertText(
      (int) $this->testUser->id(),
      $originalText
    );

    $this->assertNotEquals($originalText, $convertedText);
    $this->assertStringContainsString('autologinurl', $convertedText);
    $this->assertStringContainsString((string) $this->testUser->id(), $convertedText);
  }

  /**
   * @covers ::convertText
   */
  public function testConvertTextWithMultipleUrls(): void {
    global $base_root;
    $base_root = 'https://example.com';

    $originalText = 'Visit https://example.com/user/' . $this->testUser->id() .
                   ' and https://example.com/admin/content for more options.';

    $convertedText = $this->urlCreateService->convertText(
      (int) $this->testUser->id(),
      $originalText
    );

    // Should convert both URLs.
    $autologinCount = substr_count($convertedText, 'autologinurl');
    $this->assertEquals(2, $autologinCount);
  }

  /**
   * @covers ::convertText
   */
  public function testConvertTextWithInvalidUser(): void {
    $this->expectException(AutoLoginUrlException::class);
    $this->expectExceptionMessage('Invalid user ID provided for text conversion');

    $this->urlCreateService->convertText(99999, 'Some text');
  }

  /**
   * Tests the service handles multiple users correctly.
   */
  public function testMultipleUsers(): void {
    // Create additional test users.
    $users = [];
    for ($i = 0; $i < 3; $i++) {
      $user = User::create([
        'name' => 'testuser' . $i,
        'mail' => 'test' . $i . '@example.com',
        'status' => 1,
        'pass' => 'password123',
      ]);
      $user->save();
      $users[] = $user;
    }

    // Create URLs for each user.
    $urls = [];
    foreach ($users as $user) {
      $url = $this->urlCreateService->create(
        (int) $user->id(),
        'user/' . $user->id(),
        FALSE
      );
      $urls[] = $url;
    }

    // All URLs should be different.
    $this->assertEquals(count($urls), count(array_unique($urls)));

    // Verify database has separate records for each user.
    $database = $this->container->get('database');
    foreach ($users as $user) {
      $count = $database->select('auto_login_url')
        ->condition('uid', $user->id())
        ->countQuery()
        ->execute()
        ->fetchField();

      $this->assertEquals(1, $count);
    }
  }

}
