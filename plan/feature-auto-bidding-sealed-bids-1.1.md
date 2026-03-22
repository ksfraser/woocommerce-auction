---
goal: Extend Auto-Bidding System with Sealed Bid Mode
version: 1.1
date_created: 2026-03-22
last_updated: 2026-03-22
owner: Development Team
status: 'Planned'
tags: [feature, auction, bidding, auto-bid, sealed-bid, configuration]
---

# Implementation Plan: Auto-Bidding with Sealed Bid Mode

![Status: Planned](https://img.shields.io/badge/status-Planned-blue)

## Executive Summary

Extend the progressive auto-bidding system (v1.0) with sealed bid auction mode. Sealed bid auctions collect maximum bids from all participants but do NOT process auto-bidding or reveal current bid amounts/history until a specified reveal date/time. At the reveal time, the system automatically processes all accumulated max bids using the progressive auto-bidding algorithm. This enables "blind auction" semantics where bidders cannot see competitor activity until the reveal moment.

## 1. Requirements & Constraints

### Functional Requirements
- **REQ-SEALED-001**: Configure per-product whether auction is sealed or open
- **REQ-SEALED-002**: Set sealed bid reveal date/time per auction
- **REQ-SEALED-003**: Collect max bids from users during sealed period (no display)
- **REQ-SEALED-004**: Hide current bid and history during sealed period, show "SEALED BID IN PROGRESS"
- **REQ-SEALED-005**: Prevent manual bid placement during sealed period (accept via UI but don't display max bid input)
- **REQ-SEALED-006**: Process all accumulated auto-bids retroactively at reveal time
- **REQ-SEALED-007**: Transition auction from sealed → open after reveal time automatically
- **REQ-SEALED-008**: Display reveal countdown timer during sealed period

### Non-Functional Requirements
- **PERF-SEALED-001**: Retroactive auto-bid processing must complete < 2s for 100+ bidders
- **AUDIT-SEALED-001**: Log all sealed bid reveal events
- **RELIAB-SEALED-001**: Guarantee all max bids processed exactly once at reveal time

### Constraints
- **CON-SEALED-001**: Sealed mode is OPTIONAL per auction (backward compatible)
- **CON-SEALED-002**: Sealed reveals trigger auto-bid engine (reuse Phase 3 from v1.0)
- **CON-SEALED-003**: Sealed mode can only be enabled before auction starts
- **CON-SEALED-004**: Reveal time must be after auction start, before auction end
- **CON-SEALED-005**: No changes to core auto-bid algorithm (v1.0 unchanged)

### Design Patterns
- **PAT-SEALED-001**: Scheduler pattern for reveal time trigger (WordPress cron or native cron)
- **PAT-SEALED-002**: Configuration traits: sealed_bid_enabled(), get_sealed_reveal_time()
- **PAT-SEALED-003**: Feature flag inspection: on each display, check if sealed + not yet revealed

---

## 2. Architecture & Data Model

### Database Schema Changes

#### Table: `wp_yith_wcact_auction` (Additional Columns)
Extend schema from v1.0 with sealed bid columns:
```sql
ALTER TABLE wp_yith_wcact_auction ADD COLUMN (
  -- Sealed Bid Mode columns
  is_sealed_bid TINYINT(1) DEFAULT 0 COMMENT 'True if this auction is sealed until reveal_time',
  sealed_reveal_datetime DATETIME DEFAULT NULL COMMENT 'UTC datetime when sealed bids are revealed and auto-bidding processes',
  sealed_reveal_processed TINYINT(1) DEFAULT 0 COMMENT 'True if sealed reveal has been executed (auto-bids processed)',
  sealed_max_bids_collected INT DEFAULT 0 COMMENT 'Count of max bids submitted during sealed period'
);
```

#### Product Meta Extension
Per-auction configuration stored in WordPress post meta:
```php
// In product metabox, save:
update_post_meta($product_id, '_yith_wcact_is_sealed_bid', 1/0);
update_post_meta($product_id, '_yith_wcact_sealed_reveal_date', '2026-03-25'); // YYYY-MM-DD
update_post_meta($product_id, '_yith_wcact_sealed_reveal_time', '16:30:00'); // HH:MM:SS UTC
```

### Display Logic Enhancement

**During Sealed Period** (now < reveal_datetime AND sealed_reveal_processed = 0):
```text
[Current Price Display]
🔒 SEALED BID IN PROGRESS
Reveal: Countdown Timer
(e.g., "Reveal in 2 days, 4 hours, 23 minutes")

[Bid History Display]
🔒 Bid history will be revealed at: 2026-03-25 4:30 PM UTC

[Place Bid Section]
✓ Max Bid field shown (hidden label, collected but not displayed)
✗ Current bid display hidden
✗ No bid history visible
```

**After Reveal Time** (sealed_reveal_processed = 1):
- Show all bids (manual + auto-bids from reveal processing)
- Show current leading bid amount
- Show bid history chronologically with "(auto-bid)" labels
- Behaves exactly like open auction (v1.0)

### Processing Flow: Sealed Bid Reveal

**Trigger**: WordPress cron event at or after reveal_datetime

**Flow**:
```
1. Cron task fires: yith_wcact_sealed_bid_reveal_check
2. Query all auctions WHERE is_sealed_bid=1 AND sealed_reveal_processed=0 
                        AND sealed_reveal_datetime <= NOW()
3. FOR EACH auction:
   a. BEGIN TRANSACTION
   b. Get all max_bids for this product sorted DESC by max_bid
   c. Determine starting bid (current highest manual bid or start price)
   d. Call process_auto_bids_retroactive(product_id, starting_bid, accumulate=true)
   e. Set sealed_reveal_processed = 1, sealed_max_bids_collected = count
   f. Log event: "Sealed bid reveal processed for product X: N bids, final=amount"
   g. COMMIT TRANSACTION
   h. Trigger action hook: do_action('yith_wcact_sealed_bid_revealed', product_id)
4. Return success (count of auctions processed)
```

**Sub-process: `process_auto_bids_retroactive()`** (New method in YITH_WCACT_Auto_Bid)

NOTE: v1.1 uses SIMPLIFIED single-bid model (not cascading increments)

```
// Simplified retroactive processing: insert highest max_bid only
// No incremental escalation

MAX_BIDS = get_all_max_bids(product_id) ORDER BY max_bid DESC

IF max_bids is empty:
  RETURN (no processing needed)

// Winner is highest max_bid user
WINNER = MAX_BIDS[0]
WINNING_BID_AMOUNT = WINNER.max_bid

// Insert single auto-bid at winner's max
INSERT auto-bid (
  user_id = WINNER.user_id,
  bid_amount = WINNING_BID_AMOUNT,
  is_proxy_bid = true,
  user_max_bid = WINNER.max_bid
)

RETURN {winner_user_id, final_bid: WINNING_BID_AMOUNT, auto_bids_placed: 1}
```

**Key Difference**: Unlike open auctions (v1.0) which cascade incremental bidding between competitors, sealed auctions simply insert the single highest max bid. Simpler, fairer, less gaming.

### Example: Sealed Bid Reveal (Single Highest Bid Model)

**Setup**: Auction sealed until 2026-03-25 16:30 UTC
- User A submits max bid $50
- User B submits max bid $35
- User C submits max bid $22
- Sealed period ends at reveal time

**At Reveal Time** (single highest bid insertion):
| Step | Action | Final Bid | Notes |
|------|--------|-----------|-------|
| 1 | Get all max_bids sorted DESC | — | A=$50, B=$35, C=$22 |
| 2 | Determine winner (highest) | $50 | User A (max $50 is highest) |
| 3 | Insert single auto-bid | $50 | Winner: A at $50 |
| Result | Auction ends, A wins | $50 | Single bid, no escalation |

**Compare to Open Auction (v1.0)**:  
Open would escalate: A→$50, B→$51 (if B had higher max), etc.  
Sealed just picks highest: **A wins at $50 (simpler, fairer)**

**Alternative Scenario** (multi-step reveal):
- User A max $30
- User B max $50 (higher, will lead initially)
- User C max $25
- Increment $2

**At Reveal Time**:
| Step | Current | Notes |
|------|---------|-------|
| 1 | $50 (B's max) | Start with highest |
| 2 | A's $30 < $50, skip | No auto-bid |
| 3 | C's $25 < $50, skip | No auto-bid |
| Result | B wins at $50 | No auto-bids placed in this scenario |

**Complex Reveal Scenario** (incremental):
- User A max $25
- User B max $30
- User C max $80
- Increment $1

**At Reveal Time**:
| Step | Current | Processing | Notes |
|------|---------|-----------|-------|
| 1 | $80 (C) | Start with C's max | C is highest |
| 2 | A $25 < $80 | No bid | A can't compete |
| 3 | B $30 < $80 | No bid | B can't compete |
| Result | C wins at $80 | No incremental bids (C dominates) | |

**True Multi-Bidder Scenario**:
- User A max $25
- User B max $26
- User C max $27
- Increment $1

**At Reveal Time**:
| Step | Current | Bidder | New Bid | Notes |
|-------|---------|--------|---------|-------|
| 1 | $27 (C) | Start | — | C is highest max |
| 2 | Check B ($26) | B | $26 → No change | B's max < C's |
| 3 | Check A ($25) | A | $25 → No change | A's max < C's |
| Result | C leads at $27 | — | No auto-bids (C's max highest) | |

**If B submits higher max**:
- User A max $25
- User B max $28  
- User C max $27
- Increment $1

| Step | Current | Processing |
|------|---------|-----------|
| 1 | $28 (B) | B is highest max |
| 2 | C $27 < $28 | C doesn't auto-bid (below) |
| 3 | A $25 < $28 | A doesn't auto-bid (below) |
| Result | B wins at $28 | |

---

## 3. Implementation Phases

### Phase 0: Product Metabox UI for Sealed Bid Configuration

**GOAL-0**: Add UI controls for configuring sealed bid mode.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-0.1 | Add sealed bid metabox to product panel | `panel/product-auction-settings.php` or metabox | - New section: "Sealed Bid Settings"<br/>- Checkbox: "Enable Sealed Bid Mode"<br/>- Disabled by default, only available before auction starts<br/>- Disable toggle after auction start date passes |
| TASK-0.2 | Add reveal date/time picker | `panel/product-auction-settings.php` | - Datepicker: "Reveal Date"<br/>- Timepicker: "Reveal Time (UTC)"<br/>- Validation: reveal_datetime > now() AND < auction_end_datetime<br/>- Store in post meta: _yith_wcact_sealed_reveal_date, _yith_wcact_sealed_reveal_time |
| TASK-0.3 | Update product save handler | `includes/class.yith-wcact-auction-product.php` | - On save: validate sealed_bid settings<br/>- Populate columns: is_sealed_bid, sealed_reveal_datetime in auction table<br/>- Error if reveal time in past<br/>- Only allow changes before auction start |

**Validation**: Metabox loads, settings save/load, validation works

---

### Phase 1B: Extend Database Schema with Sealed Bid Columns

**GOAL-1B**: Add database columns for sealed bid tracking.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-1B.1 | Add sealed columns to auction table | `includes/class.yith-wcact-auction-db.php` | - Columns from §2: is_sealed_bid, sealed_reveal_datetime, sealed_reveal_processed, sealed_max_bids_collected<br/>- Migration checks for existence (idempotent)<br/>- DB version incremented to 1.3.0 |
| TASK-1B.2 | Create sealed bid audit log table | `includes/class.yith-wcact-auction-db.php` | - New table: `wp_yith_wcact_sealed_bid_audit`<br/>- Columns: id, product_id, reveal_datetime, auto_bids_count, final_bid_amount, processed_at, status (success/error)<br/>- For tracking reveals and debugging |

**Validation**: Migrations run, columns exist, no errors

---

### Phase 3B: Extend Auto-Bid Engine with Retroactive Processing

**GOAL-3B**: Add `process_auto_bids_retroactive()` method for sealed reveals.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-3B.1 | Add retroactive processing method | `includes/class.yith-wcact-auto-bid.php` | - New method: `process_auto_bids_retroactive($product_id)`<br/>- Gets all max_bids, determines winner from highest max_bid<br/>- Processes remaining max_bids against that highest amount<br/>- Returns: {winner_user_id, final_bid, auto_bids_placed: []}<br/>- Uses same algorithm as process_auto_bids (Phase 3) |
| TASK-3B.2 | Add sealed reveal transaction wrapper | `includes/class.yith-wcact-auto-bid.php` | - New method: `process_sealed_reveal_transactional($product_id)`<br/>- BEGIN TRANSACTION → process_auto_bids_retroactive() → UPDATE sealed_reveal_processed=1 → COMMIT<br/>- Rollback on error, log exception<br/>- Update sealed_max_bids_collected count |

**Validation**: Methods callable, retroactive processing works, transactions roll back on error

---

### Phase 4B: Update AJAX Handler to Support Sealed Mode

**GOAL-4B**: Modify bid submission to skip auto-bidding during sealed period.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-4B.1 | Check sealed period in AJAX | `includes/class.yith-wcact-auction-ajax.php` | - In `yith_wcact_add_bid()` after bid validation:<br/>- Check: is_sealed_bid && sealed_reveal_processed==0 && now < sealed_reveal_datetime<br/>- If in sealed period:<br/>  - Accept max_bid, save to user_max_bids<br/>  - Skip call to process_auto_bids_transactional<br/>  - Return response: is_in_sealed_mode=true, countdown_seconds=(reveal - now)<br/>- If not sealed: proceed normally (v1.0 behavior) |
| TASK-4B.2 | Update AJAX response for sealed | `includes/class.yith-wcact-auction-ajax.php` | - For sealed auctions:<br/>- Include: current_bid (hidden), is_sealed_mode=true, reveal_datetime, countdown_seconds<br/>- Do NOT include auto_bids_placed in sealed mode<br/>- Include: max_bid_accepted=true (if valid) |

**Validation**: Sealed bids collected without auto-bidding, response identifies sealed state

---

### Phase 5B: Update Display Logic for Sealed Mode

**GOAL-5B**: Show "SEALED BID IN PROGRESS" message and hide bid data during sealed period.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-5B.1 | Update current price display | `templates/woocommerce/single-product/add-to-cart/auction.php` | - Check: is_sealed && !is_revealed<br/>- If sealed: display "🔒 SEALED BID IN PROGRESS"<br/>- Show countdown: "Reveal in X days, Y hours, Z minutes"<br/>- Show reveal datetime in UTC<br/>- Hide current bid amount<br/>- Disable bid amount display |
| TASK-5B.2 | Update bid history template | `templates/frontend/list-bids.php` | - Check: is_sealed && !is_revealed<br/>- If sealed: show "🔒 Bid history will be revealed at [datetime]"<br/>- Hide all bid entries (is_proxy_bid, amounts, users)<br/>- If revealed: show all bids normally (v1.0 behavior) |
| TASK-5B.3 | Max bid input visibility | `templates/woocommerce/single-product/add-to-cart/auction.php` | - Sealed mode: show max bid field (hidden label "Maximum Bid")<br/>- JS controls visibility: show input, suppress display of max bid to user<br/>- Show message: "Your bid is secure and hidden"<br/>- Field visible and functional but don't show entered value |
| TASK-5B.4 | Frontend timer countdown | `assets/js/frontend.js` | - If sealed: start countdown timer on page load<br/>- Update every second: recalculate (reveal_time - now)<br/>- Stop when revealing: perform page reload/refresh bid data<br/>- Show "Revealed!" momentarily if timer hits zero |

**Validation**: Sealed auctions display correctly, bid history hidden, timer updates, timely reveal display

---

### Phase 8: Scheduled Reveal Processing (WordPress Cron)

**GOAL-8**: Implement automatic reveal processing at scheduled times.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-8.1 | Register cron event | `includes/class.yith-wcact-auction.php` | - On plugin activation: register recurring cron `yith_wcact_sealed_bid_reveal`<br/>- Schedule: Every 5 minutes (wp_schedule_event)<br/>- Hook function: `handle_sealed_bid_reveal()` |
| TASK-8.2 | Implement reveal handler | `includes/class.yith-wcact-auto-bid.php` | - New method: `handle_sealed_bid_reveal()`<br/>- Query auctions: is_sealed_bid=1 AND sealed_reveal_processed=0 AND reveal_datetime <= NOW()<br/>- FOR EACH: call process_sealed_reveal_transactional($product_id)<br/>- Log result: count processed, any errors<br/>- Trigger hook: do_action('yith_wcact_sealed_bid_revealed_batch')<br/>- Return count of processed auctions |
| TASK-8.3 | Create audit trail | `includes/class.yith-wcact-auction-db.php` | - After reveal processing: insert into wp_yith_wcact_sealed_bid_audit<br/>- Fields: product_id, reveal_datetime, auto_bids_count, final_bid_amount, status<br/>- Enabled debugging for reveal failures |
| TASK-8.4 | Add manual reveal trigger | `panel/admin-auction-settings.php` | - Add button in auction admin: "Force Reveal Now"<br/>- Only visible if is_sealed && not yet revealed<br/>- Calls: process_sealed_reveal_transactional(product_id)<br/>- Shows result: success/error message |

**Validation**: Cron hook registered, reveals trigger automatically at time, audit logs populated

---

### Phase 9: Testing Sealed Bid Scenarios

**GOAL-9**: Comprehensive test coverage for sealed bid modes.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-9.1 | Unit tests: sealed bid configuration | `tests/unit/SealedBidConfigTest.php` | - Test sealed settings save/load<br/>- Test validation: reveal_time > now, < auction_end<br/>- Test disabling toggle after start date<br/>- Test DB columns created properly<br/>- 100% coverage |
| TASK-9.2 | Unit tests: retroactive processing | `tests/unit/SealedBidProcessTest.php` | - Test single max_bid (no auto-bids needed)<br/>- Test multi-bidder where highest max_bid wins<br/>- Test incremental reveals (B max $30, A max $25 → B wins $30)<br/>- Test edge case: same max_bid from multiple users<br/>- 100% coverage of retroactive logic |
| TASK-9.3 | Integration tests: sealed auction flow | `tests/integration/SealedBidAuctionTest.php` | - Full flow: create sealed auction, place max bids, wait for reveal (mock time)<br/>- Verify: bids hidden during sealed period<br/>- Verify: auto-bids processed at reveal time<br/>- Verify: bids visible after reveal |
| TASK-9.4 | Integration tests: display logic | `tests/integration/SealedBidDisplayTest.php` | - Test frontend shows "SEALED BID IN PROGRESS"<br/>- Test countdown timer updates correctly<br/>- Test bid history hidden<br/>- Test max bid field functional (accepted but hidden)<br/>- Test post-reveal display shows bids<br/>- 100% coverage of display conditions |

**Validation**: All sealed bid tests pass, edge cases covered, ≥95% coverage

---

## 4. Cross-Phase Dependencies

| Phase | Blocks | Dependency |
|-------|--------|-----------|
| Phase 0 | Phase 1B, 3B | UI captures sealed settings |
| Phase 1B | Phase 3B | DB columns available |
| Phase 3B | Phase 4B, 8 | Retroactive processing ready |
| Phase 4B | Phase 5B | AJAX knows sealed state |
| Phase 5B | Phase 8 | Display logic ready for reveal |
| Phase 8 | Phase 9 | Reveal mechanism works |
| All Phases (0-8) | Phase 9 | Code complete for testing |

---

## 5. Configuration Examples

### Admin Configuration (Metabox Settings)
```
Auction Bidding Settings
─────────────────────────
☑ Enable Auto-Bidding (increment max bids)
☑ Enable Sealed Bid Mode
  
  [x] Sealed Bid Configuration
  ┌─────────────────────────────────────┐
  Reveal Date: [2026-03-25 ▼]          │
  Reveal Time (UTC): [16:30:00 ▼]      │
  
  Details:
  - Bids will remain sealed until 2026-03-25 at 4:30 PM UTC
  - Auto-bidding will process all max bids retroactively at reveal time
  - Bidders cannot see competitor activity until reveal
  └─────────────────────────────────────┘
```

### Frontend Display (During Sealed Period)
```
Product: Premium Vintage Auction
─────────────────────────────────

🔒 SEALED BID IN PROGRESS

Reveal countdown: 2 days, 4 hours, 23 minutes
Reveal time: 2026-03-25 at 4:30 PM UTC

┌─────────────────────────┐
│ Place Your Maximum Bid  │
│ ────────────────────── │
│ Enter maximum amount:   │
│ [________________] $     │
│                         │
│ Your bid is secure      │
│ and hidden until reveal  │
│                         │
│ [Place Bid]            │
└─────────────────────────┘

🔒 Bid history will be revealed at 2026-03-25 4:30 PM UTC
```

### Frontend Display (After Reveal)
```
Auction Status: CLOSED (Revealed)

Current Bid: $35.50 (Auto-bid)
Current Leader: User Alice

Bid History (5 total - 2 auto-bids)
──────────────────────────────────
1. User Bob      $30.00  Reveal - 16:28 UTC (manual bid placed during sealed period)
2. User Alice    $31.50  Reveal - 16:30 UTC (auto-bid triggered by Bob's bid)
3. User Charlie  $15.00  Reveal - 16:20 UTC (manual bid placed during sealed period)
4. User Alice    $32.00  Reveal - 16:30 UTC (auto-bid triggered by Charlie's bid)
5. User Alice    $33.00  Reveal - 16:30 UTC (auto-bid response)
6. User Bob      $34.00  Reveal - 16:30 UTC (auto-bid response)
7. User Alice    $35.50  Reveal - 16:30 UTC (auto-bid response)

[Alice wins at $35.50]
```

---

## 6. Success Criteria

- ✅ All v1.0 phases still pass (backward compatible)
- ✅ All sealed bid phases complete (0-9)
- ✅ Sealed auction bids hidden during sealed period
- ✅ Countdown timer displays and updates correctly
- ✅ Retroactive auto-bidding processes at reveal time
- ✅ All bids visible after reveal (including auto-bids)
- ✅ WordPress cron triggers reveals automatically
- ✅ Manual force-reveal button works in admin
- ✅ All sealed bid tests pass (≥95% coverage)
- ✅ No regressions to v1.0 auto-bidding
- ✅ Documentation updated with sealed bid explanations
- ✅ Version bumped to 1.5.0

---

## 7. Rollback Plan

**If critical sealed bid issues found**:
1. Feature flag: `yith_wcact_enable_sealed_bid` (default: true)
2. If disabled during sealed period:
   - Show "Sealed bid auctions currently unavailable"
   - Accept bids normally (ignore sealed mode)
3. Revert commit, disable flag, redeploy
4. Manual audit log review required post-issue

---

## 8. Metrics & Monitoring

### Track Post-Deployment
- Sealed auctions created (fraction vs total auctions)
- Reveals triggered on schedule (count, timing accuracy)
- Bids collected during sealed period (count, user participation)
- Auto-bids processed at reveal (count, average per auction)
- Reveal processing time (p50, p95, p99 latency)
- Errors during reveal processing (count, error types)

### Performance Targets
- Reveal processing: < 2s for 100+ bidders per auction
- Countdown timer: < 1s latency for timer update
- Metadata load time: < 100ms added overhead
- Zero data loss events

---

## 9. Integration with v1.0 Auto-Bidding

### Sealed Mode Uses v1.0 Algorithm
- Same `process_auto_bids()` method (reused)
- Same increment calculation (YITH_WCACT_Bid_Increment)
- Same sorted max_bid processing (DESC max_bid, ASC created_at)
- Same transaction atomicity
- Same iteration limits (MAX_AUTO_BID_ITERATIONS=100)

### Key Difference
- **v1.0**: Auto-bids occur immediately when new bid placed
- **v1.1**: Sealed mode suspends auto-bid processing until reveal time, then processes all retroactively

### Database Compatibility
- v1.0 columns: is_proxy_bid, proxy_source_bid_id, user_max_bid (unchanged)
- v1.1 additions: is_sealed_bid, sealed_reveal_datetime, sealed_reveal_processed (default 0 = open mode)
- Migrations run sequentially: v1.0 columns first, v1.1 columns second
- Open auctions (is_sealed_bid=0): behave exactly like v1.0

---

## 10. Implementation Notes

### WordPress Cron Reliability
- Note: WordPress cron requires site traffic to fire (not true cron)
- For high-reliability, recommend setting real Linux cron:
  ```bash
  */5 * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
  ```
- Alternative: CLI command (`wp yith-auction sealed-reveal`) for manual/system cron

### Timezone Handling
- Store reveal datetime in UTC (all times stored UTC)
- On display: convert to site timezone for readability
- Cron comparisons: all in UTC for consistency
- User-facing UI shows "(UTC)" to avoid confusion

### Testing Sealed Auctions
- In tests: mock WordPress time with `wp_set_current_user()` + `strtotime()`
- Create sealed auction with reveal_datetime in past → auto-triggers at first cron check
- Create sealed auction with future reveal_datetime → mock time forward to test reveal

---

## 11. Versioning & Changelog

### Version Bump: 1.4.0 → 1.5.0

**CHANGELOG Entry**:
```
## Version 1.5.0 - Sealed Bid Auction Support

### Features
- Add sealed bid auction mode: hide bids until reveal time
- Configure sealed parameters per auction (reveal datetime)
- Display countdown timer during sealed period
- Retroactive auto-bid processing at reveal time
- Audit logging for sealed bid reveals
- Manual force-reveal option in admin

### Improvements
- Extend auto-bidding to support sealed scenarios
- Add sealed bid configuration UI in metabox
- Enhance display logic to hide/show based on sealed state

### Database
- Add 4 columns to wp_yith_wcact_auction table
- Add wp_yith_wcact_sealed_bid_audit table for tracking

### Testing
- 30+ new tests for sealed bid scenarios
- Coverage ≥95%
- All v1.0 tests still pass (backward compatible)

### Backward Compatibility
- ✅ v1.0 auto-bidding still works unchanged
- ✅ Open auctions (non-sealed) behave identically to v1.0
- ✅ All v1.0 columns/data preserved
```

