# v1.4.0 Execution Checklist: Entry Fees, Commission, Post-Auction, Notifications

**Project**: YITH Auctions for WooCommerce  
**Version**: 1.4.0  
**Release Date**: TBD  
**Total Tasks**: 33  
**Estimated Effort**: 46 hours  
**Prerequisite**: v1.0 Auto-Bidding features complete & tested  

---

## Phase 2A: Entry Fees System (6 tasks, ~10 hours)

### Task 2A-1: Database Schema - Entry Fees Audit Table
- [ ] Create migration file: `includes/migrations/2026-03-22-entry-fees-tables.php`
- [ ] Add columns to `wp_yith_wcact_auction`:
  - `entry_fee_enabled` (TINYINT)
  - `entry_fee_amount` (DECIMAL 10,2)
  - `entry_fee_description` (VARCHAR 500)
- [ ] Create `wp_yith_wcact_entry_fees` table:
  - id, product_id, user_id, fee_amount, status, paid_date, refund_date, refund_reason, created_at
  - UNIQUE KEY (product_id, user_id)
- [ ] Add migration check to prevent duplicate runs
- [ ] Test migration idempotency

**Acceptance Criteria**:
- Schema migrates without errors
- Columns exist in auction table
- Entry fees audit table ready to use
- Migration can run multiple times safely

---

### Task 2A-2: Entry Fees Configuration UI in Product Metabox
- [ ] Open `panel/product-auction-settings.php` (or create if missing)
- [ ] Add "Entry Fee Settings" section
- [ ] Add checkbox: "Require Entry Fee for This Auction"
- [ ] Add number input: "Entry Fee Amount ($)" (min $1.00)
- [ ] Add textarea: "Entry Fee Description" (optional)
- [ ] Disable entry fee fields if auction already started
- [ ] Save values to post meta: `_yith_wcact_entry_fee_enabled`, `_yith_wcact_entry_fee_amount`
- [ ] Load and display saved values on product edit

**Acceptance Criteria**:
- Metabox renders correctly
- Fields save/load properly
- Validation: min $1.00 enforced
- Cannot edit after auction start date passes
- Clear UI labels

---

### Task 2A-3: Entry Fees Class - YITH_WCACT_Entry_Fees
- [ ] Create file: `includes/class.yith-wcact-entry-fees.php`
- [ ] Implement methods:
  - `is_entry_fee_required($product_id)`: bool
  - `get_entry_fee_amount($product_id)`: float
  - `record_fee_payment($product_id, $user_id, $amount)`: bool
  - `get_user_fee_status($product_id, $user_id)`: 'pending'|'paid'|'refunded'
  - `refund_entry_fee($product_id, $user_id, $reason)`: bool
  - `has_paid_entry_fee($product_id, $user_id)`: bool
- [ ] Add logging for all fee operations
- [ ] Add unit tests (8 tests, covered below)

**Acceptance Criteria**:
- Class instantiates without errors
- All methods return correct types
- Audit table updated correctly
- Logging includes REQ-ENTRY-* references
- 8/8 unit tests pass

---

### Task 2A-4: Entry Fee Payment Integration - AJAX Handler
- [ ] Create AJAX action: `yith_wcact_pay_entry_fee`
- [ ] Validate: user_id, product_id, amount
- [ ] Create WooCommerce order for entry fee (one-line order)
- [ ] Use WC payment gateway to process payment
- [ ] On success: call `record_fee_payment()`
- [ ] On failure: return error message
- [ ] Return JSON: `{success: bool, message: string, redirect_url: string}`

**Acceptance Criteria**:
- AJAX endpoint callable 
- Validates user permissions
- Order created in WC database
- Payment processing gateway integrated
- Error handling with user-friendly messages
- AJAX response format correct

---

### Task 2A-5: Entry Fee Display on Auction Frontend
- [ ] In `templates/frontend/auction-product-details.php` (or similar):
- [ ] Check `is_entry_fee_required($product_id)`
- [ ] If yes, check user fee status:
  - Status "paid": Show "✓ Entry Fee Paid ($X.XX)"
  - Status "pending": Show "⚠️ Entry Fee Required ($X.XX) [Pay Now]"
  - Status "refunded": Show "Entry fee refunded"
