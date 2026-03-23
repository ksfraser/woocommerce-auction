# Entry Fees & Commission Enhancement - Phase 3 Implementation Plan

**Version**: 1.0  
**Feature**: Entry Fees & Commission Management  
**Estimated Effort**: 33 development tasks  
**Status**: Planning Phase  
**Date**: March 2026

---

## Executive Summary

The Entry Fees & Commission feature enables auction organizers to charge optional participation fees to bidders and commission fees from sellers on winning bids. This monetization capability allows auction platforms to generate revenue and may be legally required in some jurisdictions for regulation compliance.

**Business Value**:
- New revenue stream for auction platforms
- Configurable fee models (seller commission, buyer premium, participation fee)
- Regulatory compliance for auction oversight
- Differentiated pricing models (VIP auctions vs. standard)
- Reduced operational costs through automated collection

---

## Goal & Scope

### Goal

Implement comprehensive fee management system supporting entry fees for bidders, commission collection from sellers, bidder premiums, and flexible configuration models for different auction types and user tiers.

### Scope

**In Scope**:
- Configurable entry fees (per-auction or global)
- Seller commission on winning bids (fixed % or tiered)
- Buyer premium (additional % of final bid)
- VIP/tiered fee structures
- Fee payment handling and reconciliation
- Admin fee management dashboard
- Seller and buyer fee transparency
- Refund and adjustment management
- Fee reporting and analytics

**Out of Scope** (v2.0.0+):
- Multi-currency fee handling
- Dynamic fee pricing (AI-driven)
- Fee escrow and third-party processing
- Tax calculation integration

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    Fee Calculation Flow                      │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ 1. Auction Registration                                      │
│    ├─ Determine seller tier → commission rate               │
│    ├─ Determine auction type (standard/VIP)                 │
│    └─ Calculate entry fee if applicable                     │
│         └─ Store in wp_wc_auction_fees                      │
│                        ↓                                     │
│ 2. During Bidding                                           │
│    ├─ Entry fee charged when first bid placed              │
│    ├─ Fee collected via payment gateway                     │
│    ├─ Status: PENDING_FEE → FEE_COLLECTED                   │
│    └─ Record in wp_wc_fee_transactions                      │
│                        ↓                                     │
│ 3. Auction Completion                                       │
│    ├─ Determine winning bid                                 │
│    ├─ Calculate seller commission                           │
│    │   └─ Commission = final_bid * commission_rate          │
│    ├─ Calculate buyer premium                               │
│    │   └─ Premium = final_bid * premium_rate                │
│    ├─ Determine seller payout                               │
│    │   └─ Payout = final_bid - commission                   │
│    └─ Queue payout transaction                              │
│         └─ Store in wp_wc_fee_transactions                  │
│                        ↓                                     │
│ 4. Fund Settlement                                          │
│    ├─ Settle entry fees (if not prepaid)                    │
│    ├─ Settle buyer premium                                  │
│    ├─ Settle seller commission                              │
│    ├─ Generate reconciliation reports                       │
│    └─ Trigger payment processor (Stripe/PayPal)             │
│                                                              │
└─────────────────────────────────────────────────────────────┘

System Architecture:

┌──────────────────────────────────────────────────────────────┐
│                    Admin Configuration                        │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ WooCommerce > Settings > Auction Fees                  │  │
│  │ ├─ Global Commission Rate (%)                         │  │
│  │ ├─ Buyer Premium Rate (%)                             │  │
│  │ ├─ Entry Fee (fixed or %)                             │  │
│  │ ├─ VIP Auction Rate (premium)                         │  │
│  │ ├─ Seller Tier Rates (sliding scale)                  │  │
│  │ └─ Fee Payment Methods                                │  │
│  └────────────────────────────────────────────────────────┘  │
└────────────────────────┬─────────────────────────────────────┘
                         │
                         ↓
