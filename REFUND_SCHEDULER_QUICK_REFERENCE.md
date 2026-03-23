# RefundSchedulerService - Quick Reference Guide

## Critical Findings Summary

### Location
- **File:** `src/Services/EntryFees/RefundSchedulerService.php` (Lines 1-466)
- **Namespace:** `Yith\Auctions\Services\EntryFees`
- **Class:** `RefundSchedulerService`

### Main Methods

| Method | Purpose | Returns | Key Details |
|--------|---------|---------|------------|
| `processScheduledRefunds()` | Entry point for hourly cron | Array with stats | Processes up to 50 refunds/hour |
| `processRefund(array)` | Individual refund execution | bool | Critical path for payment gateway calls |
| `notifyBidder()` | Send refund notification | void | Calls optional callback |
| `getProcessingStats()` | Monitoring/dashboard data | Array | Pending count, failures, oldest pending |
| `retryFailedRefund()` | Manual retry interface | bool | For admin UI or manual intervention |
| `pruneRefundRecords()` | Data retention | int | Deletes records older than 90 days |

---

## Database Table: `wp_wc_auction_refund_schedule`

### Critical Columns
```
scheduled_for (DATETIME) -- INDEXED
  ↑ Query: WHERE scheduled_for <= NOW() AND status = 'PENDING'
  
status (VARCHAR 50) -- INDEXED  
  ↑ Query filter for PENDING records
  
refund_id (VARCHAR 36) -- UNIQUE KEY
  ↑ Primary identifier for refund
```

### Status Values
- `PENDING` - Ready to process when scheduled_for ≤ NOW
- `PROCESSED` - Successfully refunded to bidder
- `FAILED` - Processing failed; needs investigation

### Statuses NOT Explicitly Tracked in Table
- **AUTHORIZED** - In payment_authorizations table (not refund_schedule)
- **CAPTURED** - In payment_authorizations table (not refund_schedule)

---

## Payment Gateway Integration

### PaymentGatewayInterface::refundPayment()

**Signature:**
```php
public function refundPayment(
    string $auth_id,              // Gateway authorization ID
    ?Money $amount = null,        // Amount to refund (null=full)
    array $context = []           // Metadata
): array;
```

**Required Return:**
```php
[
    'refund_id' => string,
    'refunded_amount' => Money,
    'status' => 'REFUNDED',
    'refund_timestamp' => DateTime,
    'raw_response' => array
]
```

**Called By:** `RefundSchedulerService::processRefund()` at line 260

### Context Array Passed to Gateway
```php
[
    'reason' => $refund['reason'],         // e.g., "Outbid in auction"
    'refund_id' => $refund_id,             // Our refund ID
    'scheduled_refund' => true              // Indicates cron-initiated
]
```

---

## Cron Integration

### Hook Details
- **Hook Name:** `wc_auction_process_refunds`
- **Schedule:** `hourly` (WordPress built-in)
- **Frequency:** Every hour (actual execution depends on WordPress cron trigger)

### Required Setup Code (NOT YET IMPLEMENTED)

```php
// Plugin activation hook
add_action('yith_wcact_init', function() {
    if (!wp_next_scheduled('wc_auction_process_refunds')) {
        wp_schedule_event(time(), 'hourly', 'wc_auction_process_refunds');
    }
});

// Cron handler
add_action('wc_auction_process_refunds', function() {
    $gateway = // Get payment gateway instance
    $repository = // Get repository instance
    $scheduler = new RefundSchedulerService($gateway, $repository);
    $scheduler->processScheduledRefunds();
});

// Plugin deactivation
add_action('yith_wcact_deactivate', function() {
    wp_clear_scheduled_hook('wc_auction_process_refunds');
});
```

---

## Notification System

### How It Works
1. Callback (optional) passed to constructor
2. After successful refund (`processRefund()` returns true)
3. Calls `notifyBidder()` which invokes callback

### Callback Signature
```php
function(
    int $user_id,      // WordPress user ID
    Money $amount,     // Refunded amount
    string $refund_id, // Refund identifier
    string $reason     // Reason (e.g., "Outbid")
): void
```

### Example Implementation
```php
$notification_callback = function($user_id, $amount, $refund_id, $reason) {
    $user = get_userdata($user_id);
    wp_mail(
        $user->user_email,
        'Your Refund Has Been Processed',
        "Amount: " . $amount->getFormatted() . "\n" .
        "Refund ID: $refund_id\n" .
        "Reason: $reason"
    );
};

$scheduler = new RefundSchedulerService($gateway, $repository, $notification_callback);
```

### Important Notes
- **Optional:** Service works without callback
- **Non-blocking:** Notification failure doesn't stop refund
- **Exception-safe:** Callback exceptions caught and logged
- **No templates:** No built-in email templates; client must provide

---

## Database Query Patterns

### 1. Get Pending Refunds (Called by processScheduledRefunds)

```sql
SELECT * FROM wp_wc_auction_refund_schedule 
WHERE status = 'PENDING' 
  AND scheduled_for <= CURRENT_TIMESTAMP
ORDER BY scheduled_for ASC 
LIMIT 50;
```

**Implementation:** `PaymentAuthorizationRepository::getPendingRefunds(int $limit = 50)`

**Performance:** O(log n) using `idx_scheduled` index on `scheduled_for`

### 2. Get Authorization Details

```sql
SELECT * FROM wp_wc_auction_payment_authorizations 
WHERE authorization_id = %s;
```

**Purpose:** Get `amount_cents` needed for refund amount

**Called by:** `processRefund()` at line 243

### 3. Update Refund Status

```sql
UPDATE wp_wc_auction_refund_schedule 
SET status = %s, processed_at = CURRENT_TIMESTAMP 
WHERE refund_id = %s;
```