- [ ] Add button/link to pay entry fee
- [ ] Add entry fee amount to bid section
- [ ] Prevent bidding until fee paid (disable bid button if status != 'paid')

**Acceptance Criteria**:
- Entry fee displays correctly
- Status badge shows correct state
- Pay button functional
- Bid button disabled until fee paid
- Clear UX messaging

---

### Task 2A-6: Entry Fee Refund Logic on Auction Unpaid
- [ ] In post-auction handler (Phase 4A):
- [ ] When auction unpaid or reserve not met:
  - Query all entry fees for this auction
  - For each fee with status='paid': call `refund_entry_fee($product_id, $user_id, 'Auction unpaid/reserve not met')`
  - Update status to 'refunded'
  - Log refund event
- [ ] Send refund confirmation email to each user

**Acceptance Criteria**:
- Refunds initiated on unpaid event
- Entry fee status updated to 'refunded'
- Audit trail recorded
- Email sent to users
- No double-refunds

---

### Phase 2A Unit Tests (8 tests, ~2 hours)

**File**: `tests/unit/class.yith-wcact-entry-fees.test.php`

- [ ] Test: `test_is_entry_fee_required_enabled()` - Returns true when enabled
- [ ] Test: `test_is_entry_fee_required_disabled()` - Returns false when disabled
- [ ] Test: `test_get_entry_fee_amount()` - Returns correct amount
- [ ] Test: `test_record_fee_payment_creates_audit()` - Audit entry created
- [ ] Test: `test_get_user_fee_status_paid()` - Status returns 'paid'
- [ ] Test: `test_has_paid_entry_fee_true()` - Returns true when paid
- [ ] Test: `test_has_paid_entry_fee_false()` - Returns false when not paid
- [ ] Test: `test_refund_entry_fee_updates_status()` - Refund updates status to 'refunded'

**Acceptance**: All 8 tests pass, ≥95% class coverage

---

## Phase 3A: Winner Commission System (5 tasks, ~8 hours)

### Task 3A-1: Module Settings for Commission Configuration
- [ ] Open `panel/settings-options.php` (or `premium-options.php`)
- [ ] Add new section: "Winner Commission (Buyer's Premium)"
- [ ] Add checkbox: "Enable Commission on Auction Winners"
- [ ] Add dropdown: "Commission Model"
  - Option 1: Percentage (e.g., "5%")
  - Option 2: Flat Fee (e.g., "$2.50")
  - Option 3: Hybrid (e.g., "5% + $1.00 minimum")
- [ ] Add number input: "Commission Amount / Percentage" (dynamic label)
- [ ] Add number input: "Minimum Commission (for hybrid model)"
- [ ] Add textarea: "Commission Explanation Text"
- [ ] Save to options: `yith_wcact_commission_*`

**Acceptance Criteria**:
- Settings panel loads
- All fields render correctly
- Values save to database
- Values load correctly
- Clear labels for each model

---

### Task 3A-2: Commission Calculation Class - YITH_WCACT_Commission
- [ ] Create file: `includes/class.yith-wcact-commission.php`
- [ ] Implement methods:
  - `is_commission_enabled()`: bool
  - `get_commission_model()`: 'percentage'|'flat'|'hybrid'
  - `calculate_commission($final_bid_amount)`: float
  - Sample calculations:
    - Percentage: `$bid * ($rate/100)`
    - Flat: `$flat_fee`
    - Hybrid: `max($bid * ($rate/100), $minimum)`
- [ ] Add logging with REQ-COMM-* references
- [ ] Add unit tests (6 tests)

**Acceptance Criteria**:
- Class instantiates
- All commission models calculate correctly
- Edge cases handled (zero bid, negative)?
- All calculations logged
- 6/6 unit tests pass

---

### Task 3A-3: Commission Display on Auction Page
- [ ] In `templates/frontend/auction-product-details.php`:
- [ ] Check `is_commission_enabled()`
- [ ] If yes: display "Buyer's Premium will be added to your bid"
- [ ] Show calculated commission amount
- [ ] Add info icon/link with explanation text
- [ ] Add modal/tooltip showing commission breakdown

