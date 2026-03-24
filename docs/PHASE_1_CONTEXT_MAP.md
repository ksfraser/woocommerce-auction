# Phase 4-D: Phase 1 Context Map - Settlement Calculation Engine

**Date**: March 23, 2026  
**Status**: Implementation Ready  
**Duration**: 1-2 weeks  
**Story Points**: 13

## Overview

Phase 1 of Phase 4-D implements the Settlement Calculation Engine - the foundation for all settlement operations. This phase creates the database schema, data models, repositories, and core calculation services.

## Files to Create

### Database Migrations (TASK-1-1)

| File Path | Purpose | Details |
|-----------|---------|---------|
| `includes/migrations/Migration_4_0_0_CreateSettlementBatches.php` | Settlement batches table | Tracks settlement runs and status |
| `includes/migrations/Migration_4_0_0_CreateSellerPayouts.php` | Seller payouts table | Individual seller payout records |
| `includes/migrations/Migration_4_0_0_CreatePayoutMethods.php` | Payout methods table | Seller banking details (encrypted) |
| `includes/migrations/Migration_4_0_0_CreateCommissionRules.php` | Commission rules table | Configurable tier-based rules |

### Data Models (TASK-1-5)

| File Path | Purpose | Properties |
|-----------|---------|-----------|
| `includes/models/CommissionResult.php` | Computed commission | gross, commission, fees, net |
| `includes/models/SettlementBatch.php` | Batch record | id, batch_number, status, amounts |
| `includes/models/CommissionRule.php` | Rule record | tier, rate, thresholds |

### Repository Classes (TASK-1-5)

| File Path | Purpose | Key Methods |
|-----------|---------|------------|
| `includes/repositories/SettlementBatchRepository.php` | Batch persistence | save, find, findByStatus, update |
| `includes/repositories/SellerPayoutRepository.php` | Payout persistence | save, findByBatch, findByStatus |
| `includes/repositories/PayoutMethodRepository.php` | Method persistence | save, findPrimary, encrypt/decrypt |
| `includes/repositories/CommissionRuleRepository.php` | Rule persistence | save, findByTier, findActive |

### Service Classes (TASK-1-2, 1-3, 1-4)

| File Path | Purpose | Key Methods | LOC |
|-----------|---------|------------|-----|
| `includes/services/CommissionCalculator.php` | Calculate commissions | calculateCommission, applyTierDiscount, deductFees | 350 |
| `includes/services/SettlementBatchService.php` | Batch orchestration | createBatch, validateBatch, processBatch | 400 |
| `includes/services/SellerTierCalculator.php` | Tier determination | calculateTier, getTierThreshold | 150 |

### Test Files (TASK-1-6)

| File Path | Purpose | Test Count |
|-----------|---------|-----------|
| `tests/unit/Services/CommissionCalculatorTest.php` | Commission logic | 8+ tests |
| `tests/unit/Services/SettlementBatchServiceTest.php` | Batch operations | 10+ tests |
| `tests/unit/Services/SellerTierCalculatorTest.php` | Tier calculation | 5+ tests |
| `tests/integration/Settlement/SettlementBatchIntegrationTest.php` | End-to-end flow | 15+ tests |

## Dependencies & Patterns

### Existing Code to Reference

| File | Pattern | Usage |
|------|---------|-------|
| `includes/services/AutoBiddingEngine.php` | Orchestrator service | Service architecture pattern |
| `includes/repositories/ProxyBidRepository.php` | DAO repository | Database access pattern |
| `includes/models/ProxyBid.php` | Immutable value object | Data model pattern |
| `includes/migrations/MigrationRunner.php` | Migration orchestrator | Database migration pattern |

### Namespace & Structure

```
WC\Auction\Services\*
WC\Auction\Models\*
WC\Auction\Repositories\*
WC\Auction\Migrations\*
```

### Code Conventions

- ✅ PHPDoc with UML Class Diagrams
- ✅ Requirement references (`@requirement REQ-4D-*`)
- ✅ Constructor dependency injection
- ✅ Prepared statements (WordPress $wpdb)
- ✅ Immutable value objects for models
- ✅ Repository pattern for data access
- ✅ Check ABSPATH in each file

## Database Schema

### 4 New Tables

#### wp_wc_auction_settlement_batches
```sql
CREATE TABLE wp_wc_auction_settlement_batches (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  batch_number VARCHAR(50) UNIQUE NOT NULL,
  settlement_date DATE NOT NULL,
  batch_period_start DATE NOT NULL,
  batch_period_end DATE NOT NULL,
  status ENUM('DRAFT','VALIDATED','PROCESSING','COMPLETED','CANCELLED') DEFAULT 'DRAFT',
  total_amount_cents BIGINT DEFAULT 0,
  commission_amount_cents BIGINT DEFAULT 0,
  processor_fees_cents BIGINT DEFAULT 0,
  payout_count INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  notes LONGTEXT NULL,
  KEY idx_settlement_date (settlement_date),
  KEY idx_status (status),
  KEY idx_batch_number (batch_number),
  KEY idx_created_at (created_at)
)
```

