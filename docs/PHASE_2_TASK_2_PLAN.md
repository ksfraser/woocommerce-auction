# Phase 2 Task 2: PayoutService Implementation - TDD Plan

**Status**: 🟡 Planning | **Start Date**: March 24, 2026 | **Target**: March 25, 2026

---

## 1. Overview

Phase 2-2 implements the **PayoutService** orchestrator and **PayoutRepository** data layer to execute seller payouts using the adapters from Phase 2-1.

### Context
- **Depends On**: Phase 1 (settlement engine), Phase 2-1 (adapters)
- **Unblocks**: Phase 2-3 (scheduler), Phase 2-5 (integration tests)
- **Target Coverage**: 100% (0-skipped tests)
- **TDD Workflow**: Tests first → Code → Run & verify

### Deliverables
| Component | Type | LOC Est. | Files |
|-----------|------|----------|-------|
| PayoutService | Service | 250-300 | 1 |
| PayoutRepository | DAO | 150-200 | 1 |
| Seller Payout Model | Model | 200-250 | 1 |
| Unit Tests | PHPUnit | 400-500 | 2 |
| **TOTAL** | | 1,000-1,250 | 5 |

---

## 2. Component Architecture

### 2.1 Relationships

```
Phase 2-1 Components (Available):
├── IPaymentProcessorAdapter (contract)
├── SquarePayoutAdapter, PayPalPayoutAdapter, StripePayoutAdapter (implementations)
├── PaymentProcessorFactory (router)
├── TransactionResult (response model)
└── SellerPayoutMethod (seller details)

Phase 2-2 Components (To Build):
├── PayoutService (orchestrator)
│   ├── Depends: PaymentProcessorFactory, PayoutRepository
│   ├── Methods: initiateSellerPayout(), getPayoutStatus(), processPayoutBatch()
│   └── Responsibility: Coordinate payout workflow
├── PayoutRepository (DAO)
│   ├── Depends: WordPress $wpdb
│   ├── Methods: save(), find(), findByStatus(), update()
│   └── Responsibility: Persist and retrieve payout records
└── SellerPayout (model)
    ├── Properties: seller_id, batch_id, amount_cents, method_type, status
    └── Methods: create(), fromDatabase(), toArray()

Phase 1 Components (Integration):
├── SettlementBatchService (creates payouts from batches)
├── SettlementBatchRepository (persists batches)
└── SettlementBatch (batch records)
```

### 2.2 Message Flow

```
PayoutService::initiateSellerPayout()
│
├─ Step 1: Validate inputs
│   ├─ SettlementBatch exists?
│   ├─ SellerPayoutMethod exists and verified?
│   └─ Amount > 0?
│
├─ Step 2: Create SellerPayout record (PENDING)
│   ├─ PayoutRepository::save()
│   └─ Get payout_id
│
├─ Step 3: Get payment adapter
│   ├─ PaymentProcessorFactory::getAdapter(method_type)
│   └─ Returns IPaymentProcessorAdapter
│
├─ Step 4: Initiate payment
│   ├─ adapter->initiatePayment(payout_id, amount, method)
│   └─ Returns TransactionResult
│
├─ Step 5: Update SellerPayout with transaction
│   ├─ Store transaction_result_id
│   ├─ Update status to PROCESSING
│   └─ PayoutRepository::update()
│
└─ Return: Updated SellerPayout
```

---

## 3. TDD Task Breakdown

### Phase: Write Tests First ✍️

#### 3.1 PayoutService Tests

**File**: `tests/unit/Services/PayoutServiceTest.php`

**Test Cases** (Red Phase):

1. ✅ `test_service_can_be_instantiated`
   - Assert PayoutService created with factory and repository

2. ✅ `test_initiate_seller_payout_creates_pending_record`
   - Arrange: Mock factory, repository, settlement batch
   - Act: initiateSellerPayout(batch, seller_id, amount, method)
   - Assert: SellerPayout saved with PENDING status

3. ✅ `test_initiate_seller_payout_fetches_adapter_by_method`
   - Assert: Factory called with METHOD_ACH
   - Assert: Adapter returned correctly

4. ✅ `test_initiate_seller_payout_calls_adapter_initiate_payment`
   - Assert: adapter->initiatePayment() called
   - Assert: Parameters: payout_id, amount, method

5. ✅ `test_initiate_seller_payout_updates_status_to_processing`
   - Assert: Status changed to PROCESSING after adapter call

6. ✅ `test_initiate_seller_payout_returns_updated_payout`
   - Assert: Returns SellerPayout with updated transaction data

