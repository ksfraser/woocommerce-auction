---
title: YITH Auctions for WooCommerce - Architecture Blueprint
version: 1.0
date_created: 2026-03-22
last_updated: 2026-03-22
tags: [architecture, wordpress, woocommerce, auction]
---

# YITH Auctions for WooCommerce - Architecture Blueprint

## Executive Summary

YITH Auctions for WooCommerce is a **modular, plugin-based auction system** built on WordPress and WooCommerce. The architecture follows **component-based design patterns** with clear separation of concerns, enabling extensibility through hooks, filters, and custom class overrides.

**Technology Stack:**
- PHP 7.3+ | WordPress 4.0+ | WooCommerce 3.0+
- Singleton pattern for core components
- Repository pattern for data access
- Hook/Filter system for extensibility

---

## 1. Architectural Overview

### 1.1 Core Principles

1. **Separation of Concerns**: Each component has a single, well-defined responsibility
2. **Plugin Extensibility**: Premium features extend base classes rather than modifying them
3. **Data Persistence**: Repository layer abstracts database operations
4. **Event-Driven**: Hooks and filters enable loosely-coupled interactions
5. **WordPress Integration**: Leverages native WordPress patterns (post types, metadata, hooks)

### 1.2 Architectural Pattern

The plugin implements a **Layered Architecture** with three primary layers:

```
┌─────────────────────────────────────────────┐
│         Presentation Layer                  │
│  (Frontend UI, Admin Panel, AJAX Handlers)  │
├─────────────────────────────────────────────┤
│         Business Logic Layer                │
│  (Auction Manager, Bid Validator, Pricing)  │
├─────────────────────────────────────────────┤
│         Data Access Layer                   │
│  (Repositories, DB Queries, Metadata)       │
├─────────────────────────────────────────────┤
│         Infrastructure Layer                │
│  (WordPress Hooks, WooCommerce Events)      │
└─────────────────────────────────────────────┘
```

---

## 2. Core Architectural Components

### 2.1 Plugin Coordinator: `YITH_Auctions`

**File:** `includes/class.yith-wcact-auction.php`

**Purpose:** Central coordinator managing plugin initialization and lifecycle

**Responsibilities:**
- Plugin activation/deactivation hooks
- Singleton instance management
- Component initialization
- Hook registration

**Key Methods:**
- `get_instance()` - Singleton accessor
- `__construct()` - Bootstrap all components
- `admin_print_styles()` - Register admin assets
- `frontend_print_styles()` - Register frontend assets

**Design Pattern:** Singleton + Service Locator

```php
// Usage
$auction = YITH_Auctions::get_instance();
```

### 2.2 Product Model: `WC_Product_Auction`

**File:** `includes/class.yith-wcact-auction-product.php`

**Purpose:** Custom product type extending WooCommerce's product base

**Extends:** `WC_Product`

**Responsibilities:**
- Auction metadata management (start price, dates, etc.)
- Current bid retrieval
- Auction state queries (active, ended, won)
- Bid increment calculations

**Key Metadata:**
- `_yith_auction_start_price`: Opening bid amount
- `_yith_auction_reserve_price`: Minimum acceptable bid
- `_yith_auction_start_date`: Auction start timestamp
- `_yith_auction_end_date`: Auction end timestamp
- `_yith_auction_bid_increments`: Custom increment ranges (JSON)

**Key Methods:**
- `get_auction_start_price()` - Retrieve start price
- `get_current_highest_bid()` - Get highest bid on auction
- `is_auction_started()` - Check if bidding active
- `is_auction_ended()` - Check if auction closed
- `get_auction_increment_value()` - Calculate next valid bid

### 2.3 Data Access Layer: `YITH_WCACT_Bids`

**File:** `includes/class.yith-wcact-auction-bids.php`

**Purpose:** Repository for bid persistence and retrieval

**Responsibilities:**
- Create and update bid records
- Query bid history
- Manage bid status (pending, winner, outbid)
- Bid validation

**Database Table:** `wp_yith_wcact_auction`

**Key Methods:**
- `add_bid($auction_id, $user_id, $bid_amount)` - Create bid record
- `get_auction_bids($auction_id)` - Retrieve all bids for product
- `get_user_bids($user_id)` - Retrieve user's bids
- `get_highest_bid($auction_id)` - Get current winning bid
- `update_bid_status($bid_id, $status)` - Update bid outcome

**Design Pattern:** Active Record (repository with DB access)

### 2.4 Business Logic: `YITH_WCACT_Bid_Increment`

**File:** `includes/class.yith-wcact-auction-bid-increment.php`

**Purpose:** Manage bid increment rules by price range

**Responsibilities:**
- Define increment ranges (from price → increment value)
- Calculate valid next bids
- Support global and per-product overrides

