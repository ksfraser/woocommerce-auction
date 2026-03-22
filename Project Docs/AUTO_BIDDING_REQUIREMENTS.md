# Auto-Bidding (Proxy Bidding) Feature Requirements

**Status**: Proposed  
**Date**: March 22, 2026  
**Related Features**: REQ-001 (Starting bid), REQ-002 (Bid increment), REQ-003 (Reserve price)

## Executive Summary

Auto-bidding (also called proxy bidding) is a feature where users set a **maximum bid**, and the system automatically increments their bid by the minimum increment when competing bids are placed. This matches the behavior of eBay and other major auction platforms.

## Core Concept

When User A bids with a max bid of $100:
- If current bid is $50, User A's bid shows as $50 + increment
- When User B bids $60, User A's bid auto-increases to $60 + increment (if < $100 max)
- When User B tries $90, User A's bid auto-increases to $90 + increment (if < $100 max)
- When User B tries $110, User A's bid stays at $100 (their max) and User B wins

## Requirements

### REQ-AUTO-001: Max Bid Storage
- Store user's maximum bid per product in post meta: `_yith_auction_user_{user_id}_max_bid`
- Or alternatively, create a `yith_wcact_user_max_bids` table:
  ```sql
  CREATE TABLE wp_yith_wcact_user_max_bids (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    product_id BIGINT NOT NULL,
    max_bid DECIMAL(10,2) NOT NULL,
    current_proxy_bid DECIMAL(10,2),
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE KEY unique_user_product (user_id, product_id)
  )
  ```

### REQ-AUTO-002: Bid Submission with Max Bid
When a user places a bid, they specify:
- `bid_amount`: The amount they're bidding now
- `max_bid`: Their maximum they're willing to pay (optional)
  - If `max_bid` is provided, this becomes an auto-bid
  - If only `bid_amount` is provided, it's a manual bid (current behavior)

### REQ-AUTO-003: Proxy Bid Engine
New method in `YITH_WCACT_Bids`:
```php
public function auto_bid_on_new_bid($product_id, $new_bid_amount, $new_bid_user_id) {
    // When a new bid is placed, check if other users have max bids
    // Auto-increment their proxy bids up to their max
}
```

### REQ-AUTO-004: Auto-Bid Logic
When a new bid is placed by User B:
1. Get all users with active max bids on this product (excluding User B)
2. For each user with max bid:
   - If their proxy bid < new_bid + increment AND max_bid > new_bid:
     - Auto-place their proxy bid at: new_bid + increment (or min(new_bid + increment, max_bid))
     - Record this as an auto-bid (track that it was automatic)
3. If User B's bid beats all max bids, they win
4. If User B's bid loses to a max bid, the max bid user remains ahead

### REQ-AUTO-005: Bid Display
- Show on frontend which bid is the "current leading" bid
- Indicate if current highest bid is a proxy bid or manual bid
- Allow users to see their own max bid after placing it
- Show bid history including auto-bid placements

### REQ-AUTO-006: UI for Max Bid Entry
Add form field on product page:
- Current: `<input name="_actual_bid">` - manual bid amount
- New: `<input name="_max_bid">` - maximum willing to pay
- Checkbox: `☑ Use auto-bid up to my max` (enabled by default if max_bid provided)

### REQ-AUTO-007: Data Tracking
Track in bid placement:
- `is_proxy_bid`: boolean - was this bid placed automatically?
- `user_max_bid`: decimal - the max bid that generated this proxy bid
- `competing_bid_amount`: decimal - what bid triggered this auto-bid?

### REQ-AUTO-008: Auction End with Auto-Bids
When auction ends:
- Winner is determined by highest actual bid amount
- If tie at same amount (unlikely), earliest timestamp wins
- Show final bid history including all auto-bid increments

### REQ-AUTO-009: Edge Cases
- **Lead Change**: User A has max $100, current proxy bid $60. User B manually bids $80. User A auto-bids to $85 (beat User B).
- **Max Bid Too Low**: User A sets max $50, current bid $60. Their max bid cannot be used (error message).
- **Multiple Auto-Bidders**: User A max $100, User B max $90, User C manually bids $50.
  - User B's bid auto-increased to $55
  - User A's bid auto-increased to $60
  - Future: User C bids $70 → User B auto-bids $75 (their max $90)
  - User A auto-bids $80 (their max still $100) and takes lead
- **Bid Retraction**: If a user's auto-bid reaches their max and they're losing, can they increase their max? (Feature decision needed)

## Technical Implementation Changes

