# PayoutService Dependencies - File Paths Reference

## Complete File Paths for PayoutService Integration

### 1. Core Models & DTOs

**SellerPayout Model (Status Constants, Properties)**
```
includes/models/SellerPayout.php
Status Constants:
  - STATUS_PENDING = 'PENDING'
  - STATUS_PROCESSING = 'PROCESSING'
  - STATUS_COMPLETED = 'COMPLETED'
  - STATUS_FAILED = 'FAILED'
  - STATUS_CANCELLED = 'CANCELLED'
```

**TransactionResult Model (Processor-agnostic)**
```
includes/models/TransactionResult.php
Status Constants:
  - STATUS_PENDING = 'PENDING'
  - STATUS_PROCESSING = 'PROCESSING'
  - STATUS_COMPLETED = 'COMPLETED'
  - STATUS_FAILED = 'FAILED'
  - STATUS_CANCELLED = 'CANCELLED'
```

**SellerPayoutMethod Model (Banking Details)**
```
includes/models/SellerPayoutMethod.php
Method Types:
  - METHOD_ACH = 'ACH'
  - METHOD_PAYPAL = 'PAYPAL'
  - METHOD_STRIPE = 'STRIPE'
  - METHOD_WALLET = 'WALLET'
```

**SettlementBatch Model (Batch Container)**
```
includes/models/SettlementBatch.php
Status Constants:
  - STATUS_DRAFT = 'DRAFT'
  - STATUS_VALIDATED = 'VALIDATED'
  - STATUS_PROCESSING = 'PROCESSING'
  - STATUS_COMPLETED = 'COMPLETED'
  - STATUS_CANCELLED = 'CANCELLED'
```

---

### 2. Data Access Layer (Repositories)

**PayoutRepository**
```
includes/repositories/PayoutRepository.php
Key Methods:
  - save(SellerPayout): int
  - find(int): ?SellerPayout
  - findByBatch(int): SellerPayout[]
  - findByStatus(string): SellerPayout[]
  - findBySeller(int): SellerPayout[]
  - findPending(): SellerPayout[]
  - findByTransactionId(string): ?SellerPayout
  - update(SellerPayout): bool
  - batchUpdateStatus(int[], string): bool
```

**SettlementBatchRepository**
```
includes/repositories/SettlementBatchRepository.php
Key Methods:
  - save(SettlementBatch): int
  - find(int): ?SettlementBatch
  - findByBatchNumber(string): ?SettlementBatch
  - findByStatus(string): SettlementBatch[]
  - findLatest(): ?SettlementBatch
  - findByDateRange(DateTime, DateTime): SettlementBatch[]
  - update(SettlementBatch): bool
  - delete(int): bool
```

---

### 3. Payment Processor Layer

**PaymentProcessorFactory (Main Router)**
```
includes/services/PaymentProcessorFactory.php
Key Methods:
  - registerAdapter(IPaymentProcessorAdapter): self
  - getAdapter(string): ?IPaymentProcessorAdapter
  - getAdapterByProcessor(string): ?IPaymentProcessorAdapter
  - supportsMethod(string): bool
  - supportsProcessor(string): bool
```

**IPaymentProcessorAdapter (Interface Contract)**
```
includes/contracts/IPaymentProcessorAdapter.php
Key Methods:
  - initiatePayment(string, int, SellerPayoutMethod): TransactionResult
  - getTransactionStatus(string): TransactionResult
  - refundTransaction(string, ?int): TransactionResult
  - getProcessorName(): string
  - supportsMethod(string): bool
```

**Processor Implementations**
```
includes/services/adapters/SquarePayoutAdapter.php
  Implements: IPaymentProcessorAdapter
  
includes/services/adapters/PayPalPayoutAdapter.php
  Implements: IPaymentProcessorAdapter
  
includes/services/adapters/StripePayoutAdapter.php
  Implements: IPaymentProcessorAdapter
```

---

### 4. Orchestration & Event Services

**SchedulerService (Retry Orchestration)**
```
includes/services/SchedulerService.php
Key Methods:
  - scheduleRetry(int, string, ?int): RetrySchedule
  - processDueRetries(): RetrySchedule[]
  - markRetryFailed(RetrySchedule, string): RetrySchedule
  - markRetrySucceeded(RetrySchedule): bool
  - getRetrySchedule(int): ?RetrySchedule
  - hasPendingRetries(int): bool
  - updateConfig(string, string): SchedulerConfig
  - getConfig(string): ?string
Dependencies:
  - RetryScheduleRepository
  - BatchLockRepository
  - SchedulerConfigRepository
  - EventPublisher
```

**EventPublisher (Event Publishing)**
```
includes/services/EventPublisher.php
Key Methods:
  - subscribe(string, callable): self
  - unsubscribe(string, callable): self
  - publish(string, Event): void
  - hasListeners(string): bool
Usage:
  - publish('payout.created', $event)
  - publish('payout.status_changed', $event)
  - publish('payout.failed', $event)
  - publish('payout.completed', $event)
```

---

### 5. Database Schema