┌──────────────────────────────────────────────────────────────┐
│              Business Logic Layer (Services)                 │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ FeeCalculationEngine                                  │  │
│  │ - calculateEntryFee()                                 │  │
│  │ - calculateCommission()                               │  │
│  │ - calculateBuyerPremium()                              │  │
│  │ - calculateTotalPayout()                              │  │
│  │ - calculateSellerProceeds()                            │  │
│  └────────────────────────────────────────────────────────┘  │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ FeeCollectionService                                  │  │
│  │ - collectEntryFee()                                   │  │
│  │ - recordFeeTransaction()                              │  │
│  │ - refundFee()                                          │  │
│  │ - adjustFee()                                          │  │
│  └────────────────────────────────────────────────────────┘  │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ FeeSettlementService                                  │  │
│  │ - settleFunds()                                       │  │
│  │ - generateReconciliation()                            │  │
│  │ - trackPaymentStatus()                                │  │
│  │ - handlePaymentFailure()                              │  │
│  └────────────────────────────────────────────────────────┘  │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ TierManagementService                                 │  │
│  │ - assignSellerTier()                                  │  │
│  │ - getTierCommissionRate()                             │  │
│  │ - updateTierStatus()                                  │  │
│  └────────────────────────────────────────────────────────┘  │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ FeeReportingService                                   │  │
│  │ - generateIncomeReport()                              │  │
│  │ - generateCommissionReport()                           │  │
│  │ - generateSellerPayoutReport()                         │  │
│  │ - exportForAccounting()                               │  │
│  └────────────────────────────────────────────────────────┘  │
└────────────────────────┬─────────────────────────────────────┘
                         │
                         ↓
┌──────────────────────────────────────────────────────────────┐
│        Data Access Layer (Repository Pattern)                │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ FeeConfigRepository                                   │  │
│  │ FeeTransactionRepository                              │  │
│  │ SellerTierRepository                                  │  │
│  │ FeeRefundRepository                                   │  │
│  └────────────────────────────────────────────────────────┘  │
└────────────────────────┬─────────────────────────────────────┘
                         │
                         ↓
┌──────────────────────────────────────────────────────────────┐
│              Database Layer (WordPress WPDB)                 │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ wp_wc_auction_fees (fee configuration)                │  │
│  │ wp_wc_fee_transactions (transaction history)          │  │
│  │ wp_wc_fee_refunds (refund tracking)                   │  │
│  │ wp_wc_seller_tiers (seller tier management)           │  │
│  │ wp_wc_fee_reconciliation (daily reconciliation)       │  │
│  └────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

---

## Database Schema

### Table: `wp_wc_auction_fees`

Stores fee configuration per auction

```sql
CREATE TABLE wp_wc_auction_fees (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  auction_id BIGINT UNSIGNED NOT NULL UNIQUE,
  auction_type VARCHAR(20) NOT NULL DEFAULT 'STANDARD',
  seller_id BIGINT UNSIGNED NOT NULL,
  seller_tier VARCHAR(20) NOT NULL DEFAULT 'STANDARD',
  entry_fee DECIMAL(10,2),
  entry_fee_type VARCHAR(10) NOT NULL DEFAULT 'FIXED',
  buyer_premium_rate DECIMAL(5,2),
  seller_commission_rate DECIMAL(5,2),
  override_commission_rate DECIMAL(5,2),
  vip_fee DECIMAL(10,2),
  is_vip BOOLEAN DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_seller_tier (seller_id, seller_tier),
  KEY idx_auction_type (auction_type),
  CONSTRAINT fk_auction FOREIGN KEY (auction_id) REFERENCES wp_posts(ID),
  CONSTRAINT fk_seller FOREIGN KEY (seller_id) REFERENCES wp_users(ID)
);
```

### Table: `wp_wc_fee_transactions`

Tracks all fee collection and settlement

```sql
CREATE TABLE wp_wc_fee_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transaction_id VARCHAR(36) NOT NULL UNIQUE,
  auction_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED,
  seller_id BIGINT UNSIGNED,
  transaction_type VARCHAR(20) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  fee_type VARCHAR(30) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  payment_method VARCHAR(30),
  reference_transaction_id VARCHAR(100),
  notes TEXT,
  created_at DATETIME NOT NULL,
  processed_at DATETIME,
  KEY idx_auction_type (auction_id, transaction_type),
  KEY idx_user_status (user_id, status),
  KEY idx_seller_status (seller_id, status),
  KEY idx_status (status),
  KEY idx_created_at (created_at),
  CONSTRAINT fk_auction FOREIGN KEY (auction_id) REFERENCES wp_posts(ID),
  CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES wp_users(ID),
  CONSTRAINT fk_seller FOREIGN KEY (seller_id) REFERENCES wp_users(ID)
);
```