7. ✅ `test_get_payout_status_retrieves_from_adapter`
   - Arrange: Payout with transaction_id
   - Act: getPayoutStatus(payout_id)
   - Assert: Calls adapter->getTransactionStatus(transaction_id)

8. ✅ `test_get_payout_status_updates_payout_record`
   - Assert: SellerPayout updated with latest status

9. ✅ `test_process_payout_batch_iterates_payouts`
   - Arrange: Batch with 3 payouts (all PENDING)
   - Act: processPayoutBatch(batch_id)
   - Assert: initiateSellerPayout called for each

10. ✅ `test_process_payout_batch_skips_already_processing`
    - Arrange: Mix of PENDING and PROCESSING payouts
    - Act: processPayoutBatch()
    - Assert: Only PENDING processed

11. ✅ `test_process_payout_batch_handles_adapter_errors`
    - Arrange: Adapter throws exception
    - Act: processPayoutBatch()
    - Assert: Payout marked FAILED, batch continues

12. ✅ `test_retry_failed_payout_resets_status`
    - Arrange: Payout with FAILED status
    - Act: retryFailedPayout(payout_id)
    - Assert: Status reset to PENDING, new attempt made

13. ✅ `test_get_batch_payouts_returns_all_payouts_for_batch`
    - Arrange: Batch with 5 payouts
    - Act: getBatchPayouts(batch_id)
    - Assert: Returns all 5 payouts

14. ✅ `test_calculate_total_payout_amount_excludes_failed`
    - Arrange: 3 payouts: 2 COMPLETED ($1000 each), 1 FAILED
    - Act: calculateBatchTotalAmount(batch_id)
    - Assert: Returns 200000 cents (not 300000)

15. ✅ `test_validate_payout_checks_amount_positive`
    - Arrange: Payout with $0 amount
    - Assert: Throws InvalidPayoutException

16. ✅ `test_validate_payout_checks_seller_has_method`
    - Arrange: Seller with no payout method for requested method_type
    - Assert: Throws PayoutMethodNotFoundException

17. ✅ `test_initiate_seller_payout_handles_null_method`
    - Arrange: Seller with primary method, no specific method requested
    - Act: initiateSellerPayout(batch, seller_id, amount)
    - Assert: Uses seller's primary payout method

**Coverage Target**: 100% of PayoutService methods

#### 3.2 PayoutRepository Tests

**File**: `tests/unit/Repositories/PayoutRepositoryTest.php`

**Test Cases** (Red Phase):

1. ✅ `test_repository_can_be_instantiated`
   - Assert PayoutRepository created

2. ✅ `test_save_creates_new_payout_record`
   - Arrange: SellerPayout object (no ID)
   - Act: repository->save(payout)
   - Assert: Returns positive integer ID

3. ✅ `test_save_persists_all_fields`
   - Arrange: Payout with seller_id, batch_id, amount, method, status
   - Act: save()
   - Assert: All fields stored correctly

4. ✅ `test_find_retrieves_payout_by_id`
   - Arrange: Saved payout
   - Act: repository->find(payout_id)
   - Assert: Returns SellerPayout with matching data

5. ✅ `test_find_returns_null_for_missing_payout`
   - Act: find(99999)
   - Assert: Returns null

6. ✅ `test_find_by_batch_returns_all_payouts_in_batch`
   - Arrange: 3 payouts in batch, 2 in other batch
   - Act: findByBatch(batch_id)
   - Assert: Returns exactly 3 payouts

7. ✅ `test_find_by_status_returns_filtered_payouts`
   - Arrange: 2 PENDING, 3 PROCESSING
   - Act: findByStatus(PENDING)
   - Assert: Returns exactly 2 payouts

8. ✅ `test_find_by_seller_returns_seller_payouts`
   - Arrange: Seller with 5 payouts
   - Act: findBySeller(seller_id)
   - Assert: Returns all 5 payouts

9. ✅ `test_update_modifies_existing_payout`
   - Arrange: Saved payout
   - Act: Update status to COMPLETED, save()
   - Assert: Database reflects change

10. ✅ `test_update_throws_error_for_payout_without_id`
    - Arrange: SellerPayout with no ID
    - Act: update()
    - Assert: Throws exception

11. ✅ `test_find_by_transaction_id_returns_payout`
    - Arrange: Payout with transaction_id
    - Act: findByTransactionId(transaction_id)
    - Assert: Returns correct payout

12. ✅ `test_find_pending_payouts_returns_unprocessed`
    - Arrange: Mix of statuses
    - Act: findPending()
    - Assert: Returns only PENDING payouts

13. ✅ `test_find_by_date_range_filters_by_created_date`
    - Arrange: Payouts from March 1-31
    - Act: findByDateRange(March 20-25)
    - Assert: Returns payouts in range

