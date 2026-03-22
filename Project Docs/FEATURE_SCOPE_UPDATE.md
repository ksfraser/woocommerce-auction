# Feature Scope Update: Final Requirements Summary

**Date**: March 22, 2026  
**Scope**: All Auto-Bidding Features (v1.4.0 and v1.5.0)  
**Status**: Updated requirements complete, ready for implementation

---

## 🔄 Changes from Original Plan

### ❌ Features EXCLUDED
1. **Dutch Auction (Reverse)** - lowest bid wins model removed
2. **Secret Bids** - permanently hidden bids model removed
3. **Buy Now Price** - fixed price purchase removed

### ✅ Sealed Bids SIMPLIFIED (v1.5.0)
- **OLD**: Incremental proxy bidding cascade at reveal (like open auctions)
- **NEW**: Single highest max bid insertion at reveal (simpler, fairer)
- Algorithm: Get all max_bids → Insert highest bid → Done
- Example: A max $100, B max $50 → A wins at $100 (not $101, not escalated)

### ✅ Buy Now REPLACED (v1.4.0)
- Remove "Buy Now" price point from auction
- Replace with "Add Regular Item to Cart" button
- Links to parent product (full-price version)
- User adds parent product to cart at regular price
- Benefit: upsell strategy, simple, no special checkout logic

### ✅ ENTRY FEES ADDED (v1.4.0) - NEW FEATURE
- Optional per-auction: toggle "Require Entry Fee"
- Minimum: $1.00 USD (store currency)
- Amount: settable by admin per auction
- Collected via WooCommerce standard payment flow
- Must be paid before user can place bids
- Refundable if auction doesn't reach reserve
- Audit trail: track all entry fee payments
- **Future phase**: Group multiple auction items under single entry fee ("Auction Sets")

### ✅ WINNER COMMISSION ADDED (v1.4.0) - NEW FEATURE
- Charged to auction winner at checkout
- Also called "Buyer's Premium" or "Auction Fee"
- Configurable at module level (global settings)
- Three models supported:
  1. **Percentage**: (e.g., 5% of final bid amount)
  2. **Flat Fee**: (e.g., $2.50 per auction)
  3. **Hybrid**: (e.g., 5% + $1.00 minimum)
- Display commission with explanation link on auction page
- Calculate and show commission at checkout before payment
- Applied as line item in order
- Explanation text editable in settings (customizable)

### ✅ POST-AUCTION PROCESSING ADDED (v1.4.0) - NEW FEATURE
- **Auto-generate pending order**: After auction ends, automatically create WC Order
  - Contains: auction item + final bid + commission + entry fee (if any)
  - Status: "pending payment"
  - Send email to winner with payment link
- **Auto-charge winner**: Attempt to charge saved Stripe card automatically
  - Only if enabled in settings
  - Log success/failure
  - Send email: charge successful or failed reason
- **Handle unpaid auctions**: If winner doesn't pay or reserve not met:
  - Option 1: Notify 2nd highest bidder, allow them to win
  - Option 2: Reschedule auction (same config, new time slot)
  - Frequency: next week, next month, or custom interval
  - Log all events

### ✅ EMAIL NOTIFICATIONS ADDED (v1.4.0) - NEW FEATURE
- **New bid placed**: Notify all previous bidders (optional frequency limit: max 1 per 5 min)
- **User outbid**: Email user when someone outbids them, offer to bid higher
- **Auction ending soon**: 24 hour, 1 hour, 10 minute warnings
- **Auction ended**: Notify winner + all other bidders (informational)
- **Entry fee paid**: Confirmation email for fee payment
- **Admin notification**: Unpaid auction (if using 2nd bidder or reschedule model)
- **Configurable**: Enable/disable each notification type globally
- **Templates**: Customizable email templates with filters/hooks
- **Opt-out**: Unsubscribe links in all emails

---

## 📊 Feature Matrix (All Versions)