**Acceptance Criteria**:
- Commission displays on auction page
- Correct calculation shown
- Explanation link functional
- Modal/tooltip opens and readable
- Mobile responsive

---

### Task 3A-4: Commission Modal with Explanation
- [ ] Create modal template: `templates/frontend/commission-explanation-modal.php`
- [ ] Display:
  - Commission model (Percentage, Flat, Hybrid)
  - Rate/amount/minimum fields
  - Example calculation (e.g., "If you win at $100: commission $5.00")
  - Customizable explanation text from settings
  - Close button
- [ ] Add CSS styling: `assets/css/commission-modal.css`
- [ ] Add JS for modal trigger: `assets/js/commission-modal.js`

**Acceptance Criteria**:
- Modal opens on click
- Displays correct information
- Example calculation accurate
- Closes properly
- Mobile friendly

---

### Task 3A-5: Commission Line Item in Checkout
- [ ] In checkout processing:
- [ ] Detect if winner has active auction
- [ ] Calculate commission via `YITH_WCACT_Commission::calculate_commission()`
- [ ] Add to cart as line item: "Buyer's Premium: $X.XX"
- [ ] Update order total
- [ ] Ensure order receipt includes commission line

**Acceptance Criteria**:
- Commission appears in cart/checkout
- Line item labeled clearly
- Amount correct
- Order total includes commission
- Order email shows commission

---

### Phase 3A Unit Tests (6 tests, ~1 hour)

**File**: `tests/unit/class.yith-wcact-commission.test.php`

- [ ] Test: `test_calculate_commission_percentage()` - 5% of $100 = $5.00
- [ ] Test: `test_calculate_commission_flat()` - Flat $2.50 = $2.50
- [ ] Test: `test_calculate_commission_hybrid_percentage_higher()` - 5% > minimum
- [ ] Test: `test_calculate_commission_hybrid_minimum_higher()` - Minimum > 5%
- [ ] Test: `test_is_commission_enabled()` - Returns enabled state
- [ ] Test: `test_get_commission_model()` - Returns correct model

**Acceptance**: All 6 tests pass, ≥95% class coverage

---

## Phase 4A: Post-Auction Processing (6 tasks, ~12 hours)

### Task 4A-1: Post-Auction Event Handler Hook
- [ ] Create action hook: `yith_wcact_auction_ended`
- [ ] Trigger hook in auction completion logic (when auction_end_datetime <= NOW)
- [ ] Pass parameters: `product_id, winner_user_id, final_bid_amount`
- [ ] Add documentation for hook (filter list in docs)

**Acceptance Criteria**:
- Hook fires when auction ends
- Correct parameters passed
- Can hook into it from external code
- Documented in IMPLEMENTATION_GUIDE.md

---

### Task 4A-2: Auto-Order Generation on Auction End
- [ ] Create post-auction event handler
- [ ] Listen to `yith_wcact_auction_ended` hook
- [ ] On trigger:
  - Get winner user
  - Create WooCommerce Order with status 'pending' (not 'processing')
  - Order line 1: Product @ final_bid_amount
  - Order line 2 (if applicable): Buyer's Premium (commission)
  - Order line 3 (if applicable): Entry Fee
  - Order total = bid + commission + entry_fee
  - Link order to product: `update_post_meta($product_id, '_yith_wcact_order_id', $order_id)`
  - Store auction_winner_user_id in order meta
- [ ] Log to `wp_yith_wcact_post_auction_log` table
- [ ] Handle errors gracefully (log, send admin email)

**Acceptance Criteria**:
- Order created automatically
- Order status is 'pending'
- All line items present (bid, commission, fee)
- Order total correct
- Linked to auction product
- Error logged if creation fails

---

### Task 4A-3: Auto-Charge Stripe Card (Optional Feature)
- [ ] Check module setting: `auto_charge_enabled`
- [ ] Get winner's saved Stripe payment method from WC
- [ ] Attempt charge via Stripe API
- [ ] On success:
  - Update order status to 'processing'
  - Log success with charge ID
  - Update `payment_status` column to 'paid' in auction