#### wp_wc_auction_seller_payouts
```sql
CREATE TABLE wp_wc_auction_seller_payouts (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  batch_id BIGINT NOT NULL,
  seller_id BIGINT NOT NULL,
  auction_ids JSON,
  gross_amount_cents BIGINT NOT NULL,
  commission_amount_cents BIGINT NOT NULL,
  processor_fee_cents BIGINT NOT NULL,
  net_payout_cents BIGINT NOT NULL,
  payout_method VARCHAR(50),
  payout_status ENUM('PENDING','INITIATED','PROCESSING','COMPLETED','FAILED','CANCELLED'),
  payout_id VARCHAR(255) NULL,
  payout_date DATETIME NULL,
  settlement_statement_id BIGINT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  error_message TEXT NULL,
  FOREIGN KEY (batch_id) REFERENCES wp_wc_auction_settlement_batches(id),
  KEY idx_batch_id (batch_id),
  KEY idx_seller_id (seller_id),
  KEY idx_payout_status (payout_status),
  KEY idx_payout_date (payout_date)
)
```

#### wp_wc_auction_seller_payout_methods
```sql
CREATE TABLE wp_wc_auction_seller_payout_methods (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  seller_id BIGINT NOT NULL,
  method_type ENUM('ACH','PAYPAL','STRIPE','WALLET'),
  is_primary BOOLEAN DEFAULT FALSE,
  account_holder_name VARCHAR(255),
  account_last_four VARCHAR(4),
  banking_details_encrypted LONGTEXT,
  verified BOOLEAN DEFAULT FALSE,
  verification_date DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_seller_id (seller_id),
  KEY idx_method_type (method_type),
  KEY idx_is_primary (is_primary)
)
```

#### wp_wc_auction_commission_rules
```sql
CREATE TABLE wp_wc_auction_commission_rules (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  rule_name VARCHAR(100),
  seller_tier VARCHAR(50),
  commission_type VARCHAR(50),
  commission_rate DECIMAL(5,2),
  minimum_bid_threshold_cents BIGINT,
  active BOOLEAN DEFAULT TRUE,
  effective_from DATETIME,
  effective_to DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_seller_tier (seller_tier),
  KEY idx_active (active)
)
```

## Implementation Order

**Must follow this sequence:**

1. ✅ Create migration files (register with MigrationRunner)
2. ✅ Create data models (no dependencies)
3. ✅ Create repository classes (depend on models)
4. ✅ Create service classes (depend on repositories)
5. ✅ Create unit tests for each component
6. ✅ Integration tests for complete flow

## Service Architecture Details

### CommissionCalculator
```
Input: Auction data, seller info
↓
calculateCommission()
├── Get seller tier (via SellerTierCalculator)
├── Apply tier discount
├── Deduct payment processor fees
├── Validate result
↓
Output: CommissionResult { gross, commission, fees, net }
```

### SettlementBatchService
```
Input: Date range, batch options
↓
createBatch()
├── Get auctions in period
├── Group by seller
├── Calculate commissions (via CommissionCalculator)
├── Create batch record
├── Validate totals
↓
Output: SettlementBatch record saved
```

### SellerTierCalculator
```
Input: Seller ID, historical data
↓
calculateTier()
├── Get seller's YTD revenue
├── Compare thresholds: $10k→GOLD, $50k→PLATINUM
├── Default: STANDARD (no discount)
↓
Output: Tier string { STANDARD | GOLD | PLATINUM }
```

## Acceptance Criteria (Phase 1)

- [ ] All 4 database tables created and indexed
- [ ] All migration files register with MigrationRunner
- [ ] All 3 data models implement with PHPDoc + UML
- [ ] All 4 repositories implement with caching
- [ ] All 3 services fully functional
- [ ] 20+ unit tests passing
- [ ] 95%+ code coverage achieved
- [ ] All requirement references added (REQ-4D-*)
- [ ] Code follows SOLID principles
- [ ] Performance tests pass (< 100ms commission calc)
- [ ] Security review complete (no SQL injection, type safe)
- [ ] Integration test for complete settlement flow
- [ ] PHPDoc validates without errors
- [ ] PHPStan level 5 passes

## Risk Assessment

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|-----------|
| Tier calculation threshold errors | Medium | High | Comprehensive test cases, validation in calculator |
| Commission rounding errors | Medium | High | Use integer cents, explicit rounding tests |
| Database migration conflicts | Low | High | Manual migration review, staging test first |
| Performance on large datasets | Medium | Medium | Database indexing, query optimization, load tests |
| Concurrent access race conditions | Low | High | Row-level locking, transactional operations |

## Success Metrics

- ✅ All 1,000+ LOC written with 100% PHPDoc coverage
- ✅ 20+ unit tests written and passing
- ✅ 95%+ code coverage (automated report)
- ✅ CommissionCalculator < 100ms (performance test)
- ✅ SettlementBatchService completes for 100 sellers in < 5 seconds
- ✅ Zero PHPStan violations (level 5)
- ✅ Zero PHPCS violations (PSR-12)
- ✅ All migrations test successfully on clean DB
- ✅ Code review approved by team lead
- ✅ Integrated with Phase 4-C (payment capture complete)

## Timeline

- **Day 1-2**: Migrations + Models + Repositories
- **Day 3-4**: Service implementations + Unit tests
- **Day 5**: Integration tests + Performance testing
- **Day 6**: Code review + Bug fixes
- **Day 7**: Documentation + Final testing + Commit

---

**Ready for implementation: ✅ YES**
