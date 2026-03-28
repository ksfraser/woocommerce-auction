# PayoutService Dependencies & Integration Guide

**Document Purpose:** Complete reference for all components, APIs, and dependencies required for PayoutService integration.

---

## 1. PAYOUT MODEL & DTO

### File Path
**[includes/models/SellerPayout.php](includes/models/SellerPayout.php)**

### Status Constants
```php
class SellerPayout {
    const STATUS_PENDING    = 'PENDING';     // Not yet initiated
    const STATUS_PROCESSING = 'PROCESSING';  // Processor is handling
    const STATUS_COMPLETED  = 'COMPLETED';   // Successfully paid
    const STATUS_FAILED     = 'FAILED';      // Payment failed
    const STATUS_CANCELLED  = 'CANCELLED';   // Cancelled/revoked
}
```

### Status Lifecycle
```
PENDING → PROCESSING → COMPLETED
   ↓
FAILED → PENDING (retry via SchedulerService)
   ↓
CANCELLED
```

### Private Properties (Immutable Value Object)
- `$id` (int|null) - Database ID
- `$batch_id` (int) - FK to SettlementBatch
- `$seller_id` (int) - Seller/vendor user ID
- `$amount_cents` (int) - Gross payout amount in cents
- `$method_type` (string) - Payout method (from SellerPayoutMethod constants)
- `$status` (string) - Current status (use constants above)
- `$transaction_id` (string|null) - Processor transaction ID
- `$processor_name` (string|null) - Processor name (Square, PayPal, Stripe)
- `$processor_fees_cents` (int) - Fees deducted by processor
- `$net_payout_cents` (int) - Calculated: amount - fees
- `$error_message` (string|null) - Failure reason
- `$created_at` (DateTime) - Record creation timestamp
- `$updated_at` (DateTime) - Last update timestamp
- `$completed_at` (DateTime|null) - Completion timestamp

### Key Factory Methods
```php
// Create new payout
public static function create(
    ?int $id,
    int $batch_id,
    int $seller_id,
    int $amount_cents,
    string $method_type,
    string $status
): self

// Restore from database
public static function fromDatabase(array $row): self
```

### Key Getter Methods
```php
public function getId(): ?int
public function getBatchId(): int
public function getSellerId(): int
public function getAmountCents(): int
public function getMethodType(): string
public function getStatus(): string
public function getTransactionId(): ?string
public function getProcessorName(): ?string
public function getProcessorFeesCents(): int
public function getNetPayoutCents(): int
public function getErrorMessage(): ?string
public function getCreatedAt(): DateTime
public function getUpdatedAt(): DateTime
public function getCompletedAt(): ?DateTime

// Status helper methods
public function isPending(): bool
public function isProcessing(): bool
public function isCompleted(): bool
public function isFailed(): bool
public function isCancelled(): bool

// Convert to array (for updates/persistence)
public function toArray(): array
```

---

## 2. PAYOUT REPOSITORY

### File Path
**[includes/repositories/PayoutRepository.php](includes/repositories/PayoutRepository.php)**

### Table Information
- **Name:** `wp_wc_auction_seller_payouts`
- **Prefix:** `wc_auction_seller_payouts`

### Key Methods
```php
// Persistence
public function save(SellerPayout $payout): int
public function update(SellerPayout $payout): bool

// Query by various filters
public function find(int $id): ?SellerPayout
public function findByBatch(int $batch_id): SellerPayout[]
public function findByStatus(string $status): SellerPayout[]
public function findBySeller(int $seller_id): SellerPayout[]
public function findPending(): SellerPayout[]
public function findByTransactionId(string $transaction_id): ?SellerPayout|null
public function findByDateRange(DateTime $start, DateTime $end): SellerPayout[]
public function findByBatchAndStatus(int $batch_id, string $status): SellerPayout[]

// Batch operations
public function batchUpdateStatus(int[] $ids, string $status): bool
```

### Dependencies in PayoutService
```php
private PayoutRepository $payout_repo;
// Used to:
// - Save new payouts after creation
// - Query pending payouts for processing
// - Update payout status after processor response
// - Find payouts by transaction ID for reconciliation
```

---

