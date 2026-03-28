# PayoutService Dependencies - Quick Reference Table

## Component Summary Table

| Component | File Path | Type | Key Methods | Status Constants |
|-----------|-----------|------|-------------|------------------|
| **SellerPayout** | `includes/models/SellerPayout.php` | Model/DTO | `create()`, `fromDatabase()`, getId/Batch/Seller/Amount/Method/Status getters, toArray() | PENDING, PROCESSING, COMPLETED, FAILED, CANCELLED |
| **PayoutRepository** | `includes/repositories/PayoutRepository.php` | DAO | save(), find(), findByBatch(), findByStatus(), findBySeller(), findPending(), findByTransactionId(), update(), batchUpdateStatus() | N/A |
| **SettlementBatch** | `includes/models/SettlementBatch.php` | Model | create(), fromDatabase(), getId/BatchNumber/Status/Amount getters | DRAFT, VALIDATED, PROCESSING, COMPLETED, CANCELLED |
| **SettlementBatchRepository** | `includes/repositories/SettlementBatchRepository.php` | DAO | save(), find(), findByBatchNumber(), findByStatus(), findLatest(), findByDateRange(), update(), delete() | N/A |
| **PaymentProcessorFactory** | `includes/services/PaymentProcessorFactory.php` | Factory | registerAdapter(), getAdapter(method), getAdapterByProcessor(name), supportsMethod(), supportsProcessor() | N/A |
| **IPaymentProcessorAdapter** | `includes/contracts/IPaymentProcessorAdapter.php` | Interface | initiatePayment(), getTransactionStatus(), refundTransaction(), getProcessorName(), supportsMethod() | N/A |
| ├─ **SquarePayoutAdapter** | `includes/services/adapters/SquarePayoutAdapter.php` | Implementation | (implements IPaymentProcessorAdapter) | N/A |
| ├─ **PayPalPayoutAdapter** | `includes/services/adapters/PayPalPayoutAdapter.php` | Implementation | (implements IPaymentProcessorAdapter) | N/A |
| └─ **StripePayoutAdapter** | `includes/services/adapters/StripePayoutAdapter.php` | Implementation | (implements IPaymentProcessorAdapter) | N/A |
| **TransactionResult** | `includes/models/TransactionResult.php` | Model | create(), fromProcessor(), getters for all properties, toArray() | PENDING, PROCESSING, COMPLETED, FAILED, CANCELLED |
| **SellerPayoutMethod** | `includes/models/SellerPayoutMethod.php` | Model | create(), fromDatabase(), getId/Seller/Method/Primary/AccountHolder/Verified getters | METHOD_ACH, METHOD_PAYPAL, METHOD_STRIPE, METHOD_WALLET |
| **SchedulerService** | `includes/services/SchedulerService.php` | Service | scheduleRetry(), processDueRetries(), markRetryFailed(), markRetrySucceeded(), hasPendingRetries(), updateConfig(), getConfig() | N/A |
| **EventPublisher** | `includes/services/EventPublisher.php` | Service | subscribe(), unsubscribe(), publish(), hasListeners() | N/A |

---

## Status Constants Reference

### SellerPayout Statuses
```
PENDING     - Payout created, not yet initiated
PROCESSING  - Processor is handling the transaction
COMPLETED   - Successfully delivered to seller
FAILED      - Transaction failed (can be retried)
CANCELLED   - Payout was cancelled/revoked
```

### SettlementBatch Statuses
```
DRAFT       - Batch created, pending validation
VALIDATED   - Passed validation, ready to process
PROCESSING  - Payouts being initiated
COMPLETED   - All payouts complete
CANCELLED   - Batch was cancelled
```

### TransactionResult Statuses (Processor Agnostic)
```
PENDING     - Transaction created, awaiting processing
PROCESSING  - Funds being transferred
COMPLETED   - Successfully completed
FAILED      - Transaction failed
CANCELLED   - Transaction cancelled
```

