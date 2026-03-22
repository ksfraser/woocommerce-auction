# Feature PRD: Auto-Bidding with Proxy Bidding

**Version:** 1.0 | **Status:** Planning | **Epic:** v1.4.0 | **Release:** Q2 2026

---

## Executive Summary

The Auto-Bidding feature implements proxy bidding logic (also known as proxy bidding or automatic bidding), allowing auction participants to set a maximum bid amount. The system automatically bids on their behalf up to that maximum, incrementally, using the smallest bid increment needed to win—all without requiring manual participation in bidding. This dramatically improves the auction experience by enabling passive participation while maintaining bid transparency.

---

## Business Objectives

### Primary Goals

1. **Increase participation**: Enable users to bid on auctions regardless of availability by automating the bidding process
2. **Improve UX**: Reduce friction by eliminating the need for active monitoring and manual bid submission
3. **Enhance competitiveness**: Encourage more aggressive bidding through proxy bidding by reducing anxiety about missing bidding opportunities
4. **Increase average bid values**: Users more likely to set higher max bids knowing system will bid strategically

### Success Metrics

- 40% increase in total bids placed during auctions
- 25% increase in auction completion bid counts
- 15% increase in average bid increments
- User engagement (% of users setting proxy bids) > 30%
- System performance: Auto-bid processing < 100ms per bid event

---

## Feature Overview

### User Scenarios

#### Scenario 1: Setting a Proxy Bid
**Alice wants to win a rare collector's item but can't monitor the auction actively.**

1. Alice browses an active auction for a vintage watch
2. Current bid: $50, by Bob
3. Alice clicks "Set Maximum Bid" and enters $200 as her maximum
4. System confirms her proxy bid at or near the current increment
5. Alice receives confirmation email and can close the browser

#### Scenario 2: Automatic Bidding Happens
**System automatically bids on Alice's behalf when competition appears.**