## 3. SETTLEMENT BATCH MODEL & REPOSITORY

### File Paths
- **Model:** [includes/models/SettlementBatch.php](includes/models/SettlementBatch.php)
- **Repository:** [includes/repositories/SettlementBatchRepository.php](includes/repositories/SettlementBatchRepository.php)

### Status Constants
```php
class SettlementBatch {
    const STATUS_DRAFT      = 'DRAFT';       // Initial creation
    const STATUS_VALIDATED  = 'VALIDATED';   // Validated for processing
    const STATUS_PROCESSING = 'PROCESSING';  // Payouts being initiated
    const STATUS_COMPLETED  = 'COMPLETED';   // All payouts complete
    const STATUS_CANCELLED  = 'CANCELLED';   // Batch cancelled
}
```

### Status Lifecycle
```
DRAFT → VALIDATED → PROCESSING → COMPLETED
                         ↓
                    (FAILED with retry)
                         ↓
                    CANCELLED
```

### Key Properties (SettlementBatch)
- `$id` (int|null)
- `$batch_number` (string) - Unique identifier like "2026-03-23-001"
- `$settlement_date` (DateTime) - When batch created
- `$period_start` (DateTime) - Auction period start
- `$period_end` (DateTime) - Auction period end
- `$status` (string)
- `$total_amount_cents` (int) - Gross amount
- `$commission_amount_cents` (int)
- `$processor_fees_cents` (int)
- `$payout_count` (int) - Number of sellers in batch
- `$created_at` (DateTime)
- `$processed_at` (DateTime|null)
- `$notes` (string|null)

### SettlementBatchRepository Key Methods
```php
public function save(SettlementBatch $batch): int
public function find(int $id): ?SettlementBatch
public function findByBatchNumber(string $batch_number): ?SettlementBatch
public function findByStatus(string $status): SettlementBatch[]
public function findLatest(): ?SettlementBatch
public function findByDateRange(DateTime $start, DateTime $end): SettlementBatch[]
public function update(SettlementBatch $batch): bool
public function delete(int $id): bool
```

### Dependencies in PayoutService
```php
private SettlementBatchRepository $batch_repo;
// Used to:
// - Find batch being processed
// - Update batch status as payouts progress
// - Query processing/completed batches for reports
```

---

## 4. PAYMENT PROCESSOR FACTORY & ADAPTER

### File Paths
- **Factory:** [includes/services/PaymentProcessorFactory.php](includes/services/PaymentProcessorFactory.php)
- **Interface:** [includes/contracts/IPaymentProcessorAdapter.php](includes/contracts/IPaymentProcessorAdapter.php)
- **Implementations:**
  - [includes/services/adapters/SquarePayoutAdapter.php](includes/services/adapters/SquarePayoutAdapter.php)
  - [includes/services/adapters/PayPalPayoutAdapter.php](includes/services/adapters/PayPalPayoutAdapter.php)
  - [includes/services/adapters/StripePayoutAdapter.php](includes/services/adapters/StripePayoutAdapter.php)

### PaymentProcessorFactory Methods
```php
public function registerAdapter(IPaymentProcessorAdapter $adapter): self

// Route to correct adapter by payout method
public function getAdapter(string $method_type): ?IPaymentProcessorAdapter

// Get adapter by processor name
public function getAdapterByProcessor(string $processor_name): ?IPaymentProcessorAdapter

// Feature detection
public function supportsMethod(string $method_type): bool
public function supportsProcessor(string $processor_name): bool
```

