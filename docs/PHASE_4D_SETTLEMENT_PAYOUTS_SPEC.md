# Phase 4-D: Settlement & Payouts - Epic Specification

**Version:** 1.0  
**Date:** March 23, 2026  
**Status:** Planned  
**Related:** Phase 4-A (Auto-bidding), Phase 4-B (Sealed Bidding), Phase 4-C (Payment Integration)

---

## Executive Summary

Phase 4-D implements the final component of the YITH Auctions payment infrastructure: **Seller Settlement & Payouts**. This phase automates the process of calculating seller commissions, generating settlement batches, and initiating payouts to sellers via their configured payment methods.

### Key Objectives

1. **Seller Commission Calculation** - Compute commissions based on auction results and WooCommerce fees
2. **Settlement Batch Management** - Group payouts by seller and payment method
3. **Payout Scheduling** - Schedule payouts for optimal payment processor efficiency
4. **Multi-Currency Support** - Handle settlements in multiple currencies
5. **Reconciliation** - Audit trail for all settlement transactions
6. **Seller Dashboard** - Real-time settlement status visibility
7. **Payment Processor Integration** - Support Square, PayPal, Stripe payouts

---

## Business Context

### Settlement Business Rules

1. **Commission Structure**
   - Auction success fee: Configurable % of winning bid amount
   - Platform maintenance fee: Configurable flat rate or percentage
   - Payment processor fees: Deducted from payout
   - Seller tier discounts: VIP sellers get reduced commissions

2. **Settlement Timing**
   - Settlement batched daily/weekly/monthly (configurable)
   - Payout processing: T+1 to T+5 business days depending on processor
   - Minimum payout threshold: Don't process if < $5 balance

3. **Payout Methods**
   - Direct bank transfer (ACH, wire, SWIFT)
   - PayPal/Stripe Direct
   - Merchant wallet (hold for future auctions)
   - Check (legacy/specialty)

4. **Compliance & Audit**
   - 1099 reporting for US sellers (>$20k annual)
   - GDPR compliance for banking info
   - Audit log for all transactions
   - Tax jurisdiction rules

---

## Functional Requirements

### F1: Settlement Calculation Engine

- **F1.1**: Calculate commissions per seller from completed auctions
- **F1.2**: Apply seller tier discounts based on historical volume
- **F1.3**: Deduct payment processor fees (from settlement, not from seller)
- **F1.4**: Support manual settlement amount adjustments (admin override)
- **F1.5**: Generate settlement statement (PDF) with itemized breakdown

### F2: Batch Management

- **F2.1**: Create daily/weekly/monthly settlement batches automatically
- **F2.2**: Allow manual batch creation (force settlement for specific sellers)
- **F2.3**: Preview pending payouts before final settlement
- **F2.4**: Validate all settlement amounts and banking details before processing
- **F2.5**: Handle minimum payout threshold (skip if < threshold)

### F3: Payout Execution

- **F3.1**: Interface with Square payment processor for direct payouts
- **F3.2**: Create payout to seller's bank account on file
- **F3.3**: Handle ACH transfer failures and retries
- **F3.4**: Support alternative payout methods (PayPal, wallet)
- **F3.5**: Track payout status from initiated → completed

### F4: Seller Dashboard

- **F4.1**: Show pending commission balance
- **F4.2**: Display recent settlement history (last 12 months)
- **F4.3**: Show payout method and schedule
- **F4.4**: Allow seller to update banking information
- **F4.5**: Provide export of settlements (CSV/PDF)

### F5: Admin Controls

- **F5.1**: Configure commission rates (global and per-tier)
- **F5.2**: Manually adjust settlement amounts (with audit log)
- **F5.3**: Trigger immediate settlement for specific sellers
- **F5.4**: Retry failed payouts
- **F5.5**: Generate settlement reports and reconciliation

### F6: Reconciliation

- **F6.1**: Match settled amounts to payment processor confirmations
- **F6.2**: Identify discrepancies and flag for manual review
- **F6.3**: Generate reconciliation reports
- **F6.4**: Maintain audit trail of all changes

