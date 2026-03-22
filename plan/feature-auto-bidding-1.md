---
goal: Implement Progressive Auto-Bidding with Multi-Bidder Max Bid Processing
version: 1.0
date_created: 2026-03-22
owner: Development Team
status: 'Planned'
tags: [feature, auction, bidding, auto-bid, proxy-bidding]
---

# Implementation Plan: Auto-Bidding System with Progressive Max Bid Processing

![Status: Planned](https://img.shields.io/badge/status-Planned-blue)

## Executive Summary

Implement a progressive auto-bidding (proxy bidding) system that automatically increments bids for users with maximum bid limits. When a new bid is placed, the system processes all competing max bids in descending order, automatically incrementing each bidder's proxy bid by the minimum increment until their maximum is reached. This ensures fair auction behavior matching eBay-style proxy bidding with proper handling of multiple competing auto-bidders.

## 1. Requirements & Constraints

### Functional Requirements
- **REQ-AUTO-001**: Store user maximum bid per auction product
- **REQ-AUTO-002**: Auto-increment lower proxy bids when new bid placed
- **REQ-AUTO-003**: Process multiple max bids in descending order
- **REQ-AUTO-004**: Continue auto-bidding until no more bids can beat current
- **REQ-AUTO-005**: Track auto-bid origin (which bid triggered it)
- **REQ-AUTO-006**: Maintain chronological bid history (all bids, manual + auto)
- **REQ-AUTO-007**: Prevent user from bidding against themselves

### Non-Functional Requirements
- **PERF-001**: Auto-bid processing must complete < 500ms for typical auctions (≤100 bidders)
- **DATA-001**: Zero data loss on max bids during auto-bidding
- **ATOMICITY-001**: Entire auto-bid sequence must be atomic transaction

### Constraints
- **CON-001**: Keep existing bid table structure unchanged (maintain backward compatibility)
- **CON-002**: Bids already stored in `wp_yith_wcact_auction` table, add columns incrementally
- **CON-003**: Must support both manual bids AND auto-bids seamlessly
- **CON-004**: Increment values determined by `YITH_WCACT_Bid_Increment` class per price range

### Design Patterns
- **PAT-001**: Use Singleton pattern for bid engine (consistent state)
- **PAT-002**: Transaction-based: enter transaction → process auto-bids → commit/rollback
- **PAT-003**: Immutable bid records: never modify stored bids, only insert new ones

---

## 2. Architecture & Data Model

### Database Schema Changes

#### Table: `wp_yith_wcact_auction` (Existing)
Add these columns:
```sql
ALTER TABLE wp_yith_wcact_auction ADD COLUMN (
  is_proxy_bid TINYINT(1) DEFAULT 0 COMMENT 'True if this is auto-bid',
  proxy_source_bid_id BIGINT DEFAULT NULL COMMENT 'FK to bid that triggered this auto-bid',
  user_max_bid DECIMAL(10,2) DEFAULT NULL COMMENT 'Max bid for this user (only if proxy)'
);
```

#### New Table: `wp_yith_wcact_user_max_bids`
```sql
CREATE TABLE wp_yith_wcact_user_max_bids (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  product_id BIGINT NOT NULL,
  max_bid DECIMAL(10,2) NOT NULL COMMENT 'User's maximum willing to pay',
  current_proxy_bid DECIMAL(10,2) DEFAULT 0 COMMENT 'Current auto-bid amount',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user_product (user_id, product_id),
  KEY idx_product_max_bid (product_id, max_bid DESC),
  KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Processing Algorithm: Progressive Auto-Bidding

**Trigger**: When `YITH_WCACT_Auction_Ajax::yith_wcact_add_bid()` receives a valid bid

**Flow**:
```
1. User places bid of amount X on product P
2. Check if bid is valid (minimum, allowed user, etc.)
3. IF valid:
   a. BEGIN TRANSACTION
   b. Insert user's bid into auction table (bid_amount=X, is_proxy_bid=false)
   c. Call process_auto_bids(product_id=P, new_bid_amount=X, new_bid_user=user_id)
   d. COMMIT TRANSACTION
4. IF invalid:
   - Return error message
```

**Sub-process: `process_auto_bids(product_id, new_bid_amount, new_bid_user_id)`**:
```
CURRENT_BID = new_bid_amount
CURRENT_LEADING_USER = new_bid_user_id
INCREMENT = get_increment_for_price(CURRENT_BID)

LOOP:
  COMPETING_BIDDERS = get_all_max_bids(product_id) 
                      WHERE user_id != CURRENT_LEADING_USER 
                      AND max_bid > CURRENT_BID
                      ORDER BY max_bid DESC

  IF COMPETING_BIDDERS is empty:
    RETURN (all auto-bids processed)
  
  HIGHEST_COMPETITOR = COMPETING_BIDDERS[0]
  AUTO_BID_AMOUNT = min(CURRENT_BID + INCREMENT, HIGHEST_COMPETITOR.max_bid)
  
  IF AUTO_BID_AMOUNT == CURRENT_BID:
    RETURN (no progress, exit)
  
  INSERT new bid (
    user_id = HIGHEST_COMPETITOR.user_id,
    bid = AUTO_BID_AMOUNT,
    is_proxy_bid = true,
    proxy_source_bid_id = ID of new_bid_amount,
    user_max_bid = HIGHEST_COMPETITOR.max_bid
  )
  
  UPDATE user_max_bids 
    SET current_proxy_bid = AUTO_BID_AMOUNT 
    WHERE user_id = HIGHEST_COMPETITOR.user_id
  
  CURRENT_BID = AUTO_BID_AMOUNT
  CURRENT_LEADING_USER = HIGHEST_COMPETITOR.user_id
  INCREMENT = get_increment_for_price(CURRENT_BID)
  
  GOTO LOOP
```

### Example Walkthrough

**Scenario**: Current bid $10, increment $1, User A max $22, User B max $30

| Step | Action | Current Bid | Leading User | Notes |
|------|--------|------------|--------------|-------|
| 1 | User B places bid $21 | $21 | User B | New bid inserted, is_proxy=false |
| 2 | Auto-bid process starts | $21 | User B | Get max bids > $21: A($22) |
| 3 | Auto-bid User A | $22 | User A | Auto-bid inserted, proxy_source=User B's bid |
| 4 | Check again | $22 | User A | Get max bids > $22: B($30) |
| 5 | Auto-bid User B | $23 | User B | Auto-bid inserted, proxy_source=User A's auto-bid |
| 6 | Check again | $23 | User B | Get max bids > $23: none (A can't go above $22) |
| 7 | Process ends | $23 | User B | Final: B wins at $23 |

---

## 3. Implementation Phases

### Phase 1: Database Schema & Service Class Foundation

**GOAL-1**: Extend database schema and create core auto-bid service class.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-1.1 | Create migration script for new columns | `includes/class.yith-wcact-auction-db.php` | - `is_proxy_bid`, `proxy_source_bid_id`, `user_max_bid` columns added to `wp_yith_wcact_auction`<br/>- Migration checks for column existence before adding (idempotent)<br/>- DB version incremented to 1.2.0 |
| TASK-1.2 | Create migration for user max_bids table | `includes/class.yith-wcact-auction-db.php` | - `wp_yith_wcact_user_max_bids` table created<br/>- Unique constraint on (user_id, product_id)<br/>- Indexes on product_id + max_bid, user_id<br/>- Created/updated timestamps |
| TASK-1.3 | Create `YITH_WCACT_Auto_Bid` class | `includes/class.yith-wcact-auto-bid.php` | - Singleton pattern implemented<br/>- Constructor initializes table names<br/>- Methods stubbed: process_auto_bids(), get_all_max_bids(), get_increment_for_price()<br/>- PHPDoc with requirement references |
| TASK-1.4 | Add class initialization in main plugin | `includes/class.yith-wcact-auction.php` | - Load new class in init_classes()<br/>- Make accessible via YITH_Auctions()->auto_bid<br/>- No errors on instantiation |

**Validation**: Run unit test: new class loads, singleton returns same instance, tables exist

---

### Phase 2: Max Bid Storage & Retrieval

**GOAL-2**: Implement storage and retrieval of user maximum bids.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-2.1 | Add `save_max_bid()` method | `includes/class.yith-wcact-auto-bid.php` | - Accepts (user_id, product_id, max_bid)<br/>- Validates: user_id > 0, product_id > 0, max_bid > 0<br/>- Upserts to `user_max_bids` table (insert or update)<br/>- Sanitizes floatval(max_bid) |
| TASK-2.2 | Add `get_max_bid()` method | `includes/class.yith-wcact-auto-bid.php` | - Accepts (user_id, product_id)<br/>- Returns max_bid decimal or 0 if not set<br/>- Uses prepared statement |
| TASK-2.3 | Add `get_all_max_bids()` method | `includes/class.yith-wcact-auto-bid.php` | - Accepts (product_id, exclude_user_id=null)<br/>- Returns array of objects: [id, user_id, max_bid, current_proxy_bid]<br/>- Sorted by max_bid DESC, then by created_at ASC (deterministic)<br/>- Only returns max_bid > 0 |
| TASK-2.4 | Add `update_proxy_bid()` method | `includes/class.yith-wcact-auto-bid.php` | - Accepts (user_id, product_id, proxy_bid_amount)<br/>- Updates current_proxy_bid in user_max_bids<br/>- Validates proxy_bid ≤ max_bid (no overspending) |

**Validation**: Unit tests for save, get, all_max_bids, update with various scenarios

---

### Phase 3: Core Auto-Bidding Engine

**GOAL-3**: Implement the progressive auto-bidding algorithm.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-3.1 | Implement `process_auto_bids()` method | `includes/class.yith-wcact-auto-bid.php` | - Signature: process_auto_bids($product_id, $new_bid_amount, $new_bid_user_id)<br/>- Implements algorithm from Architecture section<br/>- Returns array of auto-bids placed: [{user_id, bid_amount, source_bid_id}, ...]<br/>- Stops when no competing max_bid exists or no progress made<br/>- Uses YITH_WCACT_Bid_Increment for increment calculation |
| TASK-3.2 | Add auto-bid insertion to bids table | `includes/class.yith-wcact-auto-bid.php` | - New method: `insert_auto_bid($user_id, $product_id, $bid_amount, $source_bid_id, $user_max_bid)`<br/>- Sets: is_proxy_bid=true, proxy_source_bid_id=$source_bid_id, user_max_bid=$user_max_bid<br/>- Uses wpdb->insert() with prepared format<br/>- Returns new bid ID |
| TASK-3.3 | Wrap process in transaction | `includes/class.yith-wcact-auto-bid.php` | - New method: `process_auto_bids_transactional()`<br/>- Begins transaction, calls process_auto_bids(), commits on success<br/>- Rolls back on exception<br/>- Returns same result as process_auto_bids() |
| TASK-3.4 | Add max increment iteration limit | `includes/class.yith-wcact-auto-bid.php` | - Add constant: `MAX_AUTO_BID_ITERATIONS = 100`<br/>- process_auto_bids() exits after 100 iterations (prevents infinite loops)<br/>- Log warning if limit hit |

**Validation**: Unit tests with scenarios: 2 bidders, 3+ bidders, lead changes, max bid reached

---

### Phase 4: Integration into Bid Submission

**GOAL-4**: Integrate auto-bidding into existing bid submission flow.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-4.1 | Modify AJAX bid handler | `includes/class.yith-wcact-auction-ajax.php` | - In `yith_wcact_add_bid()` after successful bid validation:<br/>- Extract `max_bid` parameter from POST (floatval, sanitize)<br/>- If max_bid provided: call `save_max_bid(user_id, product_id, max_bid)`<br/>- Call `process_auto_bids_transactional(product_id, bid, user_id)`<br/>- Use try-catch for transaction handling |
| TASK-4.2 | Update AJAX response | `includes/class.yith-wcact-auction-ajax.php` | - Add to JSON response: `auto_bids_placed: count(auto_bid_results)`<br/>- Add: `current_leading_bid: final_bid_after_auto_bids`<br/>- Add: `current_leader_id: final_leading_user_id` |
| TASK-4.3 | Update frontend form | `templates/woocommerce/single-product/add-to-cart/auction.php` | - Add hidden input: `<input type="number" name="max_bid" data-auto-bid>`<br/>- Display dynamically (JS controlled, hidden by default)<br/>- Add label: "My maximum bid (auto-increment up to this amount)" |
| TASK-4.4 | Update frontend JavaScript | `assets/js/frontend.js` | - On bid form submission: collect max_bid from input<br/>- Add to POST data under key `max_bid`<br/>- On response: display if auto_bids_placed > 0: "3 other bids auto-placed to match yours"<br/>- Update displayed current_leading_bid with response value |

**Validation**: Integration test: bid placement with max_bid triggers auto-bids, response contains auto_bid data

---

### Phase 5: Data Display & Transparency

**GOAL-5**: Display auto-bids in bid history and auction display.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-5.1 | Update bid history query | `includes/class.yith-wcact-auction-bids.php` | - New method: `get_bids_with_proxy_info($product_id)`<br/>- Returns all bids with: id, user_id, bid, date, is_proxy_bid, proxy_source_bid_id<br/>- Sorted by date ASC (chronological)<br/>- Include user display names (wp_users.display_name) |
| TASK-5.2 | Update bid display template | `templates/frontend/list-bids.php` | - For each bid:<br/>- If is_proxy_bid: show "(auto-bid)" label + icon<br/>- Show user display name<br/>- Show bid amount<br/>- Show timestamp<br/>- Link proxy_source_bid_id as "triggered by [other user's bid]" |
| TASK-5.3 | Update current price display | `templates/woocommerce/single-product/add-to-cart/auction.php` | - Display current leading bid amount (from get_max_bid or process result)<br/>- If leading bid is proxy: show "(auto)" indicator<br/>- Refresh dynamically when AJAX response received |
| TASK-5.4 | Update admin bid analytics | `templates/admin/product-tabs/auction-tab.php` | - Show bid statistics:<br/>- Total bids: X manual + Y auto-bids<br/>- Current leading amount<br/>- Number of active max bidders<br/>- Table of all bids with proxy status |

**Validation**: Manual test: verify bid display shows auto-bids correctly, proxy linkage works

---

### Phase 6: Testing & Validation

**GOAL-6**: Comprehensive unit and integration testing.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-6.1 | Unit tests: max bid storage | `tests/unit/AutoBidTest.php` | - Test save_max_bid: valid/invalid inputs<br/>- Test get_max_bid: existing/non-existing<br/>- Test get_all_max_bids: sorting, filtering, excludes<br/>- Test update_proxy_bid: validation<br/>- 100% coverage of these methods |
| TASK-6.2 | Unit tests: auto-bidding algorithm | `tests/unit/AutoBidProcessTest.php` | - Test two-bidder scenario (A max $22, B $21 → A $22, B $23)<br/>- Test three+ bidder scenarios<br/>- Test lead changes mid-auction<br/>- Test max bid not exceeded<br/>- Test no competing max bids (single bidder)<br/>- Test iteration limit hit (100+ bidders)\- 100% coverage of process_auto_bids |
| TASK-6.3 | Integration tests: bid submission | `tests/integration/AutoBidSubmissionTest.php` | - Full flow: user with max_bid places bid<br/>- Verify auto-bids inserted<br/>- Verify transaction commit/rollback on errors<br/>- Verify response contains auto_bid_placed count |
| TASK-6.4 | Edge case tests | `tests/unit/AutoBidEdgeCasesTest.php` | - User bidding against themselves (prevent)<br/>- Max bid exactly equals previous bid (no increment)<br/>- Bid after auction closes (reject)<br/>- Concurrent bids (transaction isolation)<br/>- Reserved price not met + auto-bid |

**Validation**: All tests pass, coverage ≥ 95%, no regressions on existing tests

---

### Phase 7: Documentation & Finalization

**GOAL-7**: Complete documentation and prepare for deployment.

| Task ID | Task | File(s) | Acceptance Criteria |
|---------|------|---------|-------------------|
| TASK-7.1 | Create architecture documentation | `Project Docs/AUTO_BIDDING_IMPLEMENTATION.md` | - Explain algorithm with diagrams<br/>- Document data schema changes<br/>- List new public methods in YITH_WCACT_Auto_Bid<br/>- Include example walkthrough (3 bidders) |
| TASK-7.2 | Add PHPDoc to all new methods | `includes/class.yith-wcact-auto-bid.php` | - Every method has: @param, @return, @requirement tags<br/>- Include requirement IDs (REQ-AUTO-*)<br/>- Include UML diagrams in class docblock |
| TASK-7.3 | Create user-facing documentation | `Project Docs/AUTO_BIDDING_USER_GUIDE.md` | - Explain proxy bidding for site admins<br/>- Explain for end users (how to use max bid)<br/>- FAQ: "What is auto-bidding?", "Why did my bid increase?" |
| TASK-7.4 | Update README | `readme.txt` | - Add feature: "Proxy Bidding - Automatic bid increments on behalf of max bidders"<br/>- Update version to 1.4.0<br/>- Update changelog |
| TASK-7.5 | Git commit | N/A | - Create commit with all changes<br/>- Use conventional commit format: `feat: Add proxy auto-bidding with progressive max bid processing`<br/>- Reference all REQ-AUTO requirements |

**Validation**: Documentation complete, version bumped, all new code committed

---

## 4. Cross-Phase Dependencies

| From Phase | To Phase | Dependency | Type |
|-----------|---------|-----------|------|
| Phase 1 | Phase 2 | Database tables created | BLOCKING |
| Phase 2 | Phase 3 | save_max_bid(), get_all_max_bids() | BLOCKING |
| Phase 3 | Phase 4 | process_auto_bids_transactional() | BLOCKING |
| Phase 1,2,3 | Phase 5 | Bid data structure finalized | SOFT (display updates) |
| All Phases | Phase 6 | All code written | BLOCKING |
| All Phases | Phase 7 | All code + tests passing | BLOCKING |

---

## 5. Key Implementation Details

### Deterministic Sorting
When multiple users have same max_bid, use creation timestamp as tiebreaker:
```sql
ORDER BY max_bid DESC, created_at ASC
```
This ensures consistent behavior across restarts.

### Preventing Infinite Loops
```php
const MAX_AUTO_BID_ITERATIONS = 100;
$iterations = 0;
while ($iterations < self::MAX_AUTO_BID_ITERATIONS) {
    // process...
    $iterations++;
}
if ($iterations >= self::MAX_AUTO_BID_ITERATIONS) {
    error_log('Auto-bid iteration limit reached for product ' . $product_id);
}
```

### Transaction Isolation
```php
$wpdb->query('START TRANSACTION');
try {
    $auto_bids = $this->process_auto_bids($product_id, $bid, $user_id);
    $wpdb->query('COMMIT');
    return $auto_bids;
} catch (Exception $e) {
    $wpdb->query('ROLLBACK');
    throw $e;
}
```

### Bi-Directional Increment Lookup
```php
// Get increment for CURRENT price (to determine next step)
$increment = YITH_WCACT_Bid_Increment::get_instance()
    ->get_increment_for_price($current_bid, $product_id);
// Auto-bid amount = current_bid + increment (up to max)
$auto_bid_amount = min($current_bid + $increment, $max_bid);
```

---

## 6. Success Criteria

- ✅ All phases completed without regressions
- ✅ All 24 unit tests pass (existing + new auto-bid tests)
- ✅ Auto-bidding occurs correctly for 2+ competing max bidders
- ✅ Bid history displays auto-bids with source attribution
- ✅ No SQL injection vulnerabilities
- ✅ Transaction atomicity verified
- ✅ Documentation complete with examples
- ✅ Version bumped to 1.4.0, changelog updated
- ✅ Feature deployed to starting_bid branch commit

---

## 7. Rollback Plan

If critical issues found post-deployment:
1. Keep old bid table columns/logic intact (don't delete)
2. Add feature flag: `yith_wcact_enable_auto_bid` option (default: true)
3. If disabled: new bids skip auto-bid processing, only manual bids recorded
4. Revert commit, disable feature flag, redeploy

---

## 8. Metrics & Monitoring

Track post-deployment:
- Auto-bid events per hour (count bids with is_proxy_bid=true)
- Average auto-bids per auction
- Auction final prices (compare to pre-auto-bid baseline)
- User satisfaction (admin feedback on fairness)
- Error rate on auto-bid processing