### IPaymentProcessorAdapter Contract
```php
interface IPaymentProcessorAdapter {
    
    /**
     * Initiate a payout transaction
     * 
     * @param string $transaction_id Unique idempotency key (format: batch_id-seller_id)
     * @param int $amount_cents Amount in cents
     * @param SellerPayoutMethod $recipient Payout method details
     * @return TransactionResult Result with transaction ID and status
     * @throws \Exception On validation/processor errors
     */
    public function initiatePayment(
        string $transaction_id,
        int $amount_cents,
        SellerPayoutMethod $recipient
    ): TransactionResult;

    /**
     * Get transaction status from processor
     * 
     * @param string $transaction_id Processor transaction ID
     * @return TransactionResult Current status
     * @throws \Exception If transaction not found
     */
    public function getTransactionStatus(string $transaction_id): TransactionResult;

    /**
     * Refund a transaction
     * 
     * @param string $transaction_id Processor transaction ID
     * @param int|null $amount_cents Amount to refund (null = full)
     * @return TransactionResult Refund result
     * @throws \Exception If refund fails
     */
    public function refundTransaction(
        string $transaction_id,
        ?int $amount_cents = null
    ): TransactionResult;

    /**
     * Get processor name
     * @return string 'Square', 'PayPal', or 'Stripe'
     */
    public function getProcessorName(): string;

    /**
     * Check if adapter supports method type
     * @param string $method_type
     * @return bool
     */
    public function supportsMethod(string $method_type): bool;
}
```

### Method Type Mapping
```
SellerPayoutMethod::METHOD_ACH      → Square (default)
SellerPayoutMethod::METHOD_PAYPAL   → PayPal
SellerPayoutMethod::METHOD_STRIPE   → Stripe
SellerPayoutMethod::METHOD_WALLET   → PayPal (default)
```

### Dependencies in PayoutService
```php
private PaymentProcessorFactory $processor_factory;
// Used to:
// - Get appropriate adapter based on seller's payout method
// - Initiate payout transaction with processor
// - Query transaction status from processor
// - Refund failed transactions
```

---

## 5. TRANSACTION RESULT MODEL

### File Path
**[includes/models/TransactionResult.php](includes/models/TransactionResult.php)**

### Status Constants (Normalized Across All Processors)
```php
class TransactionResult {
    const STATUS_PENDING = 'PENDING';         // Created, awaiting processing
    const STATUS_PROCESSING = 'PROCESSING';   // Funds being transferred
    const STATUS_COMPLETED = 'COMPLETED';     // Successfully delivered
    const STATUS_FAILED = 'FAILED';           // Transaction failed
    const STATUS_CANCELLED = 'CANCELLED';     // Transaction cancelled
}
```

### Key Properties
- `$transaction_id` (string) - Processor transaction ID
- `$processor_name` (string) - Square, PayPal, or Stripe
- `$status` (string) - Normalized status
- `$amount_cents` (int) - Gross amount
- `$processor_fees_cents` (int) - Fees deducted
- `$net_payout_cents` (int) - Calculated: amount - fees
- `$initiated_at` (DateTime) - When transaction created
- `$completed_at` (DateTime|null) - Completion time
- `$error_message` (string|null) - Error reason
- `$processor_reference` (string) - Processor's internal reference
- `$metadata` (array) - Additional processor-specific data

### Key Methods
```php
// Factory methods
public static function create(...): TransactionResult
public static function fromProcessor(...): TransactionResult

// Status helpers
public function isPending(): bool
public function isProcessing(): bool
public function isCompleted(): bool
public function isFailed(): bool
public function isCancelled(): bool

// Getters for all properties
public function getTransactionId(): string
public function getProcessorName(): string
public function getStatus(): string
public function getAmountCents(): int
public function getProcessorFeesCents(): int
public function getNetPayoutCents(): int
public function getInitiatedAt(): DateTime
public function getCompletedAt(): ?DateTime
public function getErrorMessage(): ?string
public function toArray(): array
```

### Dependencies in PayoutService
```
// Returned by PaymentProcessorAdapter methods
// Used to update SellerPayout with ProcessorResult
// Provides standardized interface across all processors
```

---

## 6. SELLER PAYOUT METHOD MODEL

### File Path
**[includes/models/SellerPayoutMethod.php](includes/models/SellerPayoutMethod.php)**

### Method Type Constants
```php
class SellerPayoutMethod {
    const METHOD_ACH = 'ACH';          // Bank transfer (ACH)
    const METHOD_PAYPAL = 'PAYPAL';    // PayPal account
    const METHOD_STRIPE = 'STRIPE';    // Stripe connected account
    const METHOD_WALLET = 'WALLET';    // Internal wallet (platform credit)
}
```