**Database Table:** `wp_yith_wcact_bid_increment`

**Key Methods:**
- `get_increment_for_value($price, $product_id)` - Calculate increment for price point
- `get_increments_by_product($product_id)` - Retrieve product-specific ranges
- `save_increment_range($product_id, $from, $to, $increment)` - Persist range

**Data Structure:**
```php
[
    ['from_price' => 0,    'to_price' => 100,   'increment' => 1],
    ['from_price' => 100,  'to_price' => 500,   'increment' => 5],
    ['from_price' => 500,  'to_price' => 1000,  'increment' => 10],
    ['from_price' => 1000, 'to_price' => null,  'increment' => 25]  // open-ended
]
```

### 2.5 AJAX Endpoint: `YITH_WCACT_Auction_Ajax`

**File:** `includes/class.yith-wcact-auction-ajax.php`

**Purpose:** Handle real-time bid submissions via AJAX

**Endpoints:**
- `wp-admin/admin-ajax.php?action=yith_wcact_submit_bid`

**Responsibilities:**
- Validate bid requests
- Process bid submissions
- Return real-time updates
- Handle auction state changes

**Key Methods:**
- `submit_bid()` - Process AJAX bid request
- `validate_bid()` - Check bid validity
- `build_response()` - Format AJAX response

**Request Payload:**
```json
{
    "action": "yith_wcact_submit_bid",
    "auction_id": 123,
    "bid_amount": 150.00,
    "nonce": "verification_token"
}
```

**Response:**
```json
{
    "success": true,
    "message": "Bid accepted",
    "current_bid": 150.00,
    "next_minimum": 155.00,
    "bid_count": 5,
    "winning": true
}
```

### 2.6 Admin Interface: `YITH_Auction_Admin`

**File:** `includes/class.yith-wcact-auction-admin.php`

**Purpose:** WordPress admin UI and settings management

**Responsibilities:**
- Admin settings panel
- Product metabox UI
- Settings validation
- Admin asset enqueuing

**Extension Pattern:**
Premium features extend this class:
```php
class YITH_Auction_Admin_Premium extends YITH_Auction_Admin {
    // Premium admin UI
}
```

**Settings Panel:** WooCommerce → Settings → Auctions

### 2.7 Frontend Display: `YITH_Auction_Frontend`

**File:** `includes/class.yith-wcact-auction-frontend.php`

**Purpose:** Template rendering and frontend functionality

**Responsibilities:**
- Product page rendering
- Bid history display
- Countdown timer UI
- Frontend asset enqueuing

**Templates:** `templates/frontend/`

### 2.8 Auction Completion: `YITH_WCACT_Finish_Auction`

**File:** `includes/class.yith-wcact-auction-finish-auction.php`

**Purpose:** Handle auction end-of-life events

**Responsibilities:**
- Determine auction winner
- Update bid statuses
- Send winner notifications
- Create orders if applicable

### 2.9 User Dashboard: `YITH_Auction_My_Auctions`

**File:** `includes/class.yith-wcact-auction-my-auctions.php`

**Purpose:** User account page showing bid history

**Responsibilities:**
- Render My Auctions page
- Display user's bids and wins
- Provide bid history filtering

---

## 3. Data Architecture

### 3.1 Database Schema

**Table: `wp_yith_wcact_auction`**

Persists individual bid records:

```sql
CREATE TABLE wp_yith_wcact_auction (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    auction_id BIGINT NOT NULL,          -- Product ID
    user_id BIGINT NOT NULL,             -- WordPress user ID
    bid DECIMAL(10,2) NOT NULL,          -- Bid amount
    timestamp DATETIME NOT NULL,         -- When bid submitted
    status VARCHAR(50) DEFAULT 'pending' -- pending | winner | outbid | expired
);
```

**Indexes:**
- `(auction_id, timestamp DESC)` - Query bids for specific auction
- `(user_id, timestamp DESC)` - Query user's bid history
- `(auction_id, bid DESC)` - Find highest bid

**Table: `wp_yith_wcact_bid_increment`**

Defines increment ranges:

```sql
CREATE TABLE wp_yith_wcact_bid_increment (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    product_id BIGINT NOT NULL,          -- Product ID (0 = global)
    from_price DECIMAL(10,2) NOT NULL,   -- Range start
    to_price DECIMAL(10,2),              -- Range end (NULL = open)
    increment DECIMAL(10,2) NOT NULL     -- Required increment
);
```

### 3.2 Product Metadata

Auction data stored as WooCommerce post metadata:

```php
$auction_data = [
    '_yith_auction_start_price'   => 50.00,
    '_yith_auction_reserve_price' => 100.00,
    '_yith_auction_start_date'    => '2024-03-25 10:00:00',
    '_yith_auction_end_date'      => '2024-04-01 22:00:00',
    '_yith_auction_bid_increments' => json_encode([
        ['from' => 0, 'to' => 100, 'increment' => 1],
        ['from' => 100, 'to' => 500, 'increment' => 5]
    ])
];
```

