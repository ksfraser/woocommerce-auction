# Test Coverage Plan - Phase 1 to Phase 3

## Current State Analysis

| Component | Coverage | Status | Files |
|-----------|----------|--------|-------|
| mock-wordpress | 100% | ✅ Complete | 7 classes, 50+ tests |
| mock-woocommerce | 100% | ✅ Complete | 2 classes, 30+ tests |
| YITH Original Code | 7.29% | ❌ Minimal | 10 classes, only YITH_WCACT_Bid_Increment tested |
| ksfraser Extensions | 0% | ⏳ TO DO | 3 classes (starting_bid branch) |

---

## Phase 1: Mock Infrastructure (COMPLETED ✅)

### Deliverables
- ✅ `ksfraser/mock-wordpress`: WordPress functions, hooks, WPDB, factories
- ✅ `ksfraser/mock-woocommerce`: WC products, orders, factories
- ✅ 80+ test methods with 100% coverage
- ✅ Published to Packagist
- ✅ Integrated via Composer

### Test Files
```
tests/
├── Unit/
│   ├── WordPressFunctionsTest.php (15 tests)
│   ├── WordPressHooksTest.php (18 tests)
│   ├── WPDBTest.php (12 tests)
│   ├── PostFactoryTest.php (10 tests)
│   ├── UserFactoryTest.php (8 tests)
│   ├── WCProductTest.php (10 tests)
│   └── ProductFactoryTest.php (8 tests)
└── Fixtures/
    ├── Mock test data builders
    └── Test scenarios
```

---

## Phase 1B: Test Factories Package (READY TO START)

### Goal: 30 hours
Create reusable test builders for auction-specific scenarios

### Deliverables
- [ ] GitHub repo: `ksfraser/test-factories`
- [ ] 3 builder classes with fluent interface
- [ ] 15+ test methods, 100% coverage
- [ ] Packagist publication

### Components

#### 1. AuctionProductBuilder
**File**: `src/Builders/AuctionProductBuilder.php`

```php
class AuctionProductBuilder extends \ksfraser\MockWooCommerce\ProductFactory {
    public function withStartingPrice(float $price): self
    public function withReservePrice(float $price): self
    public function withBidIncrement(array $ranges): self
    public function withAuctionEnd(\DateTime $end): self
    public function withCurrentHighBid(float $amount, int $bidder_id): self
    public function inAuction(): self
    public function auctionEnded(): self
    public function build(): AuctionProduct
}
```

**Test Cases**:
- Basic auction product creation
- Multiple bid increment ranges
- Reserve price edge cases
- Auction end times (future, past, edge cases)
- High bid bidder association

#### 2. BidBuilder
**File**: `src/Builders/BidBuilder.php`

```php
class BidBuilder {
    public function forProduct(int $product_id): self
    public function fromBidder(int $user_id): self
    public function withAmount(float $amount): self
    public function at(\DateTime $time): self
    public function withAutoIncrement(bool $auto): self
    public function asMaximumBid(float $max): self
    public function build(): Bid
}
```

**Test Cases**:
- Simple bids
- Auto-increment bids
- Maximum bid with sniping
- Bid amount validation
- Bidder constraints
- Time-based bid ordering

#### 3. ScenarioBuilder
**File**: `src/Builders/ScenarioBuilder.php`

```php
class ScenarioBuilder {
    public function createSimpleAuction(): AuctionScenario
    public function createCompetitiveAuction(int $num_bidders): AuctionScenario
    public function createLastMinuteSniping(): AuctionScenario
    public function createReservePriceNotMet(): AuctionScenario
    public function createBidIncrementCascade(): AuctionScenario
    public function createMultipleAuctions(int $count): AuctionScenario
}
```

**Test Cases**:
- Each scenario type
- Bid ordering within scenarios
- Winner calculation
- Edge case combinations

### Test File Structure
```
tests/Unit/Builders/
├── AuctionProductBuilderTest.php (6 tests)
├── BidBuilderTest.php (6 tests)
└── ScenarioBuilderTest.php (8 tests)
```

---

## Phase 2: Migrate Existing YITH Tests (10 hours)

### Goal
Update YITH's existing tests to use new mock infrastructure

### Initial Test Coverage Assessment

**Files to Update**:
```
tests/
├── YITH_WCACT_Bid_IncrementTest.php (ONLY current test)
└── bootstrap.php (Remove hardcoded mocks, use Composer packages)
```

### Tasks

#### 2.1 Update bootstrap.php
- [ ] Remove inline mock implementations
- [ ] Import from Composer packages
- [ ] Set up test factories
- [ ] Configure test database

#### 2.2 Migrate YITH_WCACT_Bid_IncrementTest.php
- [ ] Use `ksfraser\MockWordPress\TestCase` base class
- [ ] Replace hardcoded mocks with mock factories
- [ ] Add missing test cases for edge conditions
- [ ] Verify 100% coverage

#### 2.3 Create Tests for Currently Untested YITH Classes

**Classes with 0% Coverage**:
1. `YITH_WCACT_Auction` (Core auction class)
2. `YITH_WCACT_Auction_Admin` (Admin functionality)
3. `YITH_WCACT_Auction_Bids` (Bid management)
4. `YITH_WCACT_Auction_DB` (Database operations)
5. `YITH_WCACT_Auction_Finish_Auction` (Auction completion)
6. `YITH_WCACT_Auction_Frontend` (Frontend display)
7. `YITH_WCACT_Auction_Product` (Product auction properties)
8. `YITH_WCACT_Auction_My_Auctions` (User auctions)
9. `YITH_WCACT_Auction_AJAX` (AJAX handlers)