### Key Properties
- `$id` (int)
- `$seller_id` (int)
- `$method_type` (string)
- `$is_primary` (bool) - Primary payout method
- `$account_holder_name` (string)
- `$account_last_four` (string)
- `$banking_details_encrypted` (string) - AES-256 encrypted
- `$verified` (bool) - Processor verified this method
- `$verification_date` (DateTime|null)
- `$created_at` (DateTime)
- `$updated_at` (DateTime)

### Key Methods
```php
// Factory methods
public static function create(...): SellerPayoutMethod
public static function fromDatabase(array $row): SellerPayoutMethod

// Getters
public function getId(): int
public function getSellerId(): int
public function getMethodType(): string
public function isPrimary(): bool
public function getAccountHolderName(): string
public function getAccountLastFour(): string
// Note: getEncryptedDetails() is NOT exposed - use PayoutMethodManager
public function isVerified(): bool
public function getVerificationDate(): ?DateTime
public function getCreatedAt(): DateTime
public function getUpdatedAt(): DateTime
public function toArray(): array
```

### Dependencies in PayoutService
```
// Passed to PaymentProcessorAdapter::initiatePayment()
// Contains encrypted banking details needed by processor
// Must NEVER expose encrypted details directly
```

---

## 7. SCHEDULER SERVICE

### File Path
**[includes/services/SchedulerService.php](includes/services/SchedulerService.php)**

### Key Methods
```php
/**
 * Schedule a retry for failed payout
 * Creates RetrySchedule record with next_attempt_time
 */
public function scheduleRetry(
    int $payout_id,
    string $error_reason = '',
    ?int $delay_seconds = null
): RetrySchedule

/**
 * Process payouts scheduled for retry
 * Returns all due retry schedules (ready to re-attempt)
 */
public function processDueRetries(): array // RetrySchedule[]

/**
 * Mark a retry attempt as failed
 * Updates retry with new error, increments attempt counter
 */
public function markRetryFailed(
    RetrySchedule $schedule,
    string $error_message
): RetrySchedule

/**
 * Mark a retry attempt as succeeded
 * Removes retry schedule, marks payout complete
 */
public function markRetrySucceeded(RetrySchedule $schedule): bool

/**
 * Get retry schedule for specific payout
 */
public function getRetrySchedule(int $payout_id): ?RetrySchedule

/**
 * Check if payout has pending retries
 */
public function hasPendingRetries(int $payout_id): bool

/**
 * Scheduler configuration management
 */
public function updateConfig(string $name, string $value): SchedulerConfig
public function getConfig(string $name): ?string
```

### Dependencies
```php
private RetryScheduleRepository $retry_repo;
private BatchLockRepository $batch_lock_repo;
private SchedulerConfigRepository $config_repo;
private EventPublisher $event_publisher;
```

### Dependencies in PayoutService
```php
private SchedulerService $scheduler;
// Used to:
// - Schedule retries for FAILED payouts
// - Retrieve scheduled retries for processing
// - Update retry status after completion
// - Publish scheduler events
```

---

## 8. EVENT PUBLISHER SERVICE

### File Path
**[includes/services/EventPublisher.php](includes/services/EventPublisher.php)**

### Methods
```php
/**
 * Subscribe to specific event type
 * @param string $event_name Event name (e.g., 'payout.completed')
 * @param callable $listener Callback function
 * @return self For chaining
 */
public function subscribe(string $event_name, callable $listener): self

/**
 * Unsubscribe from event
 * @param string $event_name
 * @param callable $listener
 * @return self
 */
public function unsubscribe(string $event_name, callable $listener): self

/**
 * Publish event to all registered listeners
 * @param string $event_name
 * @param Event $event Event object
 * @return void
 */
public function publish(string $event_name, Event $event): void

/**
 * Check if event has listeners
 * @param string $event_name
 * @return bool
 */
public function hasListeners(string $event_name): bool
```

### Dependencies in PayoutService
```php
private EventPublisher $event_publisher;
// Used to:
// - Publish when payout is created: 'payout.created'
// - Publish when payout status changes: 'payout.status_changed'
// - Publish when payout fails: 'payout.failed'
// - Publish when payout completes: 'payout.completed'
```

---

## 9. DATABASE TABLE STRUCTURE