### 3.3 Auction State Queries

**Active Auctions:**
```php
$active = get_posts([
    'post_type' => 'product',
    'meta_query' => [
        ['key' => '_yith_auction_start_date', 'value' => current_time('mysql'), 'compare' => '<='],
        ['key' => '_yith_auction_end_date', 'value' => current_time('mysql'), 'compare' => '>=']
    ]
]);
```

**User's Winning Bids:**
```php
$wins = $bids_repo->get_user_bids($user_id, 'winner');
```

---

## 4. Cross-Cutting Concerns

### 4.1 Error Handling

**Exception Hierarchy:**
```php
// Custom exceptions for specific error conditions
class YITH_WCACT_Bid_Invalid_Exception extends Exception {}
class YITH_WCACT_Auction_Not_Found_Exception extends Exception {}
class YITH_WCACT_Auction_Not_Active_Exception extends Exception {}
```

### 4.2 Logging

Structured logging for audit trails:

```php
// Log bid submissions
do_action('yith_wcact_log_bid_submission', [
    'auction_id' => $auction_id,
    'user_id' => $user_id,
    'bid_amount' => $bid,
    'success' => $accepted,
    'timestamp' => current_time('mysql')
]);
```

### 4.3 Security

**Input Validation:**
- Sanitize all user input via `sanitize_text_field()`, `floatval()`
- Verify nonces on AJAX requests
- Check user permissions before actions

**SQL Injection Prevention:**
- Use `$wpdb->prepare()` for all parameterized queries
- Escape output with `esc_html()`, `esc_attr()`

**Authentication:**
- Verify `current_user_id()` matches bid submitter
- Check auction access permissions

---

## 5. Extension & Plugin Architecture

### 5.1 Class Override Pattern

Premium features extend base classes:

```php
// Premium variant registration
if (class_exists('YITH_WCACT_Auction_Admin_Premium')) {
    $admin = YITH_WCACT_Auction_Admin_Premium::get_instance();
} else {
    $admin = YITH_Auction_Admin::get_instance();
}
```

### 5.2 Action Hooks

**Core Hooks** for plugin interception points:

```php
// Plugin initialization
do_action('yith_wcact_init');

// Before processing bid
do_action('yith_wcact_auction_before_set_bid', $auction_id, $user_id, $bid);

// After bid accepted
do_action('yith_wcact_auction_bid_assigned', $auction_id, $user_id, $bid, $bid_id);

// Auction completion
do_action('yith_wcact_auction_finished', $auction_id, $winner_id);
```

### 5.3 Filter Hooks

**Output Filters** for customization:

```php
// Modify current bid calculation
$current_bid = apply_filters('yith_wcact_get_current_bid', $bid, $auction_id);

// Extend settings options
$options = apply_filters('yith_wcact_settings_options', $options);

// Override class loading
apply_filters('yith_wcact_require_class', $class_path, $class_name);
```

---

## 6. Dependency Management

### 6.1 Runtime Dependencies

```json
{
    "woocommerce/woocommerce": "^3.0",
    "wordpress/wordpress": "^4.0"
}
```

### 6.2 Development Dependencies

```json
{
    "phpunit/phpunit": "^9.6",
    "yoast/phpunit-polyfills": "^2.0",
    "ksfraser/mock-wordpress": "^1.0",
    "ksfraser/mock-woocommerce": "^1.0"
}
```

### 6.3 Local File Repositories

Development uses local path repositories for faster iteration:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../../mock-wordpress"
        },
        {
            "type": "path",
            "url": "../../mock-woocommerce"
        },
        {
            "type": "path",
            "url": "../../test-factories"
        }
    ]
}
```

---

## 7. Deployment Architecture

### 7.1 Plugin Directory Structure

```
yith-auctions-for-woocommerce/
├── init.php                              # Plugin entry point
├── includes/
│   ├── class.yith-wcact-auction.php      # Core coordinator
│   ├── class.yith-wcact-auction-product.php
│   ├── class.yith-wcact-auction-bids.php
│   ├── class.yith-wcact-auction-ajax.php
│   ├── class.yith-wcact-auction-admin.php
│   ├── class.yith-wcact-auction-frontend.php
│   ├── compatibility/
│   │   ├── class.yith-wcact-compatibility.php
│   │   └── class.yith-wcact-wpml-compatibility.php
│   └── [other core components]
├── assets/
│   ├── css/          # Stylesheets
│   ├── js/           # JavaScript bundles
│   └── images/       # UI assets
├── templates/        # Rendering templates
├── tests/            # PHPUnit test suites
├── spec/             # Technical specifications
└── Project Docs/     # Implementation plans
```

### 7.2 Activation Hooks

```php
// Plugin activation
register_activation_hook(__FILE__, [$this, 'on_activation']);