14. ✅ `test_batch_update_processes_multiple_payouts`
    - Arrange: 5 payouts with PENDING status
    - Act: batchUpdateStatus(payout_ids[], PROCESSING)
    - Assert: All updated atomically

15. ✅ `test_find_returns_object_with_all_properties`
    - Arrange: Payout with all properties set
    - Act: find()
    - Assert: SellerPayout object has all properties intact

**Coverage Target**: 100% of PayoutRepository methods

#### 3.3 SellerPayout Model Tests

**File**: `tests/unit/Models/SellerPayoutTest.php` (integrated into above)

**Test Cases** (covered by service tests):

1. ✅ `test_seller_payout_create_factory_sets_all_properties`
2. ✅ `test_seller_payout_from_database_reconstructs_object`
3. ✅ `test_seller_payout_to_array_serializes_correctly`
4. ✅ `test_seller_payout_status_checks_work`
   - isPending(), isProcessing(), isCompleted(), isFailed()

---

## 4. Test File Structure

```php
// tests/unit/Services/PayoutServiceTest.php
namespace WC\Auction\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use WC\Auction\Services\PayoutService;
use WC\Auction\Repositories\PayoutRepository;
use WC\Auction\Services\PaymentProcessorFactory;
use WC\Auction\Contracts\IPaymentProcessorAdapter;
use WC\Auction\Models\SellerPayout;
use WC\Auction\Models\TransactionResult;

class PayoutServiceTest extends TestCase {
    private MockObject $factory;
    private MockObject $repository;
    private PayoutService $service;

    protected function setUp(): void {
        $this->factory = $this->createMock(PaymentProcessorFactory::class);
        $this->repository = $this->createMock(PayoutRepository::class);
        $this->service = new PayoutService($this->factory, $this->repository);
    }

    public function test_service_can_be_instantiated(): void {
        $this->assertInstanceOf(PayoutService::class, $this->service);
    }

    // ... additional test methods
}
```

---

## 5. Implementation Order

### Step 1: Write All Tests (Red Phase)
1. Create PayoutServiceTest.php (17 test methods)
2. Create PayoutRepositoryTest.php (15 test methods)
3. Run tests → All fail (expected)

### Step 2: Implement Code (Green Phase)
1. Create SellerPayout model
2. Create PayoutRepository
3. Create PayoutService
4. Run tests → All pass

### Step 3: Verify Coverage (Coverage Phase)
1. Run PHPUnit with coverage
2. Verify 100% line coverage
3. Verify 100% method coverage
4. Verify 0 skipped tests

### Step 4: Git & Commit
1. git add all files
2. git commit -m "Phase 2-2: PayoutService implementation (TDD)"
3. git push origin starting_bid

---

## 6. Success Criteria

✅ **All tests pass** (0 failures, 0 skipped, 32 tests)
✅ **100% code coverage** (lines, methods, classes)
✅ **No PDO errors** (all prepared statements)
✅ **No security vulnerabilities** (input validation, sanitization)
✅ **All SOLID principles followed** (SRP, OCP, LSP, ISP, DIP)
✅ **All PHPDoc complete** (classes, methods, parameters)
✅ **Type hints 100%** (parameters and returns)
✅ **PSR-12 compliant** (formatting, naming)

---

## 7. Database Schema (Phase 1 - Already Created)

```sql
CREATE TABLE wp_wc_auction_seller_payouts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    batch_id BIGINT NOT NULL,
    seller_id BIGINT NOT NULL,
    amount_cents BIGINT NOT NULL,
    method_type VARCHAR(50),
    status VARCHAR(50),
    transaction_id VARCHAR(255),
    transaction_reference VARCHAR(255),
    processor_name VARCHAR(100),
    processor_fees_cents BIGINT DEFAULT 0,
    net_payout_cents BIGINT DEFAULT 0,
    created_at DATETIME,
    updated_at DATETIME,
    completed_at DATETIME NULL,
    FOREIGN KEY (batch_id) REFERENCES wp_wc_auction_settlement_batches(id),
    KEY (seller_id),
    KEY (status),
    KEY (batch_id),
    UNIQUE KEY unique_transaction(transaction_id)
);
```

---

## 8. Next Steps

1. ✅ Create this plan document
2. ⏳ Write PayoutServiceTest.php (TDD - tests first)
3. ⏳ Write PayoutRepositoryTest.php (TDD - tests first)
4. ⏳ Implement SellerPayout model
5. ⏳ Implement PayoutRepository
6. ⏳ Implement PayoutService
7. ⏳ Run tests and verify 100% pass
8. ⏳ Run coverage and verify 100%
9. ⏳ Commit to git and push