1. Charlie places a bid of $75 (below Alice's $200 max)
2. System automatically bids $79 (one increment) on Alice's behalf using her max bid authority
3. Charlie is notified he was outbid and sees current bid is $79 (held by Alice)
4. Charlie places another bid $85
5. System again automatically bids $89 on Alice's behalf
6. This continues until the current bid approaches Alice's $200 maximum

#### Scenario 3: Reserve Price Interaction
**Reserve price affects proxy bidding behavior and transparency.**

1. Auction has reserve price of $150
2. Alice places proxy bid max of $200
3. Current bid is $100
4. When bids reach $150+ and Alice is leading, she sees "Reserve Met" indicator
5. Once reserve is met, Alice's actual bid (not just proxy max) is visible to other bidders
6. Other bidders can see Alice has committed to at least the reserve price

#### Scenario 4: Bid Proxy Increment Strategy
**System uses intelligent increments to maximize chances of winning while respecting max bid.**

1. Normal increment between $50-$100: $5
2. Alice sets $175 max, current bid is $100
3. Next natural increment would be $105
4. Bids escalate: $100 → $105 → $110 → $115 → $120 → $125 → $130 → $135 → $140 → $145 → $150 → $155 → $160 → $165 → $170 → $175
5. At $175, Alice is at her max and no further auto-bids occur
6. If someone else bids $176, Alice is outbid and receives notification but bid is not escalated further

---

## Detailed Requirements

### Functional Requirements

#### FR-1: Proxy Bid Setting
- User can set a maximum bid amount via "Set Maximum Bid" button on auction product page
- Button appears only when auction is active (started, not ended)
- Maximum bid amount must be greater than current bid + minimum increment
- System validates input is numeric, positive, and reasonable
- Confirmation shows: current bid, max bid, required increment above current bid
- User receives confirmation email with proxy bidding details
- User can update their proxy bid at any time (increases or decreases maximum)

#### FR-2: Automatic Bidding Logic
- When a new bid is received that's below the proxy bid max, system automatically counter-bids
- Automatic bid amount = (current winning bid) + (applicable bid increment)
- Auto-bidding respects bid increment rules configured for the auction
- System ensures proxy bid max is never exceeded
- Auto-bid is recorded with timestamp and system attribution (not user-initiated)
- Bidding history clearly marks auto-bids vs. manual bids

#### FR-3: Bid Increment Integration
- System uses auction's configured bid increment table:
  - If bid is $0-$50: increment is $1
  - If bid is $50-$100: increment is $5
  - If bid is $100-$500: increment is $10
  - If bid is $500-$1000: increment is $25
  - If bid is $1000+: increment is $50
- Increments are applied consistently for both manual and auto-bids
- Admin can override increment table per auction or site-wide

#### FR-4: Reserve Price Interaction
- Proxy bid is independent of reserve price
- Auto-bidding occurs whether or not reserve is met
- Once reserve is met:
  - Proxy bid max is revealed to other bidders (with notification)
  - Next winning bidder notification shows proxy bid has been recognized
- Reserve met state is calculated before each auto-bid decision

#### FR-5: Outbid Notifications
- When user's proxy bid is outbid, they receive instant notification
- Notification includes: current winning bid, bid placed by, time of bid
- Notification offers quick action: "raise proxy bid max" or "remove from watchlist"
- Notifications available via: email (instant), dashboard (persistent), in-auction banner (if viewing)

#### FR-6: Proxy Bid Management
- Users can view all their active proxy bids from "My Auctions" dashboard
- Dashboard shows: auction name, current bid, proxy max, bids placed by system
- Users can edit proxy bid maximum (increase or decrease) anytime before auction end
- Users can cancel proxy bid, which removes authority to auto-bid
- Audit trail shows all proxy bid updates (when set, changed, canceled)

#### FR-7: Auction Ending & Settlement
- When auction ends, final bid is determined by normal auction logic, not proxy amount
- If user's proxy bid was the highest, they win at the appropriate increment (not at their max)
- Example: Alice's max=$500, final competing bid=$450, Alice wins at $455 (not $500)
- Invoice is sent with actual winning bid amount, not proxy maximum
- Winner is notified of final bid amount and invoice

#### FR-8: System Constraints & Safety
- Maximum proxy bid cap: configurable per site (default $99,999)
- System prevents manipulation: consecutive auto-bids must be > 30 seconds apart
- If bid receipt fails, system logs error and retries (exponential backoff)
- Failed auto-bids don't prevent manual bids by other users
- Rollback capability: If auto-bid transaction fails, auction reverts to pre-bid state

---

### Non-Functional Requirements

#### NFR-1: Performance
- Auto-bid processing: < 100ms from bid submission to auto-bid decision
- Auto-bid execution: < 500ms from intent to bid placed
- Database queries for proxy bid lookup: < 50ms
- No locking required on proxy bid table (optimistic concurrency)

#### NFR-2: Scalability
- Support 1000+ concurrent auctions with auto-bidding
- Support 10,000+ users with active proxy bids
- Auto-bid queue processing: 1000 bids/second throughput
- Database connection pool: configurable, recommend 20-50 connections

#### NFR-3: Reliability
- Auto-bid failures: max 0.1% failure rate (99.9% success rate)
- System should gracefully handle failed auto-bids without losing bid data
- Audit trail: 99.99% completeness (all auto-bids logged)
- Rollback capability for failed transactions

#### NFR-4: Security
- Proxy bids are cryptographically verified (cannot be spoofed)
- User sessions tied to specific user ID (no cross-account proxy bid hijacking)
- SQL injection prevention on all proxy bid queries
- Rate limiting: max 10 proxy bid updates per minute per user

#### NFR-5: Data Integrity
- Proxy bid amount never exceeds user's configured maximum
- Duplicate bids prevented: same user cannot bid twice on same auction in < 1 second
- Atomic transactions: either full bid is recorded or nothing (no partial states)
- Historical audit: all proxy bid changes immutable once recorded

---

## Technical Architecture

### High-Level Components

```
1. Frontend Components
   ├── ProxyBidForm (set/edit maximum)
   ├── BiddingIndicator (shows auto-bid status)
   └── ProxyBidHistory (view auto-bids)

2. Core Services
   ├── ProxyBidService (manage proxy bid logic)
   ├── AutoBiddingEngine (automatic bid execution)
   └── BidIncrementCalculator (determine valid increments)

3. Data Models
   ├── ProxyBid (user's max + current state)
   ├── AuctionBid (individual bid record)
   └── BidIncrement (auction increment configuration)

4. Infrastructure
   ├── Async Queue (process auto-bids asynchronously)
   ├── Caching Layer (proxy bid state + increments)
   └── Audit Logging (immutable bid history)
```

### Database Changes

**New Tables:**
- `wp_wc_auction_proxy_bids` - Store proxy bid configurations
- `wp_wc_auction_auto_bid_log` - Immutable log of system-placed bids

**Modified Tables:**
- `wp_wc_auction_bids` - Add `is_auto_bid` flag to distinguish system vs. manual bids

**Indexes Needed:**
- `proxy_bids(auction_id, user_id)` - Quick proxy bid lookup
- `proxy_bids(auction_id)` - All proxies for an auction
- `auto_bid_log(auction_id)` - Audit trail per auction
- `bids(auction_id, placed_at)` - Temporal ordering of bids

---

## User Experience Details

### Setting a Proxy Bid

**User sees on product page (auction active):**
```
Current Bid: $50 (by Robert)
Bid Increment: $5

[Set Maximum Bid]  [Place Manual Bid]

--- When [Set Maximum Bid] clicked ---

Modal opens:
┌─────────────────────────────────────┐
│ Set Your Maximum Bid                │
├─────────────────────────────────────┤
│                                     │
│ Current Bid:     $50                │
│ Minimum Next:    $55                │
│                                     │
│ Your Maximum: [_______________] $   │
│                                     │
│ ℹ️ We'll bid automatically on your  │
│    behalf up to your maximum,       │
│    using the smallest increment     │
│    needed to win.                   │
│                                     │
│        [Set Proxy Bid] [Cancel]     │
└─────────────────────────────────────┘
```

**Confirmation:**
```
✓ Proxy Bid Set!

You've set a maximum bid of $200 for "Vintage Watch - 1950s Rolex"

We'll automatically bid on your behalf using increments of:
• $1-$5 on bids between $50-$100
• $5-$10 on bids between $100-$500
• etc.

Your current bid: $55 (next increment up from current $50)

[View Auction] [Go to My Auctions] [Dismiss]
```

### Viewing Proxy Bid Status During Auction

**User sees on product page:**
```
Auction Status: 23h 14m remaining

Your Proxy Bid: $200 (Active)
├─ Current Bid: $75 (by You, via auto-bid)
├─ Your Max: $200
└─ [Edit Maximum] [Remove Proxy Bid]

Bidding History (Recent):
─────────────────────────────────────────
02:14 PM - You (auto-bid) - $75
02:12 PM - Charlie - $70
02:10 PM - You (auto-bid) - $65
02:08 PM - Bob - $60
─────────────────────────────────────────

Next competing bid: Someone bids $80 → System auto-bids $85
```

### Outbid Notification

**Email received:**
```
Subject: You've been outbid on "Vintage Watch - 1950s Rolex"

Hi Alice,

You've been outbid!

Auction: Vintage Watch - 1950s Rolex
New Bid: $85 (by Charlie)
Your Proxy Max: $200

[Raise Your Maximum] [View Auction] [Remove Proxy Bid]

Your proxy bidding keeps you competitive. Let us know if you'd like to increase your maximum bid!
```

---

## User Stories for Implementation

### US-1: User Sets Proxy Bid
**As a** busy collector
**I want to** set a maximum bid and have the system bid on my behalf
**So that** I can win auctions without constant monitoring

**Acceptance Criteria:**
- [ ] Proxy bid form validates input (numeric, > current bid + increment)
- [ ] Proxy bid is saved to database
- [ ] Confirmation email is sent
- [ ] Proxy bid appears in "My Auctions" dashboard
- [ ] User can close browser and proxy bidding continues

---

### US-2: System Auto-Bids When Outbid
**As the** auction system
**I want to** automatically place bids on proxy bid users' behalf when they're outbid
**So that** users' desired auctions can be won without manual intervention

**Acceptance Criteria:**
- [ ] When a manual bid is placed, system checks if outbidding a proxy
- [ ] If outbid proxy < max proxy bid, auto-bid is triggered
- [ ] Auto-bid placed with next increment
- [ ] Auto-bid is recorded in audit log
- [ ] Original bidder notified they were outbid
- [ ] Proxy bid user can see auto-bid in history

---

### US-3: User Receives Outbid Notifications
**As a** proxy bid user
**I want to** receive immediate notifications when I'm outbid
**So that** I can decide to increase my bid maximum or concede

**Acceptance Criteria:**
- [ ] Email notification sent when outbid
- [ ] Notification shows new bid amount and competing bidder
- [ ] Dashboard notification displays
- [ ] In-auction notification banner appears if user viewing
- [ ] Notification includes "Raise Maximum Bid" quick action

---

### US-4: Auction Ends with Correct Winner & Price
**As a** winning bidder
**I want to** win at the lowest possible price needed to win
**So that** I'm not overcharged for items I've set high maximums on

**Acceptance Criteria:**
- [ ] Winner determined by highest proxy max (not highest bid)
- [ ] Invoice price = winning bid increment (not proxy max)
- [ ] Invoice is correct and sent immediately
- [ ] Losing bidders notified
- [ ] Auction records show all bids including auto-bids

---

## Acceptance Criteria (Epic Level)

- [ ] Auto-bidding feature fully functional in development environment
- [ ] Database migrations create required tables and indexes
- [ ] All unit tests pass (100% coverage for auto-bid logic)
- [ ] End-to-end tests cover all user scenarios
- [ ] Performance benchmarks met (< 100ms auto-bid processing)
- [ ] Security audit passed (no injection vulnerabilities)
- [ ] Documentation complete (user guide, developer guide, API docs)
- [ ] QA sign-off on manual testing
- [ ] Product Owner review + approval before release
- [ ] Release notes drafted with examples

---

## Dependencies & Constraints

### Dependencies
- **Bid Increment System**: v1.2.4+ (must support configurable increments)
- **User Authentication**: Existing auth system must identify users on proxy bids
- **Email System**: Must support transactional emails for notifications
- **Async Queue**: Background job processing for auto-bids

### Constraints
- **Backward Compatibility**: Existing auction/bid data must remain unchanged
- **PHP Compatibility**: Must work on PHP 7.3+
- **Database**: Must work with MySQL 5.7+ and PostgreSQL 10+
- **Performance**: Cannot exceed 100ms for auto-bid decisions
- **Concurrency**: Must handle parallel bids from different users safely

### Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| Duplicate bids placed | Medium | High | Implement optimistic locking + idempotency keys |
| Auto-bid fails silently | Medium | High | Comprehensive audit logging + alerting |
| Performance degrades with volume | Low | High | Database indexes + caching + load testing |
| Race conditions in bid incrementing | Medium | High | Transaction isolation level SERIALIZABLE |
| User confusion about proxy bidding | Medium | Medium | Clear UX + help documentation + tooltips |

---

## Release Strategy

- **Phased Rollout**: 
  - Phase 1 (Week 1-2): Internal testing + UAT
  - Phase 2 (Week 3): Beta testing with 10% of users
  - Phase 3 (Week 4): Full release to all users
  
- **Rollback Plan**: 
  - If > 0.5% auto-bid failure rate, rollback
  - Feature flag allows instant disabling if issues arise
  
- **Monitoring**: 
  - Track auto-bid success rate in real-time
  - Alert if any metric exceeds thresholds
  - Daily reports for first 2 weeks post-release

---

## Questions for Clarification

1. Should users be able to set proxy bids on auctions that haven't started yet?
2. What's the maximum number of concurrent proxy bids per user we want to support?
3. Should we allow proxy bids to be set on auctions from other sellers or also seller's own auctions?
4. Do we need to support "automatic bidding removal" at a specific time before auction end?
5. Should bid increment table be editable per-auction or site-wide only?