| Feature | v1.4.0 | v1.5.0 | Status |
|---------|--------|--------|--------|
| **Core Bidding** | ✓ | ✓ | ← Planned |
| Auto-bidding (Open) | ✓ | ✓ | ← Planned |
| Sealed bids (single highest) | \- | ✓ | ← Planned |
| Starting bid | ✓ | ✓ | ← Planned |
| Reserve price | ✓ | ✓ | ← Planned |
| **Entry Fees** |  |  |  |
| Optional entry fee | ✓ | ✓ | ← Planned |
| Entry fee audit trail | ✓ | ✓ | ← Planned |
| Entry fee refunds | ✓ | ✓ | ← Planned |
| **Winner Commission** |  |  |  |
| Configurable commission | ✓ | ✓ | ← Planned |
| 3 commission models (%, flat, hybrid) | ✓ | ✓ | ← Planned |
| Commission explanation link | ✓ | ✓ | ← Planned |
| Commission display at checkout | ✓ | ✓ | ← Planned |
| **Post-Auction** |  |  |  |
| Auto-generate pending order | ✓ | ✓ | ← Planned |
| Auto-charge winner (Stripe) | ✓ | ✓ | ← Planned |
| Handle unpaid auctions | ✓ | ✓ | ← Planned |
| Reschedule unpaid auctions | ✓ | ✓ | ← Planned |
| **Notifications** |  |  |  |
| New bid notification | ✓ | ✓ | ← Planned |
| Outbid notification | ✓ | ✓ | ← Planned |
| Ending soon warnings | ✓ | ✓ | ← Planned |
| Auction ended notification | ✓ | ✓ | ← Planned |
| Entry fee confirmation | ✓ | ✓ | ← Planned |
| Admin notifications | ✓ | ✓ | ← Planned |
| **Cart/Checkout** |  |  |  |
| Add regular product button | ✓ | ✓ | ← Planned |
| Parent product linking | ✓ | ✓ | ← Planned |
| Entry fee in checkout | ✓ | ✓ | ← Planned |
| Commission in checkout | ✓ | ✓ | ← Planned |
| **Auction Sets (Future)** |  |  |  |
| Group multiple items | \- | Phase 2 | ← Roadmap |
| Single fee covers set | \- | Phase 2 | ← Roadmap |

---

## 🗂️ Implementation Phases (Updated)

### v1.4.0 Release (40-50 hours)

**Phase 1-5** (Core Auto-Bidding):
- Starting bid (REQ-001)
- Bid increment (REQ-002)
- Reserve price (REQ-003)
- Auto-bidding open auctions (REQ-AUTO-001-009)
- Database schema
- AJAX integration
- Frontend display
- 24 unit tests

**Phase 2A** (Entry Fees) - 10 hours:
- Add entry fee columns to auction table
- Create entry fee class with CRUD
- Implement fee collection flow
- Add UI to product metabox
- Integrate with bid placement (require payment before bidding)
- Refund logic on unpaid auctions

**Phase 3A** (Winner Commission) - 8 hours:
- Add module settings for commission configuration
- Create commission calculation class (3 models: %, flat, hybrid)
- Display commission on auction page with explanation link
- Modal/tooltip for explanation
- Add commission to checkout as line item

**Phase 4A** (Post-Auction Processing) - 12 hours:
- Add order_id and payment_status columns
- Create post-auction event handler
- Implement auto-order generation
- Implement auto-charge via Stripe
- Implement unpaid auction handling (2nd bidder or reschedule)
- Create audit log table

**Phase 5A** (Email Notifications) - 10 hours:
- Create notification event handler class
- Implement 6 email templates (new bid, outbid, ending, ended, fee, admin)
- Add notification settings to admin
- Hook into bid placement and auction completion
- Implement frequency limiting (max 1 email per 5 min per user)

**Phase 6A** (Cart & "Buy Regular Item" Button) - 6 hours:
- Replace "Buy Now Price" display with button
- Parent product configuration in metabox
- Button links to parent product add-to-cart
- Store parent relationship in post_meta

