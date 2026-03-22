# Complete Requirements: WooCommerce Auction Auto-Bidding & Extensions

**Current Version**: 1.4.0  
**Next Version**: 1.5.0  
**Date**: March 22, 2026  
**Status**: Requirements finalized, ready for implementation phases

---

## 📋 Requirements Overview

This document consolidates ALL requirements across 5 feature areas:
1. Auto-bidding v1.0 (open auctions)
2. Sealed bids v1.1 (single highest bid)
3. Entry fees v1.4.0
4. Winner commission v1.4.0
5. Post-auction processing v1.4.0
6. Email notifications v1.4.0

**Cross-document references**:
- Auto-bidding v1.0: See `/plan/feature-auto-bidding-v1.0.md`
- Sealed bids v1.1: See `/plan/feature-auto-bidding-sealed-bids-1.1.md`
- Entry fees, commission, post-auction: See `/plan/feature-entry-fees-commission-post-auction-1.0.md`

---

## 1️⃣ CORE AUTO-BIDDING (v1.0 - Existing)

### REQ-AUTO-001 to REQ-AUTO-009
**Status**: Planned (prerequisite for all other features)
**File**: `/plan/feature-auto-bidding-v1.0.md`

**Summary**:
- Progressive max bid proxy bidding
- Automatic incremental escalation as new bids arrive
- Bid increment by range
- Reserve price enforcement
- Starting bid configuration
- AJAX real-time bidding
- 24+ unit tests

---

## 2️⃣ SEALED BIDS (v1.1 - New Feature)

### REQ-SEALED-001 to REQ-SEALED-008

| ID | Requirement | Details |
|---|---|---|
| REQ-SEALED-001 | Configure per-product whether auction is sealed or open | Toggle in product metabox, admin UI |
| REQ-SEALED-002 | Set sealed bid reveal date/time per auction | Datepicker + timepicker (UTC) in product settings |
| REQ-SEALED-003 | Collect max bids during sealed period (no display) | Max bid field visible but hidden from history |
| REQ-SEALED-004 | Hide current bid and history during sealed period | Show "🔒 SEALED BID IN PROGRESS" instead |
| REQ-SEALED-005 | Prevent manual bid display during sealed period | Accept bids via AJAX but don't display history |
| REQ-SEALED-006 | Process accumulated bids retroactively at reveal | Single highest max bid wins (simplified model) |
| REQ-SEALED-007 | Auto-transition from sealed → open after reveal | WordPress cron trigger |
| REQ-SEALED-008 | Display reveal countdown timer during sealed period | e.g., "Reveal in 2 days, 4 hours, 23 minutes" |

**Algorithm** (Simplified):
- Get all max_bids sorted DESC
- Winner = bidder with highest max_bid
- Insert single auto-bid at winner's max_bid amount
- (NOT cascading escalation like open auctions)

**Database Changes**:
```sql
ALTER TABLE wp_WcAuction_auction ADD COLUMN (
  is_sealed_bid TINYINT(1) DEFAULT 0,
  sealed_reveal_datetime DATETIME DEFAULT NULL,
  sealed_reveal_processed TINYINT(1) DEFAULT 0,
  sealed_max_bids_collected INT DEFAULT 0
);

CREATE TABLE wp_WcAuction_sealed_bid_audit (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT,
  reveal_datetime DATETIME,
  auto_bids_count INT,
  final_bid_amount DECIMAL(10,2),
  processed_at DATETIME,
  status ENUM('success', 'error'),
  error_message VARCHAR(500)
);
```

---

## 3️⃣ ENTRY FEES (v1.4.0 - New Feature)

### REQ-ENTRY-001 to REQ-ENTRY-008

| ID | Requirement | Details |
|---|---|---|
| REQ-ENTRY-001 | Optional per-auction entry fee | Configurable toggle in metabox |
| REQ-ENTRY-002 | Minimum entry fee $1.00 | Enforced validation |
| REQ-ENTRY-003 | Entry fee amount settable per auction | Any amount ≥ $1.00 |
| REQ-ENTRY-004 | Entry fee separate from auction starting bid | Entry fee + then bid starts at start_price |
| REQ-ENTRY-005 | Entry fee collected before first bid | Payment required, then can bid |
| REQ-ENTRY-006 | Entry fee refundable if reserve not met | Refund when auction unpaid or cancelled |
| REQ-ENTRY-007 | Display entry fee transparently on auction page | Show "Entry Fee: $X.XX" above bid section |
| REQ-ENTRY-008 | Audit trail of entry fee payments | Store user, amount, date, refund status |