### SellerPayoutMethod Types
```
ACH         - Bank transfer (routes to Square)
PAYPAL      - PayPal account (routes to PayPal adapter)
STRIPE      - Stripe connected account (routes to Stripe adapter)
WALLET      - Internal platform wallet (routes to PayPal adapter)
```

---

## Database Schema Reference

### Table: `wp_wc_auction_seller_payouts`

| Column | Type | Key | Notes |
|--------|------|-----|-------|
| id | BIGINT | PK, AUTO_INCREMENT | Primary key |
| batch_id | BIGINT | FK, INDEX | References settlement_batches |
| seller_id | BIGINT | INDEX | Seller/vendor ID |
| auction_ids | JSON | - | Auctions included |
| gross_amount_cents | BIGINT | - | Total revenue (cents) |
| commission_amount_cents | BIGINT | - | Commission deducted |
| processor_fee_cents | BIGINT | - | Processor fees |
| net_payout_cents | BIGINT | - | Amount paid (computed) |
| payout_method | VARCHAR(50) | - | ACH/PayPal/Stripe/Wallet |
| payout_status | ENUM | INDEX | PENDING/PROCESSING/COMPLETED/FAILED/CANCELLED |
| payout_id | VARCHAR(255) | - | Processor transaction ID |
| payout_date | DATETIME | INDEX | Execution date |
| settlement_statement_id | BIGINT | - | PDF statement reference |
| created_at | DATETIME | INDEX | Record creation |
| updated_at | DATETIME | - | Last update |
| error_message | TEXT | - | Failure reason |

### Indexes Defined
- `idx_batch_id` - Query payouts in batch
- `idx_seller_id` - Query seller's payouts  
- `idx_payout_status` - Filter by status
- `idx_payout_date` - Sort by date
- `idx_created_at` - Sort by creation

---

## Constructor Dependency Injection Pattern

```php
class PayoutService {
    public function __construct(
        PaymentProcessorFactory $processor_factory,
        PayoutRepository $payout_repo,
        SettlementBatchRepository $batch_repo,
        SchedulerService $scheduler,
        EventPublisher $event_publisher
    ) {
        // Inject all dependencies
    }
}
```

---

## Method Routing by Payout Method

| Method Type | Primary Adapter | Supported By |
|-------------|-----------------|--------------|
| ACH | Square | Square, (Stripe with micro-deposits) |
| PAYPAL | PayPal | PayPal, Square |
| STRIPE | Stripe | Stripe, (Square for compatible methods) |
| WALLET | PayPal | PayPal, Square (internal only) |

---

## Key Integration Checkpoints

### 1. On Payout Creation
- [ ] Validate seller_id has SellerPayoutMethod on file
- [ ] Create SellerPayout with STATUS_PENDING
- [ ] Save via PayoutRepository
- [ ] Publish 'payout.created' event

### 2. On Processing Start
- [ ] Get adapter: `$adapter = $factory->getAdapter($payout->getMethodType())`
- [ ] Call: `$result = $adapter->initiatePayment($txn_id, $amount, $method)`
- [ ] Update: `$payout_repo->update($payout)` with transaction_id, STATUS_PROCESSING
- [ ] Publish: 'payout.status_changed' event

### 3. On Processor Response
- [ ] Check: `$result->getStatus()`
- [ ] If COMPLETED: Update payout to STATUS_COMPLETED
- [ ] If FAILED: Update to STATUS_FAILED + store error_message
- [ ] If PENDING: Leave as PROCESSING (poll later)

### 4. On Failure
- [ ] Update SellerPayout: status=FAILED, error_message set
- [ ] Call: `$scheduler->scheduleRetry($payout_id, $error_message)`
- [ ] Publish: 'payout.failed' event

### 5. On Batch Completion Check
- [ ] Query: `$batch_payouts = $payout_repo->findByBatch($batch_id)`
- [ ] Check: All payouts either COMPLETED or FAILED
- [ ] Update SettlementBatch status to COMPLETED
- [ ] Publish: 'batch.completed' event

---

