---
goal: "Phase 4-D Settlement & Payouts - Complete Implementation"
version: "1.0"
date_created: "2026-03-23"
owner: "Architecture Team"
status: "Planned"
tags: ["feature", "payment-integration", "settlement", "payout", "commerce"]
---

# Phase 4-D: Settlement & Payouts Implementation Plan

![Status: Planned](https://img.shields.io/badge/status-Planned-blue)

Complete implementation of settlement batch creation, commission calculation, payout execution, and seller reconciliation.

## 1. Requirements & Constraints

- **REQ-4D-001**: Commission calculation engine with seller tier support
- **REQ-4D-002**: Automated daily/weekly/monthly settlement batch creation
- **REQ-4D-003**: Multi-processor payout integration (Square, PayPal, Stripe)
- **REQ-4D-004**: Seller dashboard with settlement history and payout tracking
- **REQ-4D-005**: Admin dashboard with settlement management and manual controls
- **REQ-4D-006**: Reconciliation engine matching processor records to settlements
- **REQ-4D-007**: Complete audit trail of all settlement transactions
- **REQ-4D-008**: Encryption of banking details (AES-256 at rest)
- **REQ-4D-009**: Retry logic for failed payouts with exponential backoff
- **REQ-4D-010**: Banking detail verification (micro-deposit or processor validation)

- **SEC-4D-001**: PCI-DSS compliance (never store full card/account numbers)
- **SEC-4D-002**: Encryption for all banking details in transit and at rest
- **SEC-4D-003**: Two-factor authentication for admin manual adjustments
- **SEC-4D-004**: Audit logging of all settlement modifications

- **CON-4D-001**: Phase 4-C (Payment Integration) must be complete first
- **CON-4D-002**: Requires active Square, PayPal, and/or Stripe accounts in staging
- **CON-4D-003**: Database migrations must include new settlement tables
- **CON-4D-004**: Cannot deploy to production without legal/compliance review

- **PERF-4D-001**: Commission calculation < 100ms per seller
- **PERF-4D-002**: Batch processing 1,000 sellers < 5 seconds
- **PERF-4D-003**: Payout API calls < 500ms each
- **PERF-4D-004**: Settlement dashboard loads in < 2 seconds

- **PAT-4D-001**: Follow SOLID principles (single responsibility, dependency injection)
- **PAT-4D-002**: Use interface-based design for payment processor integration
- **PAT-4D-003**: Implement idempotent operations for all settlement actions
- **PAT-4D-004**: Transactional consistency at batch level (all-or-nothing)

---

## 2. Implementation Steps

### Implementation Phase 1: Settlement Calculation Engine

**GOAL-1**: Implement commission calculation, settler tier logic, and settlement batch database schema

**Duration**: 1-2 weeks | **Story Points**: 13 | **Team**: 1-2 developers

#### TASK-1-1: Create Database Migrations

**Description**: Create database migrations for settlement infrastructure

**Files to Create/Modify**:
- `database/migrations/2026_03_23_create_settlement_tables.php` (NEW)
- `database/migrations/2026_03_23_create_commission_rules_table.php` (NEW)
- `database/migrations/2026_03_23_create_seller_payout_methods_table.php` (NEW)

**Migration Details**:

1. **Settlement Batches Table** (`wp_wc_auction_settlement_batches`)
   - Columns: id, batch_number (UNIQUE), settlement_date, batch_period_start, batch_period_end, status, total_amount_cents, commission_amount_cents, processor_fees_cents, payout_count, created_at, processed_at, notes
   - Indexes: idx_settlement_date, idx_status, idx_batch_number, idx_created_at
   - Constraints: Check status IN ('DRAFT', 'VALIDATED', 'PROCESSING', 'COMPLETED', 'CANCELLED')

2. **Seller Payouts Table** (`wp_wc_auction_seller_payouts`)
   - Columns: id, batch_id (FK), seller_id (FK), auction_ids (JSON), gross_amount_cents, commission_amount_cents, processor_fee_cents, net_payout_cents, payout_method, payout_status, payout_id, payout_date, settlement_statement_id, created_at, updated_at, error_message
   - Indexes: idx_batch_id, idx_seller_id, idx_payout_status, idx_payout_date
   - Constraints: Check payout_status IN ('PENDING', 'INITIATED', 'PROCESSING', 'COMPLETED', 'FAILED', 'CANCELLED')

3. **Payout Methods Table** (`wp_wc_auction_seller_payout_methods`)
   - Columns: id, seller_id (FK), method_type, is_primary, account_holder_name, account_last_four, banking_details_encrypted, verified, verification_date, created_at, updated_at
   - Indexes: idx_seller_id, idx_method_type, idx_is_primary

4. **Commission Rules Table** (`wp_wc_auction_commission_rules`)
   - Columns: id, rule_name, seller_tier, commission_type, commission_rate, minimum_bid_threshold_cents, active, effective_from, effective_to, created_at
   - Default Data: STANDARD tier @ 5%, GOLD tier @ 4.75% (with -5% discount), PLATINUM tier @ 4.5% (with -10% discount)

**Acceptance Criteria**:
- [ ] All migrations execute without errors
- [ ] Tables have proper indexes for performance
- [ ] Foreign key constraints configured correctly
- [ ] Default commission rules inserted
- [ ] Rollback migrations tested successfully

**Testing**: Database migration tests in `tests/Integration/DatabaseMigrations/SettlementTablesTest.php`

---

#### TASK-1-2: Implement CommissionCalculator Service

**Description**: Create commission calculation service with seller tier logic

**File to Create**: `src/Services/Settlement/CommissionCalculator.php` (350 LOC)

**Class Structure**:
```php
namespace YITHEA\Services\Settlement;

interface ICommissionCalculator {
    public function calculateCommission(int $seller_id, array $auctions_data): CommissionResult;
    public function applyTierDiscount(int $seller_id, int $gross_amount_cents): int;
    public function deductProcessorFees(int $payout_amount_cents, string $processor): int;
    public function getActiveRuleForSeller(int $seller_id): ?CommissionRule;
}

class CommissionCalculator implements ICommissionCalculator {
    // Constructor: Dependency Injection
    public function __construct(
        private \wpdb $wpdb,
        private LoggerTrait $logger,
        private SellerTierCalculator $tier_calculator
    ) {}

    public function calculateCommission(int $seller_id, array $auctions_data): CommissionResult
    // Algorithm:
    // 1. Get seller tier (via tier_calculator based on historical volume)
    // 2. Fetch active commission rule for seller tier
    // 3. For each auction:
    //    - Get winning bid amount
    //    - Apply commission rate (percentage/fixed)
    //    - Apply tier discount if applicable
    // 4. Sum all commissions
    // 5. Deduct payment processor fees
    // 6. Return CommissionResult with breakdown

    private function getSellersHistoricalVolume(int $seller_id): int
    // Query database for seller's total sold amount (last 12 months)
    // GOLD: >= $10,000 total sales
    // PLATINUM: >= $50,000 total sales
    // Returns tier string

    public function deductProcessorFees(int $payout_amount_cents, string $processor): int
    // Square: $0.25 + 1% flat
    // PayPal: $0.30 + 1.5%
    // Stripe: $0.30 + 1% direct to bank
    // Return net amount (amount - fees)
}
```

**Methods Detail**:
- `calculateCommission()` - Main entry point (150 LOC)
- `applyTierDiscount()` - Apply GOLD/PLATINUM discounts (50 LOC)
- `deductProcessorFees()` - Calculate processor fees by method (40 LOC)
- `getActiveRuleForSeller()` - Fetch rule from DB (30 LOC)
- `getSellersHistoricalVolume()` - Calculate seller tier (50 LOC)
- `validateAuctionData()` - Verify input data (40 LOC)

**Acceptance Criteria**:
- [ ] Commission calculated correctly for all auction amounts
- [ ] Seller tier discount applied (GOLD -5%, PLATINUM -10%)
- [ ] Processor fees deducted correctly by method
- [ ] Audit log recorded for all calculations
- [ ] Edge cases handled (small amounts, round-off errors)

**Testing**: 20+ unit tests in `tests/Unit/Services/Settlement/CommissionCalculatorTest.php`
- Test commission calculation (percentage-based)
- Test commission calculation (fixed-amount)
- Test tier discounts (STANDARD, GOLD, PLATINUM)
- Test processor fee deduction (Square, PayPal, Stripe)
- Test edge cases (zero amount, minimum threshold)

---

#### TASK-1-3: Create SettlementBatchService

**Description**: Create settlement batch orchestration service

**File to Create**: `src/Services/Settlement/SettlementBatchService.php` (400 LOC)

**Class Structure**:
```php
namespace YITHEA\Services\Settlement;

interface ISettlementBatchService {
    public function createBatch(string $period, ?array $seller_ids = null): SettlementBatch;
    public function validateBatch(int $batch_id): ValidationResult;
    public function processBatch(int $batch_id): ProcessResult;
    public function getBatchStatus(int $batch_id): array;
    public function cancelBatch(int $batch_id, string $reason): bool;
}

class SettlementBatchService implements ISettlementBatchService {
    public function __construct(
        private \wpdb $wpdb,
        private CommissionCalculator $commission_calc,
        private PayoutMethodManager $method_manager,
        private LoggerTrait $logger
    ) {}

    public function createBatch(string $period, ?array $seller_ids = null): SettlementBatch
    // Algorithm:
    // 1. Get completed auctions for period (or specific sellers)
    // 2. Group auctions by seller
    // 3. For each seller:
    //    a. Calculate commission via CommissionCalculator
    //    b. Check minimum payout threshold ($5 minimum)
    //    c. Create settlement_batch_id and seller_payout record
    // 4. Store batch metadata (total, fee, payout count)
    // 5. Return SettlementBatch object with status = 'DRAFT'
    // Transaction: Wrap in DB transaction (rollback on error)

    public function validateBatch(int $batch_id): ValidationResult
    // Validation checks:
    // 1. All sellers have verified payout methods
    // 2. All banking details are encrypted properly
    // 3. All amounts >= minimum threshold
    // 4. No duplicate sellers in batch
    // 5. Batch sum matches individual payout sums
    // Return ValidationResult with errors list

    public function processBatch(int $batch_id): ProcessResult
    // 1. Get batch and validate it
    // 2. Update batch status to 'PROCESSING'
    // 3. For each payout record:
    //    a. Call PayoutService.initiateSellerPayout()
    //    b. Store payout response/ID
    //    c. Update payout_status to 'INITIATED'
    // 4. Update batch status to 'COMPLETED'
    // 5. Log total processed, failed count
    // Partial success: Continue if individual payouts fail

    public function getBatchStatus(int $batch_id): array
    // Return:
    // {
    //   batch_id, batch_number, settlement_date, period_start, period_end,
    //   status, total_payouts, total_amount_cents,
    //   successful: count, failed: count, pending: count,
    //   commission_total, processor_fees_total,
    //   created_at, processed_at
    // }

    private function getCompletedAuctionsForPeriod(
        string $period_start,
        string $period_end,
        ?array $seller_ids = null
    ): array
    // Query: 
    // SELECT * FROM wp_wc_auction_auctions
    // WHERE status = 'COMPLETED'
    //   AND end_date >= period_start AND end_date <= period_end
    //   AND (seller_ids IS NULL OR seller_id IN seller_ids)
    // Return auction data with seller_id, winning_bid_amount, etc.

    private function groupAuctionsBySeller(array $auctions): array
    // Group auctions array by seller_id
    // Return: [seller_id => [auction1, auction2, ...], ...]
}
```

**Methods Detail**:
- `createBatch()` - Create batch and payouts (150 LOC)
- `validateBatch()` - Validate batch integrity (80 LOC)
- `processBatch()` - Execute payouts (100 LOC)
- `getBatchStatus()` - Return batch state (40 LOC)
- `cancelBatch()` - Cancel pending batch (30 LOC)

**Acceptance Criteria**:
- [ ] Batch created with correct total amount
- [ ] Validation catches all issues (missing methods, bad amounts)
- [ ] Processing executes payouts and updates status
- [ ] Partial failure handled gracefully
- [ ] All operations transactional and atomic

**Testing**: 15+ integration tests in `tests/Integration/Services/SettlementBatchServiceTest.php`
- Test batch creation with multiple sellers
- Test validation catches missing payout methods
- Test validation catches amount mismatches
- Test processing with successful payouts
- Test partial failure handling
- Test batch cancellation

---

#### TASK-1-4: Implement SellerTierCalculator

**Description**: Calculate seller tier based on historical volume

**File to Create**: `src/Services/Settlement/SellerTierCalculator.php` (150 LOC)

**Class Structure**:
```php
namespace YITHEA\Services\Settlement;

interface ISellerTierCalculator {
    public function calculateTier(int $seller_id): string;  // 'STANDARD'|'GOLD'|'PLATINUM'
    public function getTierDiscount(string $tier): float;  // 0.00|0.05|0.10
}

class SellerTierCalculator implements ISellerTierCalculator {
    // Tier Logic:
    // STANDARD: < $10,000 annual sales (0% discount)
    // GOLD: >= $10,000 and < $50,000 annual sales (-5% discount)
    // PLATINUM: >= $50,000 annual sales (-10% discount)

    public function calculateTier(int $seller_id): string
    // Query database for seller's total sales (last 12 months)
    // Return tier based on thresholds

    public function getTierDiscount(string $tier): float
    // Map tier to discount percentage
}
```

**Acceptance Criteria**:
- [ ] Tier calculated correctly based on sales volume
- [ ] Discounts applied correctly per tier
- [ ] Performance: tier calculation < 50ms per seller

**Testing**: 8+ unit tests

---

#### TASK-1-5: Create CommissionResult & SettlementBatch Data Models

**Description**: Create data transfer objects for commission and settlement results

**Files to Create**:
- `src/Models/Settlement/CommissionResult.php` (80 LOC)
- `src/Models/Settlement/SettlementBatch.php` (100 LOC)
- `src/Models/Settlement/CommissionRule.php` (60 LOC)

**CommissionResult Structure**:
```php
class CommissionResult {
    public int $seller_id;
    public int $gross_amount_cents;           // Sum of all auction amounts
    public float $commission_rate;            // Percentage (e.g., 0.05 for 5%)
    public int $commission_amount_cents;      // Calculated commission
    public float $tier_discount_percent;      // Discount (e.g., 0.05 for 5%)
    public int $discount_amount_cents;        // Discount value
    public int $subtotal_cents;               // After discount
    public int $processor_fee_cents;          // Payment processor fee
    public int $net_payout_cents;             // Final payout amount
    public string $processor_name;            // Square, PayPal, Stripe
    public string $seller_tier;               // STANDARD, GOLD, PLATINUM
    public array $auction_ids;                // List of auction IDs included
    public \DateTime $calculated_at;          // Timestamp
}
```

**SettlementBatch Structure**:
```php
class SettlementBatch {
    public int $batch_id;
    public string $batch_number;              // Unique batch identifier
    public string $status;                    // DRAFT, VALIDATED, PROCESSING, COMPLETED, CANCELLED
    public \DateTime $settlement_date;
    public \DateTime $period_start;
    public \DateTime $period_end;
    public int $total_payouts;
    public int $total_amount_cents;
    public int $total_commission_cents;
    public int $total_processor_fees_cents;
    public array $payout_records;             // CommissionResult objects
    public \DateTime $created_at;
    public ?\DateTime $processed_at;
}
```

**Acceptance Criteria**:
- [ ] Data models correctly map database schema
- [ ] Immutable after creation (or properly validated setters)
- [ ] JSON serialization for API responses

---

#### TASK-1-6: Unit Tests for Phase 1

**Description**: Comprehensive unit tests for commission calculation and settlement batch creation

**Test Files to Create**:
- `tests/Unit/Services/Settlement/CommissionCalculatorTest.php` (20 tests, 400 LOC)
- `tests/Unit/Services/Settlement/SellerTierCalculatorTest.php` (8 tests, 150 LOC)
- `tests/Integration/Services/SettlementBatchServiceTest.php` (15 tests, 400 LOC)

**Coverage Target**: 100% of commission and batch logic

**Key Test Cases**:
- Commission calculation for various bid amounts
- Tier discounts applied correctly
- Processor fees deducted correctly
- Seller tier calculated from sales volume
- Batch creation with multiple sellers
- Batch validation catches all errors
- Edge cases (minimum threshold, zero amounts, large numbers)

**Acceptance Criteria**:
- [ ] All 43+ tests passing
- [ ] Code coverage >= 95%
- [ ] No warnings or code quality issues (PHPStan level 5)

---

### Implementation Phase 2: Batch Processing & Payout Integration

**GOAL-2**: Implement payment processor integration, batch scheduling, and payout execution

**Duration**: 2-3 weeks | **Story Points**: 21 | **Team**: 2 developers

#### TASK-2-1: Payment Processor Adapter Pattern

**Description**: Create extensible adapter for multiple payment processors

**Files to Create**:
- `src/Integration/PaymentProcessors/IPaymentProcessorAdapter.php` (interface, 80 LOC)
- `src/Integration/PaymentProcessors/SquarePayoutAdapter.php` (300 LOC)
- `src/Integration/PaymentProcessors/PayPalPayoutAdapter.php` (300 LOC)
- `src/Integration/PaymentProcessors/StripePayoutAdapter.php` (300 LOC)

**Interface Design**:
```php
interface IPaymentProcessorAdapter {
    public function initiatePayout(PayoutRequest $request): PayoutResponse;
    public function getPayoutStatus(string $payout_id): PayoutStatus;
    public function reversePayout(string $payout_id, string $reason): ReverseResult;
    public function getPayout(string $payout_id): PayoutDetails;
}

class PayoutRequest {
    public string $recipient_id;              // Account ID from payment processor
    public string $recipient_email;
    public int $amount_cents;
    public string $currency;                  // USD, EUR, etc.
    public string $idempotency_key;           // For duplicate prevention
    public array $metadata;                   // Additional data (seller_id, batch_id, etc.)
}

class PayoutResponse {
    public string $payout_id;                 // Unique ID from processor
    public string $status;                    // PENDING, PROCESSING, COMPLETED, FAILED
    public \DateTime $created_at;
    public ?\DateTime $completed_at;
    public ?string $error_message;
}
```

**Implementation Notes**:
- Square: Uses Payouts API with bank account ID
- PayPal: Uses Payouts API with email or merchant ID
- Stripe: Uses Connect Payouts API
- All use idempotency keys to prevent duplicate payouts

**Acceptance Criteria**:
- [ ] Adapter interface supports all processors
- [ ] Each adapter correctly calls processor API
- [ ] Error handling and retries implemented
- [ ] Logging of all payout attempts
- [ ] Production credentials support

---

#### TASK-2-2: PayoutService Implementation

**Description**: Service to execute and track payouts via payment processors

**File to Create**: `src/Services/Settlement/PayoutService.php` (350 LOC)

**Class Structure**:
```php
interface IPayoutService {
    public function initiateSellerPayout(SellerPayoutRecord $payout_record): PayoutResult;
    public function trackPayoutStatus(string $payout_id): PayoutStatusResult;
    public function retryFailedPayout(int $payout_record_id, int $max_retries = 3): PayoutResult;
    public function reversePayout(int $payout_record_id, string $reason): ReverseResult;
}

class PayoutService implements IPayoutService {
    public function __construct(
        private \wpdb $wpdb,
        private PaymentProcessorFactory $processor_factory,
        private PayoutMethodManager $method_manager,
        private LoggerTrait $logger
    ) {}

    public function initiateSellerPayout(SellerPayoutRecord $payout_record): PayoutResult
    // Algorithm:
    // 1. Get seller's payout method
    // 2. Get payment processor adapter (Square/PayPal/Stripe)
    // 3. Build PayoutRequest with seller details and amount
    // 4. Call adapter.initiatePayout()
    // 5. Store response (payout_id, status, timestamp)
    // 6. Update database record with payout_id and status
    // 7. Return PayoutResult
    // Retry: Use exponential backoff for failed API calls

    public function trackPayoutStatus(string $payout_id): PayoutStatusResult
    // Poll payment processor API for current payout status
    // Update database with latest status
    // Return PayoutStatusResult with current state

    public function retryFailedPayout(int $payout_record_id, int $max_retries = 3): PayoutResult
    // 1. Get payout record from database
    // 2. Check retry count < max_retries
    // 3. Get seller's payout method
    // 4. Call payment processor API again
    // 5. Update retry_count in database
    // 6. Log retry attempt
    // Return PayoutResult with new status
}
```

**Methods**:
- `initiateSellerPayout()` - Execute payout (120 LOC)
- `trackPayoutStatus()` - Poll processor (60 LOC)
- `retryFailedPayout()` - Retry logic (80 LOC)
- `reversePayout()` - Reverse completed payout (50 LOC)
- `selectProcessorAdapter()` - Route to correct adapter (40 LOC)

**Acceptance Criteria**:
- [ ] Payouts initiated successfully to all processors
- [ ] Payout status tracked correctly
- [ ] Failed payouts retried with exponential backoff
- [ ] Audit log records all payout actions
- [ ] Idempotency keys prevent duplicates

**Testing**: Integration tests in `tests/Integration/Services/PayoutServiceTest.php` (12 tests)

---

#### TASK-2-3: Batch Scheduler Implementation

**Description**: WordPress cron scheduler for automatic settlement batch creation

**Files to Create**:
- `src/Integration/SettlementBatchScheduler.php` (280 LOC)
- `src/Admin/Hooks/SettlementBatchAjax.php` (150 LOC)

**Class Structure**:
```php
interface ISettlementBatchScheduler {
    public function register(): void;
    public function processDailyBatch(): void;
    public function processManualBatch(array $seller_ids = []): void;
    public function reschedule(): void;
}

class SettlementBatchScheduler implements ISettlementBatchScheduler {
    public function __construct(
        private SettlementBatchService $batch_service,
        private LoggerTrait $logger
    ) {}

    public function register(): void
    // 1. Add action hook: 'wc_auction_process_settlement_batch' (daily at 2am UTC)
    // 2. Register deactivation hook to clean up cron
    // 3. Log registration event

    public function processDailyBatch(): void
    // 1. Lock (transient) to prevent concurrent execution
    // 2. Determine batch period (yesterda daily settlement)
    // 3. Call SettlementBatchService.createBatch()
    // 4. Call SettlementBatchService.validateBatch()
    // 5. Call SettlementBatchService.processBatch()
    // 6. Log results
    // 7. Unlock

    public function processManualBatch(array $seller_ids = []): void
    // Allow admin to manually trigger settlement
    // Same as processDailyBatch() but with optional seller filter

    public function reschedule(): void
    // Check if cron scheduled, reschedule if needed
    // Used on plugin activation
}
```

**Acceptance Criteria**:
- [ ] Cron scheduled daily at 2am UTC
- [ ] Batch created and processed automatically
- [ ] Manual trigger works from admin
- [ ] Concurrent execution prevented
- [ ] All events logged

---

#### TASK-2-4: PayoutMethodManager Implementation

**Description**: Manage seller payout banking details and methods

**File to Create**: `src/Services/Settlement/PayoutMethodManager.php` (350 LOC)

**Class Structure**:
```php
interface IPayoutMethodManager {
    public function addPayoutMethod(int $seller_id, PayoutMethodData $method): PayoutMethod;
    public function updatePayoutMethod(int $method_id, PayoutMethodData $updates): PayoutMethod;
    public function verifyPayoutMethod(int $method_id, string $verification_method): VerificationResult;
    public function getPrimaryMethod(int $seller_id): ?PayoutMethod;
    public function listMethods(int $seller_id): array;
    public function deletePayoutMethod(int $method_id): bool;
}

class PayoutMethodManager implements IPayoutMethodManager {
    public function __construct(
        private \wpdb $wpdb,
        private EncryptionService $encryption,
        private PaymentProcessorFactory $processor_factory,
        private LoggerTrait $logger
    ) {}

    public function addPayoutMethod(int $seller_id, PayoutMethodData $method): PayoutMethod
    // 1. Validate banking details
    // 2. Encrypt sensitive data (AES-256)
    // 3. Store in database
    // 4. Mark as is_primary if no other methods exist
    // 5. Log method added (masked details)
    // 6. Return PayoutMethod

    public function verifyPayoutMethod(int $method_id, string $verification_method): VerificationResult
    // Verification methods:
    // - Micro-deposit: Send small $0.01 transfer, seller confirms amount
    // - Processor validation: Use payment processor's verification API
    // Return VerificationResult with status

    private function encryptBankingDetails(array $details): string
    // Use EncryptionService to AES-256 encrypt banking data
    // Return encrypted string

    private function decryptBankingDetails(string $encrypted_data): array
    // Use EncryptionService to decrypt
    // Return decrypted array
}
```

**Acceptance Criteria**:
- [ ] Banking details encrypted before storage
- [ ] Only last 4 digits stored unencrypted
- [ ] Verification methods supported
- [ ] No logging of sensitive data
- [ ] PCI-DSS compliance

---

#### TASK-2-5: Integration Tests Phase 2

**Description**: Test batch scheduling, payout execution, and method management

**Test Files to Create**:
- `tests/Integration/Services/PayoutServiceTest.php` (12 tests, 300 LOC)
- `tests/Integration/Integration/SettlementBatchSchedulerTest.php` (10 tests, 250 LOC)
- `tests/Unit/Services/Settlement/PayoutMethodManagerTest.php` (15 tests, 350 LOC)

**Key Test Cases**:
- Payout initiated to processor successfully
- Payout ID stored in database correctly
- Payout status tracked from processor
- Retry with exponential backoff works
- Batch scheduler created and scheduled
- Manual batch trigger works
- Banking details encrypted/decrypted correctly
- Payout method verification triggered

**Acceptance Criteria**:
- [ ] All 37 tests passing
- [ ] Code coverage >= 95%
- [ ] PHPStan level 5 compliance

---

### Implementation Phase 3: Seller Dashboard & Admin Controls

**GOAL-3**: Implement seller settlement dashboard and admin management interface

**Duration**: 1 week | **Story Points**: 13 | **Team**: 1-2 developers

#### TASK-3-1: Seller Dashboard UI & Controllers

**Description**: Create seller homepage displaying settlement history, status, and payout methods

**Files to Create**:
- `src/Frontend/Views/Settlement/SellerSettlementDashboard.php` (200 LOC)
- `src/Frontend/Controllers/SettlementDashboardController.php` (150 LOC)
- `src/Frontend/Views/Settlement/PayoutMethodForm.php` (150 LOC)
- `assets/css/settlement-dashboard.css` (150 LOC)

**Features**:
- Display current pending commission balance
- Show settlement history (last 12 months)
- List of all completed payouts with dates and amounts
- Payout method management (add/update/verify)
- Export settlement statement (PDF/CSV)
- Tax year summary (for 1099 reporting)

**Acceptance Criteria**:
- [ ] Dashboard loads in < 2 seconds
- [ ] All data accurate and up-to-date
- [ ] Responsive design (mobile, tablet, desktop)
- [ ] Export functions work correctly
- [ ] No sensitive data exposed

---

#### TASK-3-2: Admin Settlement Management Dashboard

**Description**: Create admin interface for settlement batch management and manual controls

**Files to Create**:
- `src/Admin/Pages/SettlementManagement.php` (300 LOC)
- `src/Admin/Controllers/SettlementAdminController.php` (250 LOC)
- `src/Admin/Views/Settlement/BatchList.php` (150 LOC)
- `src/Admin/Views/Settlement/BatchDetail.php` (150 LOC)
- `src/Admin/Views/Settlement/ManualAdjustment.php` (100 LOC)
- `assets/css/admin-settlement.css` (100 LOC)

**Features**:
- List all settlement batches with status
- View batch details (sellers, amounts, payouts)
- Manual settlement triggering
- Manual amount adjustments with reason/approval
- Failed payout retry button
- Batch cancellation (with confirmation)
- Commission rules management
- Settlement reports and exports

**Acceptance Criteria**:
- [ ] Admin can view all batches and details
- [ ] Manual trigger creates settlement correctly
- [ ] Adjustments properly audited
- [ ] Failed payouts can be retried
- [ ] All actions require proper permissions

---

#### TASK-3-3: Settlement Statement PDF Generation

**Description**: Generate detailed PDF settlement statements for sellers

**File to Create**: `src/Services/Settlement/SettlementStatementGenerator.php` (200 LOC)

**Features**:
- Itemized list of auctions included in settlement
- Commission rate and calculation breakdown
- Processor fees deducted
- Net payout amount
- Payout method and destination
- Tax summary (for 1099 basis)
- Digital signature/batch number for verification

**Acceptance Criteria**:
- [ ] PDF generated correctly
- [ ] All details accurate and clear
- [ ] Professional formatting
- [ ] Complies with accounting standards

---

### Implementation Phase 4: Reconciliation & Compliance

**GOAL-4**: Implement reconciliation engine, audit logging, and tax compliance

**Duration**: 1 week | **Story Points**: 13 | **Team**: 1 developer

#### TASK-4-1: ReconciliationService Implementation

**Description**: Verify settlements match payment processor records

**File to Create**: `src/Services/Settlement/ReconciliationService.php` (280 LOC)

**Process**:
1. Get settled payouts from database
2. Query payment processor for completed transfers
3. Match by payout ID, amount, and date
4. Flag any discrepancies
5. Generate reconciliation report

**Discrepancy Types**:
- Missing: Settled in DB but not found in processor
- Mismatch: Different amounts between DB and processor
- Extra: Found in processor but not in DB
- Timing: Settled dates don't match

**Acceptance Criteria**:
- [ ] All settled payouts reconciled
- [ ] Discrepancies detected and flagged
- [ ] Reconciliation report generated
- [ ] Performance acceptable (< 30 seconds for 1000 payouts)

---

#### TASK-4-2: Comprehensive Audit Logging

**Description**: Audit trail for all settlement modifications

**Implementation**:
- Log every settlement batch creation
- Log every payout initiated/completed/failed
- Log every manual adjustment with admin ID
- Log every payout method add/update
- Log every retry attempt
- Log every reconciliation

**Audit Log Table**: `wp_wc_auction_settlement_audit_log`
- id, action, user_id, batch_id, payout_id, seller_id, old_value, new_value, reason, created_at

**Acceptance Criteria**:
- [ ] All actions logged to audit table
- [ ] Audit trail complete and traceable
- [ ] Admin can view audit history
- [ ] GDPR compliance (data retention policy)

---

#### TASK-4-3: 1099 Tax Reporting Support

**Description**: Prepare seller settlement data for 1099 tax reporting

**File to Create**: `src/Services/Settlement/TaxReporting1099Service.php` (200 LOC)

**Features**:
- Filter sellers with >= $20,000 annual settlement
- Generate 1099 form data export
- Support for multiple tax years
- State-specific tax calculations

**Acceptance Criteria**:
- [ ] 1099 data correctly calculated
- [ ] Export format matches IRS requirements
- [ ] Supports prior years and filtering
- [ ] Legal review passed

---

#### TASK-4-4: Phase 4 Completion Tests & Documentation

**Description**: Final integration tests and complete documentation

**Test Files**:
- `tests/Integration/Services/ReconciliationServiceTest.php` (10 tests)
- `tests/Integration/Services/TaxReporting1099ServiceTest.php` (8 tests)
- End-to-end settlement flow test (6 tests)

**Documentation**:
- `docs/SETTLEMENT_OPERATION_GUIDE.md` - Operational procedures
- `docs/SETTLEMENT_API_REFERENCE.md` - API documentation
- `docs/SETTLEMENT_TROUBLESHOOTING.md` - Common issues and solutions

**Acceptance Criteria**:
- [ ] All 24+ tests passing
- [ ] Code coverage >= 95%
- [ ] Complete documentation for operators and developers
- [ ] Legal/compliance review passed

---

## 3. Definition of Done

For each task to be considered complete:

- [ ] Code written following SOLID principles and style guide
- [ ] 100% unit test coverage (where applicable)
- [ ] Integration tests cover main flows
- [ ] Code passes PHPStan level 5
- [ ] No security issues (encryption, SQL injection, XSS)
- [ ] Proper error handling and logging
- [ ] Database migrations tested
- [ ] Documentation updated
- [ ] Code reviewed and approved
- [ ] Tested in staging environment
- [ ] No breaking changes to existing APIs

---

## 4. Success Criteria (Phase 4-D Complete)

- ✅ Settlement batches created automatically daily
- ✅ 99%+ payout success rate
- ✅ Sellers receive payouts within T+1 to T+5 business days
- ✅ 100% reconciliation of settled amounts to processor records
- ✅ All settlement actions audited
- ✅ Seller dashboard shows accurate settlement history
- ✅ Admin can manually adjust settlements with full audit trail
- ✅ 1099 data prepared for qualified sellers
- ✅ All tests passing with 95%+ coverage
- ✅ Legal/compliance review passed

---

## 5. Risk Mitigation

| Risk | Mitigation |
|------|-----------|
| Payment processor API failures | Retry logic, monitoring, fallback manual process |
| Banking detail security breach | Encryption, PCI-DSS compliance, monitoring |
| Commission calculation errors | Comprehensive testing, reconciliation, manual review |
| Compliance violations | Legal review, audit trail, documentation |
| Scalability issues | Database indexing, batch optimization, load testing |

---

## 6. Timeline & Dependencies

**Total Duration**: 4-6 weeks (with 1-2 developer team)

**Phase Sequencing**:
1. Phase 1: Settlement Calculation (Weeks 1-2) - **No blockers**
2. Phase 2: Batch & Payout (Weeks 2-4) - **Blocked by Phase 1**
3. Phase 3: Dashboard & Admin (Week 4-5) - **Blocked by Phase 2**
4. Phase 4: Reconciliation (Week 5-6) - **Blocked by Phase 3**

**Critical Path**: Phase 1 → Phase 2 → Phase 3 → Phase 4

---

## 7. Deliverables Summary

**Code**:
- 6 new services (2,500+ LOC)
- 3 payment processor adapters (900 LOC)
- Frontend UI (400 LOC)
- Admin interface (650 LOC)
- Database migrations (200 LOC)

**Tests**:
- 100+ unit tests
- 35+ integration tests
- End-to-end tests (6+)
- Code coverage: 95%+

**Documentation**:
- Architecture specification (this document)
- Operation guide
- API reference
- Troubleshooting guide
- Compliance documentation

---

## Next Steps

1. ✅ Create Phase 4-D specifications (COMPLETE)
2. ⏳ Create GitHub Issues from implementation tasks
3. ⏳ Setup staging environment with test payment processors
4. ⏳ BEGIN IMPLEMENTATION: Phase 4-D Step 1 (Task-1-1 through 1-6)
5. ⏳ Complete Phase 1 with full test coverage
6. ⏳ Code review and approval
7. ⏳ Proceed to Phase 2-4

---

**Document Status**: Ready for Implementation ✅