### Table: `wp_wc_auction_seller_payouts`

```sql
CREATE TABLE IF NOT EXISTS wp_wc_auction_seller_payouts (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    batch_id BIGINT NOT NULL,
    seller_id BIGINT NOT NULL,
    auction_ids JSON DEFAULT NULL,
    gross_amount_cents BIGINT NOT NULL,
    commission_amount_cents BIGINT NOT NULL,
    processor_fee_cents BIGINT NOT NULL,
    net_payout_cents BIGINT NOT NULL,
    payout_method VARCHAR(50) DEFAULT NULL,
    payout_status ENUM('PENDING', 'INITIATED', 'PROCESSING', 
                       'COMPLETED', 'FAILED', 'CANCELLED') 
                  NOT NULL DEFAULT 'PENDING',
    payout_id VARCHAR(255) DEFAULT NULL,
    payout_date DATETIME DEFAULT NULL,
    settlement_statement_id BIGINT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    error_message TEXT DEFAULT NULL,
    
    FOREIGN KEY fk_batch_id (batch_id) 
        REFERENCES wp_wc_auction_settlement_batches(id) ON DELETE CASCADE,
    
    KEY idx_batch_id (batch_id),
    KEY idx_seller_id (seller_id),
    KEY idx_payout_status (payout_status),
    KEY idx_payout_date (payout_date),
    KEY idx_created_at (created_at)
)
```

### Key Indexes
- `idx_batch_id` - Query payouts in batch
- `idx_seller_id` - Query seller's payouts
- `idx_payout_status` - Filter by status (PENDING, FAILED, etc.)
- `idx_payout_date` - Sort by execution date
- `idx_created_at` - Sort by creation date

### Column Mapping to SellerPayout Properties
```
id                         → $id
batch_id                   → $batch_id
seller_id                  → $seller_id
gross_amount_cents         → $amount_cents
commission_amount_cents    → (tracking only, not in model)
processor_fee_cents        → $processor_fees_cents
net_payout_cents           → $net_payout_cents (computed)
payout_method              → $method_type
payout_status              → $status
payout_id                  → $transaction_id
payout_date                → (execution date)
settlement_statement_id    → (reference only)
created_at                 → $created_at
updated_at                 → $updated_at
error_message              → $error_message
```

---

## 10. KEY INTEGRATION POINTS FOR PAYOUTSERVICE

### Dependency Graph
```
PayoutService
├── PaymentProcessorFactory
│   └── IPaymentProcessorAdapter
│       ├── SquarePayoutAdapter
│       ├── PayPalPayoutAdapter
│       └── StripePayoutAdapter
├── PayoutRepository
│   └── SellerPayout
│       └── SellerPayoutMethod
├── SettlementBatchRepository
│   └── SettlementBatch
├── SchedulerService
│   ├── RetryScheduleRepository
│   ├── BatchLockRepository
│   ├── SchedulerConfigRepository
│   └── EventPublisher
└── EventPublisher
```

### Core Workflow Integration
```
1. CREATE PAYOUT
   - Factory receives SettlementBatch + Seller details
   - Creates SellerPayout objects
   - Saves via PayoutRepository
   - Publishes 'payout.created' event

2. PROCESS PAYOUT
   - Gets adapter from PaymentProcessorFactory by method type
   - Calls adapter.initiatePayment() with SellerPayoutMethod
   - Receives TransactionResult
   - Updates SellerPayout with transaction_id, status, fees
   - Publishes 'payout.status_changed' event

3. HANDLE FAILURE
   - Catches adapter exception or failed TransactionResult
   - Updates SellerPayout with STATUS_FAILED + error_message
   - Calls SchedulerService.scheduleRetry()
   - Publishes 'payout.failed' event

4. PROCESS RETRY
   - SchedulerService.processDueRetries()
   - Re-attempt payout via adapter
   - On success: SchedulerService.markRetrySucceeded()
   - On failure: SchedulerService.markRetryFailed()

5. COMPLETION
   - Status = STATUS_COMPLETED
   - Updates completed_at timestamp
   - Publishes 'payout.completed' event
   - Updates SettlementBatch status if all payouts complete
```

