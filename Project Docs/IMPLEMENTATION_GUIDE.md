# Auto-Bidding Feature: Complete Implementation Guide

**Created**: March 22, 2026  
**Feature**: Progressive proxy bidding with multi-bidder max bid processing  
**Status**: Planned (Ready for implementation)

---

## Quick Start

This folder contains everything needed to understand and implement the auto-bidding feature:

1. **Read First**: [`AUTO_BIDDING_REQUIREMENTS.md`](AUTO_BIDDING_REQUIREMENTS.md)
   - What is auto-bidding?
   - High-level requirements and business logic
   - User experience flow
   - Design questions and edge cases

2. **For Implementation**: [`../plan/feature-auto-bidding-1.md`](../plan/feature-auto-bidding-1.md)
   - Detailed 7-phase implementation plan
   - Specific tasks with file paths and acceptance criteria
   - Database schema changes
   - Algorithm pseudocode
   - Cross-phase dependencies, success criteria, rollback plan

3. **For Execution**: [`../EXECUTION_CHECKLIST.md`](../EXECUTION_CHECKLIST.md)
   - 32 actionable tasks organized by phase
   - Checkbox format for progress tracking
   - Estimated effort: 16-24 hours
   - Testing and validation steps

4. **For Reference**: [`auto-bidding-sequence-diagram.puml`](auto-bidding-sequence-diagram.puml)
   - Visual sequence of auto-bidding process
   - Shows bid progression through layers (UI → AJAX → Engine → DB)

---

## The Algorithm (Your Specification)

When a bid is placed, the system auto-increments competing max bids:

### Example Scenario
**Setup**: Current bid $10, increment $1, User A has max $22, User B has max $30

**Steps**:
1. **User B places bid $21**
   - Inserted as: amount=$21, is_proxy_bid=false
   - Current: $21

2. **Auto-bidding starts**
   - Get all max_bids > $21: User A ($22)
   - User A auto-bid: min($21+$1, $22) = $22
   - Inserted as: amount=$22, is_proxy_bid=true
   - Current: $22

3. **Continue processing**
   - Get all max_bids > $22: User B ($30)
   - User B auto-bid: min($22+$1, $30) = $23
   - Inserted as: amount=$23, is_proxy_bid=true
   - Current: $23

4. **Final check**
   - Get all max_bids > $23: User A ($22) ❌ not > $23
   - No eligible bidders, bidding ends
   - **Winner: User B at $23**

### Key Algorithm Features
- **Progressive**: Loop until no one can beat current bid
- **Deterministic**: Sort max_bids DESC, created_at ASC
- **Atomic**: Entire sequence in transaction
- **Safe**: Limits (100 iterations max) prevent infinite loops

---

## Implementation Structure

### 7 Phases (Chronological)

| Phase | Goal | Key Deliverable | Complexity | Est. Hours |
|-------|------|------|------------|-----------|
| 1 | Database schema + service class | `WcAuction_Auto_Bid` class | Low | 2 |
| 2 | Max bid storage/retrieval | CRUD methods for max_bids | Low | 2 |
| 3 | Auto-bidding engine | `process_auto_bids()` algorithm | High | 4 |
| 4 | AJAX integration | Connect to bid submission | Medium | 3 |
| 5 | Data display | Show auto-bids in UI | Medium | 3 |
| 6 | Testing | Unit + integration tests | High | 4 |
| 7 | Documentation | READMEs, PHPDoc, version bump | Low | 2 |

**Total**: 32 tasks, 500-700 lines of code, ≥95% test coverage

### Phase Dependencies

```
Phase 1 (DB) 
    ↓ (blocking)
Phase 2 (Storage) 
    ↓ (blocking)
Phase 3 (Engine) 
    ↓ (blocking)
Phase 4 (Integration) ← Phase 5 (Display) can start in parallel
    ↓ (blocking)
Phase 6 (Testing) ← depends on all code from 1-5
    ↓ (blocking)
Phase 7 (Finalization)
```

---

## File Structure