- [ ] On failure:
  - Log error with Stripe response
  - Update `payment_status` to 'failed'
  - Send admin email: "Failed to auto-charge winner for [auction]"
  - Move to unpaid auction handler (Task 4A-4)
- [ ] Catch all Stripe exceptions and handle gracefully

**Acceptance Criteria**:
- Stripe integration works (if API key configured)
- Success/failure logged correctly
- Order status updated appropriately
- Admin email sent on failure
- No exceptions crash the process
- Can be disabled via settings

---

### Task 4A-4: Unpaid Auction Handler - notify_2nd or Reschedule
- [ ] Read module setting: `unpaid_action`
- [ ] If setting = 'notify_2nd':
  - Query 2nd highest bidder for this auction
  - Send email: "Winner didn't pay. You're the 2nd highest bidder. Claim this item?"
  - Add claim link/button with AJAX handler
  - If 2nd bidder claims: make them winner, auto-generate order for them
  - If not claimed within 24 hours: mark auction expired
- [ ] If setting = 'reschedule':
  - Read `reschedule_interval_days` setting
  - Create new auction with:
    - Same product
    - Same config (start price, increment, reserve)
    - New start_datetime = NOW()
    - New end_datetime = NOW() + reschedule_interval_days
  - Mark old auction as 'rescheduled'
  - Update `payment_status` to 'unpaid'
- [ ] Log all actions to `wp_yith_wcact_post_auction_log`

**Acceptance Criteria**:
- Unpaid detection works
- Either notify 2nd bidder OR reschedule (depending on setting)
- 2nd bidder email accurate and functional
- New auction created with correct dates/config
- Audit log updated
- Admin notified of unpaid

---

### Task 4A-5: Post-Auction Log Table & Database Schema
- [ ] Create migration for `wp_yith_wcact_post_auction_log` table (if not done in 2A-1)
- [ ] Add columns to `wp_yith_wcact_auction`:
  - `order_id` (INT, nullable)
  - `payment_status` (ENUM: 'pending', 'paid', 'failed', 'unpaid')
- [ ] Verify migration idempotency
- [ ] Test schema changes

**Acceptance Criteria**:
- Log table ready to use
- Auction table columns added
- Migration safe to run multiple times
- No errors on fresh install

---

### Task 4A-6: Send Order Email to Winner
- [ ] On order creation:
- [ ] Send WooCommerce standard order email to winner
- [ ] Include payment link
- [ ] Template: Use WC default "Order Received" email
- [ ] Customize subject: "[Your Auction] won at $X - Payment Required"
- [ ] Include auction name, final bid, commission, total due

**Acceptance Criteria**:
- Email sent to winner after order creation
- Includes payment link
- Subject line clear
- Email content readable
- Can be customized via WC templates

---

### Phase 4A Unit Tests (10 tests, ~2.5 hours)

**File**: `tests/unit/class.yith-wcact-post-auction.test.php`

- [ ] Test: `test_auto_order_creates_wc_order()` - Order created
- [ ] Test: `test_auto_order_sets_pending_status()` - Status is 'pending'
- [ ] Test: `test_auto_order_includes_bid_line_item()` - Final bid line present
- [ ] Test: `test_auto_order_includes_commission_line_item()` - Commission line present (if enabled)
- [ ] Test: `test_auto_order_includes_entry_fee_line_item()` - Entry fee line present (if required)
- [ ] Test: `test_auto_order_total_correct()` - Sum equals bid + commission + fee
- [ ] Test: `test_auto_charge_success()` - Stripe charge succeeds
- [ ] Test: `test_auto_charge_failure()` - Stripe charge fails, logged
- [ ] Test: `test_unpaid_action_notify_2nd()` - 2nd bidder notified
- [ ] Test: `test_unpaid_action_reschedule()` - Auction rescheduled with new datetime

**Acceptance**: All 10 tests pass, ≥95% class coverage

---