**Database Changes**:
```sql
ALTER TABLE wp_WcAuction_auction ADD COLUMN (
  entry_fee_enabled TINYINT(1) DEFAULT 0,
  entry_fee_amount DECIMAL(10,2) DEFAULT NULL,
  entry_fee_description VARCHAR(500) DEFAULT NULL
);

CREATE TABLE wp_WcAuction_entry_fees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT,
  user_id INT,
  fee_amount DECIMAL(10,2),
  status ENUM('pending', 'paid', 'refunded'),
  paid_date DATETIME,
  refund_date DATETIME,
  refund_reason VARCHAR(500),
  created_at DATETIME DEFAULT NOW(),
  UNIQUE KEY (product_id, user_id)
);
```

---

## 4️⃣ WINNER COMMISSION (v1.4.0 - New Feature)

### REQ-COMM-001 to REQ-COMM-007

| ID | Requirement | Details |
|---|---|---|
| REQ-COMM-001 | Commission charged to auction winner at checkout | Line item in order |
| REQ-COMM-002 | Commission configurable globally | Module settings panel |
| REQ-COMM-003 | Support 3 commission models | % of bid, flat fee, hybrid (% + min) |
| REQ-COMM-004 | Display commission explanation link on auction page | Tooltip/modal with details |
| REQ-COMM-005 | Commission explanation editable in settings | Admin can customize explanation text |
| REQ-COMM-006 | Show calculated commission before checkout | Display on auction page + checkout |
| REQ-COMM-007 | Commission applied as line item in order | Order includes commission line |

**Module Settings Config Structure**:
```php
[
  'enable_commission' => true,
  'commission_model' => 'percentage', // 'percentage', 'flat', 'hybrid'
  'commission_percentage' => 5.0,     // for percentage or hybrid
  'commission_flat_fee' => 0,         // for flat or hybrid
  'commission_minimum' => 1.00,       // for hybrid or percentage w/ minimum
  'commission_explanation' => 'A 5% buyer\'s premium will be added to your final bid',
  'commission_explanation_link_text' => 'Learn about buyer\'s premium'
]
```

**Calculation Examples**:
1. **Percentage**: Final bid $100 → 5% → Commission $5.00
2. **Flat**: Final bid $50 → $2.50 flat → Commission $2.50
3. **Hybrid**: Final bid $30 → (5% + $1 min) → Commission max($1.50, $1.00) = $1.50

---

## 5️⃣ POST-AUCTION PROCESSING (v1.4.0 - New Feature)

### REQ-POST-001 to REQ-POST-007

| ID | Requirement | Details |
|---|---|---|
| REQ-POST-001 | Auto-generate pending payment order after auction ends | Create WC Order immediately on auction end |
| REQ-POST-002 | Order contains auction item + final bid + commission + fees | Line items: item @bid + commission + entry_fee (if any) |
| REQ-POST-003 | Send order email to winner with payment link | WordPress standard order email template |
| REQ-POST-004 | Auto-charge winner Stripe card if enabled | Optional, requires Stripe setup |
| REQ-POST-005 | Log payment attempt (success/failure) | Audit trail in post_auction_log table |
| REQ-POST-006 | Handle unpaid auctions: notify 2nd bidder OR reschedule | Two strategies, configurable |
| REQ-POST-007 | Reschedule unpaid auctions with new start time | Interval: next week, month, or custom days |

**Database Changes**:
```sql
CREATE TABLE wp_WcAuction_post_auction_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT,
  auction_winner_id INT,
  order_id INT,
  final_bid_amount DECIMAL(10,2),
  commission_amount DECIMAL(10,2),
  entry_fee_amount DECIMAL(10,2),
  total_due DECIMAL(10,2),
  auto_charge_enabled TINYINT(1),
  auto_charge_status ENUM('pending', 'success', 'failed'),
  auto_charge_error VARCHAR(500),
  unpaid_action ENUM('notify_2nd', 'reschedule'),
  reschedule_datetime DATETIME,
  created_at DATETIME DEFAULT NOW()
);

ALTER TABLE wp_WcAuction_auction ADD COLUMN (
  order_id INT DEFAULT NULL,
  payment_status ENUM('pending', 'paid', 'failed', 'unpaid') DEFAULT 'pending'
);
```

**Module Settings**:
```php
[
  'auto_generate_order' => true,      // Auto-create WC order on auction end
  'auto_charge_enabled' => false,     // Attempt auto-charge via Stripe
  'unpaid_action' => 'notify_2nd',    // OR 'reschedule'
  'reschedule_interval_days' => 7,    // If reschedule option
  'admin_email_on_unpaid' => true     // Admin email on unpaid
]
```