### New Files to Create
```
includes/
  class.yith-wcact-auto-bid.php          ← Core auto-bid engine

tests/
  unit/
    AutoBidTest.php                      ← Max bid storage tests
    AutoBidProcessTest.php               ← Algorithm tests
    AutoBidEdgeCasesTest.php             ← Edge case tests
  integration/
    AutoBidSubmissionTest.php            ← Full flow tests

Project Docs/
  AUTO_BIDDING_REQUIREMENTS.md           ✓ Created
  AUTO_BIDDING_IMPLEMENTATION.md         ← Phase 7.1
  AUTO_BIDDING_USER_GUIDE.md             ← Phase 7.3
  auto-bidding-sequence-diagram.puml     ✓ Created

plan/
  feature-auto-bidding-1.md              ✓ Created

EXECUTION_CHECKLIST.md                   ✓ Created
```

### Modified Files
```
includes/
  class.yith-wcact-auction.php           Phase 1.4: Initialize auto-bid class
  class.yith-wcact-auction-db.php        Phase 1.1-2: Add migrations
  class.yith-wcact-auction-ajax.php      Phase 4.1-2: Integrate auto-bidding
  class.yith-wcact-auction-bids.php      Phase 5.1: Bid history with proxy info

templates/
  woocommerce/single-product/
    add-to-cart/auction.php              Phase 4.3, 5.3: Max bid input + display
  admin/product-tabs/auction-tab.php     Phase 5.4: Bid analytics
  frontend/list-bids.php                 Phase 5.2: Display auto-bids

assets/
  js/frontend.js                         Phase 4.4: Handle max_bid submission
  css/frontend.css                       Phase 5.3: Optional styling

readme.txt                               Phase 7.4: Version bump to 1.4.0
```

---

## Database Schema Changes

### Existing Table: `wp_WcAuction_auction` (APPEND columns)
```sql
ALTER TABLE wp_WcAuction_auction ADD COLUMN (
  is_proxy_bid TINYINT(1) DEFAULT 0,
  proxy_source_bid_id BIGINT DEFAULT NULL,
  user_max_bid DECIMAL(10,2) DEFAULT NULL
);
```

### New Table: `wp_WcAuction_user_max_bids`
```sql
CREATE TABLE wp_WcAuction_user_max_bids (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  product_id BIGINT NOT NULL,
  max_bid DECIMAL(10,2) NOT NULL,
  current_proxy_bid DECIMAL(10,2) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user_product (user_id, product_id),
  KEY idx_product_max_bid (product_id, max_bid DESC),
  KEY idx_user_id (user_id)
);
```

---

## Core Class: WcAuction_Auto_Bid

### Public Methods (from Phase 2-3)

```php
// Max bid management
save_max_bid($user_id, $product_id, $max_bid): int|false
get_max_bid($user_id, $product_id): float
get_all_max_bids($product_id, $exclude_user_id = null): stdClass[]
update_proxy_bid($user_id, $product_id, $proxy_bid): int

// Auto-bidding engine
process_auto_bids($product_id, $new_bid_amount, $new_bid_user_id): array
process_auto_bids_transactional(...): array
insert_auto_bid($user_id, $product_id, $bid_amount, $source_bid_id): int

// Constants
const MAX_AUTO_BID_ITERATIONS = 100;
```

---

## Testing Strategy

### Unit Tests (Phase 6.1-4)
- **AutoBidTest.php**: Max bid CRUD operations (10 tests)
- **AutoBidProcessTest.php**: Algorithm with 2+, 3+, lead changes (12 tests)
- **AutoBidEdgeCasesTest.php**: User self-bid, concurrent bids, etc. (8 tests)
- **Integration**: Full bid submission flow (4 tests)

**Target**: ≥95% coverage, all 34 tests passing

### Manual Testing (Phase 6 verification)
- [ ] Two competing auto-bidders
- [ ] Lead changes during auction
- [ ] Max bid not exceeded
- [ ] Transaction rollback on error
- [ ] Bid history displays correctly

---

## Implementation Tips

