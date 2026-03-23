# Sealed Bidding API Reference

**Version:** 1.0.0  
**Requirement Tracking:** REQ-SEALED-BID-SERVICE-001, REQ-SEALED-BID-ENCRYPTION-001, REQ-SEALED-BID-KEY-ROTATION-001

---

## Table of Contents

1. [SealedBidService](#sealedbidservice)
2. [EncryptionManager](#encryptionmanager)
3. [EncryptionKeyManager](#encryptionkeymanager)
4. [SealedBidRepository](#sealedbidrepository)
5. [Exceptions](#exceptions)
6. [Value Objects](#value-objects)

---

## SealedBidService

**Namespace:** `Yith\Auctions\Services\SealedBidding\SealedBidService`

**Purpose:** Main orchestration layer for sealed bid workflow. Handles bid submission, revelation, and key management.

**Dependencies Injected:**
- `SealedBidRepository` - Data access layer
- `EncryptionManager` - Encryption/decryption operations
- `EncryptionKeyManager` - Key lifecycle management
- `\wpdb` - WordPress database object

---

### Method: `__construct()`

```php
public function __construct(
    SealedBidRepository $repository,
    EncryptionManager $encryption,
    EncryptionKeyManager $key_manager,
    \wpdb $wpdb
): void
```

**Description:** Initialize sealed bid service with dependencies.

**Parameters:**
- `$repository` - SealedBidRepository instance
- `$encryption` - EncryptionManager instance
- `$key_manager` - EncryptionKeyManager instance
- `$wpdb` - WordPress global database object

**Example:**
```php
$service = new SealedBidService($repo, $encryption, $key_mgr, $wpdb);
```

---

### Method: `submitSealedBid()`

```php
public function submitSealedBid(int $auction_id, int $user_id, string $bid_amount): string
```

**Requirement:** `REQ-SEALED-BID-SERVICE-001`

**Description:** Submits and encrypts a sealed bid. Prevents duplicate bids from same user for same auction.

**Parameters:**
| Parameter | Type | Description | Constraints |
|-----------|------|-------------|-------------|
| `$auction_id` | int | Auction WordPress post ID | > 0, must exist |
| `$user_id` | int | WordPress user ID | > 0, must exist |
| `$bid_amount` | string | Bid amount as decimal string | Must be > 0, max 2 decimal places |

**Returns:** `string` - Sealed bid UUID

**Throws:**
| Exception | Condition |
|-----------|-----------|
| `InvalidArgumentException` | If any parameter invalid or missing |
| `LogicException` | If user already has SUBMITTED bid for auction |
| `RuntimeException` | If encryption fails or database error |

**Business Logic:**
1. Validate all inputs (required, type, range)
2. Check for existing SUBMITTED bid (prevent duplicates)
3. Retrieve active encryption key
4. Encrypt bid amount using AES-256-GCM
5. Generate SHA-256 hash of plaintext bid
6. Store encrypted bid record with SUBMITTED status
7. Record event in audit trail
8. Return sealed bid UUID

**Example:**
```php
try {
    $sealed_bid_id = $service->submitSealedBid(
        auction_id: 123,
        user_id: get_current_user_id(),
        bid_amount: '100.50'
    );
    
    // Bid submitted successfully
    wp_redirect(add_query_arg([
        'auction' => 123,
        'sealed_bid' => $sealed_bid_id,
        'status' => 'submitted'
    ]));
    
} catch (LogicException $e) {
    // Bid already exists
    wp_die('You already have an active bid for this auction');
    
} catch (InvalidArgumentException $e) {
    // Invalid input
    wp_die('Invalid bid amount: ' . $e->getMessage());
}
```

**Audit Trail:** Creates SUBMITTED event in `wp_wc_auction_sealed_bid_history`

---

### Method: `getSealedBidStatus()`

```php
public function getSealedBidStatus(string $sealed_bid_id): ?array
```

**Requirement:** `REQ-SEALED-BID-SERVICE-001`

**Description:** Retrieves status of sealed bid without revealing encrypted contents.

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `$sealed_bid_id` | string | Sealed bid UUID (from submitSealedBid) |

**Returns:** 
- `array` - Bid status information
- `null` - If sealed bid not found

**Return Array Structure:**
```php
[
    'sealed_bid_id'  => 'uuid-format-string',
    'auction_id'     => 123,
    'user_id'        => 456,
    'status'         => 'SUBMITTED',  // SUBMITTED, REVEALED, ACCEPTED_FOR_COUNT, REJECTED
    'submitted_at'   => '2024-01-15 10:30:00',
    'revealed_at'    => null,  // Only populated after revelation
]
```

**Example:**
```php
$status = $service->getSealedBidStatus('bid-uuid-1234');

if ($status === null) {
    echo "Bid not found";
} elseif ($status['status'] === 'SUBMITTED') {
    echo "Your bid is sealed";
} elseif ($status['status'] === 'REVEALED') {
    echo "Bid revealed on: " . $status['revealed_at'];
}
```

---

### Method: `revealAllBids()`

```php
public function revealAllBids(int $auction_id): array
```

**Requirement:** `REQ-SEALED-BID-SERVICE-001`

**Description:** Reveals all sealed bids for an auction. Called when auction bidding period ends. Decrypts bids and validates integrity.

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `$auction_id` | int | Auction ID to reveal bids for |

**Returns:** `array` - Revelation results

**Return Array Structure:**
```php
[
    'success' => true/false,  // true only if all bids revealed successfully
    
    'revealed_bids' => [
        [
            'sealed_bid_id' => 'bid-uuid-1',
            'user_id'       => 456,
            'bid_amount'    => '100.50',
            'status'        => 'REVEALED',
        ],
        // ... more bids
    ],
    
    'failed_bids' => [
        [
            'sealed_bid_id' => 'bid-uuid-2',
            'reason'        => 'DECRYPTION_FAILED',  // DECRYPTION_FAILED, HASH_MISMATCH, PROCESSING_ERROR
            'status'        => 'REJECTED',
        ],
        // ... more failures
    ],
    
    'statistics' => [
        'SUBMITTED'          => 0,
        'REVEALED'           => 10,
        'ACCEPTED_FOR_COUNT' => 10,
        'REJECTED'           => 1,
        'total'              => 11,
    ],
]
```

**Business Logic Per Bid:**
1. Fetch all SUBMITTED bids for auction
2. Begin database transaction (all-or-nothing)
3. For each bid:
   - Decrypt using EncryptionManager
   - If decryption fails (bad tag):
     - Mark as REJECTED with reason DECRYPTION_FAILED
     - Log security alert (possible tampering)
     - Continue to next bid
   - If decryption succeeds:
     - Calculate hash of decrypted amount
     - Compare with stored hash (prevent tampering)
     - If mismatch:
       - Mark as REJECTED with reason HASH_MISMATCH
       - Log security alert
       - Continue to next bid
     - If match:
       - Update status to REVEALED
       - Store plaintext hash (for audit)
       - Record history event
4. Commit transaction
5. Return results

**Throws:** `RuntimeException` if transaction fails (automatic rollback)

**Example:**
```php
$results = $service->revealAllBids($auction_id);

if ($results['success']) {
    // All bids revealed successfully
    echo "All {$results['statistics']['REVEALED']} bids revealed";
    
    // Process revealed bids through auction logic
    foreach ($results['revealed_bids'] as $bid) {
        do_action(
            'wc_sealed_bid_revealed',
            [
                'auction_id' => $auction_id,
                'user_id' => $bid['user_id'],
                'amount' => $bid['bid_amount'],
            ]
        );
    }
} else {
    // Some bids failed
    echo "Failed bids: " . count($results['failed_bids']);
    
    foreach ($results['failed_bids'] as $failed) {
        error_log("Bid {$failed['sealed_bid_id']} failed: {$failed['reason']}");
    }
}
```

**Audit Trail:** Creates REVEALED or REJECTED events for each bid

**Security Considerations:**
- Transaction ensures atomic revelation (all or nothing)
- Hash verification detects tampering
- Failed bids automatically rejected (security-first approach)
- Detailed audit trail for compliance

---

### Method: `getSealedBidHistory()`

```php
public function getSealedBidHistory(string $sealed_bid_id): array
```

**Requirement:** `REQ-SEALED-BID-AUDIT-TRAIL-001`

**Description:** Retrieves complete audit trail for sealed bid.

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `$sealed_bid_id` | string | Sealed bid UUID |

**Returns:** `array` - Chronological list of events

**Return Array Structure:**
```php
[
    [
        'history_id' => 'uuid',
        'sealed_bid_id' => 'bid-uuid',
        'auction_id' => 123,
        'user_id' => 456,
        'event_type' => 'SUBMITTED',
        'description' => 'Sealed bid submitted',
        'metadata' => null,
        'created_at' => '2024-01-15 10:30:00',
    ],
    [
        'event_type' => 'REVEALED',
        'description' => 'Bid revealed and decrypted successfully',
        'metadata' => ['plaintext_hash' => 'sha256hash...'],
        'created_at' => '2024-01-16 14:00:00',
    ],
    // ... more events
]
```

**Example:**
```php
$history = $service->getSealedBidHistory('bid-uuid-1234');

echo "Bid Timeline:\n";
foreach ($history as $event) {
    echo $event['created_at'] . ": " . $event['event_type'] . "\n";
    echo "  " . $event['description'] . "\n";
    if (!empty($event['metadata'])) {
        echo "  Metadata: " . json_encode($event['metadata']) . "\n";
    }
}
```

---

### Method: `getAuctionBidStatistics()`

```php
public function getAuctionBidStatistics(int $auction_id): array
```

**Requirement:** `REQ-SEALED-BID-SERVICE-001`

**Description:** Gets sealed bid statistics for an auction.

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `$auction_id` | int | Auction ID |

**Returns:** `array` - Count of bids by status

**Return Structure:**
```php
[
    'SUBMITTED'          => 5,
    'REVEALED'           => 10,
    'ACCEPTED_FOR_COUNT' => 10,
    'REJECTED'           => 2,
    'total'              => 27,
]
```

**Example:**
```php
$stats = $service->getAuctionBidStatistics(123);

echo "Auction 123 Sealed Bids:\n";
echo "  Submitted: " . $stats['SUBMITTED'] . "\n";
echo "  Revealed: " . $stats['REVEALED'] . "\n";
echo "  Rejected: " . $stats['REJECTED'] . "\n";
echo "  Total: " . $stats['total'] . "\n";
```

---

### Method: `checkKeyRotation()`

```php
public function checkKeyRotation(): bool
```

**Requirement:** `REQ-SEALED-BID-KEY-ROTATION-001`

**Description:** Checks if encryption key rotation is due and performs rotation if needed. Call daily from scheduled task.

**Parameters:** None

**Returns:** `bool` - True if rotation was performed, false if not needed

**Rotation Criteria:**
- Checks if current key is within 7 days of 90-day expiration
- Creates new key if due
- Marks old key as ROTATED (kept for 365 days for backward compatibility)

**Example:**
```php
// In WordPress scheduled hook (runs daily)
add_action('wp_schedule_daily_event_key_rotation', function() {
    $service = get_sealed_bid_service();
    
    if ($service->checkKeyRotation()) {
        error_log("Encryption key rotated successfully");
    }
});

// Register hook (once during activation)
if (!wp_next_scheduled('wp_schedule_daily_event_key_rotation')) {
    wp_schedule_event(time(), 'daily', 'wp_schedule_daily_event_key_rotation');
}
```

**Audit Trail:** Creates key rotation event in logging system

---

### Method: `cleanupExpiredKeys()`

```php
public function cleanupExpiredKeys(): int
```

**Requirement:** `REQ-SEALED-BID-KEY-ROTATION-001`

**Description:** Removes encryption keys older than retention period (365 days). Call periodically (e.g., monthly) to maintain database size.

**Parameters:** None

**Returns:** `int` - Number of keys deleted

**Example:**
```php
// Add to monthly maintenance hook
add_action('wp_schedule_monthly_maintenance', function() {
    $service = get_sealed_bid_service();
    $deleted = $service->cleanupExpiredKeys();
    error_log("Deleted $deleted expired encryption keys");
});
```

---

### Method: `getKeyStatistics()`

```php
public function getKeyStatistics(): array
```

**Requirement:** `REQ-SEALED-BID-KEY-ROTATION-001`

**Description:** Gets current encryption key statistics for monitoring and administration.

**Parameters:** None

**Returns:** `array` - Key lifecycle statistics

**Return Structure:**
```php
[
    'total_keys'            => 5,           // Total keys in system
    'active_keys'           => 1,           // Currently active keys
    'rotation_due'          => false,       // Is rotation due soon?
    'active_key_expires'    => '2024-03-15 10:30:00',  // When current key expires
    'days_until_rotation'   => 45,          // Days until rotation needed
    'rotation_interval_days' => 90,         // Configured rotation interval
    'retention_days'        => 365,         // How long to keep rotated keys
]
```

**Example:**
```php
$stats = $service->getKeyStatistics();

// Display in admin dashboard
echo "Encryption Key Status:\n";
echo "  Active: " . ($stats['active_keys'] > 0 ? 'Yes' : 'No') . "\n";
echo "  Expires: " . $stats['active_key_expires'] . "\n";
echo "  Days until rotation: " . $stats['days_until_rotation'] . "\n";

if ($stats['rotation_due']) {
    echo "  ⚠️ Rotation due!\n";
}
```

---

## EncryptionManager

**Namespace:** `Yith\Auctions\Services\Encryption\EncryptionManager`

**Purpose:** Handles AES-256-GCM encrypted bid storage with authentication and key versioning.

---

### Method: `encrypt()`

```php
public function encrypt(string $plaintext): string|false
```

**Requirement:** `REQ-SEALED-BID-ENCRYPTION-001`

**Description:** Encrypts plaintext using AES-256-GCM with random IV.

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `$plaintext` | string | Data to encrypt (e.g., bid amount) |

**Returns:**
- `string` - Encrypted data (IV + ciphertext + authentication tag)
- `false` - Encryption failed

**Security Properties:**
- AES-256-GCM: 256-bit key, 128-bit authentication tag
- Random 96-bit IV per encryption (prevents patterns)
- Authenticated encryption (detects tampering)
- Constant-time operations (prevents timing attacks)

**Example:**
```php
$encrypted = $manager->encrypt('100.50');

if ($encrypted === false) {
    error_log("Encryption failed");
} else {
    // Store encrypted string in database
    $wpdb->insert('wp_wc_auction_sealed_bids', [
        'encrypted_bid' => $encrypted,
    ]);
}
```

---

### Method: `decrypt()`

```php
public function decrypt(string $encrypted): string|false
```

**Requirement:** `REQ-SEALED-BID-ENCRYPTION-001`

**Description:** Decrypts and verifies authenticated encryption. Returns false if tag verification fails (possible tampering).

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `$encrypted` | string | Encrypted data (from encrypt()) |

**Returns:**
- `string` - Decrypted plaintext
- `false` - Decryption or verification failed

**Verification:**
- Authentication tag verified (detects tampering)
- Returns false on ANY verification failure
- No exceptions (security-first)

**Example:**
```php
$bid = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM wp_wc_auction_sealed_bids WHERE sealed_bid_id = %s",
    $sealed_bid_id
));

$plaintext = $manager->decrypt($bid['encrypted_bid']);

if ($plaintext === false) {
    error_log("Bid decryption failed - possible tampering");
    $handle_tampering();
} else {
    echo "Bid amount: " . $plaintext;
}
```

---

### Method: `hashPlaintext()`

```php
public function hashPlaintext(string $plaintext): string
```

**Requirement:** `REQ-SEALED-BID-ENCRYPTION-001`

**Description:** Generates SHA-256 hash of plaintext for verification without storing plaintext.

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `$plaintext` | string | Data to hash |

**Returns:** `string` - SHA-256 hash (hex format, 64 characters)

**Use Cases:**
1. Store hash with encrypted bid (for tampering detection)
2. After decryption, recalculate hash and compare
3. If mismatch → data was modified

**Example:**
```php
// During bid submission
$bid_hash = $manager->hashPlaintext('100.50');
$wpdb->insert('wp_wc_auction_sealed_bids', [
    'bid_hash' => $bid_hash,
]);

// During revelation
$plaintext = $manager->decrypt($encrypted);
$recalculated_hash = $manager->hashPlaintext($plaintext);

if (!hash_equals($bid_hash, $recalculated_hash)) {
    error_log("Tampering detected!");
}
```

---

### Method: `deriveKeyFromPassword()`

```php
public static function deriveKeyFromPassword(string $password, string $salt): string
```

**Requirement:** `REQ-SEALED-BID-ENCRYPTION-001`

**Description:** Derives 256-bit encryption key from password using PBKDF2-SHA256.

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `$password` | string | Password to derive key from |
| `$salt` | string | Salt (typically 16 bytes) |

**Returns:** `string` - 256-bit derived key

**Key Derivation:**
- Algorithm: PBKDF2-SHA256
- Iterations: 100,000 (NIST recommended minimum)
- Output length: 256 bits (32 bytes)

**Example:**
```php
$password = 'your-secure-password';
$salt = random_bytes(16);

$key = EncryptionManager::deriveKeyFromPassword($password, $salt);

// Store salt in database, but never the password or key
```

---

### Method: `generateRandomKey()`

```php
public static function generateRandomKey(): string
```

**Requirement:** `REQ-SEALED-BID-ENCRYPTION-001`

**Description:** Generates cryptographically secure random 256-bit key.

**Parameters:** None

**Returns:** `string` - 256-bit random key (32 bytes)

**Security:**
- Uses `random_bytes()` (cryptographically secure)
- Cannot be predicted or reproduced
- Suitable for encryption key generation

**Example:**
```php
$key = EncryptionManager::generateRandomKey();

// This key can be used for encryption directly
// Store in database or key management system
```

---

### Method: `isAlgorithmSupported()`

```php
public static function isAlgorithmSupported(string $algorithm = 'aes-256-gcm'): bool
```

**Requirement:** `REQ-SEALED-BID-ENCRYPTION-001`

**Description:** Checks if OpenSSL supports specified algorithm.

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `$algorithm` | string | Algorithm name (default: 'aes-256-gcm') |

**Returns:** `bool` - True if algorithm supported, false otherwise

**Example:**
```php
if (!EncryptionManager::isAlgorithmSupported()) {
    wp_die("Error: OpenSSL AES-256-GCM not available on server");
}

// Safe to use encryption
```

---

## EncryptionKeyManager

**Namespace:** `Yith\Auctions\Services\Encryption\EncryptionKeyManager`

**Purpose:** Manages encryption key lifecycle including creation, rotation, versioning, and cleanup.

---

### Method: `__construct()`

```php
public function __construct(\wpdb $wpdb)
```

**Description:** Initialize key manager with database connection.

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `$wpdb` | \wpdb | WordPress global database object |

---

### Method: `getActiveKey()`

```php
public function getActiveKey(): array
```

**Requirement:** `REQ-SEALED-BID-KEY-ROTATION-001`

**Description:** Gets currently active encryption key (used for new encryptions).

**Parameters:** None

**Returns:** `array` - Active key metadata

**Return Structure:**
```php
[
    'id'           => 1,
    'key_id'       => 'uuid-format-string',
    'algorithm'    => 'AES-256-GCM',
    'created_at'   => '2024-01-01 10:00:00',
    'expires_at'   => '2024-03-31 10:00:00',  // 90 days after creation
]
```

**Throws:** `RuntimeException` if no active key found

**Example:**
```php
try {
    $key = $manager->getActiveKey();
    echo "Using key: " . $key['key_id'];
} catch (RuntimeException $e) {
    echo "No active encryption key - initialize first";
    $manager->createNewKey();
}
```

---

### Method: `getDecryptionKeys()`

```php
public function getDecryptionKeys(): array
```

**Requirement:** `REQ-SEALED-BID-KEY-VERSIONING-001`

**Description:** Gets all active and recently-rotated keys available for decryption (backward compatibility).

**Parameters:** None

**Returns:** `array` - Array of key metadata for decryption

**Details:**
- Returns ACTIVE and ROTATED keys
- Only includes keys created within retention period (365 days)
- Ordered by creation date (newest first)
- Can be used to decrypt old bids with previous keys

**Example:**
```php
// Attempt decryption with all available keys
$decryption_keys = $manager->getDecryptionKeys();

$plaintext = false;
foreach ($decryption_keys as $key) {
    $plaintext = $encryption->decrypt($encrypted_with_key_id($key['key_id']));
    if ($plaintext !== false) {
        break;  // Successfully decrypted
    }
}

if ($plaintext === false) {
    error_log("Cannot decrypt with any available key");
}
```

---

### Method: `getKeyById()`

```php
public function getKeyById(string $key_id): ?array
```

**Requirement:** `REQ-SEALED-BID-KEY-ROTATION-001`

**Description:** Gets specific key by UUID.

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `$key_id` | string | Key UUID |

**Returns:**
- `array` - Key metadata
- `null` - Key not found

---

### Method: `createNewKey()`

```php
public function createNewKey(): array
```

**Requirement:** `REQ-SEALED-BID-KEY-ROTATION-001`

**Description:** Creates new encryption key and marks old one as rotated.

**Parameters:** None

**Returns:** `array` - New key metadata

**Workflow:**
1. Mark current active key as ROTATED (if exists)
2. Generate new key UUID
3. Set expiration to 90 days from now
4. Insert new record with ACTIVE status
5. Return new key metadata

**Example:**
```php
$new_key = $manager->createNewKey();

echo "Created new key: " . $new_key['key_id'];
echo "Expires: " . $new_key['expires_at'];
```

---

### Method: `rotateCurrentKey()`

```php
public function rotateCurrentKey(): bool
```

**Requirement:** `REQ-SEALED-BID-KEY-ROTATION-001`

**Description:** Marks current active key as rotated (preparing for new key).

**Parameters:** None

**Returns:** `bool` - Success

**Called By:** `createNewKey()` during rotation

---

### Method: `isRotationDue()`

```php
public function isRotationDue(): bool
```

**Requirement:** `REQ-SEALED-BID-KEY-ROTATION-001`

**Description:** Checks if encryption key rotation is due soon.

**Parameters:** None

**Returns:** `bool` - True if rotation needed

**Logic:**
- Returns true if key expires within 7 days
- Returns true if current time past expiration
- Returns true if no active key exists

---

### Method: `cleanupExpiredKeys()`

```php
public function cleanupExpiredKeys(): int
```

**Requirement:** `REQ-SEALED-BID-KEY-ROTATION-001`

**Description:** Deletes keys older than retention period (365 days).

**Parameters:** None

**Returns:** `int` - Number of keys deleted

**Example:**
```php
// Schedule monthly cleanup
add_action('wp_schedule_monthly', function() {
    $deleted = $manager->cleanupExpiredKeys();
    error_log("Deleted $deleted old encryption keys");
});
```

---

### Method: `getKeyStatistics()`

```php
public function getKeyStatistics(): array
```

**Requirement:** `REQ-SEALED-BID-KEY-ROTATION-001`

**Description:** Gets encryption key statistics for monitoring.

**Parameters:** None

**Returns:** `array` - Key statistics

**Return Structure:**
```php
[
    'total_keys'            => 5,
    'active_keys'           => 1,
    'rotation_due'          => false,
    'active_key_expires'    => '2024-03-15',
    'days_until_rotation'   => 45,
    'rotation_interval_days' => 90,
    'retention_days'        => 365,
]
```

---

## SealedBidRepository

**Namespace:** `Yith\Auctions\Repository\SealedBidRepository`

**Purpose:** Data access layer for sealed bids using repository pattern.

---

### Method: `createSealedBid()`

```php
public function createSealedBid(
    int $auction_id,
    int $user_id,
    string $encrypted_bid,
    string $bid_hash,
    string $key_id,
    string $status = 'SUBMITTED'
): string|false
```

**Description:** Creates new sealed bid record.

**Returns:** Sealed bid UUID or false on failure

---

### Method: `getSealedBidById()`

```php
public function getSealedBidById(string $sealed_bid_id): ?array
```

**Description:** Gets sealed bid by UUID.

**Returns:** Bid record or null

---

### Method: `getUserSealedBid()`

```php
public function getUserSealedBid(int $auction_id, int $user_id): ?array
```

**Description:** Gets user's sealed bid for specific auction.

**Returns:** Bid record or null

---

### Method: `getBidsForAuction()`

```php
public function getBidsForAuction(int $auction_id, ?string $status = null): array
```

**Description:** Gets all sealed bids for auction, optionally filtered by status.

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `$auction_id` | int | Auction ID |
| `$status` | string\|null | Optional: SUBMITTED, REVEALED, ACCEPTED_FOR_COUNT, REJECTED |

**Returns:** `array` - Array of bid records

---

### Method: `getReadyForReveal()`

```php
public function getReadyForReveal(int $auction_id): array
```

**Description:** Gets all SUBMITTED bids ready for revelation.

**Returns:** `array` - Array of SUBMITTED bid records

---

### Method: `updateBidStatus()`

```php
public function updateBidStatus(
    string $sealed_bid_id,
    string $new_status,
    ?string $plaintext_hash = null
): bool
```

**Description:** Updates bid status and optionally stores plaintext hash after revelation.

---

### Method: `recordHistory()`

```php
public function recordHistory(
    string $sealed_bid_id,
    int $auction_id,
    int $user_id,
    string $event_type,
    string $description,
    array $metadata = []
): bool
```

**Description:** Records immutable event in audit trail.

---

### Method: `getHistory()`

```php
public function getHistory(string $sealed_bid_id): array
```

**Description:** Gets complete audit trail for sealed bid.

**Returns:** `array` - Chronological events

---

### Method: `getAuctionStatistics()`

```php
public function getAuctionStatistics(int $auction_id): array
```

**Description:** Gets bid count statistics for auction by status.

**Returns:** `array` - Counts by status

---

## Exceptions

All sealed bid exceptions inherit from `\Exception` or specific SPL exceptions:

### InvalidArgumentException

**When:** Input validation fails

```php
throw new \InvalidArgumentException('Bid amount must be decimal with max 2 places');
```

### LogicException

**When:** Business rule violated

```php
throw new \LogicException('User already has active sealed bid for this auction');
```

### RuntimeException

**When:** System/environmental error

```php
throw new \RuntimeException('Failed to encrypt bid');
throw new \RuntimeException('No active encryption key configured');
```

---

## Value Objects

### SealedBidStatus (Enumeration)

```php
class SealedBidStatus
{
    const SUBMITTED = 'SUBMITTED';
    const REVEALED = 'REVEALED';
    const ACCEPTED_FOR_COUNT = 'ACCEPTED_FOR_COUNT';
    const REJECTED = 'REJECTED';
    
    public static function isValid(string $status): bool
    public static function isTerminal(string $status): bool
}
```

**Terminal Statuses:** REVEALED, ACCEPTED_FOR_COUNT, REJECTED  
(Cannot be changed once in terminal state)

---

## Performance Notes

| Operation | Time | Complexity |
|-----------|------|-----------|
| Submit sealed bid | ~50ms | O(log n) |
| Get active key | ~1ms | O(1) |
| Decrypt single bid | ~10ms | O(1) |
| Reveal 100 bids | ~1000ms | O(n) |
| Get auction statistics | ~5ms | O(1) |

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | 2024-01-01 | Initial sealed bidding API |

