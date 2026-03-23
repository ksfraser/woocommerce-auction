# Auto-Bidding Enhancement - Phase 3 Implementation Plan

**Version**: 1.0  
**Feature**: Auto-Bidding for WooCommerce Auctions  
**Estimated Effort**: 32 development tasks  
**Status**: Planning Phase  
**Date**: March 2026

---

## Executive Summary

The Auto-Bidding feature automates the bidding process for auction participants, allowing users to set maximum bid amounts and have the system automatically place bids on their behalf up to that limit. This enhancement increases buyer confidence, improves auction completion rates, and reduces manual bidding workload.

**Business Value**:
- Increase auction conversion rates by 25-35%
- Enable remote participation without real-time monitoring
- Competitive advantage vs. other auction platforms
- Reduced operational support burden

---

## Goal & Scope

### Goal

Enable registered users to set standing bids with automatic incremental bid placement, allowing passive participation in auctions while maintaining system security, fairness, and compliance with auction rules.

### Scope

**In Scope**:
- Auto-bid registration and management UI
- Automatic bid incrementation logic
- Auto-bid history and status tracking
- Proxy bidding algorithm implementation
- Real-time bid notifications
- Admin oversight and management
- Auto-bid cancellation and modification

**Out of Scope** (v1.5.0+):
- Machine learning bid optimization
- Third-party proxy bid services
- Cross-platform auto-bidding sync
- Mobile app auto-bid push notifications

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                      Client Layer (Browser)                 │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ Auto-Bid Registration Form                           │  │
│  │ - Maximum Bid Input                                  │  │
│  │ - Auto-Bid Confirmation & Management                 │  │
│  │ - Real-time Bid Status Display                       │  │
│  └──────────────────────────────────────────────────────┘  │
└────────────────────────┬─────────────────────────────────────┘
                         │ AJAX
                         ↓
┌─────────────────────────────────────────────────────────────┐
│                      API Layer (AJAX Endpoints)             │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ POST /wp-admin/admin-ajax.php?action=set_auto_bid   │  │
│  │ POST /wp-admin/admin-ajax.php?action=cancel_auto_bid│  │
│  │ POST /wp-admin/admin-ajax.php?action=modify_auto_bid│  │
│  │ GET  /wp-admin/admin-ajax.php?action=get_auto_bid   │  │
│  │ POST /wp-admin/admin-ajax.php?action=place_auto_bid │  │
│  └──────────────────────────────────────────────────────┘  │
└────────────────────────┬─────────────────────────────────────┘
                         │
                         ↓
┌─────────────────────────────────────────────────────────────┐
│             Business Logic Layer (Services)                 │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ AutoBidService                                       │  │
│  │ - setBid($user_id, $auction_id, $max_bid)          │  │
│  │ - placeBid($user_id, $auction_id)                  │  │
│  │ - cancelBid($auto_bid_id)                          │  │
│  │ - validateAndPlace()                               │  │
│  │ - notifyUserOfBidPlacement()                        │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ ProxyBiddingEngine                                   │  │
│  │ - calculateNextBid()                                │  │
│  │ - performBidIncrement()                             │  │
│  │ - evaluateOutbidStatus()                            │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ BidHistory Manager                                   │  │
│  │ - recordAutoBidPlacement()                          │  │
│  │ - getAutoBidHistory()                               │  │
│  └──────────────────────────────────────────────────────┘  │
└────────────────────────┬─────────────────────────────────────┘
                         │
                         ↓
┌─────────────────────────────────────────────────────────────┐
│          Data Access Layer (Repository Pattern)             │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ AutoBidRepository                                    │  │
│  │ - create()                                           │  │
│  │ - update()                                           │  │
│  │ - delete()                                           │  │
│  │ - findByUser()                                       │  │
│  │ - findByAuction()                                    │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ AutoBidHistoryRepository                             │  │
│  │ - recordPlacement()                                  │  │
│  │ - getHistory()                                       │  │
│  └──────────────────────────────────────────────────────┘  │
└────────────────────────┬─────────────────────────────────────┘
                         │
                         ↓
┌─────────────────────────────────────────────────────────────┐
│           Database Layer (WordPress WPDB)                   │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ wp_wc_auction_auto_bids                             │  │
│  │ wp_wc_auction_auto_bid_history                      │  │
│  │ Modified: wp_wc_auction_bids (auto_bid_ref)         │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ Queue System (Bid Execution)                         │  │
│  │ - BidQueue (existing from Phase 2.5)                │  │
│  │ - Job: process_auto_bid_placement                    │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘

Background Worker (Cron/Queue):
  ┌─────────────────────────────────────┐
  │ Detect Outbid Condition             │
  │ → Queue bid placement job           │
  │ → Execute via BidQueue              │
  │ → Send notifications                │
  │ → Update auto-bid status            │
  └─────────────────────────────────────┘