---

## 6️⃣ EMAIL NOTIFICATIONS (v1.4.0 - New Feature)

### REQ-NOTIF-001 to REQ-NOTIF-008

| ID | Requirement | Details |
|---|---|---|
| REQ-NOTIF-001 | New bid placed notification | To all previous bidders (frequency limited) |
| REQ-NOTIF-002 | User outbid notification | Email + offer to bid higher |
| REQ-NOTIF-003 | Auction ending soon warnings | 24hr, 1hr, 10min before end |
| REQ-NOTIF-004 | Auction ended notification | To winner + all other bidders (informational) |
| REQ-NOTIF-005 | Entry fee paid confirmation | Receipt-style email |
| REQ-NOTIF-006 | Unpaid auction notification | Admin and/or 2nd bidder |
| REQ-NOTIF-007 | Configurable per notification type | Admin can enable/disable each |
| REQ-NOTIF-008 | Customizable email templates | Include filters/hooks for modification |

**Email Types** (6 templates):
1. **new_bid.html**: "New bid placed in [auction name] - [your max bid] for [product]"
2. **outbid.html**: "You've been outbid in [auction] - current bid $X"
3. **ending_soon_24h.html**: "Auction [name] ending in 24 hours"
4. **ending_soon_1h.html**: "Auction [name] ending in 1 hour"
5. **ending_soon_10m.html**: "Auction [name] ending in 10 minutes"
6. **auction_ended.html**: "Auction concluded - [winner] won at $X"
7. **entry_fee_paid.html**: "Entry fee $X paid for [auction]"
8. **unpaid_auction.html** (admin): "Payment not received for [auction]"

**Module Settings**:
```php
[
  'notifications' => [
    'new_bid' => true,
    'outbid' => true,
    'ending_soon_24h' => true,
    'ending_soon_1h' => true,
    'ending_soon_10m' => false,  // optional
    'auction_ended' => true,
    'entry_fee_paid' => true,
    'unpaid_auction' => true
  ],
  'new_bid_frequency_limit_seconds' => 300,  // max 1 per 5 min per user
  'include_unsubscribe_link' => true,
  'custom_explanation' => 'Manage notification preferences in your account'
]
```

**Frequency Limiting**:
- New bid notifications: Max 1 per user per 5 minutes
- Outbid notifications: Always sent (no limit)
- Ending soon: Once at each interval (24h, 1h, 10m)
- Ended: Once per auction
- Entry fee: Once per payment
- Unpaid: Once per event

---

## 7️⃣ CART & CHECKOUT INTEGRATION (v1.4.0 - New Feature)

### REQ-CART-001 to REQ-CART-005

| ID | Requirement | Details |
|---|---|---|
| REQ-CART-001 | Replace "Buy Now Price" with "Add Regular Item" button | Button adds parent product to cart at full retail price |
| REQ-CART-002 | Configure parent product link in auction metabox | Selector to choose regular product |
| REQ-CART-003 | Display commission in checkout summary | Line item: "Buyer's Premium: $X.XX" |
| REQ-CART-004 | Handle entry fee + commission in order | Both line items if applicable |
| REQ-CART-005 | Document entry fee refund process | Clear info on when fees refunded |

**Button Behavior**:
```
[Add Regular Item to Cart] button on auction page
  ↓
User clicks → add parent_product_id to WC cart
  ↓
Redirect to cart (WooCommerce standard)
  ↓
Entry fee NOT applied (that's for auction participation only)
  ↓
Commission NOT applied (that's for winning auction only)
```

---

## 8️⃣ DATABASE SCHEMA SUMMARY

### New Tables

| Table | Purpose |
|-------|---------|
| `wp_WcAuction_entry_fees` | Track entry fee payments & refunds |
| `wp_WcAuction_sealed_bid_audit` | Audit sealed bid reveal processing |
| `wp_WcAuction_post_auction_log` | Track post-auction order generation, payment attempts |

### Modified Tables

| Table | Columns Added | Purpose |
|-------|---|---|
| `wp_WcAuction_auction` | `entry_fee_enabled`, `entry_fee_amount`, `entry_fee_description` | Entry fee configuration |
| `wp_WcAuction_auction` | `is_sealed_bid`, `sealed_reveal_datetime`, `sealed_reveal_processed`, `sealed_max_bids_collected` | Sealed bid config & status |
| `wp_WcAuction_auction` | `order_id`, `payment_status` | Post-auction order linkage |

---

## 9️⃣ EFFORT & TIMELINE

### By Version

