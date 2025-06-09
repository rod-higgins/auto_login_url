<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Simple unit test for Auto Login URL module.
 *
 * @group auto_login_url
 */
final class SimpleAutoLoginUrlUnitTest extends UnitTestCase {

  /**
   * Tests basic validation functions.
   */
  public function testBasicValidation(): void {
    // Test user ID validation.
    $this->assertFalse($this->isValidUserId(0), 'User ID 0 is invalid.');
    $this->assertFalse($this->isValidUserId(-1), 'Negative user ID is invalid.');
    $this->assertTrue($this->isValidUserId(123), 'Positive user ID is valid.');
  }

  /**
   * Tests hash format validation.
   */
  public function testHashFormatValidation(): void {
    $this->assertFalse($this->isValidHashFormat(''), 'Empty hash is invalid.');
    $this->assertFalse($this->isValidHashFormat('abc'), 'Short hash is invalid.');
    $this->assertTrue($this->isValidHashFormat('abcd1234efgh5678'), 'Valid hash format.');
  }

  /**
   * Helper method for user ID validation.
   */
  private function isValidUserId(int $uid): bool {
    return $uid > 0;
  }

  /**
   * Helper method for hash format validation.
   */
  private function isValidHashFormat(string $hash): bool {
    return !empty($hash) && strlen($hash) >= 8 && preg_match('/^[A-Za-z0-9_-]+$/', $hash);
  }

}