**Transaction Types**:
- `ENTRY_FEE_CHARGE` - Entry fee collected from bidder
- `COMMISSION_CHARGE` - Commission collected from seller
- `BUYER_PREMIUM_CHARGE` - Premium collected from winning bidder
- `ENTRY_FEE_REFUND` - Entry fee refunded (if bid withdrawn)
- `SELLER_PAYOUT` - Funds paid to seller
- `COMMISSION_RETAIN` - Commission retained by platform
- `FEE_ADJUSTMENT` - Manual adjustment by admin

### Table: `wp_wc_seller_tiers`

```sql
CREATE TABLE wp_wc_seller_tiers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  seller_id BIGINT UNSIGNED NOT NULL UNIQUE,
  tier_level VARCHAR(20) NOT NULL DEFAULT 'STANDARD',
  total_auction_count INT UNSIGNED DEFAULT 0,
  average_rating DECIMAL(3,2),
  total_commission_paid DECIMAL(15,2),
  status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_tier_level (tier_level),
  KEY idx_status (status),
  CONSTRAINT fk_seller FOREIGN KEY (seller_id) REFERENCES wp_users(ID)
);
```

**Tier Levels**:
- `BRONZE` (0-10 auctions, 0% discount)
- `SILVER` (11-50 auctions, 5% commission discount)
- `GOLD` (51-100 auctions, 10% commission discount)
- `PLATINUM` (101+ auctions, 15% commission discount)

### Table: `wp_wc_fee_refunds`

```sql
CREATE TABLE wp_wc_fee_refunds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  refund_id VARCHAR(36) NOT NULL UNIQUE,
  original_transaction_id VARCHAR(36) NOT NULL,
  auction_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  refund_amount DECIMAL(10,2) NOT NULL,
  refund_reason VARCHAR(100),
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  approved_by BIGINT UNSIGNED,
  created_at DATETIME NOT NULL,
  processed_at DATETIME,
  KEY idx_auction_user (auction_id, user_id),
  KEY idx_status (status),
  CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES wp_users(ID),
  CONSTRAINT fk_approver FOREIGN KEY (approved_by) REFERENCES wp_users(ID)
);
```

---

## Fee Models

### Model 1: Platform Commission (Default)

```
Final Bid Amount: $100
Commission Rate: 10%
Commission Fee: $10
Seller Receives: $90
Platform Retains: $10
```

### Model 2: Commission + Buyer Premium

```
Final Bid Amount: $100
Seller Commission: 10% = $10
Buyer Premium: 5% = $5
Seller Receives: $90
Buyer Pays: $105
Platform Retains: $15
```

### Model 3: Entry Fee + Commission

```
Entry Fee: $5 (charged when first bid placed)
Final Bid Amount: $100
Commission: 10% = $10
Seller Receives: $90
Buyer Pays: Entry Fee $5 + Final Bid $100 = $105
Platform Retains: Entry Fee $5 + Commission $10 = $15
```

### Model 4: Tiered Commission

```
Sales Volume | Commission Rate
$0 - $1,000  | 15%
$1,000+      | 12%
$5,000+      | 10%
$10,000+     | 8%

Example (Platinum seller with $20,000 lifetime sales):
Final Bid: $500
Commission: 8% = $40
Seller Receives: $460
Platform Retains: $40
```

---

## API Endpoints

### 1. Get Fee Configuration

**Endpoint**: `GET /wp-admin/admin-ajax.php?action=get_auction_fees&auction_id=456`

**Response**:
```json
{
  "success": true,
  "fees": {
    "entry_fee": 5.00,
    "buyer_premium_rate": 5.0,
    "seller_commission_rate": 10.0,
    "total_estimated_platform_revenue": 15.00
  }
}
```

### 2. Calculate Final Costs

