# Encryption Installer - Plugin Installation Integration

## Overview

The **EncryptionInstaller** automatically sets up encryption during plugin activation and initialization. It handles:

- ✅ Composer dependency verification (defuse/php-encryption)
- ✅ Automatic encryption key generation on first install
- ✅ Secure key storage (wp-config > environment > options)
- ✅ Admin notifications with configuration instructions
- ✅ Verification and health checks
- ✅ No manual composer/key-gen commands required for admins

## Features

### Automatic Key Generation

When the plugin initializes for the first time:

1. **Detects available encryption method** (defuse/php-encryption or libsodium)
2. **Generates cryptographically secure key** using the available method
3. **Stores key in WordPress options** temporarily
4. **Shows admin notice** with instructions to move to wp-config.php

**No action required by administrator** — encryption is immediately functional.

### Configuration Priority

EncryptionInstaller checks for the encryption key in this order:

```
1. wp-config.php constant (AUCTION_ENCRYPTION_KEY)
   ↓ (production-recommended)
2. Environment variable (AUCTION_ENCRYPTION_KEY)
   ↓ (environment-specific)
3. WordPress options
   ↓ (temporary/fallback)
4. Error if not found (requires manual setup)
```

### Production Deployment

The admin notice shows exact code to add to `wp-config.php`:

```php
define('AUCTION_ENCRYPTION_KEY', 'DefuseCrypto... or base64...');
```

Once added to wp-config.php, the service uses that key and removes the temporary WordPress options storage.

## Usage

### For Authorization/Secure Operations

```php
use WC\Auction\Services\EncryptionInstaller;

// Get encryption service (uses configured or generated key)
$encryption = EncryptionInstaller::getEncryptionService();

// Encrypt sensitive data
$encrypted = $encryption->encrypt( $payout_method_id );

// Decrypt when needed
$plaintext = $encryption->decrypt( $encrypted );

// Verify encryption is working
$status = EncryptionInstaller::verify();
if ( !$status['valid'] ) {
    error_log( 'Encryption not working: ' . $status['error'] );
}
```

### Check Encryption Status

```php
// Get current encryption setup info
$status = EncryptionInstaller::getStatus();

echo $status['method'];       // 'defuse' or 'sodium'
echo $status['location'];     // 'wp-config', 'env', or 'options'
echo $status['generated_at']; // ISO 8601 timestamp
```

## Installation Flow

### Plugin Activation

```
admin clicks "Activate"
    ↓
init.php loads
    ↓
plugins_loaded hook fires
    ↓
yith_wcact_install() runs (priority 11)
    ↓
do_action('yith_wcact_init') called
    ↓
yith_wcact_init_encryption() hook (priority 5)
    ↓
EncryptionInstaller::install() runs
    ├─ Detect encryption method available
    ├─ Check if key already configured
    ├─ If no key: generate and store in options
    └─ Schedule admin notice with wp-config instructions
    ↓
Plugin fully initialized with encryption ready
```

### Composer Requirements

The plugin's `composer.json` includes `defuse/php-encryption`:

```json
{
  "require": {
    "defuse/php-encryption": "^2.4"
  }
}
```

**Assumption**: Hosting has `composer` available for `composer install`

When composer is available, `defuse/php-encryption` is installed as the primary encryption method.

If not available, the installer falls back to `libsodium` (PHP 7.2+).

## Configuration Methods

### Method 1: wp-config.php (Recommended for Production)

```php
// In wp-config.php
define('AUCTION_ENCRYPTION_KEY', 'DefuseCrypto... or base64-encoded key');
```

**Advantages**:
- ✅ Outside web root
- ✅ Not in database
- ✅ Environment-specific per deployment
- ✅ Version control safe

### Method 2: Environment Variable

```bash
# .env or hosting control panel
export AUCTION_ENCRYPTION_KEY="DefuseCrypto... or base64..."
```

**Advantages**:
- ✅ Twelve-factor app compliant
- ✅ Platform agnostic (.env, Docker, systemd, etc.)

### Method 3: WordPress Options (Temporary/Development)

Automatically used during initial install. Visible in admin notice. Should be migrated to wp-config ASAP for production.

## API Reference

### EncryptionInstaller::install()

Called automatically during plugin initialization.

```php
$result = EncryptionInstaller::install(); // returns true
```

- **Idempotent**: Safe to call multiple times
- **Automatic**: Hooked to `yith_wcact_init` action
- **Returns**: `bool` true for success

### EncryptionInstaller::getEncryptionService()

Get an initialized EncryptionService instance.