## Test Mocking Checklist

For PayoutServiceTest, create mocks for:

```php
$mock_factory = $this->createMock(PaymentProcessorFactory::class);
$mock_adapter = $this->createMock(IPaymentProcessorAdapter::class);
$mock_payout_repo = $this->createMock(PayoutRepository::class);
$mock_batch_repo = $this->createMock(SettlementBatchRepository::class);
$mock_scheduler = $this->createMock(SchedulerService::class);
$mock_event_publisher = $this->createMock(EventPublisher::class);

// Stub adapter responses
$mock_adapter->method('initiatePayment')
    ->willReturn(TransactionResult::create(...));

// Verify repository calls
$mock_payout_repo->expects($this->once())
    ->method('save');

// Verify events published
$mock_event_publisher->expects($this->once())
    ->method('publish')
    ->with('payout.created', $this->isInstanceOf(PayoutCreatedEvent::class));
```

---

## Performance Targets

| Operation | Target | Notes |
|-----------|--------|-------|
| Save single payout | < 10ms | Database write |
| Query pending payouts | < 50ms | For 100+ records |
| Process single payout | < 100ms | Processor call usually 50-100ms |
| Batch of 100 payouts | < 5 seconds | SettlementBatch requirement |
| Status update | < 5ms | Single record update |
| Event publishing | < 10ms | Local publish (not external) |

---

## Migration Reference

### Migration File
- **Path:** `includes/migrations/Migration_4_0_0_CreateSellerPayouts.php`
- **Methods:** `up()` (create table), `down()` (drop table), `isApplied()` (check)
- **Status:** Applied when migration system runs

---

## Requirement ID Mapping

| Requirement | Component | Summary |
|-------------|-----------|---------|
| REQ-4D-025 | SellerPayout | Model payout records |
| REQ-4D-026 | SellerPayout | Track lifecycle/status |
| REQ-4D-027 | SellerPayout | Store processor info |
| REQ-4D-034 | PayoutRepository | Persist data |
| REQ-4D-035 | PayoutRepository | Query by filters |
| REQ-4D-036 | PayoutRepository | Batch updates |
| REQ-4D-2-1 | PaymentProcessorFactory | Unified factory |
| REQ-4D-2-1 | IPaymentProcessorAdapter | Standardized contract |
| REQ-4D-041 | SchedulerService | Orchestration |
| REQ-4D-041 | EventPublisher | Event infrastructure |
| PERF-4D-001 | All | Performance targets |

---

## Code Example: Basic PayoutService Flow

```php
class PayoutService {
    private PaymentProcessorFactory $processor_factory;
    private PayoutRepository $payout_repo;
    private EventPublisher $event_publisher;
    
    // Constructor with all dependencies...
    
    /**
     * Process a single seller payout
     * @requirement REQ-4D-028: Initiate seller payout via processor
     */
    public function processPayout(SellerPayout $payout): TransactionResult {
        // 1. Get correct adapter for this seller's method
        $adapter = $this->processor_factory->getAdapter($payout->getMethodType());
        if (!$adapter) {
            throw new \Exception('No adapter for method: ' . $payout->getMethodType());
        }
        
        // 2. Initiate transaction with processor
        $result = $adapter->initiatePayment(
            'batch-' . $payout->getBatchId() . '-seller-' . $payout->getSellerId(),
            $payout->getAmountCents(),
            $seller_method  // SellerPayoutMethod object
        );
        
        // 3. Update payout with processor response
        $updated_payout = $payout->withStatus(SellerPayout::STATUS_PROCESSING)
            ->withTransactionId($result->getTransactionId())
            ->withProcessorName($result->getProcessorName())
            ->withProcessorFees($result->getProcessorFeesCents());
        
        $this->payout_repo->update($updated_payout);
        
        // 4. Publish domain event
        $this->event_publisher->publish('payout.status_changed', 
            new PayoutStatusChangedEvent($updated_payout, SellerPayout::STATUS_PROCESSING));
        
        return $result;
    }
}
```