## Phase 5A: Email Notifications (6 tasks, ~10 hours)

### Task 5A-1: Notification Event Handlers Class
- [ ] Create file: `includes/class.yith-wcact-notifications.php`
- [ ] Implement methods:
  - `send_new_bid_notification($product_id, $new_bidder_id, $bid_amount)`
  - `send_outbid_notification($product_id, $user_id, $new_leading_bid)`
  - `send_ending_soon_notification($product_id, $time_remaining)` (24h, 1h, 10m)
  - `send_auction_ended_notification($product_id, $winner_id, $final_bid)`
  - `send_entry_fee_confirmation($product_id, $user_id, $fee_amount)`
  - `send_unpaid_notification($product_id, $admin_emails, $winner_id)`
- [ ] Frequency limiting: "new bid max 1 per 5 min per user" (check last notification timestamp)
- [ ] All methods check `is_notification_enabled($type)` before sending
- [ ] Return bool (success/failure)

**Acceptance Criteria**:
- All methods implemented
- Emails send correctly
- Frequency limiting works
- Methods return success/failure
- Logging on all sends
- Configurable per notification type

---

### Task 5A-2: Email Templates for 6 Notification Types
- [ ] Create templates in `templates/emails/`:
  1. `new-bid.html` - New bid placed
  2. `outbid.html` - User outbid
  3. `ending-soon.html` - Auction ending (24h, 1h, 10m variants)
  4. `auction-ended.html` - Auction concluded
  5. `entry-fee-paid.html` - Entry fee receipt
  6. `unpaid-auction.html` - Unpaid notification (admin)

- [ ] Each template includes:
  - Clear subject line
  - Recipient name
  - Auction name, product details
  - Action buttons (bid higher, view auction, claim item, etc.)
  - Unsubscribe link
  - Footer with auction site info

**Acceptance Criteria**:
- All 6 templates render correctly
- HTML valid
- Mobile responsive
- Unsubscribe links functional
- Can be overridden by user custom templates (WC standard)

---

### Task 5A-3: Notification Event Hooks Integration
- [ ] Hook into bid placement (v1.0):
  - After successful bid: `do_action('yith_wcact_bid_placed', $product_id, $user_id, $bid_amount)`
  - Handler calls: `send_new_bid_notification()` to all previous bidders (with frequency limit)
- [ ] Hook into outbid detection:
  - When auto-bid creates new leading bid: `do_action('yith_wcact_outbid', ...)`
  - Handler calls: `send_outbid_notification()` to outbid user
- [ ] Hook into auction ending (WordPress cron):
  - 24 hours before: call `send_ending_soon_notification()`
  - 1 hour before: call `send_ending_soon_notification()`
  - 10 minutes before: call `send_ending_soon_notification()`
- [ ] Hook into auction completed:
  - Call `send_auction_ended_notification()` to all bidders
- [ ] Hook into entry fee paid:
  - Call `send_entry_fee_confirmation()`
- [ ] Hook into unpaid handler:
  - Call `send_unpaid_notification()`

**Acceptance Criteria**:
- All hooks fire at correct times
- Correct recipients receive emails
- Frequency limiting prevents spam
- No errors on hook fire
- Logging includes notification type & recipients

---

### Task 5A-4: Notification Settings UI
- [ ] In `panel/settings-options.php`:
- [ ] Add new section: "Email Notifications"
- [ ] Add toggle for each notification type:
  - New bid notification (enabled by default)
  - Outbid notification (enabled)
  - Ending soon 24h (enabled)
  - Ending soon 1h (enabled)
  - Ending soon 10m (disabled by default)
  - Auction ended (enabled)
  - Entry fee confirmation (enabled)
  - Unpaid auction (admin, enabled)
- [ ] Add number input: "New Bid Frequency Limit (seconds)" (default: 300 = 5 min)
- [ ] Add checkbox: "Include Unsubscribe Link in Emails"
- [ ] Add textarea: "Notification Opt-Out Instructions"
- [ ] Save all settings to options: `yith_wcact_notification_*`