```php
$service = EncryptionInstaller::getEncryptionService();

$encrypted = $service->encrypt('data');
$plain = $service->decrypt($encrypted);
```

**Throws**: `RuntimeException` if key not configured

### EncryptionInstaller::getEncryptionKey()

Get the configured encryption key without initializing service.

```php
$key = EncryptionInstaller::getEncryptionKey(); // string or null
```

**Returns**: Encryption key or `null` if not found

### EncryptionInstaller::getStatus()

Get encryption setup information.

```php
$status = EncryptionInstaller::getStatus();
// {
//   "method": "defuse|sodium|unknown",
//   "generated_at": "2026-03-30 12:34:56",
//   "location": "wp-config|env|options|not-configured"
// }
```

### EncryptionInstaller::verify()

Verify encryption is working (round-trip test).

```php
$result = EncryptionInstaller::verify();
// {
//   "valid": true|false,
//   "error": "error message or null"
// }
```

## Admin Notice Flow

After first plugin activation, administrators see:

> **WooCommerce Auctions Encryption Configured**
>
> Encryption has been automatically configured using Defuse.
>
> For production environments, add this constant to wp-config.php:
> ```php
> define('AUCTION_ENCRYPTION_KEY', 'DefuseCrypto...');
> ```

This notice appears once and explains the next step.

## Testing

### Unit Tests

```bash
phpunit tests/unit/Services/EncryptionInstallerTest.php
```

**Coverage**:
- ✅ Install generates key
- ✅ Install is idempotent
- ✅ Key retrieval from all sources
- ✅ Encryption service initialization
- ✅ Verification/health checks
- ✅ Status reporting
- ✅ Error handling

### Manual Verification

On admin dashboard:

```php
// In plugin code or custom admin page
$status = \WC\Auction\Services\EncryptionInstaller::getStatus();
dd( $status );

// Verify encryption works
$verify = \WC\Auction\Services\EncryptionInstaller::verify();
dd( $verify );
```

## Troubleshooting

### Error: "No encryption method available"

**Cause**: Neither `defuse/php-encryption` nor `libsodium` installed

**Solution**:
1. Install defuse via composer: `composer require defuse/php-encryption:^2.4`
2. OR enable libsodium extension in PHP

### Encryption shows "sodium" but I expected "defuse"

**Cause**: `defuse/php-encryption` not installed

**Solution**: `composer require defuse/php-encryption:^2.4`

### Admin doesn't see configuration notice

**Cause**: Key already in wp-config.php

**Expected**: Notice only shows when temporary key generated

**Resolution**: No action needed — encryption is properly configured

### Plugin says key not found despite wp-config.php

**Cause**: Constant name mismatch or PHP not picking it up

**Solution**:
1. Verify constant name: `AUCTION_ENCRYPTION_KEY` (exact case)
2. Verify it's before `wp-load.php` in WordPress load sequence
3. Clear any caches
4. Restart web server

## Requirements Coverage

- ✅ **REQ-4D-045**: Initialize encryption on plugin install
- ✅ **SEC-001**: Use authenticated encryption (via EncryptionService)
- ✅ **SEC-002**: Secure key management with configuration priority
- ✅ **Installation**: Automatic with composer assumption
- ✅ **Documentation**: Admin-friendly configuration process

## Files

1. **EncryptionInstaller.php** (350+ LOC)
   - Installation logic
   - Key generation
   - Service provisioning
   - Status/verification

2. **init.php** (updated)
   - Hook installer to `yith_wcact_init`
   - Priority 5 (runs before main plugin logic)

3. **EncryptionInstallerTest.php** (15+ tests)
   - Installation flow
   - Key retrieval
   - Service provisioning
   - Verification

## Integration Points

The installer is automatically called via:

```php
add_action( 'yith_wcact_init', 'yith_wcact_init_encryption', 5 );
```

**Priority 5** ensures encryption is ready before any plugin code runs.

This is perfect for:
- ✅ Services injecting EncryptionInstaller::getEncryptionService()
- ✅ Payment processors encrypting sensitive data
- ✅ Admin operations verifying encryption status
- ✅ Setup wizards checking capabilities

---

## Summary

The **EncryptionInstaller** brings production-ready encryption to the auction plugin with:

| Feature | Status |
|---------|--------|
| **Automatic key generation** | ✅ On install |
| **Composer integration** | ✅ Assumes available |
| **Production config** | ✅ wp-config/env |
| **Fallback encryption** | ✅ libsodium |
| **Admin notifications** | ✅ One-time notice |
| **Health checks** | ✅ verify() method |
| **Tests** | ✅ 15+ tests |

**Zero manual setup required** — just install and use!