### Database Changes
1. Create `yith_wcact_user_max_bids` table OR use post_meta pattern
2. Add `yith_wcact_auction` table columns:
   - `is_proxy_bid` (boolean) 
   - `proxy_source_bid_id` (FK to triggering bid) - nullable
   - `user_max_bid` (decimal) - nullable

### Class Changes in `YITH_WCACT_Bids`:
```php
- add_bid_with_max($user_id, $auction_id, $bid, $max_bid, $date)
- get_user_max_bid($user_id, $product_id)
- process_proxy_bids($product_id, $new_bid_amount, $new_bid_user_id)
- get_all_active_max_bids($product_id, $exclude_user_id = null)
```

### Class Changes in `YITH_WCACT_Auction_Ajax`:
```php
- Update yith_wcact_add_bid() to handle max_bid parameter
- Call YITH_WCACT_Bids::process_proxy_bids() on successful bid
```

### Frontend Changes
- Update `auction.php` template to include max bid input
- Update `frontend.js` to collect max_bid on form submission
- Add messaging to explain auto-bid behavior

## User Experience Flow

```
User A visits auction page
  → Sees current bid: $50 (increment: $5)
  → Sees their own max bid (if previously set): $100
  → Enters bid with:
     • Manual bid: $55
     • Max bid: $100 ✓ (auto-bid enabled)
  → Sees confirmation: "Your max bid is $100. We'll bid automatically up to that amount."
  
Meanwhile, User B manually bids $60
  → System auto-increments User A's proxy bid to $65 (60 + 5)
  
User B bids $80
  → System auto-increments User A's proxy bid to $85 (80 + 5)
  
User C bids $90
  → System auto-increments User A's proxy bid to $95 (90 + 5)
  
User C bids $105
  → System tries to auto-increment User A to $110, but max is $100
  → User A's proxy bid stays at $100
  → User C wins
```

## Business Considerations

1. **Fairness**: Does auto-bidding seem "fair" compared to manual bidding? (Yes, standard practice)
2. **Psychology**: Higher prices due to auto-bid wars - better for seller, more expensive for buyer
3. **Bid Sniping Prevention**: Auto-bidding reduces last-second manual bid sniping
4. **Complexity**: More complex system → more testing needed

## Open Design Questions

- Should max bid be visible to other bidders? (No - only their current proxy bid)
- Should users be able to update their max bid after initial placement? (Design choice)
- Should there be a maximum number of auto-bid increments? (Define limit if needed)
- Should the system use "soft" increments (just beat by 1 cent) or full increments? (Recommend full increments for clarity)

## Acceptance Criteria

- [ ] User can set max bid when placing initial bid
- [ ] Max bid is stored securely
- [ ] Proxy bids auto-increment per REQ-AUTO-004 logic
- [ ] All proxy bids are recorded with `is_proxy_bid = true`
- [ ] Bid history shows both manual and auto bids clearly
- [ ] Auction ending correctly determines winner from all bid types
- [ ] Edge cases handled (lead changes, max bid updates, etc.)
- [ ] Unit tests cover proxy bid logic with 100% coverage
- [ ] Frontend UX explains auto-bidding behavior clearly

## Related Files to Modify

See REQ-AUTO-001 through REQ-AUTO-009 for detailed implementation in:
- Database: `/includes/class.yith-wcact-auction-db.php`
- Bids: `/includes/class.yith-wcact-auction-bids.php`
- AJAX: `/includes/class.yith-wcact-auction-ajax.php`
- Frontend: `/templates/woocommerce/single-product/add-to-cart/auction.php`
- JS: `/assets/js/frontend.js`
- Admin: `/templates/admin/product-tabs/auction-tab.php` (optional: show max bid history)

## Implementation Resources

**For Developers**: See `/plan/feature-auto-bidding-1.md` for detailed 7-phase implementation plan with:
- Specific tasks, file paths, and acceptance criteria
- Architecture and database schema design
- Algorithm pseudocode with examples
- Cross-phase dependencies
- Success criteria and rollback plan

**For Project Managers**: See `/EXECUTION_CHECKLIST.md` for:
- Checkbox-based task tracking
- Phase-by-phase breakdown
- Deliverables and validation steps
- Estimated effort (16-24 hours, 32 tasks)
- Prerequisites and post-deployment monitoring

**Diagrams & Reference**:
- `/Project Docs/auto-bidding-sequence-diagram.puml` - Sequence diagram of bidding flow
- `/Project Docs/AUTO_BIDDING_REQUIREMENTS.md` - This file (requirements only)
