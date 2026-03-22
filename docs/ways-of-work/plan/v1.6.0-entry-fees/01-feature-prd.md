# Feature PRD: Entry Fees & Commission Model

**Version:** 1.0 | **Status:** Planning | **Epic:** v1.6.0 | **Release:** Q3 2026

---

## Executive Summary

The Entry Fees & Commission feature enables sellers to charge buyers participation fees (entry fees), and allows site administrators to configure multiple commission models (flat rate, percentage-based, tiered). This unlocks new revenue streams for sellers and the platform while providing flexibility for different auction types and price points. Sellers can choose between three commission models, and the platform automatically calculates and collects fees at settlement.

---

## Business Objectives

### Primary Goals

1. **Enable Seller Revenue**: Allow sellers to charge fees for high-value auctions
2. **Flexible Monetization**: Support multiple fee models (flat, percentage, tiered)
3. **Platform Revenue**: Collect platform commissions from winning bids
4. **Anti-Spam**: Reduce frivolous auction creation via entry fees
5. **Fair Competition**: Allow sellers to offset platform costs

### Success Metrics

- 30% of sellers enable entry fees within 6 months
- Platform commission revenue: $50K+/month by month 6
- Average entry fee collected: $25-$50 per auction
- Entry fee adoption by auction value: 50%+ for $500+ auctions
- User satisfaction: > 4.2/5 on transparency of fee structure

---

## Feature Overview

### Commission Models Supported

#### Model 1: Flat Rate Per Auction
**Fixed fee regardless of final bid:**
- $5 per auction participation
- $10 per auction participation
- $25 per auction participation

**Use Case**: Antiques, collectibles, specialty items with variable values

---

#### Model 2: Percentage of Winning Bid
**Commission as percentage of hammer price:**
- 5% of final winning bid
- 10% of final winning bid
- 15% of final winning bid

**Use Case**: Standard auctions where fee scales with item value

---

#### Model 3: Tiered Commission Model
**Commission depends on final bid amount:**

```
$0 - $100:        5%
$100 - $500:      10%
$500 - $1,000:    12%
$1,000+:          15%
```

**Use Case**: Mixed catalogs where fee adjusts by item value range

---

### Who Pays What?

**Breakdown of Costs:**

```
Winning Bid: $100

Scenario A: Flat Fee ($10)
├─ Seller pays to site: $10
├─ Buyer pays to seller: $100
├─ Seller net receives: $90
└─ Site commission: $10

Scenario B: Percentage (10%)
├─ Seller pays to site: $10 (10% of $100)
├─ Buyer pays to seller: $100
├─ Seller net receives: $90
└─ Site commission: $10

Scenario C: Tiered (5% for $0-$100)
├─ Seller pays to site: $5 (5% of $100)
├─ Buyer pays to seller: $100
├─ Seller net receives: $95
└─ Site commission: $5
```

