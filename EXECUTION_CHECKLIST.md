# Auto-Bidding Implementation Checklist

**Plan Reference**: `/plan/feature-auto-bidding-1.md`  
**Status**: Not Started  
**Owner**: -  
**Target Completion**: -  

---

## Phase 1: Database Schema & Service Class Foundation

### Deliverable: Extend DB schema + create YITH_WCACT_Auto_Bid class

- [ ] **TASK-1.1**: Create migration script for new columns
  - [ ] Alter `wp_yith_wcact_auction` table
    - [ ] Add `is_proxy_bid` TINYINT(1) DEFAULT 0
    - [ ] Add `proxy_source_bid_id` BIGINT DEFAULT NULL
    - [ ] Add `user_max_bid` DECIMAL(10,2) DEFAULT NULL
  - [ ] Update `class.yith-wcact-auction-db.php`
  - [ ] Increment DB version to 1.2.0
  - [ ] Verify migration is idempotent (re-run safe)

- [ ] **TASK-1.2**: Create migration for user max_bids table
  - [ ] Create `wp_yith_wcact_user_max_bids` table
    - [ ] Columns: id, user_id, product_id, max_bid, current_proxy_bid, created_at, updated_at
    - [ ] UNIQUE KEY on (user_id, product_id)
    - [ ] INDEX on product_id + max_bid DESC
    - [ ] INDEX on user_id
  - [ ] Add to `class.yith-wcact-auction-db.php`

- [ ] **TASK-1.3**: Create YITH_WCACT_Auto_Bid class
  - [ ] File: `includes/class.yith-wcact-auto-bid.php`
  - [ ] Implement Singleton pattern
  - [ ] Constructor: initialize `$this->table_name` and `$this->user_max_bids_table`
  - [ ] Stub methods:
    - [ ] `save_max_bid()`
    - [ ] `get_max_bid()`
    - [ ] `get_all_max_bids()`
    - [ ] `update_proxy_bid()`
    - [ ] `process_auto_bids()`
    - [ ] `insert_auto_bid()`
  - [ ] Add full PHPDoc with REQ-AUTO requirements
  - [ ] Add UML diagram in class docblock

- [ ] **TASK-1.4**: Initialize in main plugin file
  - [ ] Edit `includes/class.yith-wcact-auction.php`
  - [ ] Add require/include for new class
  - [ ] Add `'includes/class.yith-wcact-auto-bid.php'` to `$common_requires`
  - [ ] Initialize: `$this->auto_bid = YITH_WCACT_Auto_Bid::get_instance()`
  - [ ] Make accessible via `YITH_Auctions()->auto_bid`
  - [ ] Test: no PHP errors on plugin load

---

## Phase 2: Max Bid Storage & Retrieval

### Deliverable: Store and retrieve user maximum bids

- [ ] **TASK-2.1**: Implement `save_max_bid()` method
  - [ ] Signature: `save_max_bid($user_id, $product_id, $max_bid)`
  - [ ] Validation:
    - [ ] user_id > 0
    - [ ] product_id > 0
    - [ ] max_bid > 0
  - [ ] Sanitize: `floatval($max_bid)`
  - [ ] Upsert to user_max_bids (INSERT ... ON DUPLICATE KEY UPDATE)
  - [ ] Return: inserted/updated row ID or false on error

- [ ] **TASK-2.2**: Implement `get_max_bid()` method
  - [ ] Signature: `get_max_bid($user_id, $product_id)`
  - [ ] Query: prepared statement with wpdb->get_var()
  - [ ] Return: decimal or 0 if not exists

- [ ] **TASK-2.3**: Implement `get_all_max_bids()` method
  - [ ] Signature: `get_all_max_bids($product_id, $exclude_user_id = null)`
  - [ ] Query: prepared statement with wpdb->get_results()
  - [ ] Filter: max_bid > 0
  - [ ] Sort: `max_bid DESC, created_at ASC` (deterministic)
  - [ ] Return: stdClass array

- [ ] **TASK-2.4**: Implement `update_proxy_bid()` method
  - [ ] Signature: `update_proxy_bid($user_id, $product_id, $proxy_bid_amount)`
  - [ ] Validation: proxy_bid_amount ≤ max_bid
  - [ ] Update: `user_max_bids.current_proxy_bid`
  - [ ] Return: rows affected

---

## Phase 3: Core Auto-Bidding Engine

### Deliverable: Implement progressive auto-bidding algorithm