**Acceptance Criteria**:
- Settings panel loads
- All toggles render
- Settings save/load correctly
- Frequency limit value saved
- Clear descriptions for each setting

---

### Task 5A-5: Frequency Limiting for New Bid Notification
- [ ] In `send_new_bid_notification()`:
- [ ] Query `wp_postmeta` for last notification timestamp sent to each user:
  - Query: `SELECT meta_value WHERE meta_key = '_yith_wcact_last_new_bid_emails' AND post_id = $product_id`
  - Check each recipient's last email time
- [ ] If last email < frequency_limit seconds ago: skip this recipient
- [ ] Update meta with current timestamp after sending
- [ ] Log skip events (optional, for debugging)

**Acceptance Criteria**:
- Frequency limit enforced
- Users don't receive spam
- Can increase/decrease limit via settings
- Edge cases handled (meta missing, corrupted data)

---

### Task 5A-6: Notification Opt-Out / Unsubscribe
- [ ] Generate unique unsubscribe token per user per auction:
  - Token = `base64(user_id.product_id.timestamp)`
  - Store in DB (optional, or derive from input)
- [ ] Add unsubscribe link to all emails:
  - Link: `/wp-admin/admin-ajax.php?action=yith_wcact_unsubscribe_notification&token=<TOKEN>`
- [ ] Create AJAX handler for unsubscribe:
  - Decode token
  - Update user meta: `_yith_wcact_notifications_disabled_auctions` (array)
  - Return success message
- [ ] In `send_*_notification()` methods:
  - Check if user is unsubscribed from this auction
  - Skip sending if unsubscribed

**Acceptance Criteria**:
- Unsubscribe link present in all emails
- Link functional (user can click to unsubscribe)
- State persisted after unsubscribe
- Email not sent to unsubscribed users
- Clear confirmation message on unsubscribe page

---

### Phase 5A Unit Tests (12 tests, ~2 hours)

**File**: `tests/unit/class.yith-wcact-notifications.test.php`

- [ ] Test: `test_send_new_bid_notification()` - Email sent to previous bidders
- [ ] Test: `test_new_bid_frequency_limit()` - Max 1 email per 5 min per user
- [ ] Test: `test_send_outbid_notification()` - Email sent to outbid user
- [ ] Test: `test_send_ending_soon_24h()` - Email sent 24 hours before
- [ ] Test: `test_send_ending_soon_1h()` - Email sent 1 hour before
- [ ] Test: `test_send_ending_soon_10m()` - Email sent 10 minutes before
- [ ] Test: `test_send_auction_ended_notification()` - Email sent to all bidders
- [ ] Test: `test_send_entry_fee_confirmation()` - Receipt email sent
- [ ] Test: `test_send_unpaid_notification_to_admin()` - Admin email sent
- [ ] Test: `test_unsubscribe_token_generation()` - Token created correctly
- [ ] Test: `test_unsubscribe_disables_emails()` - Emails not sent after unsubscribe
- [ ] Test: `test_notification_disabled_type_not_sent()` - Email skipped if disabled in settings

**Acceptance**: All 12 tests pass, ≥95% class coverage

---

## Phase 6A: Cart & Checkout Integration (4 tasks, ~6 hours)

### Task 6A-1: Replace Buy Now Price with Add Regular Item Button
- [ ] In product template: `templates/frontend/auction-product-details.php`
- [ ] Remove/hide "Buy Now" price display (if it exists from v1.0)
- [ ] Replace with button: "Add Regular Item to Cart"
- [ ] Button triggers: `yith_wcact_add_regular_item_to_cart()` function
- [ ] Function:
  - Gets parent_product_id from meta: `_yith_wcact_parent_product_id`
  - Adds to WC cart: `WC()->cart->add_to_cart($parent_product_id)`
  - Redirects to cart page

**Acceptance Criteria**:
- "Buy Now" removed or hidden
- "Add Regular Item" button visible
- Button adds parent product to cart
- Redirect to cart works
- Button disabled if no parent product configured

---

