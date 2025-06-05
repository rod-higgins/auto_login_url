# Auto Login URL Module - Testing Documentation

This document provides comprehensive information about the testing suite for the Auto Login URL module.

## Test Coverage Overview

The module includes extensive test coverage with the following types of tests:

### Unit Tests
- **AutoLoginUrlGeneralTest.php**: Tests for the general utilities service
- **AutoLoginUrlRateLimitTest.php**: Tests for rate limiting functionality
- **AutoLoginUrlTextConverterTest.php**: Tests for text link conversion
- **AutoLoginUrlCreateTest.php**: Unit tests for URL creation service
- **AutoLoginUrlLoginTest.php**: Unit tests for login service
- **AutoLoginUrlMainControllerTest.php**: Unit tests for the main controller
- **ConfigFormTest.php**: Unit tests for the configuration form

### Kernel Tests
- **AutoLoginUrlCreateKernelTest.php**: Integration tests for URL creation
- **AutoLoginUrlLoginKernelTest.php**: Integration tests for login functionality
- **AutoLoginUrlControllerKernelTest.php**: Controller integration tests
- **AutoLoginUrlTokenTest.php**: Token system integration tests

### Functional Tests
- **AutoLoginUrlTest.php**: Basic functional testing (original)
- **AutoLoginUrlIntegrationTest.php**: Comprehensive workflow testing
- **AutoLoginUrlPerformanceSecurityTest.php**: Performance and security testing

## Running Tests

### Prerequisites

1. **Drupal Environment**: Ensure you have a working Drupal installation
2. **PHPUnit**: Install PHPUnit (usually comes with Drupal)
3. **Test Database**: Configure a test database separate from your main database

### Running All Tests

```bash
# Run all Auto Login URL tests
./vendor/bin/phpunit modules/contrib/auto_login_url/tests

# Run with coverage (requires Xdebug)
./vendor/bin/phpunit --coverage-html coverage modules/contrib/auto_login_url/tests
```

### Running Specific Test Types

```bash
# Unit tests only
./vendor/bin/phpunit modules/contrib/auto_login_url/tests/src/Unit

# Kernel tests only
./vendor/bin/phpunit modules/contrib/auto_login_url/tests/src/Kernel

# Functional tests only
./vendor/bin/phpunit modules/contrib/auto_login_url/tests/src/Functional

# Performance and security tests
./vendor/bin/phpunit modules/contrib/auto_login_url/tests/src/Functional/AutoLoginUrlPerformanceSecurityTest.php
```

### Running Individual Test Files

```bash
# Run specific test class
./vendor/bin/phpunit modules/contrib/auto_login_url/tests/src/Unit/AutoLoginUrlGeneralTest.php

# Run specific test method
./vendor/bin/phpunit --filter testValidateUserId modules/contrib/auto_login_url/tests/src/Unit/AutoLoginUrlGeneralTest.php
```

### Using Drush

```bash
# Run all tests using Drush
drush test-run auto_login_url

# Run specific test group
drush test-run --group auto_login_url
```

## Test Configuration

### PHPUnit Configuration

Create or update `phpunit.xml` in your Drupal root:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="core/tests/bootstrap.php" colors="true">
  <testsuites>
    <testsuite name="auto_login_url">
      <directory>./modules/contrib/auto_login_url/tests</directory>
    </testsuite>
  </testsuites>
  <filter>
    <whitelist>
      <directory>./modules/contrib/auto_login_url/src</directory>
    </whitelist>
  </filter>
</phpunit>
```

### Environment Variables

Set these environment variables for testing:

```bash
export SIMPLETEST_DB='mysql://username:password@localhost/test_database'
export SIMPLETEST_BASE_URL='http://localhost'
export BROWSERTEST_OUTPUT_DIRECTORY='/path/to/output'
```

## Test Structure and Organization

### Unit Tests (`tests/src/Unit/`)

Unit tests focus on testing individual classes in isolation with mocked dependencies:

- **Scope**: Single class/method testing
- **Dependencies**: All external dependencies mocked
- **Speed**: Very fast execution
- **Coverage**: Logic, validation, error handling

**Example Unit Test Structure:**
```php
final class ExampleUnitTest extends UnitTestCase {
  private ServiceInterface $mockService;
  private ClassUnderTest $serviceUnderTest;

