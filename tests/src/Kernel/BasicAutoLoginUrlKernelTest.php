<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Basic kernel tests for Auto Login URL module.
 *
 * @group auto_login_url
 */
final class BasicAutoLoginUrlKernelTest extends KernelTestBase {

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

    // Configure high rate limits for testing.
    $this->container->get('config.factory')
      ->getEditable('auto_login_url.settings')
      ->set('max_urls_per_user_per_hour', 1000)
      ->save();
  }

  /**
   * Tests that services exist and can be instantiated.
   */
  public function testServicesExist(): void {
    $createService = $this->container->get('auto_login_url.create');
    $this->assertNotNull($createService, 'Auto login URL create service exists.');

    $loginService = $this->container->get('auto_login_url.login');
    $this->assertNotNull($loginService, 'Auto login URL login service exists.');

    $generalService = $this->container->get('auto_login_url.general');
    $this->assertNotNull($generalService, 'Auto login URL general service exists.');
  }

  /**
   * Tests basic URL creation.
   */
  public function testBasicUrlCreation(): void {
    $user = User::create([
      'name' => 'testuser',
      'mail' => 'test@example.com',
      'status' => 1,
      'pass' => 'password123',
    ]);
    $user->save();

    $createService = $this->container->get('auto_login_url.create');
    $url = $createService->create(
      (int) $user->id(),
      '<front>',
      FALSE
    );

    $this->assertNotEmpty($url, 'URL was created successfully.');
    $this->assertIsString($url, 'URL is a string.');
  }

}