---

## Technical Architecture

### Component Overview

```
Settlement & Payout System Architecture
┌─────────────────────────────────────────────────────────┐
│                  Admin Dashboard                          │
│         Settlement Management Interface                   │
└──────────────────┬──────────────────────────────────────┘
                   │
┌──────────────────▼──────────────────────────────────────┐
│         SettlementBatchService (Orchestrator)            │
│  - Calculate commissions                                 │
│  - Create settlement batches                             │
│  - Trigger payout processing                             │
└──────┬───────────────────┬──────────────────┬───────────┘
       │                   │                  │
   ┌───▼──────┐     ┌──────▼────┐     ┌──────▼──────┐
   │Commission │     │ Settlement│     │  Payout    │
   │Calculator │     │  Manager  │     │  Processor │
   └───┬──────┘     └──────┬────┘     └──────┬──────┘
       │                   │                  │
   ┌───▼─────────────────────▼──────────────┬─▼─────────┐
   │        Database Layer                   │ Processor │
   │  - Auction Results                      │ Integr.   │
   │  - Settlement Batches                   │ (Square/  │
   │  - Payout Records                       │  PayPal) │
   │  - Seller Banking Info                  │           │
   └─────────────────────────────────────────┴───────────┘
```

### Data Models

**Settlement Batch**
```
wp_wc_auction_settlement_batches
├─ id (PK)
├─ batch_number (UNIQUE)
├─ settlement_date (DATE)
├─ batch_period_start (DATE)
├─ batch_period_end (DATE)
├─ status ENUM('DRAFT', 'VALIDATED', 'PROCESSING', 'COMPLETED', 'CANCELLED')
├─ total_amount_cents (BIGINT)
├─ commission_amount_cents (BIGINT)
├─ processor_fees_cents (BIGINT)
├─ payout_count (INT)
├─ created_at (TIMESTAMP)
├─ processed_at (TIMESTAMP)
└─ notes (TEXT)
```

**Seller Payout Record**
```
wp_wc_auction_seller_payouts
├─ id (PK)
├─ batch_id (FK)
├─ seller_id (FK)
├─ auction_ids (JSON array)
├─ gross_amount_cents (BIGINT)
├─ commission_amount_cents (BIGINT)
├─ processor_fee_cents (BIGINT)
├─ net_payout_cents (BIGINT)
├─ payout_method ENUM('ACH', 'PAYPAL', 'STRIPE', 'WALLET', 'CHECK')
├─ payout_status ENUM('PENDING', 'INITIATED', 'PROCESSING', 'COMPLETED', 'FAILED', 'CANCELLED')
├─ payout_id (VARCHAR - processor reference)
├─ payout_date (DATE)
├─ settlement_statement_id (FK)
├─ created_at (TIMESTAMP)
├─ updated_at (TIMESTAMP)
└─ error_message (TEXT)
```

**Seller Payout Method**
```
wp_wc_auction_seller_payout_methods
├─ id (PK)
├─ seller_id (FK)
├─ method_type ENUM('ACH', 'PAYPAL', 'STRIPE', 'WALLET')
├─ is_primary (BOOLEAN)
├─ account_holder_name (VARCHAR)
├─ account_last_four (VARCHAR - for display)
├─ banking_details_encrypted (TEXT - AES-256)
├─ verified (BOOLEAN)
├─ verification_date (TIMESTAMP)
├─ created_at (TIMESTAMP)
└─ updated_at (TIMESTAMP)
```

**Commission Rule**
```
wp_wc_auction_commission_rules
├─ id (PK)
├─ rule_name (VARCHAR)
├─ seller_tier ENUM('STANDARD', 'GOLD', 'PLATINUM')
├─ commission_type ENUM('PERCENTAGE', 'FIXED')
├─ commission_rate (DECIMAL 10,4)
├─ minimum_bid_threshold_cents (BIGINT)
├─ active (BOOLEAN)
├─ effective_from (DATE)
├─ effective_to (DATE)
└─ created_at (TIMESTAMP)
```

### Service Architecture