```

---

## Database Schema

### Table: `wp_wc_auction_auto_bids`

```sql
CREATE TABLE wp_wc_auction_auto_bids (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  auto_bid_id VARCHAR(36) NOT NULL UNIQUE,
  auction_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  maximum_bid DECIMAL(10,2) NOT NULL,
  current_bid_placed DECIMAL(10,2),
  status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  activated_at DATETIME,
  cancelled_at DATETIME,
  winning_bid_id BIGINT UNSIGNED,
  outbid_at DATETIME,
  KEY idx_user_auction (user_id, auction_id),
  KEY idx_auction_status (auction_id, status),
  KEY idx_user_status (user_id, status),
  CONSTRAINT fk_auction FOREIGN KEY (auction_id) REFERENCES wp_posts(ID),
  CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES wp_users(ID)
);
```

**Columns**:
- `id` - Primary key
- `auto_bid_id` - UUID for external reference
- `auction_id` - Product/auction ID
- `user_id` - User with auto-bid
- `maximum_bid` - Maximum amount user will pay
- `current_bid_placed` - Last bid amount placed on behalf of user
- `status` - ACTIVE, PAUSED, CANCELLED, WON_AUCTION
- `created_at` - When auto-bid was registered
- `updated_at` - Last modification
- `activated_at` - When system started bidding
- `cancelled_at` - When user cancelled
- `winning_bid_id` - Reference to bid record if won
- `outbid_at` - Timestamp when user was outbid

### Table: `wp_wc_auction_auto_bid_history`

```sql
CREATE TABLE wp_wc_auction_auto_bid_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  auto_bid_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  previous_bid DECIMAL(10,2),
  new_bid DECIMAL(10,2),
  reason VARCHAR(255),
  executed_at DATETIME NOT NULL,
  KEY idx_auto_bid (auto_bid_id),
  KEY idx_executed_at (executed_at),
  CONSTRAINT fk_auto_bid FOREIGN KEY (auto_bid_id) REFERENCES wp_wc_auction_auto_bids(id)
);
```

**Event Types**:
- `bid_placed` - Auto-bid placed on behalf of user
- `bid_incremented` - Existing auto-bid increased
- `outbid_detected` - Another bid exceeded auto-bid
- `reserve_met` - Auto-bid reached reserve price
- `auto_bid_registered` - User registered auto-bid
- `auto_bid_cancelled` - User cancelled auto-bid
- `auction_won` - User won with auto-bid
- `max_bid_modified` - User updated maximum bid

### Schema Modifications

**wp_wc_auction_bids modifications**:
- Add column: `auto_bid_ref` (BIGINT UNSIGNED, nullable) - Reference to auto-bid record
- Add index: `idx_auto_bid_ref` for tracking bids placed via auto-bidding

---

## API Endpoints

### 1. Register Auto-Bid

**Endpoint**: `POST /wp-admin/admin-ajax.php?action=set_auto_bid`

**Request**:
```json
{
  "auction_id": 456,
  "maximum_bid": 150.00,
  "nonce": "security_nonce_here"
}
```

**Response (Success)**:
```json
{
  "success": true,
  "auto_bid_id": "ab-uuid-12345",
  "message": "Auto-bid registered successfully",
  "auto_bid": {
    "id": "ab-uuid-12345",
    "maximum_bid": 150.00,
    "status": "ACTIVE",
    "created_at": "2026-03-22T10:00:00Z"
  }
}
```

**Response (Error)**:
```json
{
  "success": false,
  "error_code": "AUCTION_NOT_FOUND",
  "message": "Auction does not exist or is not active"
}
```

**Possible Errors**:
- `AUCTION_NOT_FOUND` - Specified auction doesn't exist
- `AUCTION_NOT_ACTIVE` - Auction not in active bidding window
- `INSUFFICIENT_FUNDS` - User insufficient balance (if payment required)
- `INVALID_BID_AMOUNT` - Bid below minimum or not a valid decimal
- `USER_NOT_AUTHENTICATED` - User not logged in
- `AUTO_BID_EXISTS` - User already has auto-bid on this auction

### 2. Cancel Auto-Bid

**Endpoint**: `POST /wp-admin/admin-ajax.php?action=cancel_auto_bid`

**Request**:
```json
{
  "auto_bid_id": "ab-uuid-12345",
  "nonce": "security_nonce_here"
}
```

**Response**:
```json
{
  "success": true,
  "message": "Auto-bid cancelled successfully",
  "status": "CANCELLED"
}
```

### 3. Modify Auto-Bid Maximum

**Endpoint**: `POST /wp-admin/admin-ajax.php?action=modify_auto_bid`

**Request**:
```json
{
  "auto_bid_id": "ab-uuid-12345",
  "new_maximum_bid": 200.00,
  "nonce": "security_nonce_here"
}
```

**Response**:
```json
{
  "success": true,
  "message": "Auto-bid updated",
  "new_maximum_bid": 200.00
}
```

### 4. Get Auto-Bid Status

**Endpoint**: `GET /wp-admin/admin-ajax.php?action=get_auto_bid&auto_bid_id=ab-uuid-12345`

**Response**:
```json
{
  "success": true,
  "auto_bid": {
    "id": "ab-uuid-12345",
    "auction_id": 456,
    "maximum_bid": 150.00,
    "current_bid_placed": 140.00,
    "status": "ACTIVE",
    "is_winning": true,
    "created_at": "2026-03-22T10:00:00Z",
    "updated_at": "2026-03-22T10:15:00Z"
  }
}
```

---

## Implementation Phases

### Phase 1: Core Data Infrastructure (Tasks 1-8)

**Deliverable**: Database schema, data access layer, models

1. **Task 1**: Create `wp_wc_auction_auto_bids` table migration
2. **Task 2**: Create `wp_wc_auction_auto_bid_history` table migration
3. **Task 3**: Add `auto_bid_ref` column to `wp_wc_auction_bids` table
4. **Task 4**: Create `AutoBid` value object class
5. **Task 5**: Create `AutoBidRepository` with CRUD operations
6. **Task 6**: Create `AutoBidHistoryRepository`
7. **Task 7**: Add migration runner for schema updates
8. **Task 8**: Create database tests for all repositories

**Acceptance Criteria**:
- ✅ All tables created with proper indexes
- ✅ Foreign key constraints in place
- ✅ Repository methods tested with 100% coverage
- ✅ Migrations reversible

---

### Phase 2: Business Logic Services (Tasks 9-18)

**Deliverable**: Core auto-bidding logic, validation, and event handling

9. **Task 9**: Implement `AutoBidService` with `registerAutoBid()` method
10. **Task 10**: Implement `AutoBidService::cancelAutoBid()`
11. **Task 11**: Implement `AutoBidService::modifyMaximumBid()`
12. **Task 12**: Implement `ProxyBiddingEngine::calculateNextBid()` algorithm
13. **Task 13**: Implement proxy bid placement logic in `ProxyBiddingEngine`
14. **Task 14**: Implement outbid detection and notification
15. **Task 15**: Integrate with BidQueue system for async bid placement
16. **Task 16**: Create `AutoBidValidator` for registration validation
17. **Task 17**: Implement `AutoBidStatusManager` for state transitions
18. **Task 18**: Unit tests for all business logic services (100% coverage)

**Acceptance Criteria**:
- ✅ Auto-bids placed within 100ms of outbid detection
- ✅ Proxy algorithm never exceeds user's maximum bid
- ✅ All validation rules enforced
- ✅ 100% test coverage

---

### Phase 3: API Layer (Tasks 19-26)

**Deliverable**: AJAX endpoints for UI integration

19. **Task 19**: Implement `set_auto_bid` AJAX endpoint
20. **Task 20**: Implement `cancel_auto_bid` AJAX endpoint
21. **Task 21**: Implement `modify_auto_bid` AJAX endpoint
22. **Task 22**: Implement `get_auto_bid` AJAX endpoint
23. **Task 23**: Implement `place_auto_bid` (internal queue trigger) endpoint
24. **Task 24**: Create request validators and nonce verification
25. **Task 25**: Implement error response formatting
26. **Task 26**: Integration tests for all endpoints

**Acceptance Criteria**:
- ✅ All endpoints return proper JSON responses
- ✅ Nonce validation on all state-changing endpoints
- ✅ User authentication verified
- ✅ Rate limiting applied (prevent abuse)

---

### Phase 4: Bootstrap & Background Processing (Tasks 27-32)

**Deliverable**: Auto-bid initialization, queue processing, cron integration

27. **Task 27**: Create `AutoBidBootstrapper` for plugin initialization
28. **Task 28**: Implement queue task handler for `process_auto_bid_placement`
29. **Task 29**: Create cron job for checking outbid conditions every 30 seconds
30. **Task 30**: Implement email notification system for auto-bid events
31. **Task 31**: Create admin settings page for auto-bidding configuration
32. **Task 32**: End-to-end integration tests

**Acceptance Criteria**:
- ✅ Plugin initializes without errors
- ✅ Cron jobs execute on schedule
- ✅ Queue processing completes successfully
- ✅ Email notifications sent correctly
- ✅ 100% feature coverage

---

## Technical Considerations

### Proxy Bidding Algorithm

The auto-bidding system uses a standard proxy bidding algorithm:

```
When new bid received:
  1. If no auto-bids exist for auction:
     → New bid becomes highest
  2. For each active auto-bid:
     a. If auto-bid max > new bid:
        → Automatically place counter-bid
        → Counter-bid = MIN(new bid + increment, auto-bid max)
        → Record in history
     b. If auto-bid max <= new bid:
        → User is outbid
        → Send notification
        → Update status to OUTBID
  3. Return new highest bid amount
