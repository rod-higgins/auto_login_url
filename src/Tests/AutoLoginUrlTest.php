<?php

namespace Drupal\auto_login_url\Tests;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;

/**
 * AutoLoginUrlTestCase Class.
 *
 * @ingroup Auto Login URL test
 * @group Auto Login URL
 */
class AutoLoginUrlTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['auto_login_url'];

  /**
   * The installation profile to use with this test.
   *
   * @var string
   */
  protected $profile = 'minimal';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $role = Role::load('authenticated');
    $role->grantPermission('use auto login url');
    $role->save();
    $role = Role::load('anonymous');
    $role->grantPermission('use auto login url');
    $role->save();
    $this->additionalCurlOptions = [CURLOPT_FOLLOWLOCATION => TRUE];

  }

  /**
   * Test token generation.
   */
  public function testAluTokenGenerationCheck() {

    // Create user.
    $user = $this->createUser([
      'use auto login url',
    ]);

    // Create an auto login url for this user.
    $url = auto_login_url_create($user->get('uid')->value, 'user/' . $user->get('uid')->value);

    // Access url.
    $this->drupalGet($url);

    // Make assertions.
    $this->assertSession()->statusCodeEquals(200, t('User logged in successfully.'));
    $this->assertSession()->pageTextContains($user->get('name')->value);

    // Create another user and login again.
    $user2 = $this->createUser([
      'use auto login url',
    ]);

    // Create an auto login url for this user.
    $url = auto_login_url_create($user2->get('uid')->value, 'user/' . $user2->get('uid')->value);

    // Access url.
    $this->drupalGet($url);

    // Make assertions.
    $this->assertSession()->statusCodeEquals(200, t('User 2 logged in successfully.'));
    $this->assertSession()->pageTextContains($user2->get('name')->value);
  }

  /**
   * Test token generation with different settings.
   */
  public function testAluSettingsCheck() {

    // Change settings.
    $config = $this->config('auto_login_url.settings');
    $config->set('secret', 'new secret')->save();
    $config->set('token_length', 8)->save();

    // Create user.
    $user = $this->createUser([
      'use auto login url',
    ]);

    // Create an auto login url for this user.
    $url = auto_login_url_create($user->get('uid')->value, 'user/' . $user->get('uid')->value);

    // Access url.
    $this->drupalGet($url);

    // Make assertions.
    $this->assertSession()->statusCodeEquals(200, t('User logged in successfully.'));
    $this->assertSession()->pageTextContains($user->get('name')->value);
  }

  /**
   * Test flood.
   */
  public function testAluFloodCheck() {

    // Set failed attempts to 5 for easier testing.
    $flood_config = $this->config('user.flood');
    $flood_config->set('ip_limit', 5)->save();

    // Create user.
    $user = $this->createUser([
      'use auto login url',
    ]);

    // Access 10 false URLs. Essentially triggering flood.
    for ($i = 1; $i < 6; $i++) {
      $this->drupalGet('autologinurl/' . $i . '/some-token' . $i);
      $this->assertSession()->statusCodeEquals(403, t('Got access denied page.'));
    }

    // Generate actual auto login url for this user.
    $url = auto_login_url_create($user->get('uid')->value, 'user/' . $user->get('uid')->value);

    // Access url.
    $this->drupalGet($url);

    // Make assertions.
    $this->assertSession()->statusCodeEquals(403, t('Got access denied page.'));
    $this->assertSession()->pageTextContains(t('Sorry, too many failed login attempts from your IP address. This IP address is temporarily blocked. Try again later.'));

    // Clear flood table. I am using sql instead of the flood interface
    // (\Drupal::flood()->clear('user.failed_login_ip');) because it does not
    // seem to work. But it is not a problem at this point since we know the
    // flood records will be on DB anyway.
    $connection = \Drupal::database();
    $connection->truncate('flood')->execute();

    // Try to login again.
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200, t('User logged in successfully.'));
    $this->assertSession()->pageTextContains($user->get('name')->value);
  }

}