### Critical Success Factors
1. **Transactions**: Entire auto-bid sequence must be atomic
2. **Sorting**: Always sort max_bids DESC for consistency
3. **Iteration limit**: Prevent infinite loops (100 max iterations)
4. **Sanitization**: All inputs floatval or sanitize_text_field
5. **Prepared statements**: All SQL queries use wpdb->prepare

### Code Quality Checklist
- [ ] All methods have @param, @return, @requirement PHPDoc
- [ ] All user input sanitized before use
- [ ] All SQL queries use prepared statements
- [ ] All database operations use wpdb methods
- [ ] Singleton pattern correctly implemented
- [ ] No direct SQL string concatenation
- [ ] Proper error handling with try-catch

### Git Commit Strategy
After Phase 7.5:
```bash
git add -A
git commit -m "feat: Add proxy auto-bidding with progressive max bid processing

- Implement REQ-AUTO-001: Max bid storage
- Implement REQ-AUTO-002: Auto-increment on new bids
- Implement REQ-AUTO-003: Multi-bidder processing
- Implement REQ-AUTO-004: Progressive bidding loop
- Implement REQ-AUTO-005: Proxy bid tracking
- Implement REQ-AUTO-006: Bid history display
- Implement REQ-AUTO-007: Self-bid prevention

Features:
- New WcAuction_Auto_Bid class with singleton pattern
- Database table: wp_WcAuction_user_max_bids
- Extended wp_WcAuction_auction with proxy tracking
- 34 unit + integration tests (≥95% coverage)
- Full documentation and user guide

Version bumped to 1.4.0"
```

---

## Post-Deployment Monitoring

### Metrics to Track
- Auto-bid events per hour
- Average auto-bids per auction
- Auction final prices (compare to baseline)
- Error rate on auto-bid processing
- User feedback on fairness

### Rollback Plan (if critical issues)
1. Add feature flag: `WcAuction_enable_auto_bid` (default: true)
2. If set to false: skip auto-bidding, only direct bids
3. Keep old columns/logic intact
4. Can revert commit without data loss

---

## Document Map

| Document | Purpose | Audience | Read Time |
|----------|---------|----------|-----------|
| **AUTO_BIDDING_REQUIREMENTS.md** | Business requirements, use cases, design | Product, Business Analysts | 15 min |
| **feature-auto-bidding-1.md** | Technical architecture, detailed tasks | Developers | 30 min |
| **EXECUTION_CHECKLIST.md** | Task breakdown for execution | Developers, Project Managers | 20 min |
| **AUTO_BIDDING_IMPLEMENTATION.md** | Deep dive architecture doc | Developers | 20 min |
| **AUTO_BIDDING_USER_GUIDE.md** | How-to for admins and users | Support, End Users | 10 min |
| **auto-bidding-sequence-diagram.puml** | Process flow visualization | All | 5 min |

---

## Next Steps

### To Begin Implementation:

1. **Review Phase 1** (`feature-auto-bidding-1.md`, section "Phase 1")
2. **Check Prerequisites**:
   - [ ] PHPUnit configured ✓
   - [ ] WcAuction_BidIncrement available ✓
   - [ ] DB access and version control ✓
3. **Create development branch**: `git checkout -b feature/auto-bidding`
4. **Start Phase 1 tasks** following `EXECUTION_CHECKLIST.md`
5. **Track progress** using checkbox format in checklist

### Questions?
- Algorithm details: See `/Project Docs/auto-bidding-sequence-diagram.puml`
- Task specifics: See `feature-auto-bidding-1.md` Phase sections
- Execution steps: See `EXECUTION_CHECKLIST.md` checkboxes

---

## Sealed Bid Auction Mode (Optional Enhancement)

**Planning Complete**: Yes  
**Status**: Separate implementation plan available (v1.1)

After completing the core auto-bidding feature (v1.0), you can optionally extend it with **Sealed Bid Mode**:

### What is Sealed Bid Mode?
- Bids remain hidden until a specified reveal date/time
- No current bid display or history visible during auction
- Shows "SEALED BID IN PROGRESS" with countdown timer
- At reveal time: auto-bidding processes all accumulated max bids retroactively
- Useful for fair, blind auctions