  protected function setUp(): void {
    parent::setUp();
    $this->mockService = $this->createMock(ServiceInterface::class);
    $this->serviceUnderTest = new ClassUnderTest($this->mockService);
  }

  public function testSpecificMethod(): void {
    $this->mockService->method('someMethod')->willReturn('expected');
    $result = $this->serviceUnderTest->methodUnderTest();
    $this->assertEquals('expected', $result);
  }
}
```

### Kernel Tests (`tests/src/Kernel/`)

Kernel tests test service integration within Drupal's dependency injection container:

- **Scope**: Service integration and database interaction
- **Dependencies**: Real Drupal services, test database
- **Speed**: Medium execution time
- **Coverage**: Service interaction, database operations

**Example Kernel Test Structure:**
```php
final class ExampleKernelTest extends KernelTestBase {
  protected static $modules = ['auto_login_url', 'user', 'system'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['auto_login_url']);
    $this->installSchema('auto_login_url', ['auto_login_url']);
  }

  public function testServiceIntegration(): void {
    $service = $this->container->get('auto_login_url.create');
    $result = $service->create(123, 'destination', FALSE);
    $this->assertNotEmpty($result);
  }
}
```

### Functional Tests (`tests/src/Functional/`)

Functional tests simulate complete user interactions through the web interface:

- **Scope**: End-to-end workflows and user interactions
- **Dependencies**: Full Drupal installation, web browser simulation
- **Speed**: Slower execution
- **Coverage**: Complete workflows, UI integration

**Example Functional Test Structure:**
```php
final class ExampleFunctionalTest extends BrowserTestBase {
  protected static $modules = ['auto_login_url'];
  protected $defaultTheme = 'stark';

  public function testCompleteWorkflow(): void {
    $user = $this->createUser(['use auto login url']);
    $url = auto_login_url_create($user->id(), 'user/' . $user->id(), TRUE);
    
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);
  }
}
```

## Test Coverage Areas

### Security Testing

Our security tests cover:

- **SQL Injection**: Tests various SQL injection attack vectors
- **XSS Prevention**: Verifies output is properly escaped
- **Path Traversal**: Tests resistance to directory traversal attacks
- **Timing Attacks**: Ensures consistent response times for security operations
- **Brute Force Protection**: Validates flood protection mechanisms
- **Rate Limiting**: Tests abuse prevention measures

### Performance Testing

Performance tests validate:

- **URL Creation Speed**: Bulk URL generation performance
- **Hash Uniqueness**: Efficiency of hash collision detection
- **Database Cleanup**: Cleanup operation performance
- **Memory Usage**: Memory consumption during bulk operations
- **Concurrent Access**: Handling of simultaneous requests

### Functionality Testing

Functional tests verify:

- **URL Generation**: Various destination types and configurations
- **Authentication**: Login process with different scenarios
- **Token Management**: Expiration, deletion, and validation
- **Configuration**: Settings validation and application
- **Integration**: Token system, permissions, and workflows

## Mock Objects and Test Doubles

### When to Use Mocks

1. **Unit Tests**: Always mock external dependencies
2. **Kernel Tests**: Mock only when testing specific isolated behavior
3. **Functional Tests**: Rarely use mocks; test real interactions

### Common Mock Patterns

```php
// Service mocking
$mockService = $this->createMock(ServiceInterface::class);
$mockService->method('methodName')->willReturn('value');
$mockService->expects($this->once())->method('methodName');

// Database mocking for unit tests
$mockConnection = $this->createMock(Connection::class);
$mockStatement = $this->createMock(StatementInterface::class);
$mockStatement->method('fetchField')->willReturn('result');

// Configuration mocking
$mockConfig = $this->createMock(ImmutableConfig::class);
$mockConfig->method('get')->willReturnMap([
  ['setting1', 'value1'],
  ['setting2', 'value2'],
]);
```

## Writing New Tests

### Adding Unit Tests

1. Create test file in `tests/src/Unit/`
2. Extend `UnitTestCase`
3. Mock all dependencies
4. Test public methods and edge cases
5. Follow naming convention: `ClassNameTest.php`

### Adding Kernel Tests

1. Create test file in `tests/src/Kernel/`
2. Extend `KernelTestBase`
3. Install required modules and schemas
4. Test service integration
5. Use real database for data persistence tests

### Adding Functional Tests

1. Create test file in `tests/src/Functional/`
2. Extend `BrowserTestBase`
3. Set up complete module environment
4. Test complete user workflows
5. Verify UI and permissions

### Test Naming Conventions

```php
// Good test method names
public function testCreateUrlWithValidParameters(): void
public function testValidationFailsWithInvalidInput(): void
public function testRateLimitingPreventsAbuse(): void