| Version | Features | Hours | Prerequisites |
|---------|----------|-------|---|
| v1.0 | Auto-bidding, starting bid, increment, reserve | 16-24 | None |
| v1.1 | Sealed bids (single highest bid) | 20-28 | v1.0 complete |
| v1.4.0 | Entry fees, commission, post-auction, notifications | 46 | v1.0 complete |
| v1.5.0 | Sealed bids integrated | (included in v1.1) | v1.1 complete |
| **TOTAL** | **All features** | **82-98** | **Sequential phases** |

### By Feature (New Components in v1.4.0)

| Feature | Classes | Tests | Hours |
|---------|---------|-------|-------|
| Entry Fees | 1 | 8 | 10 |
| Commission | 1 | 6 | 8 |
| Post-Auction | 1 | 10 | 12 |
| Notifications | 1 | 12 | 10 |
| Cart Integration | 1 | 4 | 6 |
| Testing (all) | — | 50+ | 8+ |
| **Total** | **5 classes** | **50+ tests** | **46 hours** |

---

## 🎯 Testing Strategy

### Coverage Requirements
- **Unit tests**: ≥95% code coverage for all new classes
- **Integration tests**: End-to-end auction flows
- **Edge cases**: 
  - Entry fee refunds
  - Commission calculation (all 3 models)
  - Auto-charge failures
  - Sealed bid reveal timing
  - Email delivery failures (graceful handling)

### Test Files
- `tests/unit/class.yith-wcact-entry-fees.test.php` (8 tests)
- `tests/unit/class.yith-wcact-commission.test.php` (6 tests)
- `tests/unit/class.yith-wcact-post-auction.test.php` (10 tests)
- `tests/unit/class.yith-wcact-notifications.test.php` (12 tests)
- `tests/integration/sealed-bid-reveal.test.php` (14 tests)
- Plus existing auto-bidding tests: 24 tests

**Total**: 74+ unit/integration tests

---

## ✅ Dependencies & Constraints

### Build Dependencies
- PHP 7.3+
- WooCommerce 5.0+
- WordPress 5.6+
- Stripe API (optional, for auto-charge)

### Implementation Constraints
- **Sealed bids**: Can only be enabled before auction starts
- **Entry fees**: Minimum $1.00, refundable only if auction unpaid
- **Commission**: Not applied to "Add Regular Item" cart button
- **Post-auction**: Requires order generation to succeed before notifying winner
- **Notifications**: Frequency limits prevent email spam (max 1 per 5 min for new bid)
- **Backward compatibility**: All features optional, don't break existing auctions

### Git & Versioning
- **Branches**: feature/entry-fees, feature/commission, feature/post-auction, feature/notifications
- **Semantic versioning**: v1.4.0 includes all new features
- **Migration scripts**: DB schema changes automated (idempotent checks)
- **.gitignore**: Exclude vendor/, node_modules/, build/

---

## 📚 Documentation Artifacts

### Implementation Plans
- `/plan/feature-auto-bidding-v1.0.md` (32 tasks, v1.0)
- `/plan/feature-auto-bidding-sealed-bids-1.1.md` (35 tasks, v1.1)
- `/plan/feature-entry-fees-commission-post-auction-1.0.md` (33 tasks, v1.4.0)

### Execution Checklists
- `/SEALED_BID_EXECUTION_CHECKLIST.md` (35 checkboxes, v1.1)
- `/ENTRY_FEES_COMMISSION_EXECUTION_CHECKLIST.md` (33 checkboxes, v1.4.0)

### Architecture & Design
- `/Project Docs/COMPLETE_VISION.md` (roadmap, feature matrix)
- `/Project Docs/IMPLEMENTATION_GUIDE.md` (technical details)
- `/Project Docs/INDEX.md` (documentation master index)
- `/Project Docs/FEATURE_SCOPE_UPDATE.md` (this session's decisions)
- `/Project Docs/auto-bidding-sequence-diagram.puml` (PlantUML diagrams)

---

## 🔄 Next Actions

1. **Begin Phase 1** (Auto-bidding v1.0)
   - Create `includes/class.yith-wcact-auction-auto-bid.php`
   - Create starting bid, increment, reserve price classes
   - Write 24 unit tests

2. **Track Progress**
   - Use execution checklists (checkbox format)
   - Update git commits with REQ-* references
   - Update implementation plan status to "In Progress" → "Complete"

3. **Quality Gates**
   - All tests pass (100% coverage required)
   - No regressions to existing code
   - PHPDoc complete for all new classes
   - Code review approval before merge

---

**Document Status**: ✅ FINAL - Ready for implementation  
**Last Updated**: March 22, 2026  
**Approved By**: Feature scope finalized with user  