**Endpoint**: `POST /wp-admin/admin-ajax.php?action=calculate_final_costs`

**Request**:
```json
{
  "auction_id": 456,
  "winning_bid": 250.00,
  "nonce": "security_nonce"
}
```

**Response**:
```json
{
  "success": true,
  "breakdown": {
    "winning_bid": 250.00,
    "buyer_premium": 12.50,
    "entry_fee": 0.00,
    "buyer_total": 262.50,
    "seller_gross": 250.00,
    "seller_commission": 25.00,
    "seller_payout": 225.00,
    "platform_revenue": 37.50
  }
}
```

### 3. Admin Fee Settings

**Endpoint**: `GET/POST /wp-admin/admin-ajax.php?action=admin_fee_settings`

**Response**:
```json
{
  "success": true,
  "settings": {
    "global_commission_rate": 10.0,
    "global_buyer_premium_rate": 5.0,
    "entry_fee_enabled": true,
    "entry_fee_amount": 5.00,
    "vip_auction_rate_premium": 2.0,
    "tiered_pricing_enabled": true
  }
}
```

### 4. Generate Fee Report

**Endpoint**: `GET /wp-admin/admin-ajax.php?action=fee_report&start_date=2026-01-01&end_date=2026-03-31`

**Response**:
```json
{
  "success": true,
  "report": {
    "period": "2026-01-01 to 2026-03-31",
    "total_auctions": 150,
    "total_winning_bids": 45000.00,
    "total_fees_collected": 4500.00,
    "entry_fees": 750.00,
    "commissions": 3750.00,
    "buyer_premiums": 2250.00,
    "refunds_issued": 200.00,
    "net_platform_revenue": 4300.00
  }
}
```

---

## Implementation Phases

### Phase 1: Fee Configuration & Storage (Tasks 1-8)

**Deliverable**: Database schema, configuration storage, admin UI

1. **Task 1**: Create `wp_wc_auction_fees` table migration
2. **Task 2**: Create `wp_wc_fee_transactions` table migration
3. **Task 3**: Create `wp_wc_seller_tiers` table migration
4. **Task 4**: Create `wp_wc_fee_refunds` table migration
5. **Task 5**: Implement `FeeConfigRepository` CRUD operations
6. **Task 6**: Create `FeeConfig` value object class
7. **Task 7**: Create admin settings page UI
8. **Task 8**: Database and repository tests

**Acceptance Criteria**:
- ✅ All fee configuration tables created
- ✅ Admin can configure global fees
- ✅ Auction-level fee overrides supported
- ✅ 100% repository test coverage

---

### Phase 2: Fee Calculation Engine (Tasks 9-16)

**Deliverable**: Core fee calculation logic

9. **Task 9**: Implement `FeeCalculationEngine` service
10. **Task 10**: Implement `calculateEntryFee()` method
11. **Task 11**: Implement `calculateCommission()` with tier support
12. **Task 12**: Implement `calculateBuyerPremium()` method
13. **Task 13**: Implement tiered pricing logic
14. **Task 14**: Implement VIP auction premium calculation
15. **Task 15**: Create fee breakdown helper methods
16. **Task 16**: Unit tests for all calculations (100% coverage)

**Acceptance Criteria**:
- ✅ All fee calculations accurate to 2 decimal places
- ✅ Tiered pricing applied correctly
- ✅ VIP auctions charged correctly
- ✅ 100% calculation test coverage

---

### Phase 3: Fee Collection (Tasks 17-23)

**Deliverable**: Fee charging and transaction recording

17. **Task 17**: Implement `FeeCollectionService::collectEntryFee()`
18. **Task 18**: Implement fee charging at first bid
19. **Task 19**: Implement `FeeTransactionRepository`
20. **Task 20**: Implement transaction recording
21. **Task 21**: Implement payment gateway integration (Stripe/PayPal)
22. **Task 22**: Implement refund mechanism
23. **Task 23**: Collection and transaction tests

**Acceptance Criteria**:
- ✅ Entry fees collected when first bid placed
- ✅ Transactions recorded immediately
- ✅ Payment gateway integration working
- ✅ Refunds processed correctly

---

### Phase 4: Fee Settlement (Tasks 24-28)

