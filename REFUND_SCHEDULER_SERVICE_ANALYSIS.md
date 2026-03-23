# RefundSchedulerService - Comprehensive Code Analysis

## 1. RefundSchedulerService Location & Implementation

### File Location
**Path:** [src/Services/EntryFees/RefundSchedulerService.php](src/Services/EntryFees/RefundSchedulerService.php)

**Namespace:** `Yith\Auctions\Services\EntryFees`

### Class Definition
```php
class RefundSchedulerService
{
    use LoggerTrait;
    
    private PaymentGatewayInterface $payment_gateway;
    private PaymentAuthorizationRepository $repository;
    private $notification_callback;
    private const BATCH_SIZE = 50;
}
```

### Main Methods

#### 1. `__construct()`
**Lines:** [94-116](src/Services/EntryFees/RefundSchedulerService.php#L94-L116)

```php
public function __construct(
    PaymentGatewayInterface $gateway,
    PaymentAuthorizationRepository $repository,
    $notification_callback = null
)
```

**Purpose:** Initialize the service with payment gateway and repository dependencies

**Parameters:**
- `$gateway` (PaymentGatewayInterface) - Payment processor implementation
- `$repository` (PaymentAuthorizationRepository) - Database access layer
- `$notification_callback` (callable|null) - Optional callback for bidder notifications
  - Signature: `function($user_id, $amount, $refund_id, $reason)`

#### 2. `processScheduledRefunds()` - Main Entry Point
**Lines:** [148-206](src/Services/EntryFees/RefundSchedulerService.php#L148-L206)

**Requirement:** REQ-ENTRY-FEE-PAYMENT-001: Process scheduled refunds

```php
public function processScheduledRefunds(): array
```

**Purpose:** Process all pending refunds that have passed the 24-hour dispute window (batch processor called hourly by cron)

**Workflow:**
1. Get refunds ready to process (scheduled_for ≤ NOW, status = PENDING, limit 50)
2. Iterate through each refund
3. Call `processRefund()` for individual processing
4. Track success/failure counts
5. Return summary statistics

**Return Value:**
```php
[
    'total_processed' => int,      // Total attempts
    'successful' => int,            // Successfully processed
    'failed' => int,                // Failed to process
    'skipped' => int,               // Skipped (e.g., not ready yet)
    'errors' => [                   // Array of error details
        [
            'refund_id' => string,
            'error' => string
        ]
    ]
]
```

**Resilience Features:**
- Continues on individual failures (doesn't abort batch)
- Logs all processing attempts
- Returns comprehensive statistics for monitoring
- No exceptions thrown (graceful error handling)

#### 3. `processRefund()` - Individual Refund Processing
**Lines:** [217-323](src/Services/EntryFees/RefundSchedulerService.php#L217-L323)

**Requirement:** REQ-ENTRY-FEE-PAYMENT-001: Process individual refund

```php
private function processRefund(array $refund): bool
```

**Purpose:** Process a single refund through complete lifecycle

**Input `$refund` Record Structure:**
```php
[
    'id' => int,
    'refund_id' => string,              // Unique refund identifier
    'authorization_id' => string,       // Payment gateway auth ID
    'user_id' => int,                   // Bidder's WordPress user ID
    'amount_cents' => int,              // Amount in cents (not used - read from auth)
    'reason' => string,                 // Refund reason (e.g., "Outbid")
    'scheduled_for' => string           // Y-m-d H:i:s format
]
```

**Processing Steps:**
1. **Retrieve Authorization** - Get authorization details including amount
2. **Create Money Object** - Convert cents to Money value object
3. **Call Payment Gateway** - Execute refund on payment processor
4. **Update Refund Status** - Mark as PROCESSED with timestamp
5. **Update Authorization Status** - Mark as REFUNDED with refund timestamp
6. **Notify Bidder** - Call notification callback if provided
7. **Log Success** - Record completion for audit trail

**Error Handling:**
- Catches all exceptions
- Updates refund status to FAILED with error message
- Logs error details (refund_id, auth_id, error message)
- Returns false (doesn't throw - allows batch to continue)

**Return Value:**
- `true` if successful
- `false` if failed (already logged)

#### 4. `notifyBidder()` - Notification System
**Lines:** [331-360](src/Services/EntryFees/RefundSchedulerService.php#L331-L360)

**Requirement:** REQ-ENTRY-FEE-PAYMENT-001: Notify bidders

```php
private function notifyBidder(
    int $user_id,
    Money $amount,
    string $refund_id,
    string $reason
): void
```

**Purpose:** Call notification callback to send bidder notification (typically email)

**Parameters:**
- `$user_id` - WordPress user ID of bidder
- `$amount` - Refunded amount as Money object
- `$refund_id` - Unique refund identifier
- `$reason` - Reason for refund

**Implementation:**
- Checks if callback is callable
- Executes callback with parameters
- Logs bidder notification
- Catches exceptions from callback without throwing (notifications are optional)

**Note:** Notification callback is optional and doesn't abort refund processing if it fails.

#### 5. `getProcessingStats()` - Monitoring
**Lines:** [362-391](src/Services/EntryFees/RefundSchedulerService.php#L362-L391)

**Requirement:** REQ-ENTRY-FEE-PAYMENT-001: Monitoring

```php
public function getProcessingStats(): array
```

**Purpose:** Get aggregate statistics about pending and failed refunds

**Return Value:**
```php
[
    'pending_count' => int,                     // Total pending refunds
    'failed_count' => int,                      // Total failed refunds
    'oldest_pending_scheduled_for' => string|null,  // Oldest pending refund time
    'next_batch_size' => int,                   // Next batch would be this many
    'failed_refunds' => array                   // Last 10 failed refunds
]
```

**Use Cases:**
- Admin dashboard display
- Monitoring/alerting systems
- Health check endpoints

#### 6. `retryFailedRefund()` - Manual Retry
**Lines:** [393-425](src/Services/EntryFees/RefundSchedulerService.php#L393-L425)

**Requirement:** REQ-ENTRY-FEE-PAYMENT-001: Manual retry

```php
public function retryFailedRefund(string $refund_id): bool
```

**Purpose:** Manually retry a single failed refund

**Usage:**
```php
try {
    $success = $scheduler->retryFailedRefund('REFUND-123');
    if ($success) {
        // Log success
    }
} catch (\Exception $e) {
    // Handle error
}
```

**Implementation:**
- Retrieves refund record by ID
- Processes it using same `processRefund()` method
- Logs all operations
- Throws exception if refund not found

#### 7. `pruneRefundRecords()` - Data Retention
**Lines:** [427-455](src/Services/EntryFees/RefundSchedulerService.php#L427-L455)

**Requirement:** REQ-ENTRY-FEE-PAYMENT-001: Data retention

```php
public function pruneRefundRecords(int $days_old = 90): int
```

**Purpose:** Delete old completed refund records (data retention policy)

**Parameters:**
- `$days_old` - Records older than this many days are deleted (default 90)

**Retention Policy:**
- Deletes only COMPLETED/REFUNDED records (keeps FAILED for investigation)
- Typically run quarterly or monthly
- Called from admin action or separate scheduled task

**Return Value:** Number of records deleted

---

## 2. Refund Schedule Table Structure

### Table: `wp_wc_auction_refund_schedule`

Located in [src/Database/Migrations/PaymentAuthorizationMigration.php](src/Database/Migrations/PaymentAuthorizationMigration.php#L216-L245)

#### Column Definitions

| Column | Type | Properties | Purpose |
|--------|------|-----------|---------|
| `id` | BIGINT(20) | PRIMARY KEY, AUTO_INCREMENT | Unique record identifier |
| `authorization_id` | VARCHAR(255) | NOT NULL, UNIQUE KEY | Links to payment authorization (Foreign key) |
| `refund_id` | VARCHAR(36) | NOT NULL, UNIQUE KEY | Unique refund identifier (UUID) |
| `user_id` | BIGINT(20) | NOT NULL, Indexed | Bidder's WordPress user ID |
| `scheduled_for` | DATETIME | NOT NULL, **Indexed** | When refund should process (24h from now) |
| `reason` | VARCHAR(255) | Nullable | Why refund is being issued (e.g., "Outbid") |
| `status` | VARCHAR(50) | NOT NULL, Indexed | Current status of refund |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP, Indexed | When refund was scheduled |
| `processed_at` | DATETIME | Nullable | When refund was actually processed |

#### Status Values

Status transitions during refund lifecycle:

```
PENDING (initial)
  ↓ (scheduled_for <= NOW, processed by cron)
PROCESSED (success) or FAILED (error)
  ↓ (after configured retention period)
DELETED (pruned)
```

**Status Meanings:**
- `PENDING` - Waiting for 24h delay to pass before processing
- `PROCESSED` - Refund successfully applied to bidder's card
- `FAILED` - Refund processing failed; needs investigation/retry

#### Indexes

**Performance-Critical Indexes:**

```sql
KEY idx_scheduled (scheduled_for)  -- Primary index for cron query
KEY idx_status (status)             -- Filter by PENDING status
KEY idx_user (user_id)              -- Look up user's refunds
KEY idx_created (created_at)        -- Retention/archive queries
```

**Critical Index for Cron Job:**
The `idx_scheduled_status` composite index would be ideal but not explicitly defined:
```sql
KEY idx_scheduled_status (status, scheduled_for)
```

#### Query Pattern - Getting Pending Refunds

```sql
SELECT * FROM wp_wc_auction_refund_schedule 
WHERE status = 'PENDING' 
  AND scheduled_for <= '2026-01-01 12:00:00'
ORDER BY scheduled_for ASC 
LIMIT 50;
```

**Index Used:** `idx_scheduled` + filtering by status

---

## 3. Existing Cron Infrastructure

### Cron Registration (Intended)

Documented in [RefundSchedulerService.php lines 35-47](src/Services/EntryFees/RefundSchedulerService.php#L35-L47):

```php
/**
 * Cron Registration:
 *
 * ```php
 * // In plugin initialization
 * if (!wp_next_scheduled('wc_auction_process_refunds')) {
 *     wp_schedule_event(time(), 'hourly', 'wc_auction_process_refunds');
 * }
 *
 * // Hooked handler
 * add_action('wc_auction_process_refunds', function() {
 *     $scheduler = new RefundSchedulerService(
 *         $payment_gateway,
 *         $repository,
 *         $notification_service
 *     );
 *     $scheduler->processScheduledRefunds();
 * });
 * ```
 */
```

### WordPress Cron Function Details

**Hook Name:** `wc_auction_process_refunds`

**Schedule Frequency:** `hourly` (WordPress built-in schedule)

**WordPress Cron Functions Used:**

```php
// Register/schedule
wp_schedule_event(time(), 'hourly', 'wc_auction_process_refunds');

// Check if already scheduled
wp_next_scheduled('wc_auction_process_refunds')

// Hook handler
add_action('wc_auction_process_refunds', callback_function);

// Unschedule (for plugin deactivation)
wp_clear_scheduled_hook('wc_auction_process_refunds');
```

### Current Implementation Status

**Status:** Cron registration code is **DOCUMENTED but NOT IMPLEMENTED in codebase**

Search Results:
- Cron hook references only found in RefundSchedulerService docstring
- No actual `wp_schedule_event()` calls found in plugin initialization
- No `add_action('wc_auction_process_refunds')` handlers found in active code

### Integration Points Required

1. **Plugin Activation Hook** - Set up cron on plugin activation
2. **Plugin Deactivation Hook** - Clear cron on plugin deactivation
3. **WordPress Admin** - Check cron status:
   ```bash
   wp cron event list
   wp cron test
   ```

---

## 4. Payment Gateway Refund Method

### PaymentGatewayInterface

Located in [src/Contracts/PaymentGatewayInterface.php](src/Contracts/PaymentGatewayInterface.php)

#### `refundPayment()` Method

**Lines:** [154-187](src/Contracts/PaymentGatewayInterface.php#L154-L187)

**Signature:**
```php
public function refundPayment(
    string $auth_id,
    ?Money $amount = null,
    array $context = []
): array;
```

**Parameters:**

| Parameter | Type | Purpose |
|-----------|------|---------|
| `$auth_id` | string | Authorization ID or Capture ID to refund (from payment gateway) |
| `$amount` | Money\|null | Amount to refund; null = full amount |
| `$context` | array | Additional metadata for the refund request |

**Context Array Fields:**
```php
[
    'reason' => 'Bid outbid',           // Reason for refund
    'refund_id' => 'REFUND-uuid',       // Our internal refund ID
    'scheduled_refund' => true,         // Indicates this is from scheduler
    // other provider-specific data
]
```

**Return Value:**
```php
[
    'refund_id' => string,              // Gateway-generated refund identifier
    'refunded_amount' => Money,         // Amount actually refunded
    'status' => 'REFUNDED',             // Result status
    'refund_timestamp' => DateTime,     // When refund was processed
    'raw_response' => array             // Provider-specific data (Square, Stripe)
]
```

**Exceptions Thrown:**
- `PaymentException` - General refund failure
- `AuthorizationExpiredException` - Hold expired before refund
- `ValidationException` - Invalid parameters

**Requirement:** REQ-ENTRY-FEE-PAYMENT-001: Release holds and refunds

### Implementation: SquarePaymentGateway

Located in [src/PaymentGateway/SquarePaymentGateway.php](src/PaymentGateway/SquarePaymentGateway.php)

The actual implementation handles:
- Calling Square API `/payments/{id}/refund` endpoint
- Idempotency key handling (prevents duplicate refunds on retry)
- Error response parsing
- TLS encryption for API calls
- Comprehensive logging

---

## 5. Notification System

### Architecture

**Type:** Callback-based (loose coupling)

**Design Pattern:** Observer Pattern / Dependency Injection

### Notification Flow

**Step 1: Initialization with Callback**

```php
// Create notification callback
$notification_callback = function($user_id, $amount, $refund_id, $reason) {
    // Send email notification
    wp_mail(
        get_userdata($user_id)->user_email,
        'Your Auction Refund Has Been Processed',
        "Amount: " . $amount->getFormatted() . "\n" .
        "Reason: " . $reason
    );
};

// Pass to service
$scheduler = new RefundSchedulerService(
    $gateway,
    $repository,
    $notification_callback  // Optional
);
```

**Step 2: Callback Invocation During Refund Processing**

[RefundSchedulerService.php lines 294-302](src/Services/EntryFees/RefundSchedulerService.php#L294-L302)

```php
// Notify bidder if callback provided
if ($this->notification_callback) {
    $this->notifyBidder(
        $refund['user_id'],
        $amount,
        $refund_id,
        $refund['reason']
    );
}
```

**Step 3: Notification Callback Signature**

```php
callable function(
    int $user_id,           // WordPress user ID
    Money $amount,          // Refunded amount (with currency)
    string $refund_id,      // Unique refund identifier
    string $reason          // Reason for refund
): void
```

### Notification Features

**Characteristics:**
- **Optional** - Service works fine without callback
- **Non-blocking** - Notification failure doesn't stop refund processing
- **Exception-safe** - Exceptions caught and logged, not thrown
- **Async-ready** - Can queue notifications instead of sending immediately

### Example Implementation

```php
class RefundNotificationService {
    private $email_service;
    
    public function notify(int $user_id, Money $amount, $refund_id, $reason) {
        $user = get_userdata($user_id);
        
        // Send email
        wp_mail(
            $user->user_email,
            'Refund Processed - ' . $amount->getFormatted(),
            $this->getEmailTemplate($user, $amount, $refund_id, $reason)
        );
        
        // Log notification
        do_action('wc_auction_refund_email_sent', $user_id, $refund_id);
    }
}

// Usage
$notification_service = new RefundNotificationService();
$scheduler = new RefundSchedulerService(
    $gateway,
    $repository,
    [$notification_service, 'notify']
);
```

### No Built-in Email Templates

**Finding:** Plugin has NO email template system for refund notifications

**Implication:**
- Notifications are handled externally
- Each implementation must provide template
- WooCommerce email system NOT integrated
- No HTML email template files in `/templates/`

---

## 6. Database Queries

### Query 1: Get Pending Refunds (Cron Entry Point)

**Location:** [PaymentAuthorizationRepository.php lines 445-462](src/Repository/PaymentAuthorizationRepository.php#L445-L462)

**Method:** `getPendingRefunds(int $limit = 50): array`

**SQL Query:**
```sql
SELECT * FROM wp_wc_auction_refund_schedule 
WHERE status = 'PENDING' 
  AND scheduled_for <= CURRENT_TIMESTAMP
ORDER BY scheduled_for ASC 
LIMIT 50
```

**Implementation:**
```php
public function getPendingRefunds(int $limit = 50): array
{
    $now = current_time('mysql');
    
    $results = $this->wpdb->get_results(
        $this->wpdb->prepare(
            "SELECT * FROM {$this->table_refunds} 
             WHERE status = 'PENDING' AND scheduled_for <= %s 
             ORDER BY scheduled_for ASC 
             LIMIT %d",
            $now,
            $limit
        ),
        ARRAY_A
    );
    
    return $results ?: [];
}
```

**Performance:**
- **Index Used:** `idx_scheduled` on `scheduled_for` column
- **Filter:** Status = 'PENDING' (secondary filter after index)
- **Complexity:** O(log n + k) where k ≤ 50 (batch size limit)
- **Batch Size:** Max 50 refunds per cron run (prevents API rate limiting)

**Return Value:**
Array of refund records with structure:
```php
[
    [
        'id' => 1,
        'refund_id' => 'REFUND-123',
        'authorization_id' => 'auth_abc',
        'user_id' => 1,
        'scheduled_for' => '2026-01-01 12:00:00',
        'reason' => 'Outbid',
        'status' => 'PENDING',
        'created_at' => '2025-12-31 12:00:00',
        'processed_at' => null
    ],
    // ... more refunds
]
```

### Query 2: Get Authorization Details

**Location:** [PaymentAuthorizationRepository.php](src/Repository/PaymentAuthorizationRepository.php)

**Method:** `getAuthorizationById(string $authorization_id): ?array`

**SQL Query:**
```sql
SELECT * FROM wp_wc_auction_payment_authorizations 
WHERE authorization_id = %s
```

**Purpose:** Retrieve amount and other details needed for refund from payment gateway

**Called by:** `RefundSchedulerService::processRefund()` at line 243

**Usage Context:**
```php
// In processRefund()
$auth = $this->repository->getAuthorizationById($auth_id);

if (!$auth) {
    throw new \Exception("Authorization not found: {$auth_id}");
}

// Create Money object from stored amount
$amount = new Money($auth['amount_cents']);
```

### Query 3: Update Refund Status

**Location:** [PaymentAuthorizationRepository.php](src/Repository/PaymentAuthorizationRepository.php)

**Method:** `updateRefundStatus(string $refund_id, string $new_status, array $additional = []): bool`

**SQL Query:**
```sql
UPDATE wp_wc_auction_refund_schedule 
SET status = %s, processed_at = %s [, additional_fields]
WHERE refund_id = %s
```

**Implementation:**
```php
public function updateRefundStatus(
    string $refund_id,
    string $new_status,
    array $additional = []
): bool {
    $update_data = ['status' => $new_status];
    $update_data = array_merge($update_data, $additional);
    
    if ('PROCESSED' === $new_status && !isset($additional['processed_at'])) {
        $update_data['processed_at'] = current_time('mysql');
    }
    
    $result = $this->wpdb->update(
        $this->table_refunds,
        $update_data,
        ['refund_id' => $refund_id]
    );
    
    return false !== $result;
}
```

**Called by:** `RefundSchedulerService::processRefund()` at lines 267 and 312

**Status Updates During Processing:**
1. **Success:** `PENDING` → `PROCESSED` (with processed_at timestamp)
2. **Failure:** `PENDING` → `FAILED` (with error_message)

### Query 4: Update Authorization Status

**Location:** [PaymentAuthorizationRepository.php](src/Repository/PaymentAuthorizationRepository.php)

**Method:** `updateAuthorizationStatus(string $authorization_id, string $new_status, array $additional_data = []): bool`

**Called by:** `RefundSchedulerService::processRefund()` at line 280

**Status Updates:**
```php
$this->repository->updateAuthorizationStatus(
    $auth_id,
    'REFUNDED',
    [
        'refunded_at' => $refund_result['refund_timestamp']->format('Y-m-d H:i:s'),
    ]
);
```

### Query 5: Get Failed Refunds (For Monitoring)

**Location:** [PaymentAuthorizationRepository.php lines 591-610](src/Repository/PaymentAuthorizationRepository.php#L591-L610)

**Method:** `getFailedRefunds(int $limit = 100): array`

**Purpose:** Retrieve failed refunds for investigation and monitoring

**SQL Query:**
```sql
SELECT * FROM wp_wc_auction_refund_schedule 
WHERE status = 'FAILED'
ORDER BY created_at DESC 
LIMIT 100
```

**Used by:** `getProcessingStats()` method for dashboard display

### Query 6: Get Refund by ID (Manual Retry)

**Location:** [PaymentAuthorizationRepository.php](src/Repository/PaymentAuthorizationRepository.php)

**Method:** `getRefundById(string $refund_id): ?array`

**Purpose:** Retrieve specific refund record for manual retry

**Called by:** `retryFailedRefund()` method at line 410

### Query 7: Prune Old Records (Data Retention)

**Location:** [PaymentAuthorizationRepository.php](src/Repository/PaymentAuthorizationRepository.php)

**Method:** `pruneOldRecords(int $days_old = 90): int`

**SQL Query:**
```sql
DELETE FROM wp_wc_auction_payment_authorizations
WHERE status IN ('CAPTURED', 'REFUNDED')
  AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
```

**Purpose:** Delete old completed records (GDPR, data retention policy)

**Safety Features:**
- Only deletes fully resolved records (CAPTURED, REFUNDED)
- Keeps PENDING and FAILED records for audit trail
- Configurable retention period (default 90 days)

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                    REFUND SCHEDULER SYSTEM                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  WordPress Cron (Hourly)                                            │
│  ├─ Hook: wc_auction_process_refunds                                │
│  └─ Frequency: Hourly (via wp_schedule_event)                       │
│       │                                                             │
│       ▼                                                             │
│  RefundSchedulerService::processScheduledRefunds()                 │
│  ├─ Get pending refunds (batch_size = 50)                          │
│  ├─ Loop through each refund:                                      │
│  │  ├─ Get authorization details                                   │
│  │  ├─ Call PaymentGateway::refundPayment()                        │
│  │  ├─ Update refund status (PROCESSED/FAILED)                     │
│  │  ├─ Update auth status (REFUNDED)                               │
│  │  └─ Notify bidder (if callback provided)                        │
│  ├─ Log operations                                                 │
│  └─ Return statistics                                              │
│       │                                                             │
│       ├─ Success path:                                             │
│       │   ├─ PaymentGateway (Square/Stripe/etc)                    │
│       │   └─ Bidder's card ← Refunded                              │
│       │                                                             │
│       ├─ Failure path:                                             │
│       │   ├─ Mark as FAILED                                        │
│       │   ├─ Log error                                             │
│       │   └─ Retry on next cron run                                │
│       │                                                             │
│       └─ Notify bidder:                                            │
│           ├─ Call notification_callback                            │
│           ├─ Send email notification                               │
│           └─ Log notification attempt                              │
│                                                                     │
├─────────────────────────────────────────────────────────────────────┤
│                      DATABASE LAYER                                 │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  wp_wc_auction_refund_schedule                                      │
│  ├─ id, authorization_id, refund_id, user_id                       │
│  ├─ scheduled_for (INDEXED) ← Query filter                         │
│  ├─ status (PENDING/PROCESSED/FAILED)                              │
│  ├─ reason, created_at, processed_at                               │
│  └─ Query: SELECT * WHERE status = 'PENDING' AND scheduled_for ≤ NOW │
│                                                                     │
│  wp_wc_auction_payment_authorizations                              │
│  ├─ 1:1 Relationship with refund_schedule                          │
│  ├─ Stores: amount_cents, auth_id, payment_gateway                │
│  └─ Updated: refunded_at timestamp                                 │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Integration Checklist

### For Implementing Refund Processing

- [ ] **Cron Registration** (Required)
  - Register hook in plugin activation: `wp_schedule_event(time(), 'hourly', 'wc_auction_process_refunds')`
  - Unregister on deactivation: `wp_clear_scheduled_hook('wc_auction_process_refunds')`

- [ ] **Payment Gateway** (Required)
  - Implement PaymentGatewayInterface
  - Provide `refundPayment()` method that calls payment processor API

- [ ] **Repository Setup** (Required)
  - Ensure PaymentAuthorizationRepository has database tables created
  - Run migration: `PaymentAuthorizationMigration::up()`

- [ ] **Notification System** (Optional)
  - Create notification callback function
  - Pass to RefundSchedulerService constructor
  - Implement email template

- [ ] **Monitoring** (Recommended)
  - Add admin dashboard widget showing processing stats
  - Use `getProcessingStats()` method
  - Set up alerts for failed refunds

- [ ] **Error Handling** (Recommended)
  - Log failed refunds for manual investigation
  - Create admin UI to retry failed refunds manually
  - Use `retryFailedRefund()` method

### Testing the Implementation

```php
// Manual test
$scheduler = new RefundSchedulerService(
    $gateway,
    $repository,
    $notification_callback
);

$stats = $scheduler->processScheduledRefunds();
echo "Processed: " . $stats['successful'] . " of " . $stats['total_processed'];
```

### Monitoring Cron Execution

```bash
# Check if cron is scheduled
wp cron event list

# Manually trigger (for testing)
wp cron test

# View next scheduled time
wp cron event list --format=table
```

---

## Key Implementation Notes

### Error Resilience
- **Batch Processing:** Continues on individual refund failures
- **Exception Handling:** Catches and logs, doesn't throw
- **Status Tracking:** Failed refunds remain PENDING for retry

### Performance Optimization
- **Batch Size Limit:** 50 refunds max per run (prevents API rate limiting)
- **Hourly Frequency:** Safe to run hourly without strain
- **Database Indexes:** `idx_scheduled` optimizes `scheduled_for <= NOW` query

### Security & PCI Compliance
- **No Card Data:** Never stored in database, only gateway tokens
- **Encrypted Storage:** Token storage delegates to payment gateway
- **Prepared Statements:** All queries use wpdb prepared statements
- **Audit Trail:** All refunds logged for compliance

### Data Retention
- **90-Day Default:** Completed records deleted after 90 days
- **Investigation Period:** Failed records kept longer
- **Configurable:** Retention period passed to `pruneRefundRecords()`

---

## Files Involved

| File | Purpose | Lines |
|------|---------|-------|
| [src/Services/EntryFees/RefundSchedulerService.php](src/Services/EntryFees/RefundSchedulerService.php) | Main refund processor | 1-466 |
| [src/Contracts/PaymentGatewayInterface.php](src/Contracts/PaymentGatewayInterface.php) | Payment abstraction contract | 154-187 (refundPayment) |
| [src/Repository/PaymentAuthorizationRepository.php](src/Repository/PaymentAuthorizationRepository.php) | Database persistence layer | 445-610 |
| [src/Database/Migrations/PaymentAuthorizationMigration.php](src/Database/Migrations/PaymentAuthorizationMigration.php) | Table creation | 216-245 |
| [tests/unit/Services/EntryFees/RefundSchedulerServiceTest.php](tests/unit/Services/EntryFees/RefundSchedulerServiceTest.php) | Unit tests | 1-639 |
| [docs/ENTRY_FEE_PAYMENT_API_REFERENCE.md](docs/ENTRY_FEE_PAYMENT_API_REFERENCE.md) | API documentation | - |
| [docs/ENTRY_FEE_PAYMENT_INTEGRATION_GUIDE.md](docs/ENTRY_FEE_PAYMENT_INTEGRATION_GUIDE.md) | Integration guide | - |

