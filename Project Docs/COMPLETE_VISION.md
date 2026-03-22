# YITH Auctions: Complete Auto-Bidding Vision

**Document**: Overall roadmap for auto-bidding feature (v1.0 + v1.1)  
**Versions Covered**: 1.4.0 (Auto-Bidding) → 1.5.0 (Sealed Bids)  
**Total Effort**: ~50 hours across 2 releases  

---

## Feature Evolution

### Phase 1: v1.4.0 - Progressive Auto-Bidding ✓ Planned

**Goal**: Implement eBay-style proxy bidding where users can set a maximum bid, and the system automatically increments competing bids.

**What Gets Built**:
- Users set max bid while bidding
- When someone else bids, auto-increment system processes all max bids in descending order
- Each bidder's proxy automatically raises to beat competitors (up to their max)
- Continue until someone's max bid is highest
- Display bid history with "(auto-bid)" labels

**Example**:
```
Auction: Vintage Watch
Start price: $10, Increment: $1

Timeline:
─────────

User A arrives, sees current: $10
→ A sets max bid: $50
→ A's current proxy bid shown: $10 (must bid to start)

User B arrives, current: $10
→ B bids: $21
→ SYSTEM processes:
  - A's max ($50) > B's bid ($21)
  - Auto-bid A to: $22 (= $21 + $1)
  → Current: $22 (A leading via auto-bid)

User C arrives, current: $22
→ C bids: $35
→ SYSTEM processes:
  - A's max ($50) > C's bid ($35)
  - Auto-bid A to: $36
  → Current: $36 (A leading)

User B returns, sees current: $36
→ B bids: $40
→ SYSTEM processes:
  - A's max ($50) > B's bid ($40)
  - Auto-bid A to: $41
  → Current: $41 (A leading)

User B sees current: $41
→ B's max exhausted (max was $40)
→ B cannot bid further

Auction ends with A at $41 ✓
```

**Implementation**: 7 phases, 32 tasks, 16-24 hours

**Deliverables**:
- ✓ `/plan/feature-auto-bidding-1.md` - Complete implementation plan
- ✓ `/EXECUTION_CHECKLIST.md` - Task breakdown with checkboxes
- ✓ `YITH_WCACT_Auto_Bid` class with algorithm
- ✓ Database: `wp_yith_wcact_user_max_bids` table
- ✓ 24 unit tests + full test coverage
- Documentation and changelog

---

### Phase 2: v1.5.0 - Sealed Bid Auctions ✓ Planned

**Goal**: Extend auto-bidding to support fair, blind auctions where bids remain sealed until a specified reveal time, then auto-bidding processes retroactively.