// Poor test method names
public function testCreate(): void
public function testSomething(): void
public function test1(): void
```

## Debugging Tests

### Running Tests with Debugging

```bash
# Enable verbose output
./vendor/bin/phpunit --verbose modules/contrib/auto_login_url/tests

# Stop on first failure
./vendor/bin/phpunit --stop-on-failure modules/contrib/auto_login_url/tests

# Enable debug output
./vendor/bin/phpunit --debug modules/contrib/auto_login_url/tests
```

### Test Debugging Tips

1. **Use `var_dump()` or `dump()`** for debugging test values
2. **Check browser output** in functional tests using `$this->getSession()->getPage()->getContent()`
3. **Verify database state** using database queries in kernel/functional tests
4. **Use `--filter`** to run specific failing tests
5. **Check log files** in `sites/simpletest/browser_output/`

### Common Test Issues

**Mock Objects Not Working:**
```php
// Wrong - mock not configured
$mock = $this->createMock(Service::class);
$result = $service->method(); // Returns null

// Right - mock properly configured
$mock = $this->createMock(Service::class);
$mock->method('method')->willReturn('expected');
```

**Database Not Available in Unit Tests:**
```php
// Wrong - trying to use real database in unit test
$database = \Drupal::database(); // Will fail

// Right - mock the database
$mockDatabase = $this->createMock(Connection::class);
```

**Missing Test Dependencies:**
```php
// Add to setUp() method
$this->installEntitySchema('user');
$this->installConfig(['auto_login_url']);
$this->installSchema('auto_login_url', ['auto_login_url']);
```

## Continuous Integration

### GitLab CI Configuration

```yaml
test:unit:
  stage: test
  script:
    - ./vendor/bin/phpunit modules/contrib/auto_login_url/tests/src/Unit
  coverage: '/^\s*Lines:\s*\d+.\d+\%/'

test:kernel:
  stage: test
  script:
    - ./vendor/bin/phpunit modules/contrib/auto_login_url/tests/src/Kernel

test:functional:
  stage: test
  script:
    - ./vendor/bin/phpunit modules/contrib/auto_login_url/tests/src/Functional
```

### GitHub Actions Configuration

```yaml
name: Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.1
      - name: Run Tests
        run: ./vendor/bin/phpunit modules/contrib/auto_login_url/tests
```

## Test Data and Fixtures

### Creating Test Users

```php
// In functional/kernel tests
$user = $this->createUser(['permission1', 'permission2']);

// With specific properties
$user = User::create([
  'name' => 'testuser',
  'mail' => 'test@example.com',
  'status' => 1,
  'pass' => 'password',
]);
$user->save();
```

### Test Configuration

```php
// Update configuration in tests
$config = $this->config('auto_login_url.settings');
$config->set('expiration', 3600);
$config->save();

// Or using container
$this->container->get('config.factory')
  ->getEditable('auto_login_url.settings')
  ->set('token_length', 32)
  ->save();
```

## Performance Benchmarks

### Expected Performance Metrics

- **URL Creation**: < 100ms per URL
- **Hash Generation**: < 50ms per hash
- **Database Queries**: < 10ms per query
- **Bulk Operations**: < 1 second per 100 operations
- **Memory Usage**: < 10MB for 1000 operations

### Profiling Tests

```bash
# Run with time profiling
time ./vendor/bin/phpunit modules/contrib/auto_login_url/tests

# Memory profiling
./vendor/bin/phpunit --log-junit results.xml modules/contrib/auto_login_url/tests
```

## Contributing Test Improvements

### Guidelines for Test Contributions

1. **Maintain Coverage**: New features require corresponding tests
2. **Follow Patterns**: Use existing test patterns and structure
3. **Document Complex Tests**: Add comments for complex test logic
4. **Performance Awareness**: Ensure tests run efficiently
5. **Cross-Platform Compatibility**: Tests should work on different environments