- [ ] **TASK-3.1**: Implement `process_auto_bids()` method
  - [ ] Implement algorithm from plan Architecture section
  - [ ] Loop:
    - [ ] Get competing max bids
    - [ ] Sort by max_bid DESC
    - [ ] For each: calculate auto_bid_amount = min(current + increment, their_max)
    - [ ] If no progress: break
    - [ ] If iteration limit (100): log warning and break
  - [ ] Return: array of auto-bids placed
  - [ ] Track source_bid_id for each auto-bid

- [ ] **TASK-3.2**: Implement `insert_auto_bid()` method
  - [ ] Signature: `insert_auto_bid($user_id, $product_id, $bid_amount, $source_bid_id, $user_max_bid)`
  - [ ] Set: is_proxy_bid=1, proxy_source_bid_id=$source_bid_id, user_max_bid=$user_max_bid
  - [ ] Insert to auction table using wpdb->insert()
  - [ ] Return: new bid ID

- [ ] **TASK-3.3**: Wrap in transaction
  - [ ] New method: `process_auto_bids_transactional($product_id, $new_bid, $new_user_id)`
  - [ ] START TRANSACTION
  - [ ] Try: call process_auto_bids()
  - [ ] Catch: ROLLBACK
  - [ ] Commit on success
  - [ ] Return: same as process_auto_bids()

- [ ] **TASK-3.4**: Add iteration limit
  - [ ] Define: `const MAX_AUTO_BID_ITERATIONS = 100;`
  - [ ] Check in loop: `$iterations < MAX_AUTO_BID_ITERATIONS`
  - [ ] Log warning if limit hit

---

## Phase 4: Integration into Bid Submission

### Deliverable: Connect auto-bidding to bid submission flow

- [ ] **TASK-4.1**: Modify AJAX bid handler
  - [ ] File: `includes/class.yith-wcact-auction-ajax.php`
  - [ ] In `yith_wcact_add_bid()` method:
    - [ ] Extract `max_bid` from POST: `floatval(sanitize_text_field(...))`
    - [ ] After bid validation & insert:
      - [ ] Call `save_max_bid($userid, $product_id, $max_bid)` if provided
      - [ ] Call `process_auto_bids_transactional(...)`
    - [ ] Use try-catch on transaction

- [ ] **TASK-4.2**: Update AJAX response
  - [ ] Add to JSON: `auto_bids_placed` (count)
  - [ ] Add to JSON: `current_leading_bid` (final amount)
  - [ ] Add to JSON: `current_leader_id` (leading user_id)

- [ ] **TASK-4.3**: Update frontend form
  - [ ] File: `templates/woocommerce/single-product/add-to-cart/auction.php`
  - [ ] Add input:
    ```html
    <input type="number" id="_max_bid" name="max_bid" 
           data-auto-bid style="display:none;">
    ```
  - [ ] Add label: "My maximum bid (auto-increment up to this amount)"

- [ ] **TASK-4.4**: Update frontend JavaScript
  - [ ] File: `assets/js/frontend.js`
  - [ ] On bid submit: collect max_bid value
  - [ ] Add to POST data: `max_bid` field
  - [ ] On response: 
    - [ ] If `auto_bids_placed > 0`: show message
    - [ ] Update displayed current bid with response value

---

## Phase 5: Data Display & Transparency

### Deliverable: Display auto-bids in history and UI

- [ ] **TASK-5.1**: Update bid history query
  - [ ] File: `includes/class.yith-wcact-auction-bids.php`
  - [ ] New method: `get_bids_with_proxy_info($product_id)`
  - [ ] Include: is_proxy_bid, proxy_source_bid_id, user display name
  - [ ] Sort: date ASC (chronological)

- [ ] **TASK-5.2**: Update bid display template
  - [ ] File: `templates/frontend/list-bids.php`
  - [ ] For each bid:
    - [ ] If is_proxy_bid: show "(auto-bid)" label
    - [ ] Show user name, amount, timestamp
    - [ ] Link to source bid if proxy

- [ ] **TASK-5.3**: Update current price display
  - [ ] File: `templates/woocommerce/single-product/add-to-cart/auction.php`
  - [ ] Display: current leading bid amount
  - [ ] Indicator: "(auto)" if proxy bid
  - [ ] Refresh on AJAX response

- [ ] **TASK-5.4**: Update admin bid analytics
  - [ ] File: `templates/admin/product-tabs/auction-tab.php`
  - [ ] Show stats:
    - [ ] Total bids: X manual + Y auto
    - [ ] Current leading amount
    - [ ] Number of active max bidders
    - [ ] Table with proxy status

---

## Phase 6: Testing & Validation

### Deliverable: Comprehensive test coverage (≥95%)

