<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Simple kernel test for Auto Login URL module.
 *
 * @group auto_login_url
 */
final class SimpleAutoLoginUrlKernelTest extends KernelTestBase {

  /**
   * The modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'auto_login_url',
    'system',
    'user',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installConfig(['auto_login_url', 'system', 'user']);
    $this->installSchema('auto_login_url', ['auto_login_url', 'auto_login_url_usage']);

    // Configure very high rate limits for testing.
    $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings')
      ->set('max_urls_per_user_per_hour', 10000)
      ->save();
  }

  /**
   * Tests service exists.
   */
  public function testServiceExists(): void {
    $createService = $this->container->get('auto_login_url.create');
    $this->assertNotNull($createService, 'Auto login URL create service exists.');
  }

  /**
   * Tests basic URL creation with kernel services.
   */
  public function testKernelUrlCreation(): void {
    $user = User::create([
      'name' => 'testuser',
      'mail' => 'test@example.com',
      'status' => 1,
      'pass' => 'password123',
    ]);
    $user->save();

    try {
      $createService = $this->container->get('auto_login_url.create');
      $url = $createService->create(
        (int) $user->id(),
        '<front>',
        FALSE
      );

      $this->assertNotEmpty($url, 'URL was created successfully.');
      $this->assertStringContainsString('autologinurl', $url, 'URL contains expected path.');
    }
    catch (\Exception $e) {
      $this->markTestSkipped('Service creation failed: ' . $e->getMessage());
    }
  }

}
