# RefundSchedulerService - Code Flow Analysis

## Call Chain: From Cron to Refunded Bidder

### Step 1: WordPress Cron Trigger

**WordPress Action Hook:**
```php
do_action('wc_auction_process_refunds');  // Fired hourly by WordPress
```

**Setup Code (needs to be implemented):**
```php
// Register hook on plugin activation
if (!wp_next_scheduled('wc_auction_process_refunds')) {
    wp_schedule_event(time(), 'hourly', 'wc_auction_process_refunds');
}

// Hook handler
add_action('wc_auction_process_refunds', function() {
    // Initialize dependencies
    $gateway = new SquarePaymentGateway(API_KEY, LOCATION_ID);
    $repository = new PaymentAuthorizationRepository($wpdb);
    
    // Create scheduler with optional notification
    $notification_callback = function($user_id, $amount, $refund_id, $reason) {
        // Send email notification
        wp_mail(get_userdata($user_id)->user_email, ...);
    };
    
    $scheduler = new RefundSchedulerService(
        $gateway,
        $repository,
        $notification_callback
    );
    
    // Start processing
    $stats = $scheduler->processScheduledRefunds();
    
    // Log results
    error_log('Refund processing: ' . json_encode($stats));
});
```

---

### Step 2: Main Entry Point - processScheduledRefunds()

