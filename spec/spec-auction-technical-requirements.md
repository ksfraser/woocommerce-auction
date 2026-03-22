---
title: WooCommerce Auction - Technical Specification
version: 1.0
date_created: 2026-03-22
last_updated: 2026-03-22
owner: Development Team
tags: [specification, auction, woocommerce, technical, requirements]
---

# WooCommerce Auction - Technical Specification

## 1. Introduction

This specification defines the technical requirements, constraints, and interfaces for the WooCommerce Auction plugin. It provides a precise, AI-optimized reference for developing, maintaining, and extending the system.

## 2. Purpose & Scope

**Purpose:** Define system capabilities, constraints, technical interfaces, and acceptance criteria for the auction management system

**Scope:** 
- Core auction functionality (product creation, bidding, completion)
- Admin configuration and management
- User-facing bidding interface
- Database schema and persistence
- API contracts for extensions

**Audience:** Developers, architects, QA engineers, and plugin extensors

**Assumptions:**
- WordPress 4.0+ and WooCommerce 3.0+ are installed and active
- PHP 7.3+ runtime available
- Database with InnoDB support for transactions
- Standard WordPress user authentication in place

---

## 3. Definitions

| Term | Definition |
|------|-----------|
| **Auction** | A time-limited sale mechanism where users submit increasing bids |
| **Bid** | An offer amount submitted by a user during active auction |
| **Reserve Price** | Minimum acceptable auction final price (not displayed) |
| **Start Price** | Opening bid amount that starts the auction |
| **Bid Increment** | Minimum difference required between consecutive bids |
| **Winner** | User with highest bid when auction closes |
| **AJAX** | Asynchronous JavaScript request for real-time bid submission |
| **Singleton** | Design pattern ensuring single instance per application lifecycle |
| **Repository** | Data access pattern abstracting database operations |
| **Hook** | WordPress extension point for custom functionality |

---

## 4. Requirements, Constraints & Guidelines

### 4.1 Functional Requirements

#### REQ-CORE-001: Auction Product Type
- The system SHALL provide custom "auction" product type in WooCommerce
- The type SHALL extend `WC_Product` base class
- Type SHALL support all standard WooCommerce product features (images, descriptions, shipping)
- Auction type SHALL be selectable in product creation UI

#### REQ-CORE-002: Auction Configuration
- System SHALL allow configuration of per-product auction parameters:
  - Start price ($0+ decimal precision 2)
  - Reserve price (optional, $0+)
  - Start date/time (past, present, or future)
  - End date/time (must be after start)
  - Custom bid increment ranges (optional)
- All parameters SHALL be stored in product postmeta
- Admin UI SHALL validate parameter ranges and logical constraints

#### REQ-CORE-003: Bid Submission
- System SHALL accept bid submissions via AJAX endpoint only
- Endpoint: `wp-admin/admin-ajax.php?action=WcAuction_submit_bid`
- Request MUST include authenticated user ID and valid nonce
- Bid amount MUST exceed current highest bid + required increment
- Bid MUST be accepted within 100ms response time (SLA)
- System SHALL return JSON response indicating acceptance/rejection

#### REQ-CORE-004: Bid Validation
- Bid validation SHALL verify:
  - Auction exists and is active (current time within start/end range)
  - User is authenticated (logged-in)
  - Bid amount ≥ (current bid + increment) OR bid ≥ start price
  - Bid amount ≤ user's available balance (if payment required)
  - No duplicate simultaneous bids from same user
- Failed validations SHALL return descriptive error message
- Invalid bids SHALL NOT be persisted to database

#### REQ-CORE-005: Bid Increments
- System SHALL support configurable bid increments by price range:
  - Global increments applied to all auctions (default)
  - Product-specific increments override global
  - Ranges defined as: from_price → to_price → increment (decimal)
- Increment calculation SHALL support:
  - Fixed increment ($5 for $0-$100)
  - Percentage-based ($500 price = 5% increment)
  - Open-ended ranges (≥$1000 = $25 increment)
- Next valid bid = current bid + applicable increment

#### REQ-CORE-006: Bid History
- System SHALL maintain complete bid history per auction:
  - User ID, bid amount, submission timestamp, final status
  - Status values: pending, winner, outbid, expired
  - History persisted immutably (no modification after creation)
