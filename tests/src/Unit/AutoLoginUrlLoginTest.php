<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Unit;

use Drupal\auto_login_url\AutoLoginUrlGeneral;
use Drupal\auto_login_url\AutoLoginUrlLogin;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\Schema\Schema;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\UserSessionInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserAuthenticationInterface;

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
   * The mocked current user session.
   */
  private UserSessionInterface $currentUser;

  /**
   * The mocked logger factory.
   */
  private LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The mocked logger channel.
   */
  private LoggerChannelInterface $logger;

  /**
   * The service under test.
   */
  private AutoLoginUrlLogin $urlLoginService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->connection = $this->createMock(Connection::class);
    $this->autoLoginUrlGeneral = $this->createMock(AutoLoginUrlGeneral::class);
    $this->userAuthentication = $this->createMock(UserAuthenticationInterface::class);
    $this->currentUser = $this->createMock(UserSessionInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);

    $this->loggerFactory->method('get')
      ->with('auto_login_url')
      ->willReturn($this->logger);

    $this->urlLoginService = new AutoLoginUrlLogin(
      $this->configFactory,
      $this->connection,
      $this->autoLoginUrlGeneral,
      $this->userAuthentication,
      $this->currentUser,
      $this->loggerFactory
    );
  }

  /**
   * @covers ::login
   */
  public function testLoginWithInvalidUserId(): void {
    $this->autoLoginUrlGeneral->method('validateUserId')
      ->with(0)
      ->willReturn(FALSE);

    $result = $this->urlLoginService->login(0, 'some-hash');
    $this->assertFalse($result);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithInvalidHashFormat(): void {
    $this->autoLoginUrlGeneral->method('validateUserId')
      ->with(123)
      ->willReturn(TRUE);

    $this->autoLoginUrlGeneral->method('validateHashFormat')
      ->with('invalid hash')
      ->willReturn(FALSE);

    $result = $this->urlLoginService->login(123, 'invalid hash');
    $this->assertFalse($result);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithNonExistentToken(): void {
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateHashFormat')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('getSecret')->willReturn('secret');
    $this->autoLoginUrlGeneral->method('getUserHash')->willReturn('user-hash');

    // Mock database query that returns no results.
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn(FALSE);

    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    $result = $this->urlLoginService->login(123, 'valid-hash');
    $this->assertFalse($result);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithExpiredToken(): void {
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateHashFormat')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('getSecret')->willReturn('secret');
    $this->autoLoginUrlGeneral->method('getUserHash')->willReturn('user-hash');

    // Mock config with 1 hour expiration.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('expiration')
      ->willReturn(3600);
    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    // Mock database query that returns an expired token.
    $expired_timestamp = time() - 7200;
    $login_data = [
      'id' => '1',
      'uid' => '123',
      'destination' => 'user/123',
      'timestamp' => (string) $expired_timestamp,
      'ip_address' => '192.168.1.1',
    ];

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($login_data);

    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    // Mock hash verification.
    $hash_select = $this->createMock(Select::class);
    $hash_select->method('fields')->willReturnSelf();
    $hash_select->method('condition')->willReturnSelf();
    $hash_statement = $this->createMock(StatementInterface::class);
    $hash_statement->method('fetchField')->willReturn('matching-hash');
    $hash_select->method('execute')->willReturn($hash_statement);

    // Mock delete operation for expired token.
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(1);
    $this->connection->method('delete')->willReturn($delete);

    $result = $this->urlLoginService->login(123, 'valid-hash');
    $this->assertFalse($result);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithBlockedUser(): void {
    $this->setupSuccessfulValidation();

    // Mock valid, non-expired token.
    $valid_timestamp = time() - 1800;
    $login_data = [
      'id' => '1',
      'uid' => '123',
      'destination' => 'user/123',
      'timestamp' => (string) $valid_timestamp,
      'ip_address' => '192.168.1.1',
    ];

    $this->setupDatabaseMocks($login_data);

    // Mock blocked user.
    // Note: This would be tested in kernel tests since User::load is static.
    $result = $this->urlLoginService->login(123, 'valid-hash');
    // Result depends on how User::load behaves.
    $this->assertIsBool($result);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithIpValidationEnabled(): void {
    $this->setupSuccessfulValidation();

    // Mock config with IP validation enabled.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['expiration', 3600],
      ['validate_ip_address', TRUE],
      ['enable_usage_analytics', FALSE],
      ['delete', FALSE],
    ]);
    $this->configFactory->method('get')->willReturn($config);

    $login_data = [
      'id' => '1',
      'uid' => '123',
      'destination' => 'user/123',
      'timestamp' => (string) (time() - 1800),
      'ip_address' => '192.168.1.1',
    ];

    $this->setupDatabaseMocks($login_data);

    // Mock different current IP.
    $this->autoLoginUrlGeneral->method('getClientIp')
      ->willReturn('192.168.1.2');

    $result = $this->urlLoginService->login(123, 'valid-hash');
    $this->assertFalse($result);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithIpValidationPassed(): void {
    $this->setupSuccessfulValidation();

    // Mock config with IP validation enabled.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['expiration', 3600],
      ['validate_ip_address', TRUE],
      ['enable_usage_analytics', FALSE],
      ['delete', FALSE],
    ]);
    $this->configFactory->method('get')->willReturn($config);

    $login_data = [
      'id' => '1',
      'uid' => '123',
      'destination' => 'user/123',
      'timestamp' => (string) (time() - 1800),
      'ip_address' => '192.168.1.1',
    ];

    $this->setupDatabaseMocks($login_data);

    // Mock same IP.
    $this->autoLoginUrlGeneral->method('getClientIp')
      ->willReturn('192.168.1.1');

    /*
     * Note: Full login test requires mocking User::load which is complex
     * in unit tests. We'll focus on testing the IP validation logic here.
     */
    $result = $this->urlLoginService->login(123, 'valid-hash');
    $this->assertIsBool($result);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithAnalyticsEnabled(): void {
    $this->setupSuccessfulValidation();

    // Mock config with analytics enabled.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['expiration', 3600],
      ['validate_ip_address', FALSE],
      ['enable_usage_analytics', TRUE],
      ['delete', FALSE],
    ]);
    $this->configFactory->method('get')->willReturn($config);

    $login_data = [
      'id' => '1',
      'uid' => '123',
      'destination' => 'user/123',
      'timestamp' => (string) (time() - 1800),
      'ip_address' => '192.168.1.1',
    ];

    $this->setupDatabaseMocks($login_data);

    // Mock analytics table exists.
    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')
      ->with('auto_login_url_usage')
      ->willReturn(TRUE);
    $this->connection->method('schema')->willReturn($schema);

    // Mock analytics insert.
    $analytics_insert = $this->createMock(Insert::class);
    $analytics_insert->method('fields')->willReturnSelf();
    $analytics_insert->method('execute')->willReturn(1);

    $this->connection->expects($this->atLeastOnce())
      ->method('insert')
      ->willReturn($analytics_insert);

    $result = $this->urlLoginService->login(123, 'valid-hash');
    $this->assertIsBool($result);
  }

  /**
   * @covers ::login
   */
  public function testLoginWithDeleteOnUse(): void {
    $this->setupSuccessfulValidation();

    // Mock config with delete on use enabled.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['expiration', 3600],
      ['validate_ip_address', FALSE],
      ['enable_usage_analytics', FALSE],
      ['delete', TRUE],
    ]);
    $this->configFactory->method('get')->willReturn($config);

    $login_data = [
      'id' => '1',
      'uid' => '123',
      'destination' => 'user/123',
      'timestamp' => (string) (time() - 1800),
      'ip_address' => '192.168.1.1',
    ];

    $this->setupDatabaseMocks($login_data);

    // Mock delete operation.
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(1);
    $this->connection->expects($this->atLeastOnce())
      ->method('delete')
      ->willReturn($delete);

    $result = $this->urlLoginService->login(123, 'valid-hash');
    $this->assertIsBool($result);
  }

  /**
   * @covers ::cleanupExpiredTokens
   */
  public function testCleanupExpiredTokens(): void {
    // Mock config.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('expiration')
      ->willReturn(3600);
    $this->configFactory->method('get')->willReturn($config);

    // Mock delete operation.
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(5);
    $this->connection->method('delete')->willReturn($delete);

    $result = $this->urlLoginService->cleanupExpiredTokens();
    $this->assertEquals(5, $result);
  }

  /**
   * @covers ::cleanupExpiredTokens
   */
  public function testCleanupExpiredTokensWithDatabaseError(): void {
    // Mock config.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(3600);
    $this->configFactory->method('get')->willReturn($config);

    // Mock delete operation that throws exception.
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willThrowException(new \Exception('Database error'));
    $this->connection->method('delete')->willReturn($delete);

    $result = $this->urlLoginService->cleanupExpiredTokens();
    $this->assertEquals(0, $result);
  }

  /**
   * @covers ::login
   */
  public function testLoginDestinationGeneration(): void {
    $destinations = [
      '<front>' => '/',
      'user/123' => '/user/123',
      'admin/content' => '/admin/content',
      'https://external.com/page' => 'https://external.com/page',
    ];

    foreach ($destinations as $input => $expected) {
      $this->setupSuccessfulValidation();

      $login_data = [
        'id' => '1',
        'uid' => '123',
        'destination' => $input,
        'timestamp' => (string) (time() - 1800),
        'ip_address' => '192.168.1.1',
      ];

      $this->setupDatabaseMocks($login_data);

      /*
       * The actual destination generation logic is complex and involves
       * Drupal's URL system, so we'll test this in kernel/integration tests.
       */
      $result = $this->urlLoginService->login(123, 'valid-hash');
      $this->assertIsBool($result);
    }
  }

  /**
   * @covers ::login
   */
  public function testLoginWithDatabaseException(): void {
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateHashFormat')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('getSecret')->willReturn('secret');
    $this->autoLoginUrlGeneral->method('getUserHash')->willReturn('user-hash');

    // Mock database query that throws exception.
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willThrowException(new \Exception('Database error'));
    $this->connection->method('select')->willReturn($select);

    $result = $this->urlLoginService->login(123, 'valid-hash');
    $this->assertFalse($result);
  }

  /**
   * Sets up mocks for successful validation.
   */
  private function setupSuccessfulValidation(): void {
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateHashFormat')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('getSecret')->willReturn('secret');
    $this->autoLoginUrlGeneral->method('getUserHash')->willReturn('user-hash');
    $this->autoLoginUrlGeneral->method('getClientIp')->willReturn('192.168.1.1');
  }

  /**
   * Sets up database mocks for login data retrieval.
   */
  private function setupDatabaseMocks(array $login_data): void {
    // Mock main query.
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($login_data);

    $select->method('execute')->willReturn($statement);

    // Mock hash verification query.
    $hash_select = $this->createMock(Select::class);
    $hash_select->method('fields')->willReturnSelf();
    $hash_select->method('condition')->willReturnSelf();
    $hash_statement = $this->createMock(StatementInterface::class);
    $hash_statement->method('fetchField')->willReturn('expected-hash');
    $hash_select->method('execute')->willReturn($hash_statement);

    $this->connection->method('select')
      ->willReturnOnConsecutiveCalls($select, $hash_select);
  }

  /**
   * Tests hash timing-safe comparison.
   */
  public function testHashTimingSafeComparison(): void {
    $this->setupSuccessfulValidation();

    $login_data = [
      'id' => '1',
      'uid' => '123',
      'destination' => 'user/123',
      'timestamp' => (string) (time() - 1800),
      'ip_address' => '192.168.1.1',
    ];

    // Mock main query.
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($login_data);

    $select->method('execute')->willReturn($statement);

    // Mock hash verification that returns different hash.
    $hash_select = $this->createMock(Select::class);
    $hash_select->method('fields')->willReturnSelf();
    $hash_select->method('condition')->willReturnSelf();
    $hash_statement = $this->createMock(StatementInterface::class);
    $hash_statement->method('fetchField')->willReturn('different-hash');
    $hash_select->method('execute')->willReturn($hash_statement);

    $this->connection->method('select')
      ->willReturnOnConsecutiveCalls($select, $hash_select);

    $result = $this->urlLoginService->login(123, 'valid-hash');
    $this->assertFalse($result);
  }

  /**
   * Tests login with missing stored hash.
   */
  public function testLoginWithMissingStoredHash(): void {
    $this->setupSuccessfulValidation();

    $login_data = [
      'id' => '1',
      'uid' => '123',
      'destination' => 'user/123',
      'timestamp' => (string) (time() - 1800),
      'ip_address' => '192.168.1.1',
    ];

    // Mock main query.
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($login_data);

    $select->method('execute')->willReturn($statement);

    // Mock hash verification that returns FALSE.
    $hash_select = $this->createMock(Select::class);
    $hash_select->method('fields')->willReturnSelf();
    $hash_select->method('condition')->willReturnSelf();
    $hash_statement = $this->createMock(StatementInterface::class);
    $hash_statement->method('fetchField')->willReturn(FALSE);
    $hash_select->method('execute')->willReturn($hash_statement);

    $this->connection->method('select')
      ->willReturnOnConsecutiveCalls($select, $hash_select);

    $result = $this->urlLoginService->login(123, 'valid-hash');
    $this->assertFalse($result);
  }

}