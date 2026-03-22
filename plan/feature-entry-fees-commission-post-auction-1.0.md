---
goal: Feature Additions and Modifications - Entry Fees, Commissions, Post-Auction Processing
version: 1.0
date_created: 2026-03-22
owner: Development Team
status: 'Planned'
tags: [feature, auction, entry-fee, commission, post-auction, notifications]
---

# Implementation Plan: Auction Entry Fees, Commissions & Post-Auction Processing

![Status: Planned](https://img.shields.io/badge/status-Planned-blue)

## Executive Summary

Add entry fee system, winner commission charging, post-auction order automation, and email notifications to the auction plugin. Entry fees are optional per-auction and allow control of auction participation. Winner commissions are charged at checkout with clear explanation. Post-auction processing automates order generation and payment collection. Future enhancement: Auction sets that group multiple items under a single entry fee.

---

## 1. Requirements & Constraints

### Auction Entry Fees (REQ-ENTRY-*)

- **REQ-ENTRY-001**: Make entry fees optional per-auction (configurable toggle)
- **REQ-ENTRY-002**: Minimum entry fee: $1.00 USD (or store currency equivalent)
- **REQ-ENTRY-003**: Entry fee amount settable per auction (no maximum limit)
- **REQ-ENTRY-004**: Entry fee is separate from auction: bidding starts at start_price
- **REQ-ENTRY-005**: Entry fee collected before first bid allowed
- **REQ-ENTRY-006**: Entry fee refundable only if auction doesn't reach reserve
- **REQ-ENTRY-007**: Display entry fee transparently on auction page
- **REQ-ENTRY-008**: Store entry fee collections per user per auction (audit trail)

### Winner Commission/Buyer's Premium (REQ-COMM-*)

- **REQ-COMM-001**: Commission charged to auction winner at checkout
- **REQ-COMM-002**: Commission configurable globally (module-level settings)
- **REQ-COMM-003**: Support 3 commission models:
  - Percentage: (e.g., "5% of final bid")
  - Flat fee: (e.g., "$2.50 per auction")
  - Hybrid: (e.g., "5% + $1.00 minimum")
- **REQ-COMM-004**: Display commission explanation link on auction page
- **REQ-COMM-005**: Commission explanation editable in module settings
- **REQ-COMM-006**: Show calculated commission amount before checkout
- **REQ-COMM-007**: Commission applied line item in checkout/order

### Post-Auction Processing (REQ-POST-*)

- **REQ-POST-001**: Auto-generate pending payment order after auction ends
- **REQ-POST-002**: Order contains auction item at final bid amount + commission + any fees
- **REQ-POST-003**: Send order email to winner with payment link
- **REQ-POST-004**: Auto-charge winner's card (Stripe integration) if enabled
- **REQ-POST-005**: Handle unpaid auctions: notify 2nd highest bidder or reschedule
- **REQ-POST-006**: Rescheduled auction inherits configuration from original
- **REQ-POST-007**: Audit log all post-auction events (order created, charged, rescheduled)

### Email Notifications (REQ-NOTIF-*)

- **REQ-NOTIF-001**: New bid placed → notify all previous bidders (optional frequency limit)
- **REQ-NOTIF-002**: User outbid → notify outbid user with option to bid higher
- **REQ-NOTIF-003**: Auction ending soon → notify all bidders (24h, 1h, 10m before end)
- **REQ-NOTIF-004**: Auction ended → notify winner + any other bidders (informational)
- **REQ-NOTIF-005**: Entry fee payment → confirmation email
- **REQ-NOTIF-006**: Admin notification of unpaid auction
- **REQ-NOTIF-007**: Configurable: enable/disable each notification type globally
- **REQ-NOTIF-008**: Configurable: email from name, unsubscribe links, customizable templates

### Auction Sets (Future, REQ-SET-*)

- **REQ-SET-001**: Group multiple auction items into a "set"
- **REQ-SET-002**: Single entry fee covers participation in all set items
- **REQ-SET-003**: Bidders can participate in entire set or individual items
- **REQ-SET-004**: Future phase (not in v1.4-1.5)

### Non-Functional Requirements

- **PERF-001**: Order generation < 1s after auction ends
- **SECURITY-001**: Entry fees use WooCommerce standard payment flow (no raw CC handling)
- **AUDIT-001**: All entry fees, commissions, charges logged for compliance

### Constraints

- **CON-001**: Entry fees and commissions are module configuration, not hard-coded
- **CON-002**: All monetary amounts in store currency
- **CON-002**: Commission calculation performed at order time (not at bid time)
- **CON-004**: Email templates extensible (filters/hooks for customization)
- **CON-005**: Backward compatible: existing auctions work without entry fee/commission

---

## 2. Architecture & Data Model

### Database Schema Changes

#### Table: `wp_WcAuction_auction` (Additional Columns)

```sql
-- Entry Fee columns
ALTER TABLE wp_WcAuction_auction ADD COLUMN (
  entry_fee_enabled TINYINT(1) DEFAULT 0 COMMENT 'True if entry fee required',
  entry_fee_amount DECIMAL(10,2) DEFAULT 0 COMMENT 'Entry fee in store currency',
  entry_fee_collected INT DEFAULT 0 COMMENT 'Count of entry fees paid'
);

-- Post-auction status columns
ALTER TABLE wp_WcAuction_auction ADD COLUMN (
  auction_result_order_id BIGINT DEFAULT NULL COMMENT 'FK to wp_posts order created at auction end',
  winner_payment_status ENUM('pending', 'paid', 'failed') DEFAULT 'pending',
  winner_charged_at DATETIME DEFAULT NULL COMMENT 'When auto-charge attempted'
);
```

#### New Table: `wp_WcAuction_entry_fees`

Track entry fee payments separately for audit:

```sql
CREATE TABLE wp_WcAuction_entry_fees (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  product_id BIGINT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  payment_id VARCHAR(100) UNIQUE COMMENT 'FK to WC payment transaction',
  status ENUM('pending', 'completed', 'refunded') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  refund_reason VARCHAR(255) DEFAULT NULL,
  KEY idx_product_user (product_id, user_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### New Table: `wp_WcAuction_post_auction_log`

Track post-auction events:

```sql
CREATE TABLE wp_WcAuction_post_auction_log (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  product_id BIGINT NOT NULL,
  event_type ENUM('order_created', 'charge_attempted', 'charge_success', 
                  'charge_failed', 'rescheduled', '2nd_bidder_notified') NOT NULL,
  details JSON COMMENT 'Event details: {order_id, amount, error, bidder_id}',
  triggered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status ENUM('success', 'error') DEFAULT 'success',
  error_message TEXT DEFAULT NULL,
  KEY idx_product_event (product_id, event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Module Configuration (Settings)

**Product Metabox** (`panel/product-auction-settings.php`):
```
Entry Fee Settings:
  ☐ Require entry fee
  [Minimum: $1.00]
  Entry fee: $_____ 

Commission Settings:
  Commission Model: [Percentage] [Flat Fee] [Percentage + Flat]
  If Percentage: __% of final bid
  If Flat: $____
  If Hybrid: __% + $____ minimum
  Commission Explanation: [Link "What is this?"] [Edit...]
  
Post-Auction Settings:
  ☐ Auto-generate pending order
  ☐ Auto-charge winner (requires Stripe)
  Handle unpaid: [Send to 2nd bidder] [Reschedule auction]
```

**Module Settings** (`Admin → Products → Auctions`):
```
Notification Settings:
  ☐ New bid notifications
  ☐ Outbid notifications
  ☐ Ending soon notifications (24h, 1h, 10m)
  ☐ Auction ended notifications
  ☐ Entry fee confirmation emails
  
Commission Settings (Global):
  Default Commission Model: [Percentage] [Flat Fee] [Hybrid]
  Default Percentage: ___%
  Default Flat Fee: $____
  Commission Explanation Text: [Edit...]
  
Post-Auction Settings (Global):
  ☐ Automatically generate winner orders
  ☐ Automatically charge winner card (Stripe)
  Handle unpaid auctions:
    [Send to 2nd highest bidder]
    [Reschedule auction]
  Rescheduling frequency: [Same time next week] [Same time next month] [Custom]
```

---

## 3. Implementation Phases

### Phase 2A: Entry Fee System (New)

**GOAL-2A**: Implement optional entry fee collection with audit trail.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-2A.1 | Add entry fee columns to auction table | `includes/class.yith-wcact-auction-db.php` | - 3 columns added (entry_fee_enabled, entry_fee_amount, entry_fee_collected)<br/>- Idempotent migration<br/>- DB version incremented to 1.4.0 |
| TASK-2A.2 | Create entry_fees audit table | `includes/class.yith-wcact-auction-db.php` | - Table created with schema from §2<br/>- Indexes on product_user, status<br/>- Idempotent migration |
| TASK-2A.3 | Add UI for entry fee config | `panel/product-auction-settings.php` | - Checkbox: "Require entry fee"<br/>- Text input: fee amount (min $1)<br/>- Validation: amount >= 1, numeric<br/>- Save to post_meta |
| TASK-2A.4 | Create WcAuction_Entry_Fee class | `includes/class.yith-wcact-entry-fee.php` | - Singleton pattern<br/>- Methods: collect_fee(), refund_fee(), get_fees_for_user(), get_fee_paid_count()<br/>- PHPDoc with @requirement tags |
| TASK-2A.5 | Modify AJAX bid handler for entry fees | `includes/class.yith-wcact-auction-ajax.php` | - Check if entry fee required<br/>- If not paid: redirect to fee payment<br/>- If paid: allow bid normally<br/>- Return fee_required flag in response |
| TASK-2A.6 | Create fee payment flow | `templates/woocommerce/auction-entry-fee.php` | - Payment form (redirect to checkout)<br/>- Fee + product added as line item<br/>- Success page confirms entry<br/>- Failure page offers retry |

**Validation**: Entry fees collected, audit trail created, refunds work on unpaid auctions

---

### Phase 3A: Winner Commission System (New)

**GOAL-3A**: Implement configurable commission charged to auction winners.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-3A.1 | Add module settings for commission | `admin/auction-settings-page.php` | - Settings UI: commission model (%, flat, hybrid)<br/>- Percentage input field<br/>- Flat fee input field<br/>- Commission explanation text editor<br/>- Save to wp_options |
| TASK-3A.2 | Create WcAuction_Commission class | `includes/class.yith-wcact-commission.php` | - Singleton pattern<br/>- Methods: calculate_commission($bid_amount), get_settings(), format_explanation()<br/>- Support 3 models: percentage, flat, hybrid<br/>- PHPDoc with @requirement |
| TASK-3A.3 | Add commission display to auction page | `templates/woocommerce/single-product/auction-details.php` | - Display: "Buyer's Premium: [explanation link]"<br/>- Show calculated commission for current bid (dynamic JS)<br/>- Link explains commission | - Modal/popup with explanation text |
| TASK-3A.4 | Create commission explanation modal | `templates/woocommerce/auction-commission-explainer.php` | - Modal content: commission definition, calculation example<br/>- Close button<br/>- Linked from auction page |
| TASK-3A.5 | Add commission to checkout | `includes/class.yith-wcact-auction-cart.php` | - If auction item: calculate commission<br/>- Add as line item in cart<br/>- Display: "Buyer's Premium: $X.XX"<br/>- Include in order total |

**Validation**: Commission calculated correctly (3 models), displayed clearly, charged at checkout

---

### Phase 4A: Post-Auction Processing (New)

**GOAL-4A**: Auto-generate orders, charge winners, handle unpaid auctions.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-4A.1 | Add post-auction columns + log table | `includes/class.yith-wcact-auction-db.php` | - Columns: auction_result_order_id, winner_payment_status, winner_charged_at<br/>- Post-auction-log table created<br/>- Indexes for queries |
| TASK-4A.2 | Create post-auction event handler | `includes/class.yith-wcact-auction-finish.php` (modify existing) | - On auction end: trigger post-auction process<br/>- Check if auto-order enabled<br/>- Call create_winner_order()<br/>- Call handle_unpaid_auction() if reserve not met |
| TASK-4A.3 | Implement auto-order creation | `includes/class.yith-wcact-post-auction.php` | - New class with method: create_winner_order($product_id)<br/>- Create WC_Order with auction item + commission + fees<br/>- Set status: pending payment<br/>- Store order_id in auction table<br/>- Log event |
| TASK-4A.4 | Implement auto-charge (Stripe) | `includes/class.yith-wcact-post-auction.php` | - New method: auto_charge_winner($order_id)<br/>- Get winner's saved Stripe card (via YITH Stripe)<br/>- Attempt charge<br/>- Store result: success/failed + timestamp<br/>- Log event + any error<br/>- Send email: success or failure |
| TASK-4A.5 | Implement unpaid auction handler | `includes/class.yith-wcact-post-auction.php` | - New method: handle_unpaid_auction($product_id)<br/>- If 2nd bidder model: notify 2nd highest bidder<br/>- If reschedule model: clone auction, reschedule for next week/month<br/>- Log event<br/>- Tag order: "Unpaid - Rescheduled" or "Unpaid - Offered to 2nd" |
| TASK-4A.6 | Add module config for post-auction | `admin/auction-settings-page.php` | - Checkbox: auto-generate orders<br/>- Checkbox: auto-charge winner<br/>- Radio: unpaid handling (2nd bidder vs reschedule)<br/>- Reschedule frequency dropdown<br/>- Save to wp_options |

**Validation**: Orders generated automatically, charges attempted, unpaid auctions handled

---

### Phase 5A: Email Notifications (New)

**GOAL-5A**: Implement comprehensive notification system for auction events.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-5A.1 | Create notification event handlers | `includes/class.yith-wcact-notifications.php` | - New class with methods:<br/>- on_new_bid($bid_id)<br/>- on_outbid($user_id, $product_id)<br/>- on_auction_ending_soon($product_id, $time_left)<br/>- on_auction_ended($product_id)<br/>- on_entry_fee_paid($user_id, $product_id, $amount)<br/>- on_admin_unpaid_auction($product_id) |
| TASK-5A.2 | Create email templates | `templates/emails/` | - new-bid.php<br/>- outbid.php<br/>- ending-soon.php<br/>- auction-ended.php<br/>- entry-fee-confirmation.php<br/>- unpaid-auction-2nd-bidder.php<br/>- All with editable copy, hooks for filters |
| TASK-5A.3 | Add notification settings UI | `admin/auction-settings-page.php` | - Checkboxes for each notification type<br/>- "From" name field<br/>- Email template editor (if using custom emails)<br/>- Test email button<br/>- Save to wp_options |
| TASK-5A.4 | Hook into bid placement | `includes/class.yith-wcact-auction-ajax.php` | - On successful bid: fire do_action('WcAuction_new_bid')<br/>- Handler calls notifications->on_new_bid()<br/>- Logs notification attempt |
| TASK-5A.5 | Hook into auction end | `includes/class.yith-wcact-auction-finish.php` | - On auction completion: fire hooks<br/>- do_action('WcAuction_auction_ended')<br/>- do_action('WcAuction_entry_fee_confirmation')<br/>- Handlers send notifications |
| TASK-5A.6 | Implement frequency limiting | `includes/class.yith-wcact-notifications.php` | - Override for "too many emails": max 1 outbid notification per user per 5 min<br/>- Batch multiple bids if rapid<br/>- Log frequency limits<br/>- Admin can override in settings |

**Validation**: Notifications sent on all events, templates customizable, frequency limits work

---

### Phase 5B: Sealed Bid Simplification (Modify Existing)

**GOAL-5B**: Modify sealed bid processing to use single highest bid (not incremental).

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-5B.1 | Modify process_auto_bids_retroactive() | `includes/class.yith-wcact-auto-bid.php` | - Change algorithm:<br/>- OLD: Incremental proxy bidding (loop escalating)<br/>- NEW: Single insertion of highest max_bid<br/>- Get all max_bids sorted DESC<br/>- Insert winning bid at highest max_bid amount<br/>- No incremental escalation<br/>- Return: {winner_user_id, final_bid_amount, auto_bids_placed: [single bid]} |
| TASK-5B.2 | Update sealed bid unit tests | `tests/unit/SealedBidProcessTest.php` | - Update tests to expect single bid insertion<br/>- Test: A max $1k, B max $800 → A wins at $1k (not $801)<br/>- Test: multi-bidder with single highest<br/>- All tests still pass |
| TASK-5B.3 | Update documentation | `Project Docs/SEALED_BID_IMPLEMENTATION.md` | - Update algorithm section: "single highest bid model"<br/>- Remove cascade description<br/>- Update examples<br/>- Add rationale: simpler, fairer, less manipulation |

**Validation**: Sealed bids use highest bid only, tests updated, behavior verified

---

### Phase 6A: Cart & Checkout Changes (New)

**GOAL-6A**: Replace "Buy Now" price with add-to-cart button for regular item.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-6A.1 | Modify auction product display | `templates/woocommerce/single-product/auction-details.php` | - Remove "Buy Now Price" display<br/>- Add button: "Buy Regular Item at Full Price"<br/>- Button links to parent product (add to cart)<br/>- CSS styling for button<br/>- Hidden if no parent product exists |
| TASK-6A.2 | Store parent product relationship | `includes/class.yith-wcact-auction-product.php` | - New method: set_parent_product($auction_product_id, $regular_product_id)<br/>- New method: get_parent_product($auction_product_id)<br/>- Store in post_meta: _WcAuction_parent_product_id<br/>- Validate on save: parent exists and is different |
| TASK-6A.3 | Update product metabox | `panel/product-auction-settings.php` | - Add field: "Parent Product (Full Price Version)"<br/>- Product selection field (autocomplete)<br/>- Display relationship: "Auction of Product [name]"<br/>- Allow clearing parent<br/>- Validation: can't link to itself |
| TASK-6A.4 | Button links to parent in cart | `templates/woocommerce/single-product/add-to-cart/auction.php` | - Button click: redirect to parent product<br/>- Parent product auto-loads in cart<br/>- OR: if button on auction page, redirect to parent cart page<br/>- URL: /product/[parent-id]/ or /cart/ with parent |

**Validation**: Parent product configured, button displays, cart adds correct product

---

### Phase 8A: Testing - Entry Fees, Commission, Post-Auction (New)

**GOAL-8A**: Comprehensive tests for new features.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-8A.1 | Unit tests: Entry fee collection | `tests/unit/EntryFeeTest.php` | - Test collect_fee(min $1)<br/>- Test refund_fee()<br/>- Test audit trail<br/>- Test multiple fees per user<br/>- 100% coverage |
| TASK-8A.2 | Unit tests: Commission calculation | `tests/unit/CommissionTest.php` | - Test 3 models: %, flat, hybrid<br/>- Test edge cases: 0%, high %<br/>- Test currency handling<br/>- 100% coverage |
| TASK-8A.3 | Integration tests: post-auction | `tests/integration/PostAuctionTest.php` | - Test order creation on auction end<br/>- Test auto-charge (mock Stripe)<br/>- Test 2nd bidder notification<br/>- Test reschedule<br/>- End-to-end flow |
| TASK-8A.4 | Integration tests: notifications | `tests/integration/NotificationTest.php` | - Test each notification type sends<br/>- Test frequency limiting<br/>- Test email template rendering<br/>- Test opt-out handling |
| TASK-8A.5 | Cart/checkout tests | `tests/integration/AuctionCheckoutTest.php` | - Test commission added to cart<br/>- Test entry fee added<br/>- Test order totals correct<br/>- Test parent product in cart |

**Validation**: All 50+ new tests pass, ≥95% coverage

---

## 4. Revised Implementation Timeline

### v1.4.0 Release
- Phase 1: Database & Core (existing) ✓
- Phase 2: Max Bid Storage (existing) ✓
- Phase 3: Auto-Bidding Engine (existing) ✓
- Phase 4: AJAX Integration (existing) ✓
- Phase 5: Display & Data (existing) ✓
- **Phase 2A: Entry Fees (NEW) ← 10 hours**
- **Phase 3A: Commission (NEW) ← 8 hours**
- **Phase 4A: Post-Auction (NEW) ← 12 hours**
- **Phase 5A: Notifications (NEW) ← 10 hours**
- **Phase 6A: Cart Changes (NEW) ← 6 hours**
- Phase 6: Testing ← 8+ hours (extended for new features)
- Phase 7: Documentation & Deploy

**Estimated v1.4.0 Hours**: 50 hours + original 24 hours = **74 hours total**

### v1.5.0 Release (Later)
- Phase 0-1B-3B-4B-5B-8-9: Sealed Bids (modified for single highest bid)
- **Phase 7A: Auction Sets (FUTURE)**

---

## 5. Feature Interactions & Dependencies

```
Entry Fee Collection
  ↓
  ├→ Bid Placement (must pay fee first)
  ├→ Unpaid Auction Handling (refund if reserve not met)
  └→ Notifications (fee confirmation email)

Winner Commission
  ↓
  ├→ Checkout (added as line item)
  ├→ Order Total (included)
  └→ Notifications (shown in winner email)

Post-Auction Processing
  ↓
  ├→ Order Creation (WC_Order)
  ├→ Auto-Charge (Stripe integration)
  ├→ 2nd Bidder Notification
  └→ Auction Rescheduling

Notifications
  ↓
  ├→ On New Bid
  ├→ On Outbid
  ├→ On Ending Soon
  ├→ On Auction End
  ├→ On Entry Fee Paid
  └→ On Admin Events

Buy Regular Item Button
  ↓
  └→ Parent Product Configuration
```

---

## 6. Success Criteria (Updated)

- ✅ Entry fees collected with audit trail
- ✅ Commission calculated (3 models), displayed, charged
- ✅ Winners orders auto-generated
- ✅ Auto-charge working (or failed gracefully)
- ✅ Unpaid auctions handled (2nd bidder or reschedule)
- ✅ All notifications sent on events
- ✅ Sealed bids use single highest (not incremental)
- ✅ Buy Now → Add Regular Item button
- ✅ All 50+ new tests pass (≥95% coverage)
- ✅ No regressions to existing features
- ✅ Version 1.4.0 deployed
- ✅ Documentation complete

---

## 7. Excluded Features (Not in Scope)

- ❌ Dutch (Reverse) Auction - lowest bid wins
- ❌ Secret Bids - permanently hidden bids
- ❌ Auction Sets (in v1.5.0, not v1.4.0)
- ❌ Buy Now at fixed price (replaced with add-to-cart)