**What Gets Added**:
- Per-auction configuration: enable sealed mode + set reveal datetime
- During sealed period:
  - Collect max bids (don't display)
  - Show "SEALED BID IN PROGRESS" message
  - Display countdown timer
  - Hide current bid and bid history
- At reveal time:
  - Auto-bidding algorithm processes all accumulated max bids retroactively
  - Bids become visible with history
  - Behaves like v1.0 from that point on

**Example**:
```
Premium Estate Auction - SEALED MODE (Reveal: 2026-03-25 at 4:30 PM UTC)

DURING SEALED PERIOD (Now: 2026-03-22 3:00 PM)
─────────────────────────────────────────────────

User A arrives:
→ Sees: 🔒 SEALED BID IN PROGRESS
→ Countdown: 2 days, 1 hour, 30 minutes
→ Enters max bid: $1,000 (hidden from display)

User B arrives:
→ Sees: 🔒 SEALED BID IN PROGRESS (same message as A)
→ Bid history not visible
→ Enters max bid: $800 (hidden from display)

User C arrives:
→ Sees: 🔒 SEALED BID IN PROGRESS (same message as A & B)
→ Enters max bid: $950 (hidden from display)

NOBODY KNOWS WHO'S BIDDING OR HOW MUCH ANYONE HAS BID ✓


AT REVEAL TIME (2026-03-25 4:30 PM UTC)
──────────────────────────────────────

System cron fires: yith_wcact_sealed_bid_reveal
→ Processes all 3 max bids retroactively:
  1. Start with highest max: C ($950)
  2. Check B ($800) < $950 → No auto-bid needed
  3. Check A ($1,000) > $950 → Auto-bid A to $951
  4. Check B ($800) < $951 → No auto-bid needed
  5. Check C ($950) < $951 → No auto-bid needed
  
Result after retroactive processing:
- Current: $951
- Leader: A
- Auto-bids placed: 1 (A's single increment)


USER EXPERIENCE POST-REVEAL
──────────────────────────

All three users refresh page, now see:

Current Bid: $951 ✨ (A leading)

Bid History:
1. User C: $950 (Revealed 4:30 PM UTC)
2. User A: $951 (Revealed 4:30 PM UTC - auto-bid)
3. User B: $800 (Revealed 4:30 PM UTC) ← unsuccessful

Winner: User A at $951
```

**Key Difference from v1.0**:
- v1.0: Bids visible immediately, auto-bids process in real-time
- v1.1: Bids hidden until reveal time, auto-bids process retroactively all at once

**Implementation**: 9 phases, 35 tasks, 20-28 hours

**Deliverables**:
- ✓ `/plan/feature-auto-bidding-sealed-bids-1.1.md` - Complete sealed bid plan
- ✓ `/SEALED_BID_EXECUTION_CHECKLIST.md` - Task breakdown with checkboxes
- Extended `YITH_WCACT_Auto_Bid` with retroactive processing
- New: WordPress cron for scheduled reveals
- Database: 4 new columns + audit table
- 40+ new tests (sealed config, retroactive algorithms, display, integration)
- Admin UI for sealed configuration (metabox)
- Frontend countdown timer
- Audit logging for all reveals

---

## Complete Feature Matrix

| Feature | v1.0 | v1.1 | Purpose |
|---------|------|------|---------|
| **User sets max bid** | ✓ | ✓ | Fair proxy bidding |
| **Auto-increment competing bids** | ✓ | ✓ | Real-time (v1.0) / retroactive (v1.1) |
| **Multiple bidder support** | ✓ | ✓ | Fair handling of 2+ competing maxes |
| **Bid history with "(auto-bid)" labels** | ✓ | ✓ | Transparency after reveal |
| **Admin configuration per auction** | ✗ | ✓ | Enable sealed mode + reveal time |
| **Hidden bids during sealed period** | ✗ | ✓ | Blind auction fairness |
| **Countdown timer during sealed period** | ✗ | ✓ | User anticipation/transparency |
| **Retroactive auto-bid processing** | ✗ | ✓ | All bids process at reveal time |
| **WordPress cron scheduling** | ✗ | ✓ | Automatic reveal triggers |
| **Manual force-reveal in admin** | ✗ | ✓ | Admin override option |
| **Audit logging** | ✗ | ✓ | Track all reveal events |

---

## Database Schema Summary

### New Tables

**`wp_yith_wcact_user_max_bids`** (v1.0)
```
id, user_id, product_id, max_bid, current_proxy_bid, 
created_at, updated_at
```
Stores each user's maximum bid per auction.

**`wp_yith_wcact_sealed_bid_audit`** (v1.1)
```
id, product_id, reveal_datetime, auto_bids_count, 
final_bid_amount, processed_at, status, error_message
```
Audit log of all sealed bid reveals (for debugging + compliance).

### Extended Tables

**`wp_yith_wcact_auction`** (Existing table, columns added in phases)

v1.0 additions:
```
is_proxy_bid, proxy_source_bid_id, user_max_bid
```

v1.1 additions:
```
is_sealed_bid, sealed_reveal_datetime, 
sealed_reveal_processed, sealed_max_bids_collected
```

---

## Class Architecture

### Core Class: `YITH_WCACT_Auto_Bid` (Singleton)

**v1.0 Methods**:
- `save_max_bid($user_id, $product_id, $max_bid)`: Store user max bid
- `get_max_bid($user_id, $product_id)`: Retrieve max bid
- `get_all_max_bids($product_id, $exclude_user_id)`: Get competing max bids (sorted DESC)
- `update_proxy_bid($user_id, $product_id, $proxy_bid)`: Track current proxy bid
- `process_auto_bids($product_id, $new_bid, $new_user_id)`: Main algorithm
- `insert_auto_bid(...)`: Create auto-bid record
- `process_auto_bids_transactional(...)`: Transaction wrapper

**v1.1 Methods** (new):
- `process_auto_bids_retroactive($product_id)`: Sealed reveal algorithm
- `process_sealed_reveal_transactional($product_id)`: Sealed transaction wrapper
- `handle_sealed_bid_reveal()`: Called by WordPress cron

**Algorithm** (same in v1.0 and v1.1):
```
Loop:
  1. Get all max_bids > current_bid (sorted DESC)
  2. If none: exit loop
  3. Take highest competitor
  4. Auto-bid them to min(current_bid + increment, their_max)
  5. If no progress: exit loop
  6. Update current_bid, goto Loop
```

---

## Use Cases

### v1.0 (Live Auctions)

**Scenario**: Online auction, real-time bidding, users watching

- New York Times estate sale live
- Multiple collectors bidding simultaneously
- Each sets a max bid
- System automatically responds with increments
- Bidders see current price updating in real-time
- Fair escalation without needing manual counter-bids
- ✓ v1.0 is perfect for this

### v1.1 (Sealed Auctions)

**Scenario**: Fair procurement, government contract, charity auction

- Government procurement auction (must be fair/transparent)
- Sealed bid period: 14 days
- Bidders submit max bids privately (hidden)
- At deadline: system retroactively processes all bids
- Winner announced publicly
- ✓ v1.1 is perfect for this

**Other use cases**:
- Charity silent auctions (sealed until event end)
- Estate sales (fair division, no collusion)
- Premium collectibles (prevent price inflation from visible competition)

---

## Implementation Timeline

### Week 1: v1.0 Auto-Bidding
- **Days 1-2**: Phase 1-2 (database, class foundation) - 4 hours
- **Days 3-4**: Phase 3 (algorithm implementation) - 4 hours
- **Day 5**: Phase 4-5 (AJAX, display) - 4 hours

### Week 1 (continued): v1.0 Testing & Deployment
- **Hour 26-30**: Phase 6 (testing, 24+ tests) - 4 hours
- **Hour 31-36**: Phase 7 (docs, version bump, commit) - 4 hours
- **Review + merge**: 4-8 hours

### Week 2: v1.1 Sealed Bids
- **Days 6-7**: Phase 0-1B (UI, database) - 4 hours
- **Days 8-9**: Phase 3B-4B (retroactive processor, AJAX) - 4 hours
- **Day 10**: Phase 5B (frontend display, timer) - 4 hours

### Week 2 (continued): v1.1 Scheduling & Deployment
- **Hour 53-57**: Phase 8 (cron scheduling) - 4 hours
- **Hour 58-62**: Phase 9 (testing, 40+ tests) - 4 hours
- **Hour 63-66**: Integration (docs, version bump) - 4 hours
- **Review + merge**: 4-8 hours

**Total Effort**: ~65-75 hours (~2 weeks with focused work)

---

## Testing Strategy

### v1.0 Test Suites (24 tests)
1. **Bid Increment Tests** (14 tests) - existing from REQ-002
2. **Max Bid Storage** (5 tests) - CRUD operations
3. **Auto-Bid Algorithm** (5 tests) - 2/3/4+ bidder scenarios

### v1.1 Test Suites (40+ tests)
1. **Sealed Config Tests** (10 tests) - settings, validation, DB
2. **Retroactive Algorithm** (12 tests) - all scenarios
3. **Integration Tests** (6 tests) - full flow sealed→reveal
4. **Display Tests** (8 tests) - UI and timer
5. **Edge Cases** (6 tests) - concurrent, errors, rollback

**Coverage Target**: ≥95% for all new code

---

## Success Criteria

### v1.0 Checklist
- ✅ All 7 phases complete with 32 tasks
- ✅ 24 unit tests pass (100%)
- ✅ ≥95% code coverage
- ✅ No regressions to existing features
- ✅ Documentation complete
- ✅ Version bumped to 1.4.0
- ✅ Git commit in conventional format
- ✅ Code review approved

### v1.1 Checklist  
- ✅ All 9 phases complete with 35 tasks
- ✅ 40+ unit/integration tests pass (100%)
- ✅ ≥95% code coverage on sealed code
- ✅ All v1.0 tests still pass (no regressions)
- ✅ Sealed auction manual test successful
- ✅ Cron reveals triggered automatically
- ✅ Documentation complete
- ✅ Version bumped to 1.5.0
- ✅ Git commit in conventional format
- ✅ Code review approved

---

## Rollback Plans

### v1.0 Rollback
If critical auto-bidding issues found:
1. Feature flag: `yith_wcact_enable_auto_bid` (disable it)
2. When disabled: skip auto-bidding, only direct bids accepted
3. Revert commit if necessary
4. Old code remains unchanged, new columns left in place (data integrity)

### v1.1 Rollback
If critical sealed bid issues found:
1. Feature flag: `yith_wcact_enable_sealed_bid` (disable it)
2. When disabled: treat all auctions as open (v1.0 behavior)
3. Revert commit if necessary
4. Sealed auction data preserved for audit

---

## Documentation Structure

```
Project Docs/
├── AUTO_BIDDING_REQUIREMENTS.md ← What needs to be built (v1.0)
├── SEALED_BIDDING_REQUIREMENTS.md ← What needs to be built (v1.1)
├── IMPLEMENTATION_GUIDE.md ← How to use these docs (THIS FILE)
├── AUTO_BIDDING_IMPLEMENTATION.md ← Deep dive (v1.0)
├── SEALED_BID_IMPLEMENTATION.md ← Deep dive (v1.1)
├── AUTO_BIDDING_USER_GUIDE.md ← End user docs
├── SEALED_AUCTION_USER_GUIDE.md ← End user docs (sealed)

plan/
├── feature-auto-bidding-1.md ← 7 phases, 32 tasks (v1.0)
├── feature-auto-bidding-sealed-bids-1.1.md ← 9 phases, 35 tasks (v1.1)

├── EXECUTION_CHECKLIST.md ← Task checklist (v1.0)
├── SEALED_BID_EXECUTION_CHECKLIST.md ← Task checklist (v1.1)

auto-bidding-sequence-diagram.puml ← Algorithm visualization
```

---

## Next Actions

### Immediate (Today/This Week)
- [ ] Review this document
- [ ] Review `/plan/feature-auto-bidding-1.md` (v1.0)
- [ ] Review `/plan/feature-auto-bidding-sealed-bids-1.1.md` (v1.1)
- [ ] Start v1.0 Phase 1 when ready

### After v1.0 Completes
- [ ] Review v1.1 plan
- [ ] Ensure all v1.0 tests passing
- [ ] Merge v1.0 branch
- [ ] Start v1.1 Phase 0

### Monitoring Post-Deployment
- Auto-bid event count per day
- Average auto-bids per auction
- Auction final prices (vs baseline)
- Sealed reveal processing time
- User satisfaction feedback

---

## Key Contacts / Decision Points

**Resolved Questions**:
- ✓ Algorithm verified (progressive max bid processing)
- ✓ Database schema designed (user_max_bids table + audit table)
- ✓ Sealed reveal timing planned (WordPress cron every 5 min)
- ✓ Backward compatibility ensured (feature flags, optional config)
- ✓ Testing strategy defined (40+ tests, ≥95% coverage)

**Open Questions** (for stakeholder discussion):
- How frequently should sealed auctions be used? (business need validation)
- Should sealed reveal be pausable/reschedulable? (support requirement)
- Need admin reporting on sealed bid audit log? (compliance requirement)
- Should bidders receive notifications at reveal time? (UX enhancement)

---

**Ready to begin? Start with v1.0 Phase 1 using [EXECUTION_CHECKLIST.md](../EXECUTION_CHECKLIST.md)**