**Phase 6** (Testing) - 8+ hours (extended):
- Unit tests for all new classes: entry fees, commission, post-auction
- Integration tests: full auction flow with fees, commission, order creation
- Notification tests: all 6 email types, frequency limiting
- Cart tests: entry fee + commission in checkout
- 50+ new tests, ≥95% coverage for all new code

**Phase 7** (Documentation & Deploy):
- Update README
- Update architecture docs
- Version bump to 1.4.0
- Changelog
- Git commit

**Total v1.4.0**: ~76 hours (core 24 + new 52)

---

### v1.5.0 Release (Sealed Bids - 20-28 hours)

**Phase 0-1B-3B-4B-5B-8-9** (Sealed Bids with single highest bid model):
- Admin UI for sealed configuration (reveal datetime)
- Database schema for sealed tracking
- Retroactive processor: insert single highest max bid
- AJAX detection of sealed period (accept max bid, skip auto-bid)
- Frontend: "SEALED BID IN PROGRESS", countdown timer, hidden bid history
- WordPress cron for automatic reveals
- 40+ tests for sealed bid scenarios

**Change from Original**: 
- Instead of cascading incremental auto-bids at reveal (like A→$100, B→$101, A→$102, etc.)
- Just insert single highest max bid (A wins at $100)
- Simpler algorithm, prevents gaming, fairer for sealed auctions

**Total v1.5.0**: ~24 hours

---

### Phase 7A (Future): Auction Sets
- Group multiple auction items
- Single entry fee covers all items in set
- Users can bid on entire set or individual items
- Future phase (not in v1.4-1.5)

---

## 🎯 Success Criteria (Complete)

**v1.4.0 Completion**:
- ✅ Entry fees collected (audit trail, refunds)
- ✅ Commission 3 models working (%, flat, hybrid)
- ✅ Auto-order generation on auction end
- ✅ Auto-charge attempts sent to Stripe
- ✅ Unpaid audit handling (2nd bidder or reschedule)
- ✅ All 6 email notification types sent
- ✅ Frequency limiting prevents email spam
- ✅ "Add Regular Item" button shows, links to parent
- ✅ Entry fee + commission visible at checkout
- ✅ 50+ new tests all pass (≥95% coverage)
- ✅ No regressions to core auto-bidding
- ✅ Version 1.4.0 released with changelog

**v1.5.0 Completion**:
- ✅ Sealed bids use single highest bid (not cascade)
- ✅ Countdown timer displays during sealed period
- ✅ Bid history hidden during sealed
- ✅ All bids visible after reveal
- ✅ Cron triggers reveals automatically
- ✅ 40+ sealed bid tests pass
- ✅ All v1.4.0 tests still pass
- ✅ Version 1.5.0 released with changelog

---

## 📝 New Implementation Documents Created

1. **`/plan/feature-entry-fees-commission-post-auction-1.0.md`** (3,000+ lines)
   - Complete 6-phase breakdown (2A, 3A, 4A, 5A, 6A, 8A)
   - Database schema for entry fees, post-auction, notifications
   - Configuration options for all features
   - Module settings UI specifications

2. **`/plan/feature-auto-bidding-sealed-bids-1.1.md`** (UPDATED)
   - Sealed bid algorithm changed to single highest bid
   - Examples updated
   - No more cascading escalation

3. **This Document**: Feature scope & summary

---

## 🚀 Ready for Implementation

All features are now fully specified:
- Entry Fees ✓
- Winner Commission ✓
- Post-Auction Processing ✓
- Email Notifications ✓
- Updated Sealed Bids ✓
- Replaced Buy Now ✓

**Next Steps**:
1. Update implementation guides with new features
2. Create execution checklist for v1.4.0 (combining auto-bid + new features)
3. Begin Phase 1 development

**No more unknowns - ready to build!**