**Migration File (Table Creation)**
```
includes/migrations/Migration_4_0_0_CreateSellerPayouts.php
Methods:
  - up(): bool
  - down(): bool
  - isApplied(): bool
  
Table: wp_wc_auction_seller_payouts
Columns:
  id (BIGINT, PK)
  batch_id (BIGINT, FK)
  seller_id (BIGINT)
  gross_amount_cents (BIGINT)
  commission_amount_cents (BIGINT)
  processor_fee_cents (BIGINT)
  net_payout_cents (BIGINT)
  payout_method (VARCHAR)
  payout_status (ENUM)
  payout_id (VARCHAR) → transaction_id
  payout_date (DATETIME)
  settlement_statement_id (BIGINT)
  created_at (DATETIME)
  updated_at (DATETIME)
  error_message (TEXT)

Indexes:
  idx_batch_id
  idx_seller_id
  idx_payout_status
  idx_payout_date
  idx_created_at
```

---

### 6. Related Migration (SettlementBatches)

**Settlement Batch Migration**
```
includes/migrations/Migration_4_0_0_CreateSettlementBatches.php
Table: wp_wc_auction_settlement_batches
Related to: SettlementBatch model
```

---

### 7. Test References

**PayoutService Tests**
```
tests/unit/Services/PayoutServiceTest.php
```

**PayoutRepository Tests**
```
tests/unit/Repositories/PayoutRepositoryTest.php
```

**PaymentProcessorFactory Tests**
```
tests/unit/Services/PaymentProcessorFactoryTest.php
```

**PaymentProcessor Adapters Tests**
```
tests/unit/Services/Adapters/PaymentProcessorAdaptersTest.php
```

**SchedulerService Tests**
```
tests/unit/Services/SchedulerServiceTest.php
```

**EventPublisher Tests**
```
tests/unit/Services/EventPublisherTest.php
```

---

## Dependency Injection Structure

PayoutService constructor pattern:
```php
__construct(
    PaymentProcessorFactory $processor_factory,
    PayoutRepository $payout_repo,
    SettlementBatchRepository $batch_repo,
    SchedulerService $scheduler,
    EventPublisher $event_publisher
)
```

Supporting services needed by SchedulerService:
```php
__construct(
    RetryScheduleRepository,
    BatchLockRepository,
    SchedulerConfigRepository,
    EventPublisher
)
```

---

## Status Constants Summary

### SellerPayout (5 states)
```
PENDING     - Initial state
PROCESSING  - Processor handling
COMPLETED   - Successfully executed
FAILED      - Execution failed
CANCELLED   - Manually cancelled
```

### SettlementBatch (5 states)
```
DRAFT       - Initial state
VALIDATED   - Ready for processing
PROCESSING  - Payouts being initiated
COMPLETED   - All complete
CANCELLED   - Batch cancelled
```

### TransactionResult (5 states - Processor Agnostic)
```
PENDING     - Created, awaiting processing
PROCESSING  - Funds transferring
COMPLETED   - Successfully completed
FAILED      - Transaction failed
CANCELLED   - Transaction cancelled
```

### SellerPayoutMethod Types (4 types)
```
ACH         - Bank transfer (Square default)
PAYPAL      - PayPal account (PayPal adapter)
STRIPE      - Stripe account (Stripe adapter)
WALLET      - Platform wallet (PayPal adapter)
```

---

## Quick Integration Checklist

Use these files in PayoutService:
```
✓ includes/models/SellerPayout.php
✓ includes/models/SettlementBatch.php
✓ includes/models/TransactionResult.php
✓ includes/models/SellerPayoutMethod.php
✓ includes/repositories/PayoutRepository.php
✓ includes/repositories/SettlementBatchRepository.php
✓ includes/services/PaymentProcessorFactory.php
✓ includes/contracts/IPaymentProcessorAdapter.php
✓ includes/services/adapters/SquarePayoutAdapter.php
✓ includes/services/adapters/PayPalPayoutAdapter.php
✓ includes/services/adapters/StripePayoutAdapter.php
✓ includes/services/SchedulerService.php
✓ includes/services/EventPublisher.php
✓ includes/migrations/Migration_4_0_0_CreateSellerPayouts.php
```

Mock these in tests:
```
✗ PaymentProcessorFactory (mock getAdapter)
✗ IPaymentProcessorAdapter (mock initiatePayment)
✗ PayoutRepository (mock save, find, update)
✗ SettlementBatchRepository (mock find, update)
✗ SchedulerService (mock scheduleRetry)
✗ EventPublisher (mock publish, verify calls)
```

---

## Database Access Pattern

Primary table: `wp_wc_auction_seller_payouts`

Query patterns:
```sql
-- Get pending payouts for processing
SELECT * FROM wp_wc_auction_seller_payouts 
WHERE payout_status = 'PENDING'
ORDER BY created_at ASC

-- Find payouts in batch
SELECT * FROM wp_wc_auction_seller_payouts 
WHERE batch_id = :batch_id
ORDER BY created_at DESC

-- Find by transaction ID (reconciliation)
SELECT * FROM wp_wc_auction_seller_payouts 
WHERE payout_id = :payout_id

-- Get failed payouts (for retry scheduling)
SELECT * FROM wp_wc_auction_seller_payouts 
WHERE payout_status = 'FAILED'
AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
```

---

## Performance Requirements

From AGENTS.md:
- PERF-4D-001: Payouts < 100ms per record
- Settlement batch processing < 5 seconds for 100 sellers
- Status updates < 5ms

Optimization notes:
```
Use indexes:
  idx_batch_id for batch queries
  idx_payout_status for status filtering
  idx_created_at for date ranges
  
Avoid N+1: Use batch queries where possible
Cache adapter instances in factory
Use async/queue for processor calls if available
```