**Called by:** `processRefund()` at lines 267 (success) and 312 (failure)

### 4. Get Failed Refunds (For Monitoring)

```sql
SELECT * FROM wp_wc_auction_refund_schedule 
WHERE status = 'FAILED'
ORDER BY created_at DESC 
LIMIT 100;
```

**Used by:** `getProcessingStats()` for dashboard

---

## Error Handling & Resilience

### Batch Processing Resilience
```
processScheduledRefunds()
  ├─ Gets 50 refunds
  ├─ For each refund:
  │  ├─ processRefund() catches ALL exceptions
  │  ├─ Returns false on error
  │  ├─ Updates status to FAILED
  │  └─ Continues to next refund (doesn't re-throw)
  └─ Returns summary stats
```

### Error Status Flow
```
PENDING (initial)
  ↓
  ├─ processRefund() succeeds?
  │  YES → status = PROCESSED
  │  NO  → status = FAILED
  │
  → Next cron run processes again until success
  → Failed records remain for investigation
  → Manual retry via retryFailedRefund()
```

### Exception Hierarchy
- `PaymentException` - Refund processing failed
- General `\Exception` - Caught, logged, status updated to FAILED

---

## Performance Characteristics

| Aspect | Value | Note |
|--------|-------|------|
| **Batch Size** | 50 refunds/run | Prevents API rate limiting |
| **Run Frequency** | Hourly | Via WordPress cron |
| **Query Complexity** | O(1) + O(n) | n ≤ 50 |
| **Index Strategy** | idx_scheduled on scheduled_for | Efficient filtering |
| **Idempotency** | Yes | Safe to re-run |

---

## Integration Checklist

### Must Implement
- [ ] Cron registration (wp_schedule_event on activation)
- [ ] PaymentGatewayInterface with refundPayment() method
- [ ] Database migration (PaymentAuthorizationMigration::up())

### Should Implement
- [ ] Notification callback (email to bidders)
- [ ] Monitoring UI (show getProcessingStats())
- [ ] Manual retry UI (for failed refunds)

### Optional
- [ ] Email templates (currently external)
- [ ] Webhook/API notifications (currently via callback only)

---

## Testing Examples

### Test 1: Process Single Refund Successfully
```php
$result = $scheduler->processScheduledRefunds();
assert($result['total_processed'] == 1);
assert($result['successful'] == 1);
assert($result['failed'] == 0);
```

### Test 2: Batch Processing with Failures
```php
// 50 refunds queued, 45 succeed, 5 fail
$result = $scheduler->processScheduledRefunds();
assert($result['total_processed'] == 50);
assert($result['successful'] == 45);
assert($result['failed'] == 5);
```

### Test 3: Manual Retry
```php
$success = $scheduler->retryFailedRefund('REFUND-123');
assert($success == true);
```

### Test 4: Statistics
```php
$stats = $scheduler->getProcessingStats();
echo $stats['pending_count'];           // Currently pending
echo $stats['oldest_pending_scheduled_for'];  // Oldest scheduled
echo $stats['next_batch_size'];         // Next batch would process N
```

---

## Debug Checklist

**If refunds not processing:**
1. ✓ Is cron hook registered? `wp cron event list`
2. ✓ Is PaymentGatewayInterface implemented with refundPayment()?
3. ✓ Are pending refunds in database? `SELECT * FROM wp_wc_auction_refund_schedule WHERE status='PENDING'`
4. ✓ Check logs for PaymentException errors
5. ✓ Run manually: `$scheduler->processScheduledRefunds()`

**If only some refunds processing:**
1. ✓ Check if batch limit (50) being hit
2. ✓ Check scheduled_for timestamps (still in future?)
3. ✓ Check refund status values in database

**If bidders not notified:**
1. ✓ Is notification callback provided to constructor?
2. ✓ Check callback function signature (4 parameters)
3. ✓ Check logs for notification exceptions

---

## Key Constants & Configuration

```php
// In RefundSchedulerService
private const BATCH_SIZE = 50;  // Max refunds per cron run

// In PaymentAuthorizationRepository
$this->table_refunds = $this->wpdb->prefix . 'wc_auction_refund_schedule';

// In PaymentAuthorizationMigration  
$days_old = 90;  // Default retention period before pruning
```

---

## Architecture Pattern

**Pattern:** Service → Gateway Contract → Concrete Implementation

```
RefundSchedulerService (service layer)
  ├─ Uses: PaymentGatewayInterface (abstraction)
  │  ├─ Implementation: SquarePaymentGateway
  │  ├─ Implementation: StripePaymentGateway (future)
  │  └─ Implementation: PayPalPaymentGateway (future)
  ├─ Uses: PaymentAuthorizationRepository (data access)
  └─ Uses: LoggerTrait (cross-cutting)
```

**Benefits:**
- Payment provider independence
- Testable (mock gateway in tests)
- Extensible (add new payment providers)
- Loosely coupled

---

## File References

| File | Purpose |
|------|---------|
| `src/Services/EntryFees/RefundSchedulerService.php` | Main implementation |
| `src/Contracts/PaymentGatewayInterface.php` | Payment gateway contract |
| `src/Repository/PaymentAuthorizationRepository.php` | Database access |
| `src/Database/Migrations/PaymentAuthorizationMigration.php` | Schema definition |
| `tests/unit/Services/EntryFees/RefundSchedulerServiceTest.php` | Unit tests |
| `docs/ENTRY_FEE_PAYMENT_API_REFERENCE.md` | API documentation |
| `docs/ENTRY_FEE_PAYMENT_INTEGRATION_GUIDE.md` | Integration guide |