### Task 6A-2: Parent Product Configuration in Metabox
- [ ] In product auction metabox: `panel/product-auction-settings.php`
- [ ] Add new field: "Related Regular Product"
- [ ] Field type: Dropdown/Select2 with product search
- [ ] Filter: Only show non-auction (regular) products
- [ ] Default: Empty/None
- [ ] Save to post meta: `_yith_wcact_parent_product_id`
- [ ] Load and display saved value

**Acceptance Criteria**:
- Metabox field renders
- Product search functional
- Only non-auction products appear in list
- Value saves/loads correctly
- Validation: optional (can be empty)

---

### Task 6A-3: Entry Fee + Commission Display in Checkout
- [ ] In WooCommerce checkout template: `woocommerce/checkout/form-checkout.php` (or cart page)
- [ ] Add custom sections before order summary:
  - If entry fee due: "Entry Fee: $X.XX"
  - If commission due: "Buyer's Premium: $X.XX"
- [ ] Separate line items (not combined)
- [ ] Update order total to include both

**Acceptance Criteria**:
- Entry fee displays (if applicable)
- Commission displays (if applicable)
- Correct amounts shown
- Order total includes both
- Mobile responsive layout

---

### Task 6A-4: Document Entry Fee Refund Process
- [ ] Update README.md with section: "Entry Fee Refunds"
- [ ] Explain when refunds issued:
  - Auction doesn't reach reserve
  - Auction cancelled
  - Winner doesn't pay (if using 2nd bidder strategy)
- [ ] Explain how refund appears:
  - WC order marked 'Refunded'
  - Refund appears in customer account
  - Email receipt sent
- [ ] Add troubleshooting: "Why wasn't my entry fee refunded?"

**Acceptance Criteria**:
- Documentation clear and complete
- Refund timing explained
- Customer-friendly language
- Includes screenshot/example (if applicable)

---

### Phase 6A Unit Tests (4 tests, ~1 hour)

**File**: `tests/integration/checkout-integration.test.php`

- [ ] Test: `test_add_regular_item_button_functional()` - Button adds parent product to cart
- [ ] Test: `test_entry_fee_displays_in_checkout()` - Entry fee line item shown
- [ ] Test: `test_commission_displays_in_checkout()` - Commission line item shown
- [ ] Test: `test_order_total_includes_fee_and_commission()` - Total = bid + fee + commission

**Acceptance**: All 4 tests pass, ≥95% coverage

---

## Phase 8A: Testing & QA (5+ test suites, ~8 hours)

### Phase 8A-1: Unit Test Suite - All Components
- [ ] Run all new unit tests:
  - Entry fees: 8 tests
  - Commission: 6 tests
  - Post-auction: 10 tests
  - Notifications: 12 tests
- [ ] Verify: 36 tests pass, ≥95% code coverage
- [ ] Generate coverage report: `coverage/index.html`

**Acceptance Criteria**:
- All unit tests pass
- Coverage ≥95% for new code
- Coverage report generated
- No PHP warnings/errors

---

### Phase 8A-2: Integration Test Suite
- [ ] Create `tests/integration/auction-flow-with-fees-commission.test.php`
- [ ] Test: Complete auction flow from start to payment:
  1. Auction created with entry fee + commission enabled
  2. User 1 pays entry fee
  3. User 1 places bid
  4. User 2 pays entry fee
  5. User 2 places higher bid
  6. Auction ends (User 2 wins)
  7. Order auto-generated with bid + commission + entry fee
  8. Winner receives order email
  9. Payment processed (success or fail)
  10. Verify order total, line items, user notifications

**Acceptance Criteria**:
- Full flow executes without errors
- All components integrate correctly
- Database state consistent
- Emails sent at each step

---

### Phase 8A-3: Edge Case Testing
- [ ] Test: Entry fee minimum ($1.00 enforcement)
- [ ] Test: Commission calculation accuracy (all 3 models)
- [ ] Test: Sealed bid reveal timing (scheduled correctly)
- [ ] Test: Notification frequency limit (no duplicate emails)
- [ ] Test: Refund processing on unpaid auction
- [ ] Test: 2nd bidder notification (correct email sent)
- [ ] Test: Auction reschedule (new datetime correct)
- [ ] Test: Commission not applied to "Add Regular Item" button

