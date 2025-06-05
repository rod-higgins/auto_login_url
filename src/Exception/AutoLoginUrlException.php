<?php

declare(strict_types=1);

namespace Drupal\auto_login_url\Exception;

/**
 * Custom exception for Auto Login URL module.
 */
final class AutoLoginUrlException extends \Exception {

  /**
   * Constructs an AutoLoginUrlException.
   *
   * @param string $message
   *   The exception message.
   * @param int $code
   *   The exception code.
   * @param \Throwable|null $previous
   *   The previous exception.
   */
  public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = NULL) {
    parent::__construct($message, $code, $previous);
  }

}