- Bid history queryable by:
  - Auction ID (all bids on product)
  - User ID (user's all bids)
  - Bid ID (specific bid details)

#### REQ-CORE-007: Auction Completion
- When end date/time reached, system SHALL:
  - Identify highest bid as winner
  - Update all outbid bid statuses to "outbid"
  - Update winning bid status to "winner"
  - Trigger `WcAuction_auction_finished` action
  - Send winner notification email (if configured)
  - Create pending order (if payment integration active)

#### REQ-CORE-008: User Dashboard
- Authenticated users SHALL access "My Auctions" page showing:
  - Auctions user has active bids in
  - Current bid amount and status (winning/outbid)
  - Remaining time to auction close
  - Auctions user has won
  - Bid history with timestamps

#### REQ-CORE-009: Admin Settings Panel
- Admin users SHALL access WooCommerce → Settings → Auctions:
  - Display options (show username in bids, button placement)
  - Global bid increment ranges management UI
  - Email notification settings
  - Integration settings (payment plugins, WooCommerce hooks)

### 4.2 Non-Functional Requirements

#### REQ-PERF-001: Response Time
- Bid submission AJAX response: ≤100ms (99th percentile)
- Bid history retrieval: ≤200ms for auction with 1000+ bids
- Admin settings load: ≤500ms
- Product page render: ≤1s (including auction elements)

#### REQ-PERF-002: Database Optimization
- Bid queries MUST use indexes on (auction_id, timestamp, bid)
- No unindexed full-table scans in critical paths
- Increment lookups via index on (product_id, from_price)

#### REQ-SECURITY-001: Input Validation
- ALL user input SHALL be sanitized via `sanitize_text_field()` or equivalent
- Numeric input (bid amounts) SHALL be validated as `floatval()` with range checks
- All AJAX requests MUST verify nonce via `wp_verify_nonce()`

#### REQ-SECURITY-002: Authorization
- Only authenticated users SHALL submit bids
- Bid submission MUST verify current user ID matches bid owner
- Admin settings access MUST verify `manage_options` capability
- Product editing MUST verify `edit_products` capability

#### REQ-SECURITY-003: Data Integrity
- Bid records SHALL use `INSERT` for creation (immutable append-only)
- Status updates SHALL use `UPDATE` with WHERE on bid_id
- All DB queries SHALL use `$wpdb->prepare()` for parameterization
- No string concatenation in SQL queries

#### REQ-SECURITY-004: SQL Injection Prevention
- 100% of database queries SHALL use prepared statements
- No exceptions for "safe" or "hardcoded" values
- Code review checklist MUST include SQL injection verification

#### REQ-COMPAT-001: WordPress Compatibility
- System SHALL support WordPress 4.0+ through current version
- All deprecated WordPress functions SHALL NOT be used
- System SHALL use `get_current_user_id()` (not `$GLOBALS['current_user']`)
- Hook system SHALL follow WordPress conventions

#### REQ-COMPAT-002: WooCommerce Compatibility
- System SHALL support WooCommerce 3.0+ through current version
- Custom product type SHALL extend `WC_Product` correctly
- Metadata SHALL persist through WooCommerce import/export

#### REQ-QUAL-001: Code Quality
- SOLID principles SHALL be followed (SRP, OCP, LSP, ISP, DIP)
- Classes SHALL have single, well-defined responsibility
- Code coverage target: 100% for critical paths (bid validation, storage)
- PHPStan analysis: Level 5+ required

#### REQ-QUAL-002: Documentation
- All public classes SHALL have PHPDoc blocks with:
  - @requirement tag mapping to REQ-* identifiers
  - @param and @return type hints
  - UML description of class relationships
- Complex algorithms SHALL have inline comments
- Database schema SHALL be documented in migration files

#### REQ-QUAL-003: Logging & Monitoring
- Structured logging SHALL record:
  - Bid submission attempts (with validation results)
  - Auction state changes (start, end, winner determined)
  - Admin configuration changes
  - System errors with stack traces
- Log levels: ERROR, WARNING, INFO, DEBUG (configurable)

---

## 5. Interfaces & Data Contracts

### 5.1 AJAX Bid Submission Interface

**Endpoint:** `POST /wp-admin/admin-ajax.php`

**Request:**
```json
{
    "action": "WcAuction_submit_bid",
    "auction_id": 12345,
    "bid_amount": 250.50,
    "nonce": "abc123def456"
}
```

**Success Response (HTTP 200):**
```json
{
    "success": true,
    "message": "Bid accepted",
    "data": {
        "bid_id": 987,
        "current_bid": 250.50,
        "next_minimum": 255.50,
        "bid_count": 5,
        "winning": true,
        "timestamp": "2024-03-25 14:30:00"
    }
}
```

**Error Response (HTTP 200):**
```json
{
    "success": false,
    "message": "Bid amount too low. Minimum: $255.50",
    "data": {
        "code": "BID_TOO_LOW",
        "current_bid": 250.00,
        "required_increment": 5.50
    }
}
```

### 5.2 Database Table: `wp_WcAuction_auction`

```sql
CREATE TABLE wp_WcAuction_auction (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    auction_id BIGINT UNSIGNED NOT NULL COMMENT 'WooCommerce product ID',
    user_id BIGINT UNSIGNED NOT NULL COMMENT 'WordPress user ID',
    bid DECIMAL(10,2) UNSIGNED NOT NULL COMMENT 'Bid amount',
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When bid submitted',
    status VARCHAR(50) NOT NULL DEFAULT 'pending' COMMENT 'pending|winner|outbid|expired',
    KEY idx_auction_time (auction_id, timestamp DESC),
    KEY idx_user_time (user_id, timestamp DESC),
    KEY idx_auction_bid (auction_id, bid DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.3 Database Table: `wp_WcAuction_BidIncrement`

```sql
CREATE TABLE wp_WcAuction_BidIncrement (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL COMMENT '0 = global, else product ID',
    from_price DECIMAL(10,2) UNSIGNED NOT NULL COMMENT 'Range start',
    to_price DECIMAL(10,2) UNSIGNED COMMENT 'Range end (NULL = open)',
    increment DECIMAL(10,2) UNSIGNED NOT NULL COMMENT 'Increment value',
    UNIQUE KEY uk_product_range (product_id, from_price),
    KEY idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.4 Product Metadata Schema

**Stored as WooCommerce post metadata:**

```php
[
    '_yith_auction_start_price' => '50.00',           // string decimal
    '_yith_auction_reserve_price' => '100.00',        // string decimal
    '_yith_auction_start_date' => '2024-03-25 10:00', // datetime string
    '_yith_auction_end_date' => '2024-04-01 22:00',   // datetime string
    '_yith_auction_bid_increments' => '[{...}]'       // JSON array
]
```

### 5.5 Class Interfaces

#### `WcAuction_Bids` Repository Interface

```php
/**
 * @requirement REQ-CORE-006
 */
public function add_bid($auction_id, $user_id, $bid_amount);
// Returns: Bid ID (int) or throws Exception

/**
 * @requirement REQ-CORE-006
 */
public function get_auction_bids($auction_id, $order = 'DESC');
// Returns: Array of bid records

/**
 * @requirement REQ-CORE-006
 */
public function get_highest_bid($auction_id);
// Returns: Bid amount (decimal) or null
```

#### `WC_Product_Auction` Model Interface

```php
/**
 * @requirement REQ-CORE-002
 */
public function get_auction_start_price();
// Returns: decimal

/**
 * @requirement REQ-CORE-004
 */
public function is_auction_active();
// Returns: bool (true if current_time between start/end dates)

/**
 * @requirement REQ-CORE-005
 */
public function get_auction_increment_value($current_bid);
// Returns: decimal (increment for next valid bid)
```

---

## 6. Acceptance Criteria

### AC-001: Auction Product Creation

**Given** an authenticated admin user on product creation page  
**When** product type is set to "Auction"  
**Then** admin SHALL see auction-specific fields:
- Start Price input
- Reserve Price input (optional)
- Start Date/Time picker
- End Date/Time picker
- Bid Increment ranges section

**AND** wizard SHALL validate:
- Start date < End date
- All prices ≥ $0
- All required fields populated before save

### AC-002: Bid Acceptance

**Given** an active auction product  
**And** authenticated user with valid bid amount  
**When** user submits AJAX bid request  
**Then** system SHALL:
1. Validate bid amount ≥ (highest_bid + increment)
2. Create bid record in database
3. Update auction's current highest bid
4. Return success JSON within 100ms

### AC-003: Bid Rejection - Insufficient Amount

**Given** active auction with current bid $250 and $5 increment  
**And** user submits bid of $253  
**When** bid validation executes  
**Then** system SHALL:
1. Reject bid (< $255 minimum)
2. Return error message: "Bid amount too low"
3. NOT create database record
4. Respond with current_bid and required_increment fields

### AC-004: Auction Completion

**Given** auction with end date in the past  
**When** auction completion handler triggers  
**Then** system SHALL:
1. Query all bids for auction
2. Mark highest bid as status='winner'
3. Mark all others as status='outbid'
4. Trigger WcAuction_auction_finished action
5. Send winner notification email

### AC-005: Bid History Retrieval

**Given** user logged into account  
**When** user navigates to "My Auctions" page  
**Then** system SHALL display:
1. All active auctions with user's bids
2. Current bid amount and status (winning/outbid)
3. Auctions user has won with final amounts
4. Sorted by most recent first

### AC-006: Increment Configuration

**Given** admin in Settings → Auctions → Increments  
**When** admin creates range: $0–$100 = $1 increment  
**And** $100–$500 = $5 increment  
**And** $500+ = $10 increment  
**Then** next valid bid calculations SHALL be:
- Current bid $50 → next minimum = $51
- Current bid $150 → next minimum = $155
- Current bid $600 → next minimum = $610

---

## 7. Test Automation Strategy

### Test Levels

- **Unit Tests**: Individual component behavior (>90% coverage)
- **Integration Tests**: Component interactions with mocks
- **End-to-End Tests**: Complete workflows (optional, manual for now)

### Frameworks & Tools

- **Test Runner**: PHPUnit 9.6+
- **Compatibility**: Yoast PHPUnit Polyfills
- **Mocking**: ksfraser/mock-wordpress, ksfraser/mock-woocommerce
- **Factories**: ksfraser/test-factories (ScenarioBuilder, BidBuilder)

### Test Coverage Requirements

- **Critical Paths**: 100% (bid validation, storage, completion)
- **Business Logic**: ≥90%
- **Overall Target**: ≥85%

### CI/CD Integration

- Tests run on every commit
- Coverage reports generated automatically
- Failed tests block merge to main branch

### Test Organization

```
tests/
├── Unit/
│   ├── Auction/
│   ├── Bid/
│   └── Increment/
└── fixtures/
    ├── active-auction.php
    └── bid-samples.php
```

---

## 8. Rationale & Context

### Why Lightweight Data Model?

The system uses direct WooCommerce product metadata rather than custom post types to:
- Maximize compatibility with WooCommerce import/export
- Reduce database complexity
- Enable gradual migration from existing product systems

### Why Singleton Pattern?

Singleton coordinators (YITH_Auctions) ensure:
- Single initialization point
- Easy extension via class variants
- Familiar to WordPress developers
- Simplified testing with mocks

### Why AJAX for Bidding?

Real-time bidding requires:
- Sub-100ms response times (HTTP request/response overhead unacceptable)
- Asynchronous updates without page refresh
- Live bid conflict resolution
- AJAX enables all above

---

## 9. Dependencies & External Integrations

### External Systems

- **EXT-001**: WooCommerce - Product data storage and e-commerce operations
- **EXT-002**: WordPress User System - Authentication and authorization
- **EXT-003**: WordPress Post Types - Product persistence mechanism

### Third-Party Services

- **SVC-001**: Payment Gateway (optional) - Order creation if enabled
- **SVC-002**: Email Service - Winner notifications

### Infrastructure Dependencies

- **INF-001**: MySQL/InnoDB Database - Supports transactions for bid atomicity
- **INF-002**: PHP 7.3+ Runtime - Required for language features
- **INF-003**: WordPress Admin - Settings management interface

### Optional Integrations

- **WPML Plugin**: Automatic multi-language support for auction content
- **Premium Extensions**: Register via class variant pattern

---

## 10. System Constraints

### Performance Constraints

- Auction queries must complete in ≤200ms
- Bid insertion must complete in ≤50ms
- Single page can display up to 100 auctions without degradation

### Scalability Constraints

- Current design supports up to 1M bids per auction
- Further optimization needed for 10M+ total bids across system
- Read-heavy workload (optimization via caching recommended for >100K auctions)

### Compatibility Constraints

- Must maintain compatibility with WordPress 4.0+
- Must maintain compatibility with WooCommerce 3.0+
- PHP 7.3+ requirement (no older versions supported)

### Data Constraints

- Bid amounts stored as DECIMAL(10,2) - max value: $99,999,999.99
- Product IDs limited by WordPress core (BIGINT)
- Timestamps stored as MySQL DATETIME (accurate to seconds)

---

## 11. Future Extensions

### v1.4.0: Auto-Bidding System

**REQ-FUTURE-AUTO-001**: System SHALL support proxy bidding where user sets maximum bid, system auto-increments

**REQ-FUTURE-AUTO-002**: Auto-bids SHALL follow bid increment rules automatically

**REQ-FUTURE-AUTO-003**: System SHALL NOT auto-bid beyond user's maximum

### v1.5.0: Sealed Bid Auctions

**REQ-FUTURE-SEALED-001**: System SHALL support hidden-bid auctions where bids revealed after close

**REQ-FUTURE-SEALED-002**: Winner determined by highest sealed bid

### v1.4.0: Entry Fees & Commission

**REQ-FUTURE-FEE-001**: System SHALL support optional entry fees per auction

**REQ-FUTURE-FEE-002**: System SHALL support commission calculation (3 models: flat, percentage, tiered)