**Key Point:** Seller always pays, not buyer (fee is seller's business cost)

---

## Detailed Requirements

### Functional Requirements

#### FR-1: Admin Configuration of Commission Models
- Admin can set one of three commission models site-wide
- Admin can override per-seller (e.g., premium sellers get 5% vs 10%)
- Admin can set commission caps (max $100 commission per auction)
- Commission model configurable in Settings > Seller Commission
- Changes apply to new auctions only (existing auctions grandfathered)

#### FR-2: Seller View & Configuration
- Seller sees current commission model for their auctions
- If flat fee: shows "$10 per auction"
- If percentage: shows "10% of winning bid"
- If tiered: shows "5-15% depending on final bid"
- Seller can't change commission model (set by admin)
- Seller can see example: "If item sells for $500, commission would be $50"

#### FR-3: Automated Fee Calculation
- Fee calculated automatically when auction ends
- Formula applied based on final winning bid + commission model
- Fee displayed with breakdown to seller
- Fee cannot exceed configured maximum
- Fee rounded to 2 decimal places

#### FR-4: Fee Payment & Settlement
- Upon auction settlement, fee deducted from seller's payout
- If seller balance insufficient, payment flagged for review
- Fee collected before seller receives payment notification
- Fee recorded in seller's transaction history
- Receipt generated showing: winning bid, fee, seller net payout

#### FR-5: Transparency & Communication
- Seller notified of commission model when creating auction
- Invoice shows: winning bid amount, commission amount, seller net
- Seller dashboard shows total commissions paid (monthly, yearly)
- Seller can export commission report (CSV)
- All fees itemized and explainable in dashboard

#### FR-6: Buyer Visibility (Optional Disclosure)
- Buyer does not pay entry fees directly
- However, seller may choose to absorb fee or price item higher
- Auction page may optionally show "Seller is earning after fees"
- Buyer sees final winning bid only (what they'll pay)

#### FR-7: Support & Exceptions
- Admin can manually adjust commission (in rare cases)
- Admin creates refund ticket (e.g., auction cancelled, buyer returns item)
- Refund process: receipt generated, commission refunded
- Manual adjustments logged in audit trail

#### FR-8: Reporting & Analytics
- Admin dashboard: Total commissions collected (daily, monthly, yearly)
- Breakdown by: commission model, seller tier, auction value range
- Charts: Trend of commission revenue over time
- Forecasting: Projected revenue based on active auctions
- Fraud detection: Unusually high refund rates flagged

### Non-Functional Requirements

#### NFR-1: Performance
- Commission calculation: < 10ms
- Settlement process: < 2 seconds (including fee deduction)
- Commission report generation: < 5 seconds for 1 year of data
- Dashboard loading: < 1 second

#### NFR-2: Scalability
- Handle 100,000+ auctions daily
- Support 10,000+ sellers with active auctions
- Financial reporting on 1M+ historical transactions
- No performance degradation at scale

#### NFR-3: Reliability
- Commission calculation: 99.99% accuracy
- Fee deduction: Always successful (atomic transaction)
- Report generation: Replicable results (audit trail integrity)
- Backup of commission data: Daily, encrypted

#### NFR-4: Security
- Commission data encrypted at rest
- Commission calculations audited (no manual override without logging)
- Financial transactions use PCI DSS standards
- Rate limiting on refund requests (one per day per user)
- Admin actions logged with timestamp, user ID, reason

#### NFR-5: Data Integrity
- Commission amount immutable once recorded
- Associated with specific auction transaction
- Reconciliation ability: Total commissions = sum of individual fees
- Audit trail: Every commission dollar tracked from calculation to collection

---

## Technical Architecture

### Commission Calculation Engine

```php
class CommissionCalculator {
    private $configRepository;
    private $auctionRepository;
    private $logger;
    
    public function calculateCommission(Auction $auction): Commission
    {
        // 1. Get commission model for this seller
        $model = $this->configRepository->getCommissionModel($auction->sellerId);
        
        // 2. Get final bid amount
        $winningBid = $auction->getWinningBidAmount();
        
        // 3. Calculate fee based on model type
        switch ($model->getType()) {
            case 'FLAT':
                $commission = $model->getFlatAmount();
                break;
                
            case 'PERCENTAGE':
                $commission = $winningBid * ($model->getPercentage() / 100);
                break;
                
            case 'TIERED':
                $commission = $this->calculateTieredCommission($winningBid, $model);
                break;
        }
        
        // 4. Apply maximum commission cap (if configured)
        $maxCommission = $model->getMaximumCommission();
        if ($maxCommission && $commission > $maxCommission) {
            $commission = $maxCommission;
        }
        
        // 5. Round to 2 decimal places
        $commission = round($commission, 2);
        
        // 6. Create and record commission object
        $commissionRecord = Commission::create(
            auctionId: $auction->getId(),
            sellerId: $auction->getSellerId(),
            winningBid: $winningBid,
            model: $model->getType(),
            amount: $commission
        );
        
        // 7. Log for audit trail
        $this->logger->info('Commission calculated', [
            'auction_id' => $auction->getId(),
            'amount' => $commission,
            'model' => $model->getType()
        ]);
        
        return $commissionRecord;
    }
    
    private function calculateTieredCommission(float $bid, CommissionModel $model): float
    {
        $tiers = $model->getTiers(); // [range=>'0-100', rate=>5, ...]
        
        foreach ($tiers as $tier) {
            if ($bid >= $tier['min'] && $bid < $tier['max']) {
                return $bid * ($tier['rate'] / 100);
            }
        }
        
        return 0;  // Shouldn't reach here if tiers configured correctly
    }
}
```

---

### Database Schema

#### Table: wp_wc_auction_commissions

```sql
CREATE TABLE wp_wc_auction_commissions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    auction_id BIGINT NOT NULL UNIQUE,
    seller_id BIGINT NOT NULL,
    winning_bid DECIMAL(10,2) NOT NULL,
    commission_model VARCHAR(50) NOT NULL,  -- FLAT, PERCENTAGE, TIERED
    rate_percentage DECIMAL(5,2) NULL,      -- For PERCENTAGE model
    flat_amount DECIMAL(10,2) NULL,         -- For FLAT model
    commission_amount DECIMAL(10,2) NOT NULL,
    max_applied BOOLEAN DEFAULT FALSE,      -- Was maximum cap applied?
    status ENUM('calculated', 'pending_payment', 'paid', 'refunded', 'disputed') DEFAULT 'calculated',
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL,
    refunded_at TIMESTAMP NULL,
    refund_reason VARCHAR(255) NULL,
    notes VARCHAR(500) NULL,
    
    KEY idx_seller_id (seller_id),
    KEY idx_auction_id (auction_id),
    KEY idx_status (status),
    KEY idx_calculated_at (calculated_at),
    KEY idx_paid (paid_at),
    FOREIGN KEY fk_auction (auction_id) REFERENCES wp_wc_auction_items(ID),
    FOREIGN KEY fk_seller (seller_id) REFERENCES wp_users(ID)
);
```

#### Table: wp_wc_commission_models

```sql
CREATE TABLE wp_wc_commission_models (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    type ENUM('FLAT', 'PERCENTAGE', 'TIERED') NOT NULL,
    flat_amount DECIMAL(10,2) NULL,
    percentage_rate DECIMAL(5,2) NULL,
    maximum_commission DECIMAL(10,2) NULL,
    default_model BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_by BIGINT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY idx_active (is_active),
    KEY idx_default (default_model),
    FOREIGN KEY fk_created_by (created_by) REFERENCES wp_users(ID)
);
```

#### Table: wp_wc_seller_commission_overrides

```sql
CREATE TABLE wp_wc_seller_commission_overrides (
    id INT PRIMARY KEY AUTO_INCREMENT,
    seller_id BIGINT NOT NULL UNIQUE,
    commission_model_id INT NOT NULL,
    override_reason VARCHAR(500),
    created_by BIGINT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY fk_seller (seller_id) REFERENCES wp_users(ID),
    FOREIGN KEY fk_model (commission_model_id) REFERENCES wp_wc_commission_models(id),
    FOREIGN KEY fk_created_by (created_by) REFERENCES wp_users(ID)
);
```

---

### Settlement Integration

**When auction ends and winner determined:**

```php
// In AuctionCoordinator::settle($auction)

public function settle(Auction $auction): void {
    // 1. Determine winner
    $winner = $this->determineWinner($auction);
    
    // 2. Create invoice for buyer
    $invoice = $this->createInvoice($auction, $winner);
    
    // 3. Calculate seller commission
    $commission = $this->commissionCalculator->calculateCommission($auction);
    $this->commissionRepository->save($commission);
    
    // 4. Calculate seller payout (winning bid - commission)
    $sellerPayout = $auction->getWinningBid() - $commission->amount;
    
    // 5. Create seller transaction record
    $transaction = SellerTransaction::create(
        sellerId: $auction->getSellerId(),
        type: 'AUCTION_PAYOUT',
        grossAmount: $auction->getWinningBid(),
        commission: -$commission->amount,
        netAmount: $sellerPayout,
        auctionId: $auction->getId()
    );
    $this->transactionRepository->save($transaction);
    
    // 6. Deduct commission from seller balance
    $seller = $this->userRepository->find($auction->getSellerId());
    $seller->availableBalance -= $commission->amount;
    $this->userRepository->save($seller);
    
    // 7. Send notifications (buyer invoice, seller payout confirmation)
    $this->notificationService->notifyBuyer($winner, $invoice);
    $this->notificationService->notifySeller($seller, $transaction);
    
    // 8. Log settlement
    $this->logger->info('Auction settled', [
        'auction_id' => $auction->getId(),
        'winning_bid' => $auction->getWinningBid(),
        'commission' => $commission->amount,
        'seller_payout' => $sellerPayout
    ]);
}
```

---

## User Experience Details

### Admin Configuration: Setting Commission Models

**Admin page - Settings > Auction Commission:**

```
COMMISSION MODELS

Current Model: Percentage

( ) Flat Rate per Auction
    ├─ Fee Amount: [________] $
    └─ [Set as Default]

( ) Percentage of Winning Bid
    ├─ Percentage: [________] %
    └─ [Set as Default]

(●) Tiered Commission Model
    ├─ Tier 1: $0     - $100   @ [__]%
    ├─ Tier 2: $100   - $500   @ [__]%
    ├─ Tier 3: $500   - $1000  @ [__]%
    ├─ Tier 4: $1000  - $5000  @ [__]%
    ├─ Tier 5: $5000+ @ [__]%
    └─ [Set as Default]

OPTIONAL SETTINGS:
├─ Maximum Commission Cap: [________] $
│  (Leave blank for no limit)
└─ [Save Settings] [Preview]
```

---

### Seller Dashboard: Commission Overview

**Seller sees in dashboard:**

```
AUCTION COMMISSION SUMMARY

Commission Model: 10% of Winning Bid
(Set by site administrator)

THIS MONTH:
├─ Auctions Held: 12
├─ Total Auction Value: $1,500
├─ Total Commission Paid: $150
└─ Seller Net: $1,350

LIFETIME:
├─ Total Auctions: 87
├─ Total Commission Paid: $2,340
└─ Average Commission: ~$27/auction

Auction Breakdown:
────────────────────────────────────
Auction Name | Winning Bid | Commission
────────────────────────────────────
Rare Vase    | $300        | $30
Digital Camera | $450       | $45
Book Set     | $100        | $10
────────────────────────────────────

[View All] [Download Report]
```

---

### Seller Notification: Settlement with Commission

**Email seller receives when auction ends:**

```
Subject: Auction Settled - "Rare Vase" - $300

Hi Maria,

Your auction has ended and a winner has been determined.

AUCTION: "Rare Vase - Ming Dynasty"
WINNING BID: $300.00
COMMISSION (10%): -$30.00
YOUR PAYOUT: $270.00

Payment will be processed to your account within 3-5 business days.

BREAKDOWN:
├─ Winning Bid.............. $300.00
├─ Commission (10%)...... -$30.00
└─ Your Net Payout......... $270.00

[View Details] [Account Balance] [Help]
```

---

### Invoice: What Buyer Sees

**Buyer invoice doesn't show commission:**

```
AUCTION INVOICE

Item: "Rare Vase - Ming Dynasty"
Winning Bid: $300.00
Auction ID: #12345

Amount Due: $300.00

[Pay Now] [View Auction]

---
Note: This is your purchase amount.
Seller commission is not shown here.
```

**Seller sees commission separately in their settlement notification**

---

## User Stories for Implementation

### US-1: Admin Sets Commission Model
**As an** administrator
**I want to** configure how commissions are calculated site-wide
**So that** I can generate revenue while keeping fees transparent to sellers

**Acceptance Criteria:**
- [ ] Admin can choose from 3 commission models
- [ ] Can set flat fee, percentage rate, or tiered rates
- [ ] Can set maximum commission cap
- [ ] Settings saved to database
- [ ] New auctions use configured model

---

### US-2: Seller Creates Auction with Commission
**As a** seller
**I want to** see what commission I'll pay for my auction
**So that** I can factor fees into my pricing strategy

**Acceptance Criteria:**
- [ ] Auction creation form shows current commission model
- [ ] Example calculation shown (e.g., "If item sells for $500, you'll pay $50")
- [ ] Commission model can't be changed (set by admin)
- [ ] Seller understands commission before listing

---

### US-3: Commission Calculated & Deducted on Settlement
**As the** auction system
**I want to** automatically calculate and deduct commission when auction ends
**So that** commissions are collected reliably and accurately

**Acceptance Criteria:**
- [ ] Commission calculated based on final winning bid
- [ ] Formula matches configured commission model
- [ ] Commission deducted from seller payout
- [ ] Transaction recorded with commission detail
- [ ] Seller notification shows net payout (winning bid - commission)

---

### US-4: Seller Sees Commission History & Reports
**As a** seller
**I want to** view my commission history and generate reports
**So that** I can track costs and plan pricing

**Acceptance Criteria:**
- [ ] Dashboard shows total commissions paid (month, year, lifetime)
- [ ] Auction-by-auction breakdown available
- [ ] Reports can be exported (CSV)
- [ ] Monthly and yearly summaries visible
- [ ] Can filter by auction type, date range

---

### US-5: Admin Views Commission Analytics
**As an** administrator
**I want to** see commission revenue trends and forecasts
**So that** I can monitor platform profitability

**Acceptance Criteria:**
- [ ] Admin dashboard shows total commissions collected
- [ ] Breakdown by commission model type
- [ ] Charts showing revenue trends (daily, monthly, yearly)
- [ ] Forecast based on active auctions
- [ ] Can export commission report for accounting

---

## Dependencies & Constraints

### Dependencies
- Existing auction & settlement system (working order)
- User account balances & payment system
- Seller payout infrastructure
- Notification system (email to sellers)

### Constraints
- Commission calculation must be atomic (no partial charges)
- Historical commission data immutable (audit trail)
- Commission applies only to winning bids (not cancelled auctions)
- Changes to commission model grandfathered (don't affect old auctions)

### Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| Commission calculation error | Low | High | Unit tests, reconciliation reports, alerts |
| Seller insufficient balance for commission | Medium | Medium | Flag payment, admin review, partial payment options |
| User confusion about fee structure | Medium | Medium | Clear UI, help docs, FAQs, in-app tooltips |
| Commission refund disputes | Medium | Low | Audit trail, dispute process, admin override |
| Tax implications uncommunicated | Medium | High | Legal review, seller notifications, documentation |

---

## Release Strategy

- **Phase 1 (Week 1-2)**: 
  - Implement commission calculation
  - Test on staging (100+ auctions)
  - Admin training
  
- **Phase 2 (Week 3)**: 
  - Beta with 20 sellers
  - Monitor commission accuracy
  - Gather seller feedback
  
- **Phase 3 (Week 4+)**: 
  - Full rollout to all sellers
  - Run reconciliation reports
  - Monitor compliance

---

## Questions for Clarification

1. What percentage rates should be pre-configured by default (5%, 10%, 15%)?
2. Should there be different commission models for different seller tiers (premium vs standard)?
3. Should buyers be informed about the commission model in any way?
4. What's the policy if a buyer returns an item - should commission be refunded?
5. Should the platform absorb commission on promotional auctions, or always charge?
