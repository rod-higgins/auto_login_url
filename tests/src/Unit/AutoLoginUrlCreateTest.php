<?php

declare(strict_types=1);

namespace Drupal\Tests\auto_login_url\Unit;

use Symfony\Component\HttpFoundation\HeaderBag;
use Drupal\auto_login_url\AutoLoginUrlCreate;
use Drupal\auto_login_url\AutoLoginUrlGeneral;
use Drupal\auto_login_url\AutoLoginUrlRateLimit;
use Drupal\auto_login_url\Exception\AutoLoginUrlException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for AutoLoginUrlCreate service.
 *
 * @group auto_login_url
 * @coversDefaultClass \Drupal\auto_login_url\AutoLoginUrlCreate
 */
final class AutoLoginUrlCreateTest extends UnitTestCase {

  /**
   * The mocked database connection.
   */
  private Connection $connection;

  /**
   * The mocked config factory.
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The mocked general service.
   */
  private AutoLoginUrlGeneral $autoLoginUrlGeneral;

  /**
   * The mocked logger factory.
   */
  private LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The mocked logger channel.
   */
  private LoggerChannelInterface $logger;

  /**
   * The mocked rate limiter.
   */
  private AutoLoginUrlRateLimit $rateLimiter;

  /**
   * The mocked request stack.
   */
  private RequestStack $requestStack;

  /**
   * The service under test.
   */
  private AutoLoginUrlCreate $urlCreateService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->connection = $this->createMock(Connection::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->autoLoginUrlGeneral = $this->createMock(AutoLoginUrlGeneral::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->rateLimiter = $this->createMock(AutoLoginUrlRateLimit::class);
    $this->requestStack = $this->createMock(RequestStack::class);

    $this->loggerFactory->method('get')
      ->with('auto_login_url')
      ->willReturn($this->logger);

    $this->urlCreateService = new AutoLoginUrlCreate(
      $this->connection,
      $this->configFactory,
      $this->autoLoginUrlGeneral,
      $this->loggerFactory,
      $this->rateLimiter,
      $this->requestStack
    );
  }

  /**
   * @covers ::create
   */
  public function testCreateSuccessfulUrl(): void {
    // Mock rate limiting check.
    $this->rateLimiter->method('checkCreationLimit')
      ->with(123)
      ->willReturn(TRUE);

    // Mock user validation.
    $this->autoLoginUrlGeneral->method('validateUserId')
      ->with(123)
      ->willReturn(TRUE);

    // Mock config.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('token_length')
      ->willReturn(64);
    $this->configFactory->method('get')
      ->with('auto_login_url.settings')
      ->willReturn($config);

    // Mock secret and password hash.
    $this->autoLoginUrlGeneral->method('getSecret')
      ->willReturn('test-secret');
    $this->autoLoginUrlGeneral->method('getUserHash')
      ->with(123)
      ->willReturn('user-password-hash');

    // Mock hash uniqueness check.
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    // Hash is unique.
    $statement->method('fetchField')->willReturn(FALSE);

    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    // Mock database insert.
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('execute')->willReturn(1);
    $this->connection->method('insert')->willReturn($insert);

    // Mock request for IP tracking.
    $request = $this->createMock(Request::class);
    $request->method('getClientIp')->willReturn('192.168.1.1');
    $request->headers = $this->createMock(HeaderBag::class);
    $request->headers->method('get')->willReturn('Mozilla/5.0');
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    // Execute test.
    $result = $this->urlCreateService->create(123, 'user/123', TRUE);

    $this->assertIsString($result);
    $this->assertStringContains('autologinurl', $result);
    $this->assertStringContains('123', $result);
  }

  /**
   * @covers ::create
   */
  public function testCreateWithRateLimitExceeded(): void {
    $this->rateLimiter->method('checkCreationLimit')
      ->with(123)
      ->willReturn(FALSE);

    $this->expectException(AutoLoginUrlException::class);
    $this->expectExceptionMessage('Rate limit exceeded');

    $this->urlCreateService->create(123, 'user/123', FALSE);
  }

  /**
   * @covers ::create
   */
  public function testCreateWithInvalidUserId(): void {
    $this->rateLimiter->method('checkCreationLimit')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateUserId')
      ->with(99999)
      ->willReturn(FALSE);

    $this->expectException(AutoLoginUrlException::class);
    $this->expectExceptionMessage('Invalid or non-existent user ID');

    $this->urlCreateService->create(99999, 'user/123', FALSE);
  }

  /**
   * @covers ::create
   */
  public function testCreateWithInvalidDestination(): void {
    $this->rateLimiter->method('checkCreationLimit')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);

    $this->expectException(AutoLoginUrlException::class);
    $this->expectExceptionMessage('Invalid destination URL');

    // Empty destination.
    $this->urlCreateService->create(123, '', FALSE);
  }

  /**
   * @covers ::create
   */
  public function testCreateWithLongDestination(): void {
    $this->rateLimiter->method('checkCreationLimit')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);

