<?php

declare(strict_types=1);

namespace Drupal\auto_login_url\Controller;

use Drupal\auto_login_url\AutoLoginUrlGeneral;
use Drupal\auto_login_url\AutoLoginUrlLogin;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for auto login URL functionality.
 *
 * @package Drupal\auto_login_url\Controller
 */
final class AutoLoginUrlMainController extends ControllerBase {

  /**
   * The kill switch for page caching.
   */
  private KillSwitch $killSwitch;

  /**
   * The Auto Login Url General service.
   */
  private AutoLoginUrlGeneral $autoLoginUrlGeneral;

  /**
   * The Auto Login Url Login service.
   */
  private AutoLoginUrlLogin $autoLoginUrlLogin;

  /**
   * The logger channel.
   */
  private LoggerChannelInterface $logger;

  /**
   * Constructs an AutoLoginUrlMainController object.
   *
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $kill_switch
   *   The kill switch service.
   * @param \Drupal\auto_login_url\AutoLoginUrlGeneral $auto_login_url_general
   *   The Auto Login Url General service.
   * @param \Drupal\auto_login_url\AutoLoginUrlLogin $auto_login_url_login
   *   The Auto Login Url Login service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(
    KillSwitch $kill_switch,
    AutoLoginUrlGeneral $auto_login_url_general,
    AutoLoginUrlLogin $auto_login_url_login,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->killSwitch = $kill_switch;
    $this->autoLoginUrlGeneral = $auto_login_url_general;
    $this->autoLoginUrlLogin = $auto_login_url_login;
    $this->logger = $logger_factory->get('auto_login_url');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('page_cache_kill_switch'),
      $container->get('auto_login_url.general'),
      $container->get('auto_login_url.login'),
      $container->get('logger.factory')
    );
  }

  /**
   * Handles auto login requests.
   *
   * @param int $uid
   *   The user ID from the URL.
   * @param string $hash
   *   The authentication hash from the URL.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Drupal\Core\Routing\TrustedRedirectResponse
   *   A redirect response to the destination or an error page.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when access is denied due to flood control or invalid credentials.
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Thrown when the request parameters are invalid.
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the login data is not found.
   */
  public function login(int $uid, string $hash): RedirectResponse|TrustedRedirectResponse {
    // Always disable page cache for login attempts.
    $this->killSwitch->trigger();

    try {
      // Validate request parameters.
      $this->validateRequest($uid, $hash);

      // Check for flood protection.
      $this->checkFloodProtection();

      // Attempt login.
      $destination = $this->attemptLogin($uid, $hash);

      // Create appropriate redirect response.
      return $this->createRedirectResponse($destination);

    }
    catch (AccessDeniedHttpException | BadRequestHttpException | NotFoundHttpException $e) {
      // Re-throw HTTP exceptions.
      throw $e;
    }
    catch (\Exception $e) {
      // Log unexpected errors and register flood event.
      $this->logger->error('Unexpected error during auto login: @message', [
        '@message' => $e->getMessage(),
      ]);

      $this->autoLoginUrlGeneral->registerFlood($hash);
      throw new AccessDeniedHttpException('Login failed due to system error');
    }
  }

  /**
   * Validates the incoming request parameters.
   *
   * @param int $uid
   *   The user ID.
   * @param string $hash
   *   The hash token.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Thrown when parameters are invalid.
   */
  private function validateRequest(int $uid, string $hash): void {
    if ($uid <= 0) {
      $this->logger->warning('Invalid user ID @uid in auto login request', ['@uid' => $uid]);
      throw new BadRequestHttpException('Invalid user ID');
    }

    if (empty($hash) || !$this->autoLoginUrlGeneral->validateHashFormat($hash)) {
      $this->logger->warning('Invalid hash format in auto login request for user @uid', ['@uid' => $uid]);
      throw new BadRequestHttpException('Invalid hash format');
    }
  }

  /**
   * Checks flood protection and blocks if necessary.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when the IP is blocked by flood protection.
   */
  private function checkFloodProtection(): void {
    if ($this->autoLoginUrlGeneral->checkFlood()) {
      $client_ip = $this->autoLoginUrlGeneral->getClientIp();

      $this->logger->warning('Auto login blocked due to flood protection for IP @ip', [
        '@ip' => $client_ip,
      ]);

      $this->messenger()->addError(
        $this->t('Sorry, too many failed login attempts from your IP address. This IP address is temporarily blocked. Try again later.')
      );

      throw new AccessDeniedHttpException('Too many failed login attempts');
    }
  }

  /**
   * Attempts to perform the login.
   *
   * @param int $uid
   *   The user ID.
   * @param string $hash
   *   The hash token.
   *
   * @return string
   *   The destination URL.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when login fails.
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the login data is not found.
   */
  private function attemptLogin(int $uid, string $hash): string {
    $destination = $this->autoLoginUrlLogin->login($uid, $hash);

    if ($destination === FALSE) {
      // Register flood event for failed attempt.
      $this->autoLoginUrlGeneral->registerFlood($hash);

      // Determine the type of failure for appropriate response.
      if (!$this->autoLoginUrlGeneral->validateUserId($uid)) {
        throw new NotFoundHttpException('User not found');
      }

      throw new AccessDeniedHttpException('Invalid or expired login token');
    }

    return $destination;
  }

  /**
   * Creates an appropriate redirect response.
   *
   * @param string $destination
   *   The destination URL.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Drupal\Core\Routing\TrustedRedirectResponse
   *   The redirect response.
   */
  private function createRedirectResponse(string $destination): RedirectResponse|TrustedRedirectResponse {
    // Validate and sanitize destination URL.
    $destination = $this->sanitizeDestination($destination);

    // Check if it's an external URL that needs trusted redirect.
    if ($this->isExternalUrl($destination)) {
      return new TrustedRedirectResponse($destination, 302);
    }

    return new RedirectResponse($destination, 302);
  }

  /**
   * Sanitizes the destination URL.
   *
   * @param string $destination
   *   The raw destination URL.
   *
   * @return string
   *   The sanitized destination URL.
   */
  private function sanitizeDestination(string $destination): string {
    // Remove any potentially dangerous characters.
    $destination = filter_var($destination, FILTER_SANITIZE_URL);

    if (empty($destination)) {
      $this->logger->warning('Invalid destination URL, redirecting to front page');
      return '/';
    }

    return $destination;
  }

  /**
   * Checks if a URL is external.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if external, FALSE if internal.
   */
  private function isExternalUrl(string $url): bool {
    return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
  }

  /**
   * Provides a health check endpoint for monitoring.
   *
   * @return array
   *   A render array with health status.
   */
  public function healthCheck(): array {
    return [
      '#type' => 'markup',
      '#markup' => $this->t('Auto Login URL service is operational'),
      '#cache' => [
    // Cache for 5 minutes.
        'max-age' => 300,
      ],
    ];
  }

}
