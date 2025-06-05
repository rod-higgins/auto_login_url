# Auto Login URL

**Secure auto login functionality using cryptographic tokens for user authentication.**

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Security Features](#security-features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [API Functions](#api-functions)
- [Token Integration](#token-integration)
- [Security Considerations](#security-considerations)
- [Monitoring and Analytics](#monitoring-and-analytics)
- [Troubleshooting](#troubleshooting)
- [Development](#development)
- [Support](#support)

## Overview

The Auto Login URL module provides a secure way to create time-limited, cryptographically signed URLs that allow users to automatically log into your Drupal site without entering credentials. This is particularly useful for:

- Email-based authentication workflows
- Secure password reset processes
- User invitation systems
- API integrations requiring temporary access
- Mobile app authentication handoffs

## Features

### Core Functionality
- **Secure URL Generation**: Creates cryptographically secure auto-login URLs using HMAC-based signatures
- **Flexible Destinations**: Support for any internal or external destination URL after login
- **Configurable Expiration**: Customizable token lifetime (1 hour to 1 year)
- **Text Link Conversion**: Automatically convert existing links in text to auto-login versions
- **Token Integration**: Built-in Drupal token support for easy integration with other modules

### Security & Protection
- **Rate Limiting**: Configurable limits on URL creation per user (default: 10/hour)
- **Flood Protection**: Protection against brute force attacks using Drupal's flood control
- **IP Validation**: Optional IP address validation for enhanced security
- **Single-Use URLs**: Optional deletion of URLs after first use
- **Cryptographic Security**: Uses Drupal's cryptographic functions with site-specific salt

### Administration & Monitoring
- **Configuration Interface**: Comprehensive admin interface at `/admin/people/autologinurl`
- **Usage Analytics**: Optional tracking of URL usage with configurable data retention
- **Health Monitoring**: Built-in health check endpoint for system monitoring
- **Automated Cleanup**: Cron-based cleanup of expired tokens and old analytics data
- **Extensive Logging**: Detailed logging of all authentication attempts and security events

## Security Features

This module implements multiple layers of security:

1. **Cryptographic Signatures**: Uses HMAC with site-specific keys
2. **Time-based Expiration**: All tokens expire automatically
3. **Rate Limiting**: Prevents abuse through configurable rate limits
4. **Flood Protection**: Built-in protection against brute force attacks
5. **User Validation**: Validates user status (active, not blocked) before login
6. **IP Validation**: Optional IP address restriction
7. **Secure Random Generation**: Uses cryptographically secure random number generation
8. **Hash Format Validation**: Validates token format to prevent injection attacks

## Requirements

- **Drupal**: 10.2+ or 11.x
- **PHP**: 8.1+ (as required by Drupal core)
- **Database**: MySQL 5.7+, PostgreSQL 10+, SQLite 3.26+

### Dependencies
- `drupal:system` (>= 10.2)
- `drupal:user` (>= 10.2)

### Recommended
- `drupal:token` - For enhanced token integration (optional but recommended)

## Installation

### Via Composer (Recommended)
```bash
composer require drupal/auto_login_url
drush en auto_login_url
```

### Manual Installation
1. Download the module from [drupal.org/project/auto_login_url](https://www.drupal.org/project/auto_login_url)
2. Extract to `/modules/contrib/auto_login_url`
3. Enable via admin interface or drush: `drush en auto_login_url`

### Post-Installation
1. Configure permissions at `/admin/people/permissions`
2. Configure module settings at `/admin/people/autologinurl`
3. Test functionality with the health check at `/admin/reports/auto-login-url/health`

## Configuration

Access the configuration form at **Administration → People → Auto Login URL**.

### Security Settings

**Secret Key**
- Automatically generated cryptographic key for token signing
- Can be manually regenerated (invalidates all existing URLs)
- Minimum 16 characters when manually set

**Token Length**
- Length of generated URL tokens (8-128 characters)
- Default: 64 characters
- Longer tokens provide better security

**IP Address Validation**
- Optional validation that URLs only work from creation IP
- Provides additional security but may cause issues with dynamic IPs
- Default: Disabled

### Expiration Settings

**Expiration Time**
- How long URLs remain valid (1 hour to 1 year)
- Default: 30 days (2,592,000 seconds)
- Common values:
  - 1 hour: 3,600 seconds
  - 1 day: 86,400 seconds  
  - 1 week: 604,800 seconds
  - 1 month: 2,592,000 seconds

### Rate Limiting

**Maximum URLs per User per Hour**
- Prevents abuse by limiting URL creation
- Default: 10 URLs per hour per user
- Range: 1-100 URLs per hour

### Behavior Settings

**Delete URLs After Use**
- Whether to delete URLs after first successful use
- Provides better security but prevents URL reuse
- Default: Disabled

**Enable Usage Analytics**
- Tracks URL usage for monitoring and analytics
- Data automatically cleaned up after 6 months
- Default: Enabled

## Usage

### Basic URL Creation

```php
// Create auto-login URL for user ID 123 to their profile page
$url = auto_login_url_create(123, 'user/123', TRUE);
echo $url; // https://example.com/autologinurl/123/abc123def...

// Create URL to front page
$url = auto_login_url_create(123, '<front>', TRUE);

// Create URL to external site
$url = auto_login_url_create(123, 'https://external-site.com/dashboard', TRUE);
```

### Text Link Conversion

```php
$text = 'Please visit https://example.com/user/123 to access your profile.';
$converted = auto_login_url_convert_text(123, $text);
// Links in $converted will now be auto-login URLs for user 123
```

### Error Handling

```php
try {
    $url = auto_login_url_create($uid, $destination, TRUE);
    // Use $url
} catch (\Drupal\auto_login_url\Exception\AutoLoginUrlException $e) {
    \Drupal::logger('my_module')->error('Auto login URL creation failed: @message', [
        '@message' => $e->getMessage(),
    ]);
}
```

## API Functions

### auto_login_url_create()

Creates an auto-login URL for a specific user.

```php
auto_login_url_create(int $uid, string $destination, bool $absolute = FALSE): string
```

**Parameters:**
- `$uid` - User ID to create login URL for
- `$destination` - URL to redirect to after login
- `$absolute` - Whether to generate absolute URL (default: FALSE)

**Returns:** The generated auto-login URL

**Throws:** `AutoLoginUrlException` on failure

### auto_login_url_convert_text()

Converts all URLs in text to auto-login versions for a specific user.

```php
auto_login_url_convert_text(int $uid, string $text): string
```

**Parameters:**
- `$uid` - User ID to create auto-login URLs for
- `$text` - Text containing URLs to convert

**Returns:** Text with converted auto-login URLs

**Throws:** `AutoLoginUrlException` on failure

### Helper Functions

```php
// Check if user can create auto-login URLs (not rate limited)
$can_create = auto_login_url_user_can_create($uid);

// Get user-specific statistics
$stats = auto_login_url_get_user_stats($uid);
// Returns: ['active_urls' => 5, 'total_urls_created' => 15, ...]
```

## Token Integration

When the Token module is enabled, the following tokens are available:

### User Tokens

**[user:auto-login-url-token]**
- Generates auto-login URL to site front page
- Example: `https://example.com/autologinurl/123/abc123...`

**[user:auto-login-url-account-edit-token]**
- Generates auto-login URL to user's account edit page
- Example: `https://example.com/autologinurl/123/def456.../edit`

### Usage in Email Templates

```html
<!-- In email templates -->
<p>Hello [user:display-name],</p>
<p>Click here to access your account: [user:auto-login-url-token]</p>
<p>To edit your profile, visit: [user:auto-login-url-account-edit-token]</p>
```

## Security Considerations

### Best Practices

1. **Use Shortest Practical Expiration**: Set expiration time as short as practical for your use case
2. **Enable Rate Limiting**: Use appropriate rate limits to prevent abuse
3. **Monitor Usage**: Enable analytics to monitor for unusual patterns
4. **Secure Transmission**: Only send auto-login URLs over secure channels (HTTPS email, etc.)
5. **Consider IP Validation**: Enable IP validation for high-security environments
6. **Use Single-Use URLs**: Enable "delete after use" for maximum security
7. **Regular Cleanup**: Ensure cron is running to clean up expired tokens

### Permissions

Grant permissions carefully:

- **"Use Auto Login URL"**: Required for URLs to work, can be granted to authenticated or anonymous users
- **"Administer Auto Login URL"**: Administrative access, should be restricted to trusted administrators

### Security Monitoring

Monitor these log messages:
- Failed authentication attempts
- Rate limit violations
- IP address mismatches (if validation enabled)
- Unusual usage patterns

## Monitoring and Analytics

### Health Check

Access `/admin/reports/auto-login-url/health` to verify the service is operational.

### Statistics Dashboard

The configuration page provides real-time statistics:
- Active auto-login URLs
- Expired URLs requiring cleanup
- Rate limiting status
- Usage analytics (if enabled)

### Database Maintenance

The module automatically maintains its database tables:
- **Cron Cleanup**: Expired tokens cleaned during cron runs
- **Analytics Retention**: Usage data kept for 6 months
- **Rate Limit Cleanup**: Old rate limiting data automatically purged

### Manual Maintenance

Administrative functions available in the configuration interface:
- Clean up expired URLs immediately
- Clear rate limiting data
- Purge old analytics data
- Regenerate secret key

## Troubleshooting

### Common Issues

**URLs not working (403 Forbidden)**
- Check user permissions ("use auto login url")
- Verify user account is active and not blocked
- Check if rate limit exceeded
- Verify URL hasn't expired

**Rate limit exceeded errors**
- Check current rate limiting configuration
- Review user's recent creation attempts
- Clear rate limiting data if appropriate
- Increase rate limit if legitimate usage

**Database errors**
- Ensure database schema is up to date: `drush updb`
- Check database connectivity and permissions
- Review recent database maintenance

**Performance issues**
- Run cleanup functions to remove old data
- Check database indexes are present
- Monitor rate limiting statistics
- Consider reducing analytics retention period

### Log Analysis

Check these log channels:
- `auto_login_url` - Module-specific logs
- `user` - User authentication logs
- `system` - General system logs

### Debug Mode

For development, enable verbose logging:
```php
// In settings.php
$config['system.logging']['error_level'] = 'verbose';
```

## Development

### Testing

The module includes comprehensive functional tests:

```bash
# Run all tests
./vendor/bin/phpunit modules/contrib/auto_login_url/tests

# Run specific test
./vendor/bin/phpunit modules/contrib/auto_login_url/tests/src/Functional/AutoLoginUrlTest.php
```

### Development Setup

```bash
# Install dependencies
composer install

# Enable development modules
drush en auto_login_url dblog

# Run tests
drush test-run auto_login_url
```

### Contributing

1. Fork the project on [GitLab](https://git.drupalcode.org/project/auto_login_url)
2. Create a feature branch
3. Add tests for new functionality
4. Ensure coding standards compliance
5. Submit merge request

### Code Standards

```bash
# Check coding standards
./vendor/bin/phpcs --standard=Drupal modules/contrib/auto_login_url

# Fix coding standards
./vendor/bin/phpcbf --standard=Drupal modules/contrib/auto_login_url
```

## Support

### Documentation
- [Project page](https://www.drupal.org/project/auto_login_url)
- [Issue queue](https://www.drupal.org/project/issues/auto_login_url)
- [API documentation](https://git.drupalcode.org/project/auto_login_url)

### Community Support
- [Drupal.org issue queue](https://www.drupal.org/project/issues/auto_login_url)
- [Drupal Slack](https://drupal.slack.com) - #support channel

### Reporting Issues
When reporting issues, please include:
- Drupal version
- PHP version
- Module version
- Steps to reproduce
- Relevant log messages
- Configuration details (without sensitive data)

### Professional Support
For professional support, custom development, or consulting services, contact the module maintainer through their [Drupal.org profile](https://drupal.org/user/1538394).

### Author/Maintainers:

- Thanos Nokas [Matrixlord](https://www.drupal.org/u/matrixlord)
- Rod Higgins [Code Poet](https://www.drupal.org/u/code-poet)
- Francesco Placella [plach](https://www.drupal.org/u/plach)
- Panagiotis Moutsopoulos [vensires](https://www.drupal.org/u/vensires)
- Michael Anello [ultimike](https://www.drupal.org/u/ultimike)

### License:

GPL-2.0-or-later

### Project Page:

https://www.drupal.org/project/auto_login_url