**File:** [src/Services/EntryFees/RefundSchedulerService.php](src/Services/EntryFees/RefundSchedulerService.php#L148-L206)

```php
public function processScheduledRefunds(): array
{
    // Log start
    $this->logInfo('Starting refund processing batch', [
        'batch_size' => self::BATCH_SIZE,  // 50
    ]);

    $stats = [
        'total_processed' => 0,
        'successful' => 0,
        'failed' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    try {
        // STEP 2.1: Get pending refunds (scheduled_for <= NOW, status PENDING)
        // SQL: SELECT * FROM wp_wc_auction_refund_schedule 
        //      WHERE status = 'PENDING' AND scheduled_for <= NOW()
        //      ORDER BY scheduled_for ASC LIMIT 50
        $pending_refunds = $this->repository->getPendingRefunds(self::BATCH_SIZE);

        if (empty($pending_refunds)) {
            $this->logInfo('No pending refunds to process');
            return $stats;  // Early return if nothing to do
        }

        $this->logInfo('Found pending refunds', [
            'count' => count($pending_refunds),
        ]);

        // STEP 2.2: Process each refund
        foreach ($pending_refunds as $refund) {
            $stats['total_processed']++;

            try {
                // STEP 2.2.1: Call processRefund() for individual processing
                $result = $this->processRefund($refund);

                if ($result) {
                    $stats['successful']++;
                } else {
                    $stats['failed']++;
                }
            } catch (\Exception $e) {
                $stats['failed']++;
                $stats['errors'][] = [
                    'refund_id' => $refund['refund_id'],
                    'error' => $e->getMessage(),
                ];

                $this->logError('Failed to process refund', [
                    'refund_id' => $refund['refund_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Log summary
        $this->logInfo('Refund batch processing complete', $stats);

        return $stats;

    } catch (\Exception $e) {
        $this->logError('Critical error in refund batch processing', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        throw new PaymentException('Refund batch processing failed: ' . $e->getMessage());
    }
}
```

**Database Query Executed:**
```sql
SELECT * FROM wp_wc_auction_refund_schedule 
WHERE status = 'PENDING' AND scheduled_for <= '2026-01-15 14:00:00'
ORDER BY scheduled_for ASC 
LIMIT 50;
```

**Result:** Array of up to 50 refund records, oldest first

---

### Step 3: Individual Refund Processing

**File:** [src/Services/EntryFees/RefundSchedulerService.php](src/Services/EntryFees/RefundSchedulerService.php#L217-L323)

#### 3.1 processRefund() - Complete Flow

```php
private function processRefund(array $refund): bool
{
    $refund_id = $refund['refund_id'];              // e.g., 'REFUND-abc123'
    $auth_id = $refund['authorization_id'];         // e.g., 'auth_xyz789'

    try {
        // STEP 3.1.1: Get authorization details from database
        // QUERY: SELECT * FROM wp_wc_auction_payment_authorizations 
        //        WHERE authorization_id = 'auth_xyz789'
        $auth = $this->repository->getAuthorizationById($auth_id);

        if (!$auth) {
            throw new \Exception("Authorization not found: {$auth_id}");
        }

        // Retrieve the amount to refund (in cents)
        // e.g., 2500 cents = $25.00
        $amount = new Money($auth['amount_cents']);

        // Log debug info
        $this->logDebug('Processing refund', [
            'refund_id' => $refund_id,
            'auth_id' => $auth_id,
            'amount' => $amount->getFormatted(),      // e.g., "$25.00"
            'user_id' => $refund['user_id'],
        ]);

        // STEP 3.1.2: Call payment gateway to execute refund
        // This calls the actual payment processor (Square, Stripe, etc)
        // 
        // Gateway receives:
        // - $auth_id: The authorization ID from initially authorizing the entry fee
        // - $amount: The amount to refund (Money object)
        // - Context array with metadata
        //
        // CRITICAL: This is where the actual refund happens on bidder's card
        $refund_result = $this->payment_gateway->refundPayment(
            $auth_id,
            $amount,
            [
                'reason' => $refund['reason'],          // e.g., "Outbid"
                'refund_id' => $refund_id,              // Our internal tracking ID
                'scheduled_refund' => true,             // Flag: scheduled (not manual)
            ]
        );

        // STEP 3.1.3: Update refund status to PROCESSED
        // QUERY: UPDATE wp_wc_auction_refund_schedule 
        //        SET status = 'PROCESSED', processed_at = NOW()
        //        WHERE refund_id = 'REFUND-abc123'
        $this->repository->updateRefundStatus(
            $refund_id,
            'PROCESSED',
            [
                'processed_at' => current_time('mysql'),
            ]
        );

        // STEP 3.1.4: Update authorization status to REFUNDED
        // QUERY: UPDATE wp_wc_auction_payment_authorizations 
        //        SET status = 'REFUNDED', refunded_at = '2026-01-15 14:15:00'
        //        WHERE authorization_id = 'auth_xyz789'
        $this->repository->updateAuthorizationStatus(
            $auth_id,
            'REFUNDED',
            [
                'refunded_at' => $refund_result['refund_timestamp']->format('Y-m-d H:i:s'),
            ]
        );

        // Log success
        $this->logInfo('Refund processed successfully', [
            'refund_id' => $refund_id,
            'auth_id' => $auth_id,
            'amount' => $amount->getFormatted(),
        ]);

        // STEP 3.1.5: Notify bidder if callback provided
        if ($this->notification_callback) {
            // Call notifyBidder() to send notification
            $this->notifyBidder(
                $refund['user_id'],
                $amount,
                $refund_id,
                $refund['reason']
            );
        }

        return true;  // Success

    } catch (\Exception $e) {
        // STEP 3.1.6: Error handling - update status to FAILED

        $this->logError('Refund processing failed', [
            'refund_id' => $refund_id,
            'auth_id' => $auth_id,
            'error' => $e->getMessage(),
        ]);

        // Mark refund as FAILED but don't throw
        // This allows batch processing to continue
        try {
            // QUERY: UPDATE wp_wc_auction_refund_schedule 
            //        SET status = 'FAILED', error_message = 'Card declined'
            //        WHERE refund_id = 'REFUND-abc123'
            $this->repository->updateRefundStatus(
                $refund_id,
                'FAILED',
                ['error_message' => $e->getMessage()]
            );
        } catch (\Exception $update_error) {
            $this->logError('Failed to update refund status after error', [
                'refund_id' => $refund_id,
                'error' => $update_error->getMessage(),
            ]);
        }

        return false;  // Failure (but don't throw)
    }
}
```

**Key Decision Points:**
- Line 243: Authorization not found? → Throw exception → Caught at 308 → Return false
- Line 260: Payment gateway returns refund_result? → Continue
- Line 283: Gateway throws PaymentException? → Caught at 304 → Return false

---

### Step 4: Payment Gateway Integration

**Interface:** [src/Contracts/PaymentGatewayInterface.php](src/Contracts/PaymentGatewayInterface.php#L154-L187)

```php
// Called from: RefundSchedulerService::processRefund() at line 260
$refund_result = $this->payment_gateway->refundPayment(
    $auth_id,           // Authorization ID (e.g., 'auth_xyz789')
    $amount,            // Money object (e.g., $25.00)
    [
        'reason' => 'Outbid in auction #123',
        'refund_id' => 'REFUND-abc123',
        'scheduled_refund' => true,
    ]
);
```

**Expected Return:**
```php
[
    'refund_id' => 'ref_sq_789def',         // Gateway refund ID
    'refunded_amount' => Money(2500),        // Actual refunded amount
    'status' => 'REFUNDED',                  // Status from gateway
    'refund_timestamp' => DateTime,          // When refund was processed
    'raw_response' => [...]                  // Provider-specific data
]
```

**Implementation Example: SquarePaymentGateway**
```php
public function refundPayment(string $auth_id, ?Money $amount = null, array $context = []): array
{
    // This would:
    // 1. Connect to Square API
    // 2. POST /v2/refunds endpoint
    // 3. Pass: payment_id (from $auth_id), amount_money
    // 4. Return: refund details from Square response
    
    // For entry fee refunds, amount MUST match the originally held amount
}
```

---

### Step 5: Notification

**Called from:** [src/Services/EntryFees/RefundSchedulerService.php](src/Services/EntryFees/RefundSchedulerService.php#L331-D360)

```php
private function notifyBidder(
    int $user_id,               // 1 (example user ID)
    Money $amount,              // Money(2500) = $25.00
    string $refund_id,          // 'REFUND-abc123'
    string $reason              // 'Outbid in auction #123'
): void
{
    try {
        // Check if callback is provided and callable
        if (!is_callable($this->notification_callback)) {
            return;  // No callback, no notification
        }

        // Call the notification callback
        // Signature: function($user_id, $amount, $refund_id, $reason)
        call_user_func(
            $this->notification_callback,
            $user_id,           // 1
            $amount,            // Money(2500)
            $refund_id,         // 'REFUND-abc123'
            $reason             // 'Outbid in auction #123'
        );

        // Log that we called the callback
        $this->logDebug('Bidder notified of refund', [
            'user_id' => $user_id,
            'refund_id' => $refund_id,
        ]);

    } catch (\Exception $e) {
        // Notification failed, but don't throw
        // Refund already processed, notification is bonus
        $this->logWarning('Failed to notify bidder', [
            'user_id' => $user_id,
            'error' => $e->getMessage(),
        ]);
    }
}
```

**Example Callback Implementation:**
```php
$notification_callback = function($user_id, $amount, $refund_id, $reason) {
    $user = get_userdata($user_id);
    
    if (!$user) return;  // User not found
    
    // Send email
    wp_mail(
        $user->user_email,
        'Your Auction Entry Fee Has Been Refunded',
        "Dear {$user->display_name},\n\n" .
        "Your entry fee refund of {$amount->getFormatted()} has been processed.\n" .
        "Refund ID: {$refund_id}\n" .
        "Reason: {$reason}\n\n" .
        "The funds should appear in your account within 3-5 business days.\n\n" .
        "- Auction Team"
    );
    
    // Optionally log email sent
    error_log("Refund notification sent to user {$user_id}");
};
```

---

## Complete Data Flow Example

### Scenario: Outbid Bidder Gets Refund

#### Time T0: Bid Placed (24 hours ago)
```
User places bid on auction
  ↓
Entry fee authorized: $25.00
  ↓
INSERT wp_wc_auction_payment_authorizations
  authorization_id = 'auth_abc123'
  amount_cents = 2500
  status = 'AUTHORIZED'
  
INSERT wp_wc_auction_refund_schedule
  refund_id = 'REFUND-xyz789'
  authorization_id = 'auth_abc123'
  status = 'PENDING'
  scheduled_for = NOW + 24 hours = 'T0 + 24h'
```

#### Time T0 + 24 hours: Auction Ends
```
Auction finishes
Winner: Another bidder
This bidder: OUTBID
  ↓
Refund is now ready (scheduled_for = NOW)
```

#### Time T0 + 24h ± 1 hour: Cron Runs
```
WordPress cron triggers: do_action('wc_auction_process_refunds')
  ↓
Call: $scheduler->processScheduledRefunds()
  ↓
Query: SELECT * FROM wp_wc_auction_refund_schedule 
       WHERE status = 'PENDING' AND scheduled_for <= NOW
       LIMIT 50
  ↓
Returns: [{ refund_id: 'REFUND-xyz789', ... }]
  ↓
Loop iteration:
  Call: processRefund($refund)
    ↓
    Query: SELECT * FROM wp_wc_auction_payment_authorizations
           WHERE authorization_id = 'auth_abc123'
    ↓
    Returns: { amount_cents: 2500, ... }
    ↓
    Create: $amount = new Money(2500)
    ↓
    Call: $gateway->refundPayment('auth_abc123', Money(2500), [...])
    ↓
    Gateway: → Call Square/Stripe API
             → POST /v2/refunds
             → Card refunded $25.00
             → Return refund response
    ↓
    Update: UPDATE wp_wc_auction_refund_schedule
            SET status = 'PROCESSED', processed_at = NOW
            WHERE refund_id = 'REFUND-xyz789'
    ↓
    Update: UPDATE wp_wc_auction_payment_authorizations
            SET status = 'REFUNDED', refunded_at = NOW
            WHERE authorization_id = 'auth_abc123'
    ↓
    Call: $notification_callback(user_id, Money(2500), 'REFUND-xyz789', 'Outbid')
    ↓
    Callback: → Get user email
              → Send email: "Your refund of $25.00 has been processed"
              → Log email sent
    ↓
    Return: true
  ↓
stats['successful']++
```

#### Result: Bidder's Card
```
Before refund: $1,234.56 account balance
  ↓ (Refund processed)
After refund: $1,259.56 account balance (+$25.00)
  ↓
Email received: "Your refund has been processed"
```

---

## Error Scenarios

### Scenario 1: Authorization Expired (7 days)

```
Authorization created 7+ days ago
  ↓
Cron runs, tries to refund
  ↓
Gateway API call fails: "Authorization expired"
  ↓
Exception caught: AuthorizationExpiredException
  ↓
UPDATE wp_wc_auction_refund_schedule 
SET status = 'FAILED', 
    error_message = 'Authorization expired'
WHERE refund_id = 'REFUND-xyz789'
  ↓
RESULT: status = 'FAILED'
ACTION: Needs manual investigation
```

### Scenario 2: Card Declined

```
Gateway attempts refund to card
  ↓
Card returns: "Card declined"
  ↓
Exception: PaymentException("Card declined")
  ↓
UPDATE wp_wc_auction_refund_schedule 
SET status = 'FAILED', 
    error_message = 'Card declined'
WHERE refund_id = 'REFUND-xyz789'
  ↓
RESULT: Retry next hour (same refund PENDING)
        Must fix card issue to succeed
```

### Scenario 3: Notification Callback Fails

```
processRefund() succeeds
  ↓
Update statuses to REFUNDED/PROCESSED
  ↓
Call: $notification_callback(...)
  ↓
Callback: wp_mail() fails (SMTP error)
  ↓
Exception caught: \Exception
  ↓
Log warning: "Failed to notify bidder"
  ↓
RESULT: Refund STILL PROCESSED (status = 'PROCESSED')
        Notification not sent (but not critical)
```

---

## Monitoring & Debugging

### Check Status

```php
$stats = $scheduler->getProcessingStats();

// Output:
[
    'pending_count' => 3,                          // 3 refunds waiting
    'failed_count' => 1,                           // 1 failed previous run
    'oldest_pending_scheduled_for' => '2026-01-15 12:00:00',
    'next_batch_size' => 3,                        // Next run would process 3
    'failed_refunds' => [
        [
            'refund_id' => 'REFUND-failed123',
            'reason' => 'Outbid',
            'error_message' => 'Card declined',
            // ... more fields
        ]
    ]
]
```

### Manual Retry

```php
// Retry a specific failed refund
try {
    $success = $scheduler->retryFailedRefund('REFUND-failed123');
    if ($success) {
        echo "Refund retried successfully";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

### View Database Status

```sql
-- Pending refunds
SELECT refund_id, user_id, scheduled_for 
FROM wp_wc_auction_refund_schedule 
WHERE status = 'PENDING' 
ORDER BY scheduled_for DESC;

-- Failed refunds
SELECT refund_id, reason, error_message, created_at 
FROM wp_wc_auction_refund_schedule 
WHERE status = 'FAILED' 
ORDER BY created_at DESC 
LIMIT 10;

-- Refunds processed today
SELECT COUNT(*), SUM(amount_cents)/100 as total_amount 
FROM wp_wc_auction_refund_schedule 
WHERE status = 'PROCESSED' 
  AND DATE(processed_at) = CURDATE();
```

---

## Performance Analysis

### Single Refund Processing Time

```
Per Refund Breakdown:
├─ Get authorization from DB: ~5ms
├─ Call payment gateway (network): ~200-500ms (depends on gateway)
├─ Update refund status: ~5ms
├─ Update auth status: ~5ms
├─ Send notification (email): ~100-500ms (if callback synchronous)
└─ Log operations: <1ms
────────────────────────────
TOTAL PER REFUND: ~320-1000ms depending on gateway response time
```

### Batch Performance

```
Batch of 50 refunds:
├─ Get pending refunds query: ~10ms
├─ Loop 50 refunds: 50 × (320-1000ms) = 16-50 seconds
└─ Total batch processing: ~16-50 seconds
```

**With 24 batches per day (hourly):**
- Processing power: Distributed over whole day
- Peak: ~50 seconds per hour (5% load)
- Safe for hourly runs

---

## Key Takeaways

1. **Stateless Design:** Every refund processed independently; failures don't break subsequent refunds
2. **Database-Driven:** Query pattern depends on `scheduled_for` index for performance
3. **Callback-Based Notifications:** Loose coupling allows different notification implementations
4. **Exception-Safe:** Catches all exceptions, logs them, continues processing
5. **Idempotent:** Safe to run multiple times; updates are idempotent
6. **Batch Processing:** 50 per run prevents API rate limits and ensures throughput