### Error Handling Strategy
```
Transaction Types:
1. Validation Error → Immediate FAILED (no retry)
2. Network/Processor Timeout → FAILED + Schedule Retry
3. Insufficient Funds → FAILED + Manual Review
4. Account Restrictions → FAILED + Manual Review
5. Invalid Banking Details → FAILED + Require Update

Retry Logic:
- Max attempts: Configurable via SchedulerService config
- Backoff: Exponential (1min, 5min, 15min, 1hr, etc.)
- Manual override: Admins can retry manually anytime
```

### Status Update Flow
```
Initial           → PENDING
                     ↓
On Processing     → PROCESSING (after adapter.initiatePayment)
                     ↓
Success Check     → COMPLETED (after verification)
                     ↓
On Error          → FAILED (with error_message)
                     ↓
Retry Scheduled   → PENDING (via SchedulerService)
                     ↓
On Manual Cancel  → CANCELLED
```

---

## 11. REQUIRED DEPENDENCIES SUMMARY

### Constructor Injection Pattern for PayoutService
```php
public function __construct(
    PaymentProcessorFactory $processor_factory,
    PayoutRepository $payout_repo,
    SettlementBatchRepository $batch_repo,
    SchedulerService $scheduler,
    EventPublisher $event_publisher
) {
    $this->processor_factory = $processor_factory;
    $this->payout_repo = $payout_repo;
    $this->batch_repo = $batch_repo;
    $this->scheduler = $scheduler;
    $this->event_publisher = $event_publisher;
}
```

### Test Mocking Interfaces
When writing PayoutServiceTest, mock these contracts:
- `IPaymentProcessorAdapter` - Mock all processor adapters
- `PayoutRepository` - Mock database operations
- `SettlementBatchRepository` - Mock batch queries
- `SchedulerService` - Mock retry scheduling
- `EventPublisher` - Verify events published

---

## 12. REQUIREMENT TRACEABILITY

### Key Requirements Addressed by Components
```
REQ-4D-025: SellerPayout model (immutable value object)
REQ-4D-026: SellerPayout status tracking and lifecycle
REQ-4D-027: SellerPayout stores processor information
REQ-4D-034: PayoutRepository persists payout data
REQ-4D-035: PayoutRepository queries by multiple filters
REQ-4D-036: PayoutRepository atomic batch updates
REQ-4D-2-1: PaymentProcessorFactory unified factory pattern
REQ-4D-2-1: IPaymentProcessorAdapter standardized contract
REQ-4D-041: SchedulerService orchestration coordination
REQ-4D-041: EventPublisher event infrastructure
PERF-4D-001: Payouts processed < 100ms per record
```

---

## 13. QUICK REFERENCE: METHOD ROUTING

### Getting Correct Adapter for Payout
```php
// Option 1: By seller's payout method
$method = SellerPayoutMethod::METHOD_ACH; // or PAYPAL, STRIPE, WALLET
$adapter = $processor_factory->getAdapter($method);

// Option 2: By processor name (direct)
$adapter = $processor_factory->getAdapterByProcessor('Square');

// Option 3: Support check before routing
if ($processor_factory->supportsMethod($method)) {
    $adapter = $processor_factory->getAdapter($method);
}
```

### Creating TransactionResult from Adapter Response
```php
// Adapter returns standardized TransactionResult
$result = $adapter->initiatePayment(
    'batch-123-seller-456',        // transaction_id (idempotency key)
    10000,                          // amount_cents ($100.00)
    $seller_payout_method           // SellerPayoutMethod object
);

// Access result properties
$txn_id = $result->getTransactionId();
$status = $result->getStatus();
$fees = $result->getProcessorFeesCents();
$net = $result->getNetPayoutCents();
// All normalized across Square, PayPal, Stripe
```

### Checking Payout Readiness
```php
// From database
$payout = $payout_repo->find($id);
if ($payout->isPending()) {
    // Ready to process
}
if ($payout->isFailed() && $scheduler->hasPendingRetries($id)) {
    // Scheduled for retry
}

// Batch status
$batch = $batch_repo->find($batch_id);
if ($batch->isProcessing()) {
    // Already started, don't re-process
}
```