- [ ] **TASK-6.1**: Unit tests - max bid storage
  - [ ] File: `tests/unit/AutoBidTest.php`
  - [ ] Tests:
    - [ ] save_max_bid: valid input, invalid input, upsert
    - [ ] get_max_bid: existing, non-existing
    - [ ] get_all_max_bids: sorting, filtering, excludes
    - [ ] update_proxy_bid: valid, validation fails
  - [ ] Coverage: 100% of methods

- [ ] **TASK-6.2**: Unit tests - auto-bidding algorithm
  - [ ] File: `tests/unit/AutoBidProcessTest.php`
  - [ ] Test scenarios:
    - [ ] Two bidders (A max $22, B $21)
    - [ ] Three+ bidders
    - [ ] Lead changes mid-auction
    - [ ] Max bid not exceeded
    - [ ] Single bidder (no auto-bids)
    - [ ] Iteration limit hit
  - [ ] Coverage: 100% of process_auto_bids()

- [ ] **TASK-6.3**: Integration tests - bid submission
  - [ ] File: `tests/integration/AutoBidSubmissionTest.php`
  - [ ] Test full flow:
    - [ ] User with max_bid places bid
    - [ ] Auto-bids inserted
    - [ ] Transaction commit/rollback
    - [ ] Response contains auto_bid_placed

- [ ] **TASK-6.4**: Edge case tests
  - [ ] File: `tests/unit/AutoBidEdgeCasesTest.php`
  - [ ] Test:
    - [ ] User bidding against themselves (prevent)
    - [ ] Max bid = previous bid (no increment)
    - [ ] Bid after auction closed (reject)
    - [ ] Concurrent bids (transaction isolation)
    - [ ] Reserve price not met + auto-bid

- [ ] Run all tests: `php vendor/bin/phpunit --testdox`
- [ ] Coverage ≥ 95%
- [ ] No regressions on existing tests

---

## Phase 7: Documentation & Finalization

### Deliverable: Complete documentation and production-ready code

- [ ] **TASK-7.1**: Create architecture documentation
  - [ ] File: `Project Docs/AUTO_BIDDING_IMPLEMENTATION.md`
  - [ ] Include:
    - [ ] Algorithm with diagrams
    - [ ] Data schema changes
    - [ ] Public methods in YITH_WCACT_Auto_Bid
    - [ ] Example walkthrough (3 bidders)

- [ ] **TASK-7.2**: Add PHPDoc to all methods
  - [ ] Every method: @param, @return, @requirement
  - [ ] Include REQ-AUTO-* tags
  - [ ] Add UML diagrams in class docblock

- [ ] **TASK-7.3**: Create user documentation
  - [ ] File: `Project Docs/AUTO_BIDDING_USER_GUIDE.md`
  - [ ] For admins: how to explain feature
  - [ ] For users: how to use max bid
  - [ ] FAQ section

- [ ] **TASK-7.4**: Update README & version
  - [ ] File: `readme.txt`
  - [ ] Add feature: "Proxy Bidding - Automatic bid increments"
  - [ ] Update version to 1.4.0
  - [ ] Update changelog

- [ ] **TASK-7.5**: Git commit
  - [ ] Stage all changes: `git add -A`
  - [ ] Commit with message:
    ```
    feat: Add proxy auto-bidding with progressive max bid processing
    
    - REQ-AUTO-001: Store user maximum bids per product
    - REQ-AUTO-002: Auto-increment when new bids placed
    - REQ-AUTO-003: Process multiple max bids in order
    - REQ-AUTO-004: Continue until no more bids can beat current
    - REQ-AUTO-005: Track auto-bid origin and source
    - REQ-AUTO-006: Display bid history with proxy indicators
    - REQ-AUTO-007: Prevent self-bidding
    
    Implements progressive proxy bidding matching eBay behavior.
    ```

---

## Summary

**Total Tasks**: 32  
**Estimated Lines of Code**: 500-700  
**Test Coverage Target**: ≥95%  
**Estimated Time**: 16-24 hours (depending on team experience)  

**Prerequisites**:
- PHPUnit configured ✓
- `YITH_WCACT_Bid_Increment` class available ✓
- Existing bid table structure understood ✓

**Post-Deployment**:
- Monitor auto-bid events/hour
- Track average auto-bids per auction
- Gather user feedback on fairness
- Monitor error rates

---

## Notes

- Keep `feature-auto-bidding-1.md` open for reference while executing tasks
- Tasks within same phase can be executed in parallel
- Cross-phase dependencies are clearly marked in plan
- All SQL queries must use prepared statements (wpdb->prepare)
- All user input must be sanitized (floatval, sanitize_text_field, etc.)