**Deliverable**: Payout processing and reconciliation

24. **Task 24**: Implement `FeeSettlementService`
25. **Task 25**: Implement seller payout calculation
26. **Task 26**: Implement daily reconciliation job
27. **Task 27**: Integrate with WooCommerce payment methods
28. **Task 28**: Settlement process tests

**Acceptance Criteria**:
- ✅ Sellers paid correctly after auction completion
- ✅ Commission deducted correctly
- ✅ Daily reconciliation accurate
- ✅ Payout status tracked and reported

---

### Phase 5: Reporting & Analytics (Tasks 29-33)

**Deliverable**: Fee reports, analytics, seller/platform dashboards

29. **Task 29**: Implement `FeeReportingService`
30. **Task 30**: Create admin fee dashboard
31. **Task 31**: Create seller payout dashboard
32. **Task 32**: Implement export functionality (CSV/PDF)
33. **Task 33**: Analytics and reporting tests

**Acceptance Criteria**:
- ✅ Admin sees revenue reports
- ✅ Sellers see payout information
- ✅ Reports exportable for accounting
- ✅ Data accurate and auditable

---

## Technical Considerations

### Fee Display & Transparency

All fees must be clearly disclosed to users:
- **Sellers**: Commission rate before listing
- **Bidders**: Entry fee and buyer premium before bidding
- **Winners**: Full breakdown before checkout

### Payment Method Integration

Support multiple payment methods:
- **Credit/Debit Cards** (via Stripe)
- **PayPal**
- **Bank Transfer** (for seller payouts)
- **Wallet** (pre-loaded balance)

### Seller Tier Calculation

Tiers calculated automatically based on:
- Total auctions created
- Total sales volume
- Average seller rating
- Account age and status

Tier increases benefit from commission reductions.

### Refund Policy

Entry fees refunded if:
- Auction cancelled before close
- Auction fraud detected
- Bidder requests (within 24 hours)
- Platform error/issue

Commissions not refunded unless seller disputes.

### Tax Compliance

Platform responsible for:
- Tracking all fees collected
- 1099 reporting (US)
- Sales tax calculation (if applicable)
- Commission reporting to sellers

---

## Testing Strategy

### Unit Tests (100% coverage)

Suites:
- FeeCalculationEngine tests (25 tests)
- FeeCollectionService tests (15 tests)
- TierManagement tests (12 tests)
- Repository tests (20 tests)
- Total: 72+ tests

### Integration Tests

Scenarios:
- E2E fee collection flow
- Multi-tier commission calculation
- Entry fee with refund
- Seller payout with commission
- VIP auction premium calculation
- Total: 18+ tests

### Edge Cases

- Division by zero in percentage calculations
- Rounding differences in large amounts
- Concurrent fee collection
- Payment failure and retry
- Seller tier transition scenarios

---

## Success Metrics

| Metric | Target | Measurement |
|--------|--------|-------------|
| Fee collection rate | >99% | Fees collected / fees due |
| Seller satisfaction | >4/5 | Rating on payout accuracy |
| Platform revenue | Varies | Total fees collected |
| Settlement time | ≤2 days | Payout completion |
| Calculation accuracy | 100% | Fees to nearest cent |

---

## Risk Assessment

| Risk | Probability | Impact | Mitigation |
|-----|-----------|--------|-----------|
| Tax compliance issue | Medium | High | Hire tax consultant, audit logs |
| Payment gateway failure | Low | Medium | Retry logic, fallback method |
| Seller disputes | Medium | Medium | Clear documentation, audit trail |
| Negative user reaction | Medium | Low | Transparent communication, discounts |
| Currency rounding errors | Low | Low | Use PHP BC Math, tests |

---

## Documentation Deliverables

1. **Fee Structure Documentation** - How fees are calculated
2. **Admin Guide** - Configuring fees and tiers
3. **Seller Guide** - Understanding commissions
4. **Buyer Guide** - Entry fees and premiums
5. **API Reference** - Fee calculation endpoints
6. **Accounting Export Guide** - Tax reporting

---

**Prepared by**: Development Team  
**Next Step**: Create GitHub issues from this implementation plan