### Feature Highlights
- Optional per-auction configuration
- Admin UI to set reveal date/time
- Automatic processing at reveal time (WordPress cron)
- Manual force-reveal button in admin
- Full audit logging of all reveals
- ≥95% test coverage

### Documentation for Sealed Bids

1. **Read First**: [Sealed Bid Plan](../plan/feature-auto-bidding-sealed-bids-1.1.md)
   - 9 phases with detailed tasks
   - Architecture and data model
   - Example scenarios

2. **Execution Guide**: [Sealed Bid Checklist](../SEALED_BID_EXECUTION_CHECKLIST.md)
   - 35 tasks across 9 phases
   - 20-28 hour estimate
   - Checkboxes for progress tracking

### Prerequisites for Sealed Bid Implementation
- [ ] Core Auto-Bidding v1.0 complete and merged
- [ ] All v1.0 tests passing (≥95% coverage)
- [ ] Database migrations applied
- [ ] Code review of v1.0 approved

### Key Technical Additions (Sealed Bids)
- **Database**: 4 new columns + audit table
- **New Method**: `process_auto_bids_retroactive()` (variant of v1.0 algorithm)
- **New Phases**: Configuration UI (Phase 0), Database (Phase 1B), Engine (Phase 3B), AJAX (Phase 4B), Display (Phase 5B), Scheduling (Phase 8), Testing (Phase 9)
- **Scheduling**: WordPress cron runs every 5 minutes to detect and process reveals
- **Frontend**: Countdown timer, sealed message display

### Architecture Diagram: Sealed vs Open

```
OPEN AUCTION (v1.0)           SEALED AUCTION (v1.1)
────────────────              ─────────────────
User places bid $21           User places bid $21
         ↓                              ↓
Auto-bid processes            Max bid stored
immediately                   (no auto-bid yet)
         ↓                              ↓
Current bid updated           Show "SEALED IN PROGRESS"
to $22 (or higher)            (countdown timer)
         ↓                              ↓
Next user sees $22            User bids $30
and new max bid               (max also stored)
         ↓                              ↓
Auto-bid processes for $30    (Reveal time arrives)
         ↓                              ↓
                               ALL auto-bids
                               process retroactively:
                               - $21 bid of User A
                               - $22 auto-bid response
                               - $23 auto-bid response (if applicable)
                               etc.
                               ↓
                               Auction ends
                               Bids now visible
```

### When to Use Sealed Bids

**Use sealed mode for**:
- Government procurement auctions (fairness requirement)
- Charity auctions (blind bidding)
- Estate sales (prevent bidder coordination)
- Premium items (prevent bid inflation from visible competition)

**Don't use for**:
- Real-time auctions (live bidding is better)
- Quick sales (reveal delay not helpful)
- Consumer retail (transparent pricing preferred)

### Sealed Bid Implementation Sequence

After v1.0 is complete:

1. **Phase 0** (3 hrs): Admin UI for sealed settings ✓
2. **Phase 1B** (1.5 hrs): Database schema ✓
3. **Phase 3B** (2.5 hrs): Retroactive auto-bid processor ✓
4. **Phase 4B** (2.5 hrs): AJAX integration ✓
5. **Phase 5B** (3.5 hrs): Frontend display + timer ✓
6. **Phase 8** (2.5 hrs): Cron scheduling ✓
7. **Phase 9** (4.5 hrs): Testing (40+ tests) ✓
8. **Integration** (4 hrs): Docs, review, version bump ✓

**Total**: 20-28 hours of development + review

---

**Ready to implement?**

- **v1.0 (Auto-Bidding)**: Start with Phase 1 in [EXECUTION_CHECKLIST.md](../EXECUTION_CHECKLIST.md)
- **v1.1 (Sealed Bids)**: After v1.0 is done, use [SEALED_BID_EXECUTION_CHECKLIST.md](../SEALED_BID_EXECUTION_CHECKLIST.md)**