```

### Concurrency & Race Conditions

**Problem**: Multiple simultaneous bids could cause race conditions

**Solution**:
- Use database row-level locking with SELECT FOR UPDATE
- Queue all bid placements to ensure serial processing
- Use transaction isolation level SERIALIZABLE for critical sections
- Leverage BidQueue system (from Phase 2.5) for reliable queue

### Security Considerations

1. **Maximum Bid Limits**: Validate never exceeds user's available balance
2. **Nonce Verification**: All AJAX endpoints require valid WordPress nonce
3. **User Authentication**: Only authenticated users can set auto-bids
4. **Bid Tampering**: All bid amounts validated server-side
5. **Data Privacy**: Auto-bid amounts only visible to bid owners and admins

### Performance Optimization

1. **Index Strategy**:
   - Composite index on (auction_id, status) for outbid detection
   - Composite index on (user_id, status) for user's auto-bids
   
2. **Caching**:
   - Cache active auto-bids per auction (60-second TTL)
   - Cache user's auto-bids (120-second TTL)
   - Clear cache on any modification

3. **Query Optimization**:
   - Use LEFT JOIN to fetch current bid in one query
   - Avoid N+1 queries in history retrieval
   - Pagination for history display (20 items/page)

---

## Testing Strategy

### Unit Tests (100% coverage required)

**Suites**:
- AutoBidService tests (15 tests)
- ProxyBiddingEngine tests (12 tests)
- AutoBidValidator tests (10 tests)
- Repository tests (18 tests)
- **Total**: 55+ tests

### Integration Tests

**Scenarios**:
- E2E auto-bid registration and placement
- Multiple simultaneous auto-bids on same auction
- Auto-bid with reserve price
- Auto-bid cancellation workflow
- Maximum bid modification with active bid
- **Total**: 12+ tests

### Quality Gates

- ✅ Minimum 80% code coverage (PHPStan level 5)
- ✅ All tests pass
- ✅ No critical security issues
- ✅ Performance benchmarks met

---

## Deployment Strategy

### Migrations

Auto-created on plugin update via WordPress hooks:

```php
add_action('plugins_loaded', function() {
  $setup = AutoBidDatabaseSetup::getInstance();
  $setup->migrate();
});
```

### Feature Flags

Control rollout with WordPress option:

```
wc_auction_auto_bidding_enabled = 1/0
```

### Rollout Plan

1. **Alpha** (1 week): Internal testing on staging
2. **Beta** (2 weeks): Limited rollout to 5% of users
3. **Gamma** (1 week): 25% of users
4. **Full Release**: 100% of installations

---

## Success Metrics

| Metric | Target | Measurement |
|--------|--------|-------------|
| Auction completion rate | +25% | Auctions completed / total auctions |
| Auto-bid adoption | >15% | Users with ≥1 auto-bid / total users |
| Bid response time | ≤100ms | P99 latency for auto-bid placement |
| System reliability | >99.9% | Uptime of auto-bid service |
| User satisfaction | >4.5/5 | Average rating in plugin reviews |

---

## Risk Assessment

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|-----------|
| Race condition in concurrent bids | Medium | High | Serializable transaction isolation + queue |
| User confusion about proxy bids | High | Low | Clear UI documentation + tooltips |
| Database performance degradation | Low | Medium | Proper indexing + caching strategy |
| Regulatory compliance issues | Low | High | Terms of service update + audit logs |

---

## Dependencies & Blockers

**Must Complete Before**:
- ✅ BidQueue system (Phase 2.5) - **COMPLETED**
- ✅ Core auction infrastructure
- ✅ User authentication system
- ✅ Bid validation framework

**External Dependencies**:
- WordPress 5.0+ (for AJAX stability)
- WooCommerce 3.0+ (for product integration)

---

## Documentation Deliverables

1. **API Reference** - Auto-bidding endpoints documentation
2. **User Guide** - How to use auto-bidding feature
3. **Admin Guide** - Configuration and oversight
4. **Developer Guide** - Extending auto-bidding functionality
5. **Architecture Diagrams** - System design documentation

---

**Prepared by**: Development Team  
**Next Step**: Create GitHub issues from this implementation plan and assign to development team