**New Test Files**:
```
tests/Unit/YITH/
├── AuctionTest.php (12 tests)
├── AuctionAdminTest.php (10 tests)
├── AuctionBidsTest.php (15 tests)
├── AuctionDBTest.php (12 tests)
├── AuctionFinishAuctionTest.php (8 tests)
├── AuctionFrontendTest.php (10 tests)
├── AuctionProductTest.php (10 tests)
├── AuctionMyAuctionsTest.php (8 tests)
└── AuctionAJAXTest.php (10 tests)
```

**Coverage Target**: Bring YITH code coverage from 7.29% → 35-40%

---

## Phase 3: TDD Implementation - v1.4.0 Features (46+ hours)

### Goal: 100% coverage for all new features before code implementation

### Feature 1: Entry Fees (REQ-ENTRY-FEES-001 through 006)

**Test File**: `tests/Unit/ksfraser/EntryFees/EntryFeesTest.php`

```php
// Feature specs from requirements:
tests:
- testEntryFeeCalculation_WithPercentageFee_CalculatesCorrectly()
- testEntryFeeCalculation_WithFixedFee_CalculatesCorrectly()
- testEntryFeeCalculation_WithHybridFee_CalculatesCorrectly()
- testEntryFeesAreRefunded_WhenAuctionCancelled()
- testEntryFeesAreNotRefunded_WhenUserLoses()
- testMultipleAuctionsFeeAccumulation()
```

**Classes to Test** (Before Implementation):
- `EntryFeeCalculator`
- `EntryFeeValidator`
- `EntryFeeRefundManager`

### Feature 2: Commission (REQ-COMMISSION-001 through 004)

**Test File**: `tests/Unit/ksfraser/Commission/CommissionTest.php`

```php
tests:
- testCommissionCalculation_WithPercentageRate()
- testCommissionCalculation_WithMinimumThreshold()
- testCommissionDeduction_FromWinningBid()
- testCommissionReporting_ByAuction()
```

**Classes to Test**:
- `CommissionCalculator`
- `CommissionValidator`
- `CommissionReportGenerator`

### Feature 3: Post-Auction Processing (REQ-POST-AUCTION-001 through 005)

**Test File**: `tests/Unit/ksfraser/PostAuction/PostAuctionProcessorTest.php`

```php
tests:
- testWinnerNotification_SendsEmail()
- testLoserNotification_SendsEmail()
- testPaymentProcessing_WithPaymentGateway()
- testOrderCreation_FromAuctionDetails()
- testAuctionArchival_AfterCompletion()
```

**Classes to Test**:
- `PostAuctionProcessor`
- `NotificationDispatcher`
- `PaymentProcessor`
- `AuctionArchiver`

### Feature 4: Notifications (REQ-NOTIFICATIONS-001 through 004)

**Test File**: `tests/Unit/ksfraser/Notifications/NotificationTest.php`

```php
tests:
- testHighBidNotification_OnNewBid()
- testOutbidNotification_OnBidExceeded()
- testAuctionWonNotification_OnCompletion()
- testReservePriceNotMetNotification_OnAuctionEnd()
```

**Classes to Test**:
- `NotificationManager`
- `EmailTemplateRenderer`
- `NotificationQueue`

### Test Execution Order (TDD Red-Green-Refactor)

```
Phase 3a: Entry Fees
  1. Write all entry fee tests (all fail - RED)
  2. Implement EntryFeeCalculator
  3. Implement EntryFeeValidator
  4. Tests pass (GREEN)
  5. Refactor for clarity

Phase 3b: Commission
  1. Write all commission tests (all fail - RED)
  2. Implement CommissionCalculator
  3. Implement CommissionValidator
  4. Tests pass (GREEN)
  5. Refactor

... (continue for Phase 3c & 3d)
```

---

## Coverage Milestones

| Phase | Target Coverage | Status | Effort |
|-------|-----------------|--------|--------|
| Phase 1 (Mocks) | 100% (mock code) | ✅ Complete | ✅ 60 hrs |
| Phase 1B (Factories) | 100% (factories) | ⏳ Ready | 30 hrs |
| Phase 2 (YITH Migrate) | 35-40% (YITH) | ⏳ Ready | 10 hrs |
| Phase 3a (Entry Fees) | 100% (entry fees) | ⏳ Ready | 12 hrs |
| Phase 3b (Commission) | 100% (commission) | ⏳ Ready | 8 hrs |
| Phase 3c (Post-Auction) | 100% (post-auction) | ⏳ Ready | 15 hrs |
| Phase 3d (Notifications) | 100% (notifications) | ⏳ Ready | 11 hrs |
| **TOTAL** | **Overall 80%+** | **In Progress** | **146 hrs** |

---

## Test Execution Commands

```bash
# Run all tests
vendor/bin/phpunit

# Run only new ksfraser tests
vendor/bin/phpunit tests/Unit/ksfraser/

# Run with coverage report
vendor/bin/phpunit --coverage-html coverage/

# Run single test file
vendor/bin/phpunit tests/Unit/ksfraser/BidIncrement/BidIncrementManagerTest.php

# Run specific test method
vendor/bin/phpunit --filter testEntryFeeCalculation_WithPercentageFee
```

---

## Quality Gates

✅ Before merging ANY code:
1. New tests exist and pass
2. Code coverage ≥ 100% for new code
3. All existing tests still pass
4. No regression in overall coverage
5. PHPStan/PHPMD checks pass
6. Security scan complete