**Acceptance Criteria**:
- All edge cases handled correctly
- No exceptions or errors
- Database remains consistent

---

### Phase 8A-4: Performance Testing
- [ ] Load test: 100+ auctions ending simultaneously
  - Verify: Auto-order generation completes < 5 seconds
  - Verify: Email queue doesn't overflow
  - Verify: No timeout errors
- [ ] Notification volume: Send 1000 new bid notifications
  - Verify: Frequency limit prevents spam
  - Verify: No duplicate sends
  - Verify: Completes < 2 seconds per batch

**Acceptance Criteria**:
- Performance meets requirements
- No timeouts or crashes
- Database handles load

---

### Phase 8A-5: Security Testing
- [ ] Input validation:
  - Entry fee amount: only numbers, min $1
  - Commission rate: only 0-100 for percentage
  - User IDs: validated before database queries
- [ ] SQL injection prevention:
  - All queries use prepared statements
  - Verify no raw user input in SQL
- [ ] XSS prevention:
  - All user data escaped in templates
  - Commission explanation text sanitized
- [ ] Authorization:
  - Entry fee payment only by auction participant
  - Commission only calculated for winners
  - Unpaid handling respects user roles

**Acceptance Criteria**:
- No SQL injection vulnerabilities
- No XSS vulnerabilities
- Authorization checks pass security review

---

### Phase 8A: Regression Testing
- [ ] Run existing v1.0 tests (24 tests):
  - All 24 auto-bidding tests still pass
  - No regressions in starting bid, increment, reserve
- [ ] Verify: Backward compatibility
  - Auctions without entry fees work
  - Auctions without commission work
  - Open auctions unaffected by sealed bid code

**Acceptance Criteria**:
- All 24 existing tests pass
- No new failures
- Backward compatible

---

## 🏁 Final Tasks

### Pre-Release Checklist
- [ ] All 36+ unit tests pass
- [ ] All integration tests pass
- [ ] Code review completed & approved
- [ ] PHPDoc complete for all new classes (100%)
- [ ] README updated with new features
- [ ] CHANGELOG updated with v1.4.0 notes
- [ ] Database migrations tested (fresh install + upgrade)
- [ ] No PHP warnings/errors in debug mode
- [ ] Security review completed
- [ ] Performance testing completed

### Deployment Checklist
- [ ] Create git branch: `feature/v1.4.0-entry-fees-commission-post-auction`
- [ ] All commits reference requirements (REQ-ENTRY-*, REQ-COMM-*, etc.)
- [ ] Version bumped to 1.4.0 in `init.php`
- [ ] CHANGELOG.md updated
- [ ] Tag: `v1.4.0`
- [ ] Build: Composer vendors included
- [ ] .gitignore excludes vendor/
- [ ] Tested on PHP 7.3, 7.4, 8.0, 8.1, 8.2

### Post-Release Tasks
- [ ] Deploy to staging environment
- [ ] Run smoke tests on staging
- [ ] Deploy to production
- [ ] Monitor logs for errors
- [ ] Verify notifications sent correctly
- [ ] Confirm orders created for past/current auctions
- [ ] Check commission calculations accurate

---

## 📊 Progress Tracking

| Phase | Tasks | Status | Hours | Start | End |
|-------|-------|--------|-------|-------|-----|
| 2A (Entry Fees) | 6 | ⏳ Not Started | 10 | — | — |
| 3A (Commission) | 5 | ⏳ Not Started | 8 | — | — |
| 4A (Post-Auction) | 6 | ⏳ Not Started | 12 | — | — |
| 5A (Notifications) | 6 | ⏳ Not Started | 10 | — | — |
| 6A (Cart) | 4 | ⏳ Not Started | 6 | — | — |
| 8A (Testing) | 5+ | ⏳ Not Started | 8+ | — | — |
| **TOTAL** | **33** | **⏳** | **46-54** | — | — |

---

**Checklist Version**: 1.0  
**Last Updated**: March 22, 2026  
**Owner**: Development Team  
**Status**: Ready for implementation
