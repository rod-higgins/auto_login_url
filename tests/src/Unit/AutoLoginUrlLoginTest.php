<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Unit;

use Drupal\auto_login_url\AutoLoginUrlGeneral;
use Drupal\auto_login_url\AutoLoginUrlLogin;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserAuthenticationInterface;
use Drupal\user\UserInterface;

/**
 * Unit tests for AutoLoginUrlLogin service.
 *
 * @group auto_login_url
 * @coversDefaultClass \Drupal\auto_login_url\AutoLoginUrlLogin
 */
final class AutoLoginUrlLoginTest extends UnitTestCase {

  /**
   * The mocked config factory.
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The mocked database connection.
   */
  private Connection $connection;

  /**
   * The mocked general service.
   */
  private AutoLoginUrlGeneral $autoLoginUrlGeneral;

  /**
   * The mocked user authentication service.
   */
  private UserAuthenticationInterface $userAuthentication;

  /**
   * The mocked current user.
   */
  private AccountProxyInterface $currentUser;

  /**
   * The mocked logger factory.
   */
  private LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The mocked logger channel.
   */
  private LoggerChannelInterface $logger;

  /**
   * The mocked entity type manager.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The service under test.
   */
  private AutoLoginUrlLogin $loginService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->connection = $this->createMock(Connection::class);
    $this->autoLoginUrlGeneral = $this->createMock(AutoLoginUrlGeneral::class);
    $this->userAuthentication = $this->createMock(UserAuthenticationInterface::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $this->loggerFactory->method('get')
      ->with('auto_login_url')
      ->willReturn($this->logger);

    $this->loginService = new AutoLoginUrlLogin(
      $this->configFactory,
      $this->connection,
      $this->autoLoginUrlGeneral,
      $this->userAuthentication,
      $this->currentUser,
      $this->loggerFactory,
      $this->entityTypeManager
    );
  }

  /**
   * @covers ::login
   */
  public function testLoginWithInvalidUserId(): void {
    $this->autoLoginUrlGeneral->method('validateUserId')
      ->with(0)
      ->willReturn(FALSE);

    $result = $this->loginService->login(0, 'test-hash');
    $this->assertFalse($result);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithInvalidHashFormat(): void {
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateHashFormat')
      ->with('invalid hash')
      ->willReturn(FALSE);

    $result = $this->loginService->login(123, 'invalid hash');
    $this->assertFalse($result);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithValidParameters(): void {
    // Mock validation methods.
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateHashFormat')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('getSecret')->willReturn('secret');
    $this->autoLoginUrlGeneral->method('getUserHash')->willReturn('hash');

    // Mock database query.
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn([
      'id' => '1',
      'uid' => '123',
      'destination' => 'user/123',
      'timestamp' => (string) time(),
      'ip_address' => '192.168.1.1',
    ]);

    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    // Mock config for expiration check.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('expiration')->willReturn(3600);
    $this->configFactory->method('get')->willReturn($config);

    // Mock user loading.
    $user = $this->createMock(UserInterface::class);
    $user->method('isBlocked')->willReturn(FALSE);
    $user->method('isActive')->willReturn(TRUE);
    $user->method('setLastLoginTime')->willReturnSelf();
    $user->method('save')->willReturnSelf();

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('load')->willReturn($user);
    $this->entityTypeManager->method('getStorage')->willReturn($userStorage);

    $result = $this->loginService->login(123, 'valid-hash');
    $this->assertNotFalse($result);
    $this->assertIsString($result);
  }

  /**
   * @covers ::cleanupExpiredTokens
   */
  public function testCleanupExpiredTokens(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('expiration')->willReturn(3600);
    $this->configFactory->method('get')->willReturn($config);

    // Mock delete query.
    $this->connection->method('delete')
      ->willReturnSelf();
    $this->connection->method('condition')
      ->willReturnSelf();
    $this->connection->method('execute')
      ->willReturn(5);

    $result = $this->loginService->cleanupExpiredTokens();
    $this->assertEquals(5, $result);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithExpiredToken(): void {
    // Mock validation methods.
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateHashFormat')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('getSecret')->willReturn('secret');
    $this->autoLoginUrlGeneral->method('getUserHash')->willReturn('hash');

    // Mock database query with expired timestamp.
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn([
      'id' => '1',
      'uid' => '123',
      'destination' => 'user/123',
      // Expired timestamp.
      'timestamp' => (string) (time() - 7200),
      'ip_address' => '192.168.1.1',
    ]);

    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    // Mock config for expiration check.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('expiration')->willReturn(3600);
    $this->configFactory->method('get')->willReturn($config);

    $result = $this->loginService->login(123, 'valid-hash');
    $this->assertFalse($result);
  }

}
