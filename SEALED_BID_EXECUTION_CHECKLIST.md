# Sealed Bid Auction Implementation: Execution Checklist

**Feature**: Progressive Auto-Bidding with Sealed Bid Mode  
**Plan Document**: `/plan/feature-auto-bidding-sealed-bids-1.1.md`  
**Base Feature**: Auto-Bidding v1.0 (must complete before this)  
**Total Tasks**: 35 tasks across 9 phases  
**Effort Estimate**: 20-28 hours (includes v1.0 prerequisite ~16-24 hours)  
**Target Coverage**: ≥95%  

---

## Quick Navigation
- [Phase 0: UI Configuration](#phase-0-ui-configuration) - 3 tasks
- [Phase 1B: Database Schema](#phase-1b-database-schema) - 2 tasks
- [Phase 3B: Auto-Bid Engine](#phase-3b-auto-bid-engine) - 2 tasks
- [Phase 4B: AJAX Handler](#phase-4b-ajax-handler) - 2 tasks
- [Phase 5B: Display Logic](#phase-5b-display-logic) - 4 tasks
- [Phase 8: Scheduled Processing](#phase-8-scheduled-processing) - 4 tasks
- [Phase 9: Testing](#phase-9-testing) - 4 tasks
- [Integration & Finalization](#integration--finalization) - 14 tasks

---

## Prerequisites

**MUST COMPLETE BEFORE STARTING SEALED BID WORK**:
- [ ] v1.0 Auto-Bidding implementation complete (all phases 1-7)
- [ ] All v1.0 tests passing (≥95% coverage)
- [ ] Git branch: `feature/auto-bidding` merged or in working state
- [ ] Database migrations for v1.0 applied
- [ ] Code review of v1.0 completed

---

## Phase 0: UI Configuration (3 tasks)

**Phase Goal**: Add admin UI for sealed bid settings

### TASK-0.1: Add Sealed Bid Metabox Section
- [ ] Identify product metabox location: `panel/product-auction-settings.php` or custom
- [ ] Create new settings section: "Sealed Bid Settings"
- [ ] Add checkbox: `<input type="checkbox" name="WcAuction_is_sealed_bid">`
- [ ] Default: unchecked
- [ ] Store in: `_WcAuction_is_sealed_bid` post meta
- [ ] Validation: disable if auction already started
- **Acceptance**:
  - [ ] Metabox section renders in product edit page
  - [ ] Checkbox saves/loads from post meta
  - [ ] Disabled after auction start date
  - [ ] No PHP errors

### TASK-0.2: Add Reveal Date/Time Pickers
- [ ] Add datepicker input: `<input type="date" name="WcAuction_sealed_reveal_date">`
- [ ] Add timepicker input: `<input type="time" name="WcAuction_sealed_reveal_time">`
- [ ] Combine and store in: `_WcAuction_sealed_reveal_date`, `_WcAuction_sealed_reveal_time`
- [ ] Add validation message: "Reveal time must be after now and before auction end"
- [ ] Show on page only if checkbox enabled (JS toggle)
- [ ] Convert user timezone to UTC for storage
- [ ] Display stored value in admin UTC
- **Acceptance**:
  - [ ] Datepicker/timepicker render correctly
  - [ ] Inputs toggle visibility with checkbox
  - [ ] Values save to post meta
  - [ ] Timezone conversion works (test UTC vs local)

### TASK-0.3: Update Product Save Handler
- [ ] Modify `includes/class.yith-wcact-auction-product.php`
- [ ] On product save: extract sealed_bid settings from POST
- [ ] Validate: reveal_datetime > now() AND reveal_datetime < auction_end_datetime
- [ ] Return error if validation fails (show in admin UI)
- [ ] Populate auction table columns:
  - `is_sealed_bid` = 1 or 0
  - `sealed_reveal_datetime` = UTC datetime from combined date/time
  - `sealed_reveal_processed` = 0 (not yet processed)
- [ ] Test with edge cases:
  - [ ] Reveal date in past (should error)
  - [ ] Reveal after auction end (should error)
  - [ ] Valid future reveal (should accept)
- **Acceptance**:
  - [ ] Settings save without errors
  - [ ] Auction table columns populated
  - [ ] Validation catches invalid dates
  - [ ] Editing sealed auction shows current settings

---

## Phase 1B: Database Schema (2 tasks)

**Phase Goal**: Extend database with sealed bid tracking columns

### TASK-1B.1: Add Sealed Columns to Auction Table
- [ ] Edit `includes/class.yith-wcact-auction-db.php`
- [ ] Create migration function (idempotent):
  ```php
  function add_sealed_bid_columns() {
    // Check if columns exist before adding
    // ALTER TABLE wp_WcAuction_auction ADD COLUMN:
    //   - is_sealed_bid TINYINT(1) DEFAULT 0
    //   - sealed_reveal_datetime DATETIME DEFAULT NULL
    //   - sealed_reveal_processed TINYINT(1) DEFAULT 0
    //   - sealed_max_bids_collected INT DEFAULT 0
  }
  ```
- [ ] Hook into activation/version check
- [ ] Increment DB version to 1.3.0
- [ ] Test: run twice to verify idempotent (no duplicate column error)
- **Acceptance**:
  - [ ] Columns added to wp_WcAuction_auction
  - [ ] Migration runs without error
  - [ ] idempotent check prevents duplicate adds
  - [ ] DB version updated

### TASK-1B.2: Create Sealed Bid Audit Log Table
- [ ] Create new table: `wp_WcAuction_sealed_bid_audit`
- [ ] Schema:
  ```sql
  CREATE TABLE wp_WcAuction_sealed_bid_audit (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    product_id BIGINT NOT NULL,
    reveal_datetime DATETIME NOT NULL,
    auto_bids_count INT DEFAULT 0,
    final_bid_amount DECIMAL(10,2) DEFAULT NULL,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('success', 'error') DEFAULT 'success',
    error_message TEXT DEFAULT NULL,
    KEY idx_product_id (product_id),
    KEY idx_processed_at (processed_at)
  );
  ```
- [ ] Create in migration function (idempotent)
- [ ] Test: table created, entries insert correctly
- **Acceptance**:
  - [ ] Table created successfully
  - [ ] Migration idempotent
  - [ ] Can insert audit records
  - [ ] Indexes created properly

---

## Phase 3B: Auto-Bid Engine (2 tasks)

**Phase Goal**: Add retroactive auto-bid processing for sealed reveals

### TASK-3B.1: Add Retroactive Processing Method
- [ ] Edit `includes/class.yith-wcact-auto-bid.php`
- [ ] Create new method: `process_auto_bids_retroactive($product_id)`
- [ ] Logic:
  - Get all max_bids for product_id (sorted DESC by max_bid, ASC by created_at)
  - If empty: return null
  - Set CURRENT_BID = highest max_bid from list
  - Set CURRENT_LEADING_USER = that user_id
  - Get increment for that price
  - Loop through remaining max_bids:
    - If max_bid > CURRENT_BID: auto-bid them to min(CURRENT_BID + increment, their max_bid)
    - Update CURRENT_BID and loop
  - Return result array with winner info and auto-bids placed
- [ ] PHPDoc: include @requirement tags (REQ-SEALED-006)
- [ ] Test edge cases:
  - [ ] Single max_bid (no auto-bids needed)
  - [ ] Multiple same max_bid (tiebreaker by created_at)
  - [ ] Incremental escalation across 3+ bidders
- **Acceptance**:
  - [ ] Method callable, no errors
  - [ ] Returns correct winner
  - [ ] Auto-bids inserted correctly
  - [ ] Increment calculation uses v1.0 engine

### TASK-3B.2: Add Sealed Reveal Transaction Wrapper
- [ ] In `includes/class.yith-wcact-auto-bid.php`
- [ ] Create new method: `process_sealed_reveal_transactional($product_id)`
- [ ] Flow:
  - BEGIN TRANSACTION
  - Call process_auto_bids_retroactive($product_id)
  - UPDATE wp_WcAuction_auction
    - Set sealed_reveal_processed = 1
    - Set sealed_max_bids_collected = count($max_bids)
  - COMMIT on success
  - ROLLBACK on exception, log error
- [ ] Return result object with success/error
- [ ] PHPDoc with @requirement tags
- [ ] Test:
  - [ ] Successful transaction commits
  - [ ] Error triggers rollback
  - [ ] sealed_reveal_processed flag set
- **Acceptance**:
  - [ ] Transaction completes successfully
  - [ ] Rollback works on error
  - [ ] Audit log updated
  - [ ] No orphaned records on failure

---

## Phase 4B: AJAX Handler (2 tasks)

**Phase Goal**: Modify bid submission to skip auto-bidding during sealed period

### TASK-4B.1: Check Sealed Period in Bid Handler
- [ ] Edit `includes/class.yith-wcact-auction-ajax.php`
- [ ] In `WcAuction_add_bid()` after bid validation:
  - Check if product is_sealed_bid = 1
  - Check if sealed_reveal_processed = 0
  - Check if NOW < sealed_reveal_datetime
  - If ALL true: **sealed period active**
- [ ] If sealed active:
  - Accept the max_bid parameter
  - Save via save_max_bid($user_id, $product_id, $max_bid)
  - **SKIP** call to process_auto_bids_transactional()
  - Add to response: is_in_sealed_mode=true
  - Add to response: countdown_seconds = (sealed_reveal_datetime - now)
  - Add to response: sealed_message = "Your bid is secure and will be revealed..."
- [ ] If not sealed: proceed with v1.0 logic (auto-bids immediately)
- [ ] Test:
  - [ ] Sealed auction accepts bid without auto-bidding
  - [ ] Open auction auto-bids immediately
  - [ ] Response includes sealed flag
  - [ ] Countdown seconds calculated correctly
- **Acceptance**:
  - [ ] Sealed bids collected, not auto-processed
  - [ ] Open bids auto-process as per v1.0
  - [ ] Response identifies auction state
  - [ ] No auto-bids placed during sealed period

### TASK-4B.2: Update AJAX Response Structure
- [ ] Modify response JSON in `WcAuction_add_bid()`:
  ```json
  {
    "success": true/false,
    "current_bid": 123.45,
    "is_sealed_mode": true/false,
    "is_in_sealed_mode": true/false,
    "sealed_reveal_datetime": "2026-03-25 16:30:00",
    "countdown_seconds": 86400,
    "auto_bids_placed": 0 (if sealed) or N (if open)
  }
  ```
- [ ] For sealed auctions: do NOT include auto_bids_placed count
- [ ] Include: max_bid_accepted=true (if valid)
- [ ] Test response structure with various scenarios
- **Acceptance**:
  - [ ] Response JSON valid in sealed mode
  - [ ] Response JSON valid in open mode
  - [ ] All expected fields present
  - [ ] Countdown accurate

---

## Phase 5B: Display Logic (4 tasks)

**Phase Goal**: Update frontend to show sealed bid state and hide data

### TASK-5B.1: Current Price Display - Sealed Handling
- [ ] Edit `templates/woocommerce/single-product/add-to-cart/auction.php`
- [ ] Add sealed check at top:
  ```php
  $is_sealed = (get_post_meta($product_id, '_WcAuction_is_sealed_bid', true) == 1);
  $seal_reveal = get_post_meta($product_id, '_WcAuction_sealed_reveal_datetime', true);
  $is_revealed = (time() > strtotime($seal_reveal));
  ```
- [ ] If $is_sealed && !$is_revealed:
  - Display: "🔒 SEALED BID IN PROGRESS"
  - Hide current bid amount
  - Show countdown: "Reveal in X days, Y hours, Z minutes"
  - Show "Reveal will occur at: [datetime in user tz]"
- [ ] Otherwise: show current bid (v1.0 behavior)
- [ ] CSS class for styling: `.sealed-bid-status`
- [ ] Test:
  - [ ] Sealed auction shows message before reveal
  - [ ] Open auction shows current bid
  - [ ] Post-reveal shows bid amount
- **Acceptance**:
  - [ ] Sealed display correct during period
  - [ ] Current bid hidden correctly
  - [ ] Countdown shown
  - [ ] Post-reveal shows bid

### TASK-5B.2: Bid History - Sealed Handling
- [ ] Edit `templates/frontend/list-bids.php`
- [ ] Check if sealed && !revealed:
  ```php
  if ($is_sealed && !$is_revealed) {
    echo "🔒 Bid history will be revealed at: " . $reveal_datetime;
    return; // hide all bid entries
  }
  ```
- [ ] Otherwise: show all bids with proxy info (v1.0 behavior)
- [ ] Test:
  - [ ] Sealed auction hides full history
  - [ ] Post-reveal shows all bids including auto-bids
  - [ ] Bid entries show "(auto-bid)" label correctly
- **Acceptance**:
  - [ ] Sealed hides bid history
  - [ ] Revealed shows all bids
  - [ ] Auto-bid labels visible after reveal

### TASK-5B.3: Max Bid Input - Sealed Visibility
- [ ] Edit `templates/woocommerce/single-product/add-to-cart/auction.php`
- [ ] Max bid field always present in DOM:
  ```html
  <input type="number" name="max_bid" class="sealed-bid-input" 
         style="display:none" placeholder="Your maximum bid">
  ```
- [ ] JS shows field only during sealed period:
  - Check is_sealed_mode from AJAX response
  - If true: show input with message "Your bid is secure and hidden"
  - If false: hide input (open auction, no proxy bidding UI yet)
- [ ] Don't show entered value back to user (no value display)
- [ ] Test:
  - [ ] Sealed auction shows max bid input
  - [ ] Open auction hides max bid input
  - [ ] Value accepted and submitted
- **Acceptance**:
  - [ ] Max bid field functional during sealed
  - [ ] Field hidden during open
  - [ ] Value securely submitted

### TASK-5B.4: Frontend Countdown Timer
- [ ] Edit `assets/js/frontend.js`
- [ ] On page load: if sealed auction detected:
  - Calculate sealed_reveal_datetime from data attr or AJAX response
  - Start countdown loop (setInterval every 1000ms)
  - Display: "Reveal in X days, Y hours, Z minutes"
  - Update every second
  - When timer hits zero: show "Revealed!" or refresh page
- [ ] Stop timer if page navigation happens
- [ ] Test across timezones:
  - [ ] Timer shows correct countdown
  - [ ] Updates every second
  - [ ] Stops at reveal time
- **Acceptance**:
  - [ ] Countdown displays and updates
  - [ ] Accurate time calculation
  - [ ] Visible to user during sealed period
  - [ ] Triggers reveal/refresh

---

## Phase 8: Scheduled Processing (4 tasks)

**Phase Goal**: Implement automatic sealed reveal at scheduled times

### TASK-8.1: Register WordPress Cron Event
- [ ] Edit `includes/class.yith-wcact-auction.php`
- [ ] On plugin activation (or init):
  ```php
  if (!wp_next_scheduled('WcAuction_sealed_bid_reveal')) {
    wp_schedule_event(time(), 'five_minutes', 'WcAuction_sealed_bid_reveal');
  }
  ```
- [ ] Add function to handle the hook
- [ ] Test:
  - [ ] Cron event registers
  - [ ] Cron event fires at expected intervals
  - [ ] Can query event status via wp-cli
- **Acceptance**:
  - [ ] Cron hook scheduled
  - [ ] No duplicate schedules
  - [ ] Can be verified via `wp_next_scheduled()`

### TASK-8.2: Implement Reveal Handler Function
- [ ] In `includes/class.yith-wcact-auto-bid.php`
- [ ] Create method: `handle_sealed_bid_reveal()`
- [ ] Query all auctions:
  ```sql
  SELECT product_id FROM wp_WcAuction_auction 
  WHERE is_sealed_bid=1 AND sealed_reveal_processed=0 
  AND sealed_reveal_datetime <= UTC_TIMESTAMP()
  ```
- [ ] For each product_id:
  - Call `process_sealed_reveal_transactional($product_id)`
  - Catch and log any errors
  - Collect results
- [ ] Return {count: N, errors: [], last_run: timestamp}
- [ ] Log to error_log: "Sealed bid reveal processed: N auctions"
- [ ] Test:
  - [ ] Queries auctions correctly
  - [ ] Processes each auction
  - [ ] Logs results
- **Acceptance**:
  - [ ] Handler callable
  - [ ] Processes all due auctions
  - [ ] Logs execution
  - [ ] Error handling works

### TASK-8.3: Create Audit Trail on Reveal
- [ ] In `process_sealed_reveal_transactional()` or `handle_sealed_bid_reveal()`
- [ ] After successful reveal: insert into wp_WcAuction_sealed_bid_audit
  ```php
  $wpdb->insert('wp_WcAuction_sealed_bid_audit', [
    'product_id' => $product_id,
    'reveal_datetime' => $seal_reveal,
    'auto_bids_count' => count($auto_bids),
    'final_bid_amount' => $final_bid,
    'status' => 'success',
    'error_message' => null
  ]);
  ```
- [ ] On error: insert with status='error', error_message filled
- [ ] Test:
  - [ ] Audit records created for each reveal
  - [ ] Success/error status tracked
  - [ ] Can query audit log for debugging
- **Acceptance**:
  - [ ] Audit table populated on reveals
  - [ ] Error logging works
  - [ ] Audit log queryable

### TASK-8.4: Add Manual Reveal Button
- [ ] Edit `panel/admin-auction-settings.php` or appropriate admin panel
- [ ] Add button visible only if: is_sealed && !sealed_reveal_processed
  ```php
  if ($is_sealed && !$is_revealed) {
    echo '<button name="force_reveal" class="button">Force Reveal Now</button>';
  }
  ```
- [ ] Handle POST: if force_reveal button clicked:
  - Call `process_sealed_reveal_transactional($product_id)`
  - Show success message or error
  - Update page to show reveal completed
- [ ] Test:
  - [ ] Button appears for sealed unrevealed auctions
  - [ ] Clicking triggers reveal
  - [ ] Shows result message
  - [ ] Updates UI after reveal
- **Acceptance**:
  - [ ] Button visible/hidden appropriately
  - [ ] Force reveal works immediately
  - [ ] Feedback to admin user

---

## Phase 9: Testing (4 tasks)

**Phase Goal**: Comprehensive test coverage for sealed bid mode

### TASK-9.1: Sealed Configuration Tests
- [ ] Create `tests/unit/SealedBidConfigTest.php`
- [ ] Test suite: `class SealedBidConfigTest extends WP_UnitTestCase`
- [ ] Test cases (minimum 10 tests):
  - [ ] test_sealed_settings_save_and_load()
  - [ ] test_reveal_date_validation_future()
  - [ ] test_reveal_date_validation_past_should_error()
  - [ ] test_reveal_date_before_auction_end()
  - [ ] test_toggle_disabled_after_auction_start()
  - [ ] test_db_columns_created()
  - [ ] test_audit_table_created()
  - [ ] test_sealed_auction_created_with_settings()
  - [ ] test_product_meta_populated()
  - [ ] test_complex_datetime_handling()
- [ ] Run: `./vendor/bin/phpunit tests/unit/SealedBidConfigTest.php`
- **Acceptance**:
  - [ ] All 10+ tests pass
  - [ ] Coverage ≥95% of config code
  - [ ] Edge cases covered

### TASK-9.2: Retroactive Processing Tests
- [ ] Create `tests/unit/SealedBidProcessTest.php`
- [ ] Test suite for `process_auto_bids_retroactive()`
- [ ] Test cases (minimum 12 tests):
  - [ ] test_single_max_bid_no_auto_bids()
  - [ ] test_two_bidders_highest_wins()
  - [ ] test_three_bidders_escalation()
  - [ ] test_equal_max_bids_tiebreaker_by_created_at()
  - [ ] test_large_gap_no_increment_needed()
  - [ ] test_incremental_escalation_multiple_rounds()
  - [ ] test_empty_max_bids_returns_null()
  - [ ] test_winner_determined_correctly()
  - [ ] test_auto_bids_inserted_to_auction_table()
  - [ ] test_transaction_rollback_on_error()
  - [ ] test_sealed_flags_updated_correctly()
  - [ ] test_audit_log_populated()
- [ ] Run tests
- **Acceptance**:
  - [ ] All 12+ tests pass
  - [ ] Coverage ≥95% of reveal logic
  - [ ] All scenarios work

### TASK-9.3: Integration Tests - Sealed Auction Flow
- [ ] Create `tests/integration/SealedBidAuctionTest.php`
- [ ] Full end-to-end flow tests (minimum 6 tests):
  - [ ] test_create_sealed_auction()
  - [ ] test_place_bid_during_sealed_period()
  - [ ] test_bid_history_hidden_during_sealed()
  - [ ] test_auction_display_shows_sealed_message()
  - [ ] test_reveal_time_trigger_processes_bids()
  - [ ] test_bids_visible_after_reveal()
- [ ] Mock time progression if needed (test mock time library)
- [ ] Run tests
- **Acceptance**:
  - [ ] All 6+ tests pass
  - [ ] Full flow works end-to-end
  - [ ] Bids collected then revealed correctly

### TASK-9.4: Frontend Display Tests
- [ ] Create `tests/integration/SealedBidDisplayTest.php`
- [ ] Frontend display logic tests (minimum 8 tests):
  - [ ] test_sealed_message_displays_during_period()
  - [ ] test_countdown_timer_shows_correct_time()
  - [ ] test_bid_history_hidden_during_sealed()
  - [ ] test_max_bid_input_visible_during_sealed()
  - [ ] test_current_bit_hidden_during_sealed()
  - [ ] test_reveal_message_shown_at_reveal_time()
  - [ ] test_bids_displayed_after_reveal()
  - [ ] test_auto_bid_labels_show_after_reveal()
- [ ] Use WordPress/PHP testing with mock HTML/JS where needed
- [ ] Run tests
- **Acceptance**:
  - [ ] All 8+ tests pass
  - [ ] Display logic coverage ≥95%
  - [ ] Frontend behavior verified

---

## Integration & Finalization (14 tasks)

**Phase Goal**: Documentation, version bump, and deployment

### TASK-INT-1: Create Sealed Bid Implementation Doc
- [ ] Create `Project Docs/SEALED_BID_IMPLEMENTATION.md`
- [ ] Contents:
  - Overview of sealed bid mode
  - Architecture diagram (sealed vs open flow)
  - Database schema changes
  - New classes/methods documented
  - Example walkthrough (3 bidders, sealed then reveal)
- [ ] Include: 1-2 diagrams (PlantUML or Excalidraw)

### TASK-INT-2: Update AUTO_BIDDING_REQUIREMENTS.md
- [ ] Add section: "Sealed Bid Mode Requirements (v1.1)"
- [ ] Document sealed bid requirements (REQ-SEALED-001 through 008)
- [ ] Add use cases: "Why use sealed bids?"
- [ ] Add edge cases specific to sealed mode

### TASK-INT-3: Update IMPLEMENTATION_GUIDE.md
- [ ] Add section: "Sealed Bid Auction Mode (v1.1)"
- [ ] Add link to sealed bid plan document
- [ ] Add link to sealed bid test checklist
- [ ] Update prerequisites to mention v1.0

### TASK-INT-4: Add Full PHPDoc to Sealed Methods
- [ ] In `class.yith-wcact-auto-bid.php`:
  - [ ] `process_auto_bids_retroactive()`: add @param, @return, @requirement, @example
  - [ ] `process_sealed_reveal_transactional()`: add @param, @return, @requirement
  - [ ] `handle_sealed_bid_reveal()`: add @param, @return, @requirement
  - [ ] Include UML class diagram in class docblock
- [ ] In `class.yith-wcact-auction-product.php`:
  - [ ] Sealed-related configuration methods: full PHPDoc

### TASK-INT-5: Create User Guide for Sealed Auctions
- [ ] Create `Project Docs/SEALED_AUCTION_USER_GUIDE.md`
- [ ] For admins: how to enable sealed mode, set reveal time
- [ ] For end users: how to bid in sealed auctions, what happens at reveal
- [ ] FAQ: "Why can't I see bids?", "When will bids be revealed?"
- [ ] Screenshots or diagrams of sealed auction flows

### TASK-INT-6: Update Changelog
- [ ] Edit `readme.txt`
- [ ] Add section for version 1.5.0:
  ```
  == Version 1.5.0 ==
  * Add sealed bid auction mode
  * Hide bids until reveal time
  * Configure per-product sealed settings
  * Automatic reveal processing with auto-bidding
  * Audit logging for sealed reveals
  * 30+ new tests for sealed scenarios
  ```

### TASK-INT-7: Bump Plugin Version
- [ ] Edit `init.php` or main plugin file
- [ ] Change version constant: `1.4.0` → `1.5.0`
- [ ] Update plugin header comment
- [ ] Verify version displays correctly in admin: Dashboard → Updates

### TASK-INT-8: Code Quality Review
- [ ] Run PHPStan on all new code:
  ```bash
  ./vendor/bin/phpstan analyse includes/class.yith-wcact-auto-bid.php
  ```
- [ ] Fix any type errors (≥level 5)
- [ ] Run PHPMD:
  ```bash
  ./vendor/bin/phpmd includes/class.yith-wcact-auto-bid.php text codesize
  ```
- [ ] Address any complexity issues

### TASK-INT-9: Security Review
- [ ] Audit all input sanitization (sealed datetime, product_id, etc.)
- [ ] Verify sql injection prevention (all wpdb->prepare)
- [ ] Check XSS protection in output (wp_esc_html, wp_esc_attr)
- [ ] Verify CSRF nonces on admin actions
- [ ] Review permission checks (admin-only, user ownership)

### TASK-INT-10: Performance Testing
- [ ] Test reveal processing time:
  - [ ] 50 bids: should complete < 500ms
  - [ ] 200 bids: should complete < 1500ms
  - [ ] 500+ bids: should complete < 2000ms
- [ ] Profile using XDebug or similar
- [ ] Optimize if exceeds targets (add indexes, batch processes)

### TASK-INT-11: Run Full Test Suite
- [ ] Run all tests (v1.0 + v1.1):
  ```bash
  ./vendor/bin/phpunit tests/
  ```
- [ ] Results:
  - [ ] All tests pass (0 failures)
  - [ ] Coverage ≥95% for new code
  - [ ] No regressions to v1.0 tests
- [ ] Generate coverage report:
  ```bash
  ./vendor/bin/phpunit --coverage-html coverage/ tests/
  ```

### TASK-INT-12: Integration Testing - Full Scenarios
- [ ] Test scenario 1: Open auction (v1.0 behavior still works)
- [ ] Test scenario 2: Sealed to reveal with auto-bidding
- [ ] Test scenario 3: Sealed reveal with single bidder (no auto-bids)
- [ ] Test scenario 4: Sealed reveal with 5+ bidders (complex escalation)
- [ ] Document results

### TASK-INT-13: Create Git Commit
- [ ] Stage all changes:
  ```bash
  git add .
  ```
- [ ] Create conventional commit:
  ```bash
  git commit -m "feat: Add sealed bid auction mode with retroactive auto-bidding
  
  - Implement REQ-SEALED-001 through REQ-SEALED-008
  - Add sealed bid configuration per auction (reveal datetime)
  - Hide current bid and history during sealed period
  - Show 'SEALED BID IN PROGRESS' with countdown timer
  - Collect max bids during sealed period (no display)
  - Process auto-bids retroactively at reveal time
  - Implement WordPress cron for automatic reveals
  - Add manual force-reveal option in admin
  - Audit logging for sealed bid reveal events
  
  Features:
  - New phase 0: Admin UI for sealed settings
  - New phases 1B, 3B, 4B, 5B, 8-9: sealed support
  - Database: 4 new columns + sealed_bid_audit table
  - Frontend: sealed message, countdown timer
  - Backend: retroactive auto-bid processor, cron scheduler
  
  Testing:
  - 40+ new unit/integration tests
  - ≥95% code coverage
  - All v1.0 tests still pass (backward compatible)
  
  Version bumped to 1.5.0"
  ```

### TASK-INT-14: Deployment Checklist
- [ ] Code review approved by team
- [ ] All tests passing locally
- [ ] Migration scripts tested on staging DB
- [ ] Sealed auction manually tested end-to-end
- [ ] Reveal processing manually triggered and verified
- [ ] Performance benchmarks met
- [ ] Documentation complete
- [ ] Changelog updated
- [ ] Ready to merge to main branch

---

## Testing Command Reference

```bash
# Run all tests
./vendor/bin/phpunit tests/

# Run specific test file
./vendor/bin/phpunit tests/unit/SealedBidConfigTest.php

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage/ tests/

# Run specific test method
./vendor/bin/phpunit tests/unit/SealedBidConfigTest.php::SealedBidConfigTest::test_sealed_settings_save_and_load

# Check code quality
./vendor/bin/phpstan analyse includes/

# Check for issues
./vendor/bin/phpmd includes/ text codesize,unusedcode
```

---

## Progress Tracking

**Completed**: —  
**In Progress**: —  
**Blocked**: —  
**Total Progress**: 0/35 tasks (0%)

Track completion by checking boxes above when each task is complete.

---

## Notes & Blockers

- **Blocker**: Cannot start Phase 1B until Phase 0 UI is complete (settings must be captured)
- **Blocker**: Cannot start Phase 4B AJAX changes until Phase 1B DB columns exist
- **Note**: All sealed bid code reuses v1.0 auto-bid engine (no algorithm changes)
- **Note**: For testing sealed reveals: use WordPress time mocking or manually set product meta reveal_datetime to past
- **Note**: WordPress cron might need real system cron setup for reliability (see `/plan/feature-auto-bidding-sealed-bids-1.1.md` section 10)

---

## Estimated Effort Breakdown

| Phase | Tasks | Effort (hrs) | Notes |
|-------|-------|------------|-------|
| Phase 0 | 3 | 3-4 | UI implementation, metabox fields |
| Phase 1B | 2 | 1-2 | DB migrations (straightforward) |
| Phase 3B | 2 | 2-3 | Retroactive algorithm, similar to v1.0 |
| Phase 4B | 2 | 2-3 | AJAX logic with conditional branching |
| Phase 5B | 4 | 3-4 | Frontend display + JS timer |
| Phase 8 | 4 | 2-3 | Cron scheduling, audit logging |
| Phase 9 | 4 | 4-5 | Testing (most time-consuming) |
| Integration | 14 | 3-4 | Docs, review, finalization |
| **TOTAL** | **35** | **20-28 hours** | Includes reviews and testing |

---

**Status**: Ready to begin. Start with Phase 0 Task 0.1.