// Creates database tables
public function on_activation() {
    global $wpdb;
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}yith_wcact_auction ...");
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}yith_wcact_bid_increment ...");
}
```

---

## 8. Testing Architecture

### 8.1 Test Strategy

- **Unit Tests**: Individual component behavior (100% coverage target)
- **Integration Tests**: Component interactions with mocks
- **Test Isolation**: Mock external dependencies (DB, HTTP, WordPress)

### 8.2 Test Structure

```
tests/
├── Unit/
│   ├── AuctionProductBuilderTest.php
│   ├── BidBuilderTest.php
│   └── ScenarioBuilderTest.php
├── bootstrap.php          # Test environment setup
└── fixtures/              # Test data
```

### 8.3 Test Frameworks

- **PHPUnit 9.6+** - Test runner
- **Yoast Polyfills** - PHP version compatibility
- **Mock WordPress** - WordPress stubs
- **Mock WooCommerce** - WooCommerce stubs

---

## 9. Extensibility Patterns

### 9.1 Adding Custom Functionality

**Pattern 1: Extend Core Component**
```php
class My_Custom_Auction_Frontend extends YITH_Auction_Frontend {
    public function render_auction_page() {
        // Custom rendering logic
        parent::render_auction_page();
    }
}
```

**Pattern 2: Use Hooks**
```php
add_action('yith_wcact_auction_bid_assigned', function($auction_id, $user_id, $bid) {
    // Custom logic when bid accepted
    notify_user_via_custom_channel($user_id, $bid);
});
```

**Pattern 3: Create Add-On Plugin**
```php
// In separate plugin file
if (defined('YITH_WCACT_INIT')) {
    // YITH Auctions is active, extend it
    add_filter('yith_wcact_settings_options', 'add_custom_settings');
}
```

### 9.2 Premium Extensions

Premium features use class variant detection:

```php
// In base class
if (class_exists('YITH_WCACT_Auction_Admin_Premium')) {
    return YITH_WCACT_Auction_Admin_Premium::get_instance();
}
```

Premium plugin defines `YITH_WCACT_Auction_Admin_Premium` to override functionality.

---

## 10. Key Design Decisions

### Decision 1: Singleton Pattern for Core Components

**Rationale:** Ensures single instance of coordinator and managers across request lifecycle

**Trade-offs:**
- Pro: Simple, WordPress-native pattern
- Con: Harder to test without proper mocking

**Mitigation:** Mock classes provide test-friendly singletons

### Decision 2: Metadata-Based State

**Rationale:** Leverage WordPress post metadata for clean product data segregation

**Trade-offs:**
- Pro: No custom post type needed, coexists with standard products
- Con: Query performance requires indexing

### Decision 3: Hook-Based Extensibility

**Rationale:** Follows WordPress conventions for plugin interoperability

**Trade-offs:**
- Pro: Familiar to WordPress developers
- Con: Less type-safe than direct class extension

### Decision 4: Component-Based UI

**Rationale:** Render templates via component classes rather than direct PHP files

**Trade-offs:**
- Pro: Testable, reusable, composable
- Con: More abstraction layers

---

## 11. Future Architectural Considerations

### Planned Enhancements

1. **Auto-Bidding System** (v1.4)
   - Progressive proxy bidding
   - New `YITH_WCACT_AutoBidder` component
   - Extends `YITH_WCACT_Bids` repository

2. **Sealed Bid Auctions** (v1.5)
   - Blind bidding with retroactive reveal
   - New `YITH_WCACT_SealedBidHandler` component
   - Enhanced `WC_Product_Auction_Sealed` product variant

3. **Entry Fees & Commission** (v1.4)
   - Fee calculations
   - New `YITH_WCACT_FeeCalculator` service
   - Commission model strategy pattern

### Architectural Principles for Extensions

- **Don't break existing components**: Use composition and decoration
- **Backward compatibility**: Maintain hook signatures across versions
- **Clear boundaries**: New features extend, don't modify core logic
- **Testability**: All new components must have >90% test coverage

---

## 12. Architecture Governance

### Code Review Checklist

- [ ] Follows SRP (single responsibility per class)
- [ ] No circular dependencies
- [ ] Uses dependency injection where appropriate
- [ ] Includes 100% test coverage
- [ ] Has PHPDoc blocks with requirement mapping
- [ ] Follows WordPress/PHP standards
- [ ] No hardcoded business logic in templates

### Documentation Requirements

- [ ] Classes documented with UML diagrams
- [ ] Public APIs documented
- [ ] Complex algorithms explained
- [ ] Integration points identified
- [ ] Test cases documented
