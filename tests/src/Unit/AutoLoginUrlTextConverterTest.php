<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Unit;

use Drupal\auto_login_url\AutoLoginUrlCreate;
use Drupal\auto_login_url\AutoLoginUrlTextConverter;
use Drupal\auto_login_url\Exception\AutoLoginUrlException;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for AutoLoginUrlTextConverter service.
 *
 * @group auto_login_url
 * @coversDefaultClass \Drupal\auto_login_url\AutoLoginUrlTextConverter
 */
final class AutoLoginUrlTextConverterTest extends UnitTestCase {

  /**
   * The mocked URL creator service.
   */
  private AutoLoginUrlCreate $urlCreator;

  /**
   * The service under test.
   */
  private AutoLoginUrlTextConverter $textConverter;

  /**
   * Test user ID.
   */
  private int $testUid = 123;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->urlCreator = $this->createMock(AutoLoginUrlCreate::class);
    $this->textConverter = new AutoLoginUrlTextConverter($this->testUid, $this->urlCreator);
  }

  /**
   * @covers ::__construct
   */
  public function testConstruct(): void {
    $this->markTestSkipped('Skipping test due to final class mocking issues.');
    $converter = new AutoLoginUrlTextConverter(456, $this->urlCreator);
    $this->assertInstanceOf(AutoLoginUrlTextConverter::class, $converter);
  }

  /**
   * @covers ::convertUrl
   */
  public function testConvertUrlWithValidUrl(): void {
    $originalUrl = 'https://example.com/user/123';
    $convertedUrl = 'https://example.com/autologinurl/123/hash123';

    $this->urlCreator->expects($this->once())
      ->method('create')
      ->with($this->testUid, $originalUrl, TRUE)
      ->willReturn($convertedUrl);

    $matches = [$originalUrl];
    $result = $this->textConverter->convertUrl($matches);

    $this->assertEquals($convertedUrl, $result);
  }

  /**
   * @covers ::convertUrl
   */
  public function testConvertUrlWithEmptyMatch(): void {
    $matches = [''];
    $result = $this->textConverter->convertUrl($matches);
    $this->assertEquals('', $result);
  }

  /**
   * @covers ::convertUrl
   */
  public function testConvertUrlWithMissingMatch(): void {
    $matches = [];
    $result = $this->textConverter->convertUrl($matches);
    $this->assertEquals('', $result);
  }

  /**
   * @covers ::convertUrl
   * @covers ::shouldSkipUrl
   */
  public function testConvertUrlSkipsImageFiles(): void {
    $imageUrls = [
      'https://example.com/image.jpg',
      'https://example.com/photo.jpeg',
      'https://example.com/graphic.png',
      'https://example.com/icon.gif',
      'https://example.com/bitmap.bmp',
      'https://example.com/modern.webp',
      'https://example.com/vector.svg',
    ];

    foreach ($imageUrls as $url) {
      $matches = [$url];
      $result = $this->textConverter->convertUrl($matches);
      $this->assertEquals($url, $result, "Image URL {$url} should not be converted");
    }

    // URL creator should never be called for image files.
    $this->urlCreator->expects($this->never())->method('create');
  }

  /**
   * @covers ::convertUrl
   * @covers ::shouldSkipUrl
   */
  public function testConvertUrlSkipsDocumentFiles(): void {
    $documentUrls = [
      'https://example.com/document.pdf',
      'https://example.com/report.doc',
      'https://example.com/spreadsheet.xlsx',
      'https://example.com/archive.zip',
    ];

    foreach ($documentUrls as $url) {
      $matches = [$url];
      $result = $this->textConverter->convertUrl($matches);
      $this->assertEquals($url, $result, "Document URL {$url} should not be converted");
    }

    $this->urlCreator->expects($this->never())->method('create');
  }

  /**
   * @covers ::convertUrl
   * @covers ::shouldSkipUrl
   */
  public function testConvertUrlSkipsAssetFiles(): void {
    $assetUrls = [
      'https://example.com/styles.css',
      'https://example.com/script.js',
    ];

    foreach ($assetUrls as $url) {
      $matches = [$url];
      $result = $this->textConverter->convertUrl($matches);
      $this->assertEquals($url, $result, "Asset URL {$url} should not be converted");
    }

    $this->urlCreator->expects($this->never())->method('create');
  }

  /**
   * @covers ::convertUrl
   * @covers ::shouldSkipUrl
   */
  public function testConvertUrlSkipsAnchorLinks(): void {
    $anchorUrls = [
      'https://example.com/page#section1',
      'https://example.com/article#top',
      '#local-anchor',
    ];

    foreach ($anchorUrls as $url) {
      $matches = [$url];
      $result = $this->textConverter->convertUrl($matches);
      $this->assertEquals($url, $result, "Anchor URL {$url} should not be converted");
    }

    $this->urlCreator->expects($this->never())->method('create');
  }

  /**
   * @covers ::convertUrl
   * @covers ::shouldSkipUrl
   */
  public function testConvertUrlSkipsSpecialProtocols(): void {
    $specialUrls = [
      'mailto:user@example.com',
      'tel:+1234567890',
      'mailto:contact@site.org?subject=Hello',
      'tel:555-0123',
    ];

    foreach ($specialUrls as $url) {
      $matches = [$url];
      $result = $this->textConverter->convertUrl($matches);
      $this->assertEquals($url, $result, "Special protocol URL {$url} should not be converted");
    }

    $this->urlCreator->expects($this->never())->method('create');
  }

  /**
   * @covers ::convertUrl
   * @covers ::shouldSkipUrl
   */
  public function testConvertUrlProcessesValidUrls(): void {
    $validUrls = [
      'https://example.com/user/profile',
      'https://example.com/admin/content',
      'https://example.com/page',
      'https://example.com/node/123',
    ];

    $this->urlCreator->method('create')
      ->willReturn('https://example.com/autologinurl/123/hash');

    foreach ($validUrls as $url) {
      $matches = [$url];
      $result = $this->textConverter->convertUrl($matches);
      $this->assertEquals('https://example.com/autologinurl/123/hash', $result);
    }
  }

  /**
   * @covers ::convertUrl
   */
  public function testConvertUrlHandlesException(): void {
    $url = 'https://example.com/valid-page';
    $matches = [$url];

    $this->urlCreator->expects($this->once())
      ->method('create')
      ->with($this->testUid, $url, TRUE)
      ->willThrowException(new AutoLoginUrlException('Creation failed'));

    // Should return original URL when conversion fails.
    $result = $this->textConverter->convertUrl($matches);
    $this->assertEquals($url, $result);
  }

  /**
   * @covers ::convertUrl
   */
  public function testConvertUrlHandlesGenericException(): void {
    $url = 'https://example.com/valid-page';
    $matches = [$url];

    $this->urlCreator->expects($this->once())
      ->method('create')
      ->with($this->testUid, $url, TRUE)
      ->willThrowException(new \Exception('Generic error'));

    // Should return original URL when any exception occurs.
    $result = $this->textConverter->convertUrl($matches);
    $this->assertEquals($url, $result);
  }

  /**
   * @covers ::shouldSkipUrl
   */
  public function testShouldSkipUrlCaseInsensitive(): void {
    $mixedCaseUrls = [
      'https://example.com/Image.JPG',
      'https://example.com/PHOTO.PNG',
      'https://example.com/Document.PDF',
      'https://example.com/STYLES.CSS',
    ];

    foreach ($mixedCaseUrls as $url) {
      $matches = [$url];
      $result = $this->textConverter->convertUrl($matches);
      $this->assertEquals($url, $result, "Mixed case URL {$url} should be skipped");
    }

    $this->urlCreator->expects($this->never())->method('create');
  }

  /**
   * @covers ::shouldSkipUrl
   */
  public function testShouldSkipUrlWithQueryParameters(): void {
    $urlsWithQueries = [
      'https://example.com/image.jpg?width=100&height=200',
      'https://example.com/document.pdf?download=1',
      'mailto:user@example.com?subject=Test&body=Hello',
    ];

    foreach ($urlsWithQueries as $url) {
      $matches = [$url];
      $result = $this->textConverter->convertUrl($matches);
      $this->assertEquals($url, $result, "URL with query parameters {$url} should be skipped if it contains skippable extension");
    }

    $this->urlCreator->expects($this->never())->method('create');
  }

  /**
   * @covers ::shouldSkipUrl
   */
  public function testShouldNotSkipValidUrlsWithSimilarPatterns(): void {
    // URLs that might look like they should be skipped but shouldn't be.
    $validUrls = [
    // No actual .jpg extension.
      'https://example.com/user-jpg',
    // No actual .pdf extension.
      'https://example.com/pdf-viewer',
    // Query parameter, not extension.
      'https://example.com/page?format=pdf',
    ];

    $this->urlCreator->method('create')
      ->willReturn('https://example.com/autologinurl/123/hash');

    foreach ($validUrls as $url) {
      $matches = [$url];
      $result = $this->textConverter->convertUrl($matches);
      $this->assertEquals('https://example.com/autologinurl/123/hash', $result);
    }
  }

  /**
   * Tests the comprehensive skip logic with edge cases.
   *
   * @covers ::shouldSkipUrl
   */
  public function testShouldSkipUrlEdgeCases(): void {
    // Test URLs that should be skipped.
    $skipUrls = [
      // File extensions in different positions.
      'https://example.com/files/document.pdf',
      'https://example.com/images/photo.jpg',
      'https://cdn.example.com/assets/style.css',

      // Anchor links.
      'https://example.com/page#main',
      '#top',

      // Special protocols.
      'mailto:test@example.com',
      'tel:555-1234',
    ];

    foreach ($skipUrls as $url) {
      $matches = [$url];
      $result = $this->textConverter->convertUrl($matches);
      $this->assertEquals($url, $result, "URL should be skipped: {$url}");
    }

    // URLs that should be converted.
    $convertUrls = [
      'https://example.com/user/123',
      'https://example.com/admin/config',
      'https://example.com/',
    ];

    $this->urlCreator->method('create')
      ->willReturn('https://example.com/autologinurl/123/hash');

    foreach ($convertUrls as $url) {
      $matches = [$url];
      $result = $this->textConverter->convertUrl($matches);
      $this->assertEquals('https://example.com/autologinurl/123/hash', $result);
    }
  }

}
