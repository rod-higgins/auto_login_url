<?php

declare(strict_types=1);

namespace Drupal\auto_login_url;

use Drupal\auto_login_url\Exception\AutoLoginUrlException;

/**
 * Helper class for converting text links to auto login URLs.
 *
 * @package Drupal\auto_login_url
 */
final class AutoLoginUrlTextConverter {

  /**
   * The user ID for whom to create auto login URLs.
   */
  private int $uid;

  /**
   * The auto login URL creation service.
   */
  private AutoLoginUrlCreate $urlCreator;

  /**
   * Constructs an AutoLoginUrlTextConverter object.
   *
   * @param int $uid
   *   The user ID.
   * @param \Drupal\auto_login_url\AutoLoginUrlCreate $url_creator
   *   The URL creation service.
   */
  public function __construct(int $uid, AutoLoginUrlCreate $url_creator) {
    $this->uid = $uid;
    $this->urlCreator = $url_creator;
  }

  /**
   * Converts a URL to an auto login version.
   *
   * @param array $matches
   *   The regex matches array.
   *
   * @return string
   *   The converted URL or original URL if conversion fails.
   */
  public function convertUrl(array $matches): string {
    $url = $matches[0] ?? '';

    if (empty($url)) {
      return $url;
    }

    // Skip image files and other non-page URLs.
    if ($this->shouldSkipUrl($url)) {
      return $url;
    }

    try {
      return $this->urlCreator->create($this->uid, $url, TRUE);
    }
    catch (AutoLoginUrlException $e) {
      // Log the error and return original URL.
      \Drupal::logger('auto_login_url')->warning('Failed to convert URL @url: @message', [
        '@url' => $url,
        '@message' => $e->getMessage(),
      ]);
      return $url;
    }
  }

  /**
   * Determines if a URL should be skipped during conversion.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL should be skipped, FALSE otherwise.
   */
  private function shouldSkipUrl(string $url): bool {
    // Skip image files and other media.
    $media_extensions = [
      '.jpg', '.jpeg', '.png', '.gif', '.bmp', '.webp', '.svg',
      '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.zip', '.css', '.js',
    ];

    foreach ($media_extensions as $extension) {
      if (str_contains(strtolower($url), $extension)) {
        return TRUE;
      }
    }

    // Skip anchor links.
    if (str_contains($url, '#')) {
      return TRUE;
    }

    // Skip mailto and tel links.
    if (str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
      return TRUE;
    }

    return FALSE;
  }

}