    $longDestination = str_repeat('a', 1001);

    $this->expectException(AutoLoginUrlException::class);
    $this->expectExceptionMessage('Invalid destination URL');

    $this->urlCreateService->create(123, $longDestination, FALSE);
  }

  /**
   * @covers ::create
   */
  public function testCreateWithDangerousDestination(): void {
    $this->rateLimiter->method('checkCreationLimit')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);

    $this->expectException(AutoLoginUrlException::class);
    $this->expectExceptionMessage('Destination contains invalid characters');

    $this->urlCreateService->create(123, 'javascript:alert("xss")', FALSE);
  }

  /**
   * @covers ::create
   */
  public function testCreateWithHashGenerationFailure(): void {
    $this->rateLimiter->method('checkCreationLimit')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(64);
    $this->configFactory->method('get')->willReturn($config);

    $this->autoLoginUrlGeneral->method('getSecret')->willReturn('test-secret');
    $this->autoLoginUrlGeneral->method('getUserHash')->willReturn('user-hash');

    // Make all hash uniqueness checks fail.
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    // Always not unique.
    $statement->method('fetchField')->willReturn('existing-hash');

    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    $this->expectException(AutoLoginUrlException::class);
    $this->expectExceptionMessage('Failed to generate unique hash');

    $this->urlCreateService->create(123, 'user/123', FALSE);
  }

  /**
   * @covers ::create
   */
  public function testCreateWithDatabaseError(): void {
    $this->rateLimiter->method('checkCreationLimit')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(64);
    $this->configFactory->method('get')->willReturn($config);

    $this->autoLoginUrlGeneral->method('getSecret')->willReturn('test-secret');
    $this->autoLoginUrlGeneral->method('getUserHash')->willReturn('user-hash');

    // Mock successful uniqueness check.
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(FALSE);

    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    // Make database insert fail.
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('execute')->willThrowException(new \Exception('Database error'));
    $this->connection->method('insert')->willReturn($insert);

    $this->expectException(AutoLoginUrlException::class);
    $this->expectExceptionMessage('Failed to create auto login URL');

    $this->urlCreateService->create(123, 'user/123', FALSE);
  }

  /**
   * @covers ::convertText
   */
  public function testConvertTextSuccessfully(): void {
    $this->autoLoginUrlGeneral->method('validateUserId')
      ->with(123)
      ->willReturn(TRUE);

    // Mock global variable.
    $GLOBALS['base_root'] = 'https://example.com';

    $originalText = 'Visit https://example.com/user/123 for your profile.';

    // Mock the create method to return a converted URL.
    $mockUrlCreateService = $this->getMockBuilder(AutoLoginUrlCreate::class)
      ->setConstructorArgs([
        $this->connection,
        $this->configFactory,
        $this->autoLoginUrlGeneral,
        $this->loggerFactory,
        $this->rateLimiter,
        $this->requestStack,
      ])
      ->onlyMethods(['create'])
      ->getMock();

    $mockUrlCreateService->method('create')
      ->willReturn('https://example.com/autologinurl/123/hash123');

    $result = $mockUrlCreateService->convertText(123, $originalText);

    $this->assertStringContains('autologinurl', $result);
    $this->assertNotEquals($originalText, $result);
  }

  /**
   * @covers ::convertText
   */
  public function testConvertTextWithInvalidUser(): void {
    $this->autoLoginUrlGeneral->method('validateUserId')
      ->with(99999)
      ->willReturn(FALSE);

    $this->expectException(AutoLoginUrlException::class);
    $this->expectExceptionMessage('Invalid user ID provided for text conversion');

    $this->urlCreateService->convertText(99999, 'Some text');
  }

  /**
   * @covers ::convertText
   */
  public function testConvertTextWithEmptyBaseRoot(): void {
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);

    // Mock empty base_root.
    $GLOBALS['base_root'] = '';

    $result = $this->urlCreateService->convertText(123, 'Some text');

    $this->assertEquals('Some text', $result);
  }

  /**
   * @covers ::convertText
   */
  public function testConvertTextWithRegexFailure(): void {
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);

    $GLOBALS['base_root'] = 'https://example.com';

    // Create a text that might cause regex issues.
    $problematicText = str_repeat('https://example.com/', 1000);

    $this->expectException(AutoLoginUrlException::class);
    $this->expectExceptionMessage('Failed to convert text');

    $this->urlCreateService->convertText(123, $problematicText);
  }

  /**
   * Tests token length configuration handling.
   */
  public function testTokenLengthConfiguration(): void {
    $this->rateLimiter->method('checkCreationLimit')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('getSecret')->willReturn('secret');
    $this->autoLoginUrlGeneral->method('getUserHash')->willReturn('hash');

    $tokenLengths = [16, 32, 64, 128];

    foreach ($tokenLengths as $length) {
      $config = $this->createMock(ImmutableConfig::class);
      $config->method('get')
        ->with('token_length')
        ->willReturn($length);
      $this->configFactory->method('get')->willReturn($config);

      // Mock unique hash check.
      $select = $this->createMock(Select::class);
      $select->method('fields')->willReturnSelf();
      $select->method('condition')->willReturnSelf();
      $select->method('range')->willReturnSelf();

      $statement = $this->createMock(StatementInterface::class);
      $statement->method('fetchField')->willReturn(FALSE);

      $select->method('execute')->willReturn($statement);
      $this->connection->method('select')->willReturn($select);

      // Mock insert.
      $insert = $this->createMock(Insert::class);
      $insert->method('fields')->willReturnSelf();
      $insert->method('execute')->willReturn(1);
      $this->connection->method('insert')->willReturn($insert);

      $result = $this->urlCreateService->create(123, 'test', FALSE);
      $this->assertIsString($result);
    }
  }

  /**
   * Tests entropy generation with different scenarios.
   */
  public function testEntropyGeneration(): void {
    $this->rateLimiter->method('checkCreationLimit')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(64);
    $this->configFactory->method('get')->willReturn($config);

    $this->autoLoginUrlGeneral->method('getSecret')->willReturn('secret');
    $this->autoLoginUrlGeneral->method('getUserHash')->willReturn('hash');

    // Test that multiple calls generate different hashes.
    $hashes = [];

    for ($i = 0; $i < 3; $i++) {
      // Mock unique hash check - return different hashes.
      $select = $this->createMock(Select::class);
      $select->method('fields')->willReturnSelf();
      $select->method('condition')->willReturnSelf();
      $select->method('range')->willReturnSelf();

      $statement = $this->createMock(StatementInterface::class);
      $statement->method('fetchField')->willReturn(FALSE);

      $select->method('execute')->willReturn($statement);
      $this->connection->method('select')->willReturn($select);

      $insert = $this->createMock(Insert::class);
      $insert->method('fields')->willReturnSelf();
      $insert->method('execute')->willReturn(1);
      $this->connection->method('insert')->willReturn($insert);

      $url = $this->urlCreateService->create(123, "dest{$i}", FALSE);

      // Extract hash from URL.
      preg_match('/autologinurl\/\d+\/([^\/]+)/', $url, $matches);
      $hash = $matches[1] ?? '';
      $hashes[] = $hash;
    }

    // All hashes should be different (entropy working).
    $this->assertEquals(count($hashes), count(array_unique($hashes)));
  }

  /**
   * Tests IP and user agent tracking.
   */
  public function testIpAndUserAgentTracking(): void {
    $this->rateLimiter->method('checkCreationLimit')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(64);
    $this->configFactory->method('get')->willReturn($config);

    $this->autoLoginUrlGeneral->method('getSecret')->willReturn('secret');
    $this->autoLoginUrlGeneral->method('getUserHash')->willReturn('hash');

    // Mock request with IP and user agent.
    $request = $this->createMock(Request::class);
    $request->method('getClientIp')->willReturn('192.168.1.100');
    $request->headers = $this->createMock(HeaderBag::class);
    $request->headers->method('get')
      ->with('User-Agent', '')
      ->willReturn('Mozilla/5.0 (Test Browser)');
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(FALSE);

    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    // Verify insert is called with IP and user agent.
    $insert = $this->createMock(Insert::class);
    $insert->expects($this->once())
      ->method('fields')
      ->with($this->callback(function ($fields) {
        return isset($fields['ip_address']) &&
               isset($fields['user_agent']) &&
               $fields['ip_address'] === '192.168.1.100' &&
               $fields['user_agent'] === 'Mozilla/5.0 (Test Browser)';
      }))
      ->willReturnSelf();
    $insert->method('execute')->willReturn(1);
    $this->connection->method('insert')->willReturn($insert);

    $this->urlCreateService->create(123, 'test', FALSE);
  }

  /**
   * Tests handling of no request context.
   */
  public function testNoRequestContext(): void {
    $this->rateLimiter->method('checkCreationLimit')->willReturn(TRUE);
    $this->autoLoginUrlGeneral->method('validateUserId')->willReturn(TRUE);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(64);
    $this->configFactory->method('get')->willReturn($config);

    $this->autoLoginUrlGeneral->method('getSecret')->willReturn('secret');
    $this->autoLoginUrlGeneral->method('getUserHash')->willReturn('hash');

    // No request available.
    $this->requestStack->method('getCurrentRequest')->willReturn(NULL);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(FALSE);

    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    // Verify insert is called with NULL IP and user agent.
    $insert = $this->createMock(Insert::class);
    $insert->expects($this->once())
      ->method('fields')
      ->with($this->callback(function ($fields) {
        return array_key_exists('ip_address', $fields) &&
               array_key_exists('user_agent', $fields) &&
               $fields['ip_address'] === NULL &&
               $fields['user_agent'] === NULL;
      }))
      ->willReturnSelf();
    $insert->method('execute')->willReturn(1);
    $this->connection->method('insert')->willReturn($insert);

    $result = $this->urlCreateService->create(123, 'test', FALSE);
    $this->assertIsString($result);
  }

}
