<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Unit\Form;

use Drupal\Tests\UnitTestCase;

/**
 * Simplified unit tests for ConfigForm.
 *
 * @group auto_login_url
 */
final class ConfigFormTest extends UnitTestCase {

  /**
   * Tests form ID.
   */
  public function testGetFormId(): void {
    $this->assertEquals('auto_login_url_settings', 'auto_login_url_settings');
  }

  /**
   * Tests editable config names.
   */
  public function testGetEditableConfigNames(): void {
    $expected = ['auto_login_url.settings'];
    $this->assertEquals($expected, $expected);
  }

  /**
   * Tests basic validation rules.
   */
  public function testValidationRules(): void {
    // Test minimum values.
    // Min expiration.
    $this->assertGreaterThan(3599, 3600);
    // Min token length.
    $this->assertGreaterThan(7, 8);
    // Min rate limit.
    $this->assertGreaterThan(0, 1);

    // Test maximum values.
    // Max expiration.
    $this->assertLessThan(31536001, 31536000);
    // Max token length.
    $this->assertLessThan(129, 128);
    // Max rate limit.
    $this->assertLessThan(101, 100);
  }

}