**SettlementBatchService**
- `createBatch()` - Create new settlement batch for period
- `calculateCommissions()` - Compute all seller commissions
- `validateBatch()` - Check amounts, banking info, thresholds
- `processBatch()` - Execute payouts to all sellers
- `getBatchStatus()` - Return batch state and statistics

**PayoutService**
- `initiateSellerPayout()` - Send payout to payment processor
- `trackPayoutStatus()` - Poll processor for status updates
- `retryFailedPayout()` - Retry failed payout with backoff
- `cancelPayout()` - Cancel pending payout
- `getSellerPayoutHistory()` - Return seller's payout records

**CommissionCalculator**
- `calculateSellerCommission()` - Compute commission for single seller
- `applySellerTierDiscount()` - Apply tier-based discount
- `deductProcessorFees()` - Subtract payment processor fees
- `generateSettlementStatement()` - Create detail PDF

**PayoutMethodManager**
- `addPayoutMethod()` - Register seller payout account
- `updatePayoutMethod()` - Update banking details
- `verifyPayoutMethod()` - Validate account (micro-deposit)
- `getPrimaryPayoutMethod()` - Get seller's default payout account

---

## Non-Functional Requirements

### Security
- **NF-SEC-001**: Encrypt all banking information at rest (AES-256)
- **NF-SEC-002**: PCI-DSS compliance (never store full card numbers)
- **NF-SEC-003**: Audit log of all settlement actions
- **NF-SEC-004**: Two-factor authentication for settlement adjustments

### Performance
- **NF-PERF-001**: Batch process 1,000+ sellers in < 5 seconds
- **NF-PERF-002**: Settlement calculation < 100ms per seller
- **NF-PERF-003**: Payout API calls to processor < 500ms each

### Reliability
- **NF-REL-001**: 99.9% uptime for settlement system
- **NF-REL-002**: Automatic retry for processor timeouts (3 attempts)
- **NF-REL-003**: Transactional consistency (all-or-nothing batches)

### Scalability
- **NF-SCAL-001**: Support 10,000+ sellers scaling to 100,000+
- **NF-SCAL-002**: Handle 10,000+ payouts per batch
- **NF-SCAL-003**: Database indexes optimized for settlement queries

### Compliance
- **NF-COMP-001**: GDPR compliance for seller banking data
- **NF-COMP-002**: Tax jurisdiction support (1099, GST, VAT)
- **NF-COMP-003**: Payment processor compliance (ACH, wire rules)

---

## Implementation Constraints

1. **Phase 4-C Must Complete First** - Payment infrastructure (authorization, capture, refund) is prerequisite
2. **Payment Processor Integration** - Requires active Square/PayPal/Stripe accounts
3. **Banking Compliance** - Requires legal review for payout terms
4. **Timeline** - 4-6 weeks estimated for full implementation
5. **Testing** - Requires staging environment with test payment processors

---

## Success Criteria

- ✅ Settlement batches created automatically (daily at 2am UTC)
- ✅ 99%+ payout success rate (< 1% failures)
- ✅ Sellers receive payments within promised timeframe (T+1 to T+5 days)
- ✅ All settlements reconcilable to payment processor records
- ✅ Audit trail complete for compliance review
- ✅ Seller dashboard shows accurate settlement history
- ✅ Admin can manually adjust settlements with full audit trail

---

## Dependencies & Integrations

### External Dependencies
- **Square Payment Processor API** - Payout endpoint integration
- **ACH Network** - Bank transfer processing
- **Tax/Compliance Services** - 1099 reporting (future)

### Internal Dependencies
- Phase 4-C Payment Integration (COMPLETE ✅)
- WooCommerce Core (order, user, payment methods)
- WordPress Cron (batch scheduling)
- Logging & Monitoring (structured logs)

---

## Next Steps

1. Create Epic Architecture Specification (breakdown-epic-arch)
2. Create Implementation Plan (create-implementation-plan)
3. Break into Stories/Tasks for development
4. Setup staging environment with test payment processor
5. Begin implementation of Phase 4-D Step 1 (Settlement Calculations)
