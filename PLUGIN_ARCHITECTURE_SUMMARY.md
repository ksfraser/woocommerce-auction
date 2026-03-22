# YITH Auctions for WooCommerce - Architecture Summary

**Plugin Version**: 1.2.4  
**Documentation Date**: March 22, 2026  
**Status**: Core features stable; auto-bidding (v1.4.0+), sealed bids (v1.5.0+), entry fees/commission (v1.4.0+) in advanced planning phase

---

## 1. PROJECT STRUCTURE

### Root Organization
```
yith-auctions-for-woocommerce/
├── init.php                      # Plugin entry point
├── composer.json                 # PSR-4 autoloading + dev dependencies
├── phpunit.xml                   # Test configuration
├── includes/                     # Core plugin classes (legacy style)
├── src/                          # Modern PSR-4 namespace (future)
├── tests/unit/                   # PHPUnit tests
├── templates/                    # Frontend & admin templates
├── assets/                       # CSS, JS, images
├── panel/                        # Plugin settings & options
├── plugin-fw/                    # YITH Framework (bundled)
├── languages/                    # i18n translations (.pot)
└── Project Docs/                 # Comprehensive technical documentation
```

### Key Directories

| Directory | Purpose |
|-----------|---------|
| `includes/` | 9 main classes + 3 compatibility classes |
| `tests/unit/` | 2 test suites (AuctionProductTest, BidIncrementTest) |
| `templates/` | Twig/PHP templates for admin & frontend UI |
| `Project Docs/` | 8+ specification documents (INDEX, COMPLETE_VISION, REQUIREMENTS, plans) |
| `assets/` | CSS (shop/admin/timepicker), JS (frontend/admin), jquery plugins |

---

## 2. CORE FUNCTIONALITY & FEATURES

### Current Base Features (v1.2.4)
- ✅ **Auction Products**: Create & manage auction-type WooCommerce products
- ✅ **Bidding System**: Place, track, and display auction bids
- ✅ **Start Price**: Minimum opening bid per product
- ✅ **Bid Increments**: Dynamic increment by price range (global + per-product override)
- ✅ **Auction Lifecycle**: Start/end dates, real-time status tracking
- ✅ **Buyer Experience**: "Bid Tab" on product page, bid history, current price display
- ✅ **Admin Tools**: Product metabox configuration, auction settings panel, global bid increment ranges
- ✅ **Shop Display**: Auction badge/icon, differentiation from regular products
- ✅ **User Auctions**: "My Auctions" page showing user's active/won auctions
- ✅ **wpml Compatibility**: Multi-language support

### Planned Features
- **v1.4.0 Auto-Bidding** (32 tasks, 16-24 hrs): Progressive max bid proxy bidding (#REQ-AUTO-*)
- **v1.5.0 Sealed Bids** (35 tasks, 20-28 hrs): Blind auctions with retroactive reveal (#REQ-SEAL-*)
- **v1.4.0 Entry Fees** (33 tasks, 12-16 hrs): Optional per-auction fees, refundable (#REQ-ENTRY-*)
- **v1.4.0 Commission** (33 tasks, 16-20 hrs): Post-auction winner commission (%, flat, hybrid) (#REQ-COMM-*)

---

## 3. MAIN CLASSES IN includes/ DIRECTORY

### Architecture Overview
All classes use **Singleton pattern** for instance management. Request-scoped initialization via WordPress hooks.

| Class | Purpose | Key Methods |
|-------|---------|-------------|
| **YITH_Auctions** | Main plugin coordinator | `instance()`, `init_classes()`, loads all modules |
| **WC_Product_Auction** | Extends WC_Product | `get_current_bid()`, `get_start_price()`, `get_reserve_price()` |
| **YITH_WCACT_DB** | Database setup & migrations | `install()`, `create_db_table()` (v1.1.0 schema) |
| **YITH_WCACT_Bids** | Bid data access layer | `add_bid()`, `get_bids_auction()`, `get_max_bid()`, `get_last_bid_user()` |
| **YITH_WCACT_Bid_Increment** | Bid increment range management | `get_increment_for_price()`, `get_ranges()`, `save_ranges()` |
| **YITH_WCACT_Auction_Ajax** | AJAX endpoints | `yith_wcact_add_bid()` (validates & persists bids), `redirect_to_my_account()` |
| **YITH_Auction_Admin** | Admin UI & configuration | Panel setup, product metabox, settings options |
| **YITH_Auction_Frontend** | Frontend UI rendering | Product page tabs, bid form, shop badges, price filters |
| **YITH_WCACT_Auction_My_Auctions** | User auction dashboard | User's active/won auctions, bid history |
| **YITH_WCACT_Finish_Auction** | Auction lifecycle completion | Determines winner, processes completion |
| **YITH_WCACT_Compatibility** | Framework compatibility | Version compatibility checks |
| **YITH_WCACT_WPML_Compatibility** | Multi-language support | WPML integration |

---

## 4. DATABASE SCHEMA

### Current Tables

**`wp_yith_wcact_auction`** (Bid records)
```sql
id BIGINT PRIMARY KEY
user_id BIGINT NOT NULL
auction_id BIGINT NOT NULL (Product ID)
bid VARCHAR(255) NOT NULL (Decimal formatted as string)
date TIMESTAMP (Bid timestamp)
```

**`wp_yith_wcact_bid_increment`** (Increment ranges)
```sql
id BIGINT PRIMARY KEY
product_id BIGINT (0 = global)
from_price DECIMAL(10,2) (Price range floor)
increment DECIMAL(10,2) (Minimum increment)
[Key: product_id, from_price]
```

### Product Metadata (stored in wp_postmeta)
- `_yith_auction_start_price` - Minimum opening bid
- `_yith_auction_reserve_price` - No-sale threshold (optional)
- `_yith_auction_to` - Auction end datetime
- `_yith_auction_from` - Auction start datetime
- `_yith_auction_use_custom_increment` - Boolean: use product-specific increments

### Future Tables (Planned)
- `wp_yith_wcact_user_max_bids` (v1.4.0 auto-bidding)
- `wp_yith_wcact_sealed_bid_audit` (v1.5.0 sealed reveals)
- `wp_yith_wcact_entry_fees` (v1.4.0 fee audit)
- `wp_yith_wcact_post_auction_log` (v1.4.0 order generation)

---

## 5. PLUGIN CONFIGURATION & SETTINGS

### Global Settings Panel (`panel/settings-options.php`)
Located under: **YITH Plugins → Auctions → Settings**

**Product Settings Section**:
- `yith_wcact_settings_tab_auction_show_name` - Display full username in bid tab (checkbox)
- `yith_wcact_settings_tab_auction_show_button_plus_minus` - Show bid increment/decrement buttons (checkbox)
- `yith_wcact_settings_tab_auction_show_button_pay_now` - Show "Pay Now" button after auction ends (checkbox)

**Global Bid Increment Ranges**:
- `yith_wcact_global_bid_increment_ranges` - Custom field defining ranges by price tier
- Filter: `yith_wcact_settings_options` allows premium/extension additions

### Per-Product Configuration (Product Edit Metabox)
- Auction type selector
- Start price input
- Reserve price input (optional)
- Start/end datetime pickers
- Custom bid increment toggle + ranges (if enabled)
- Status indicators

### Constants (defined in init.php)
```php
YITH_WCACT_VERSION = '1.2.4'
YITH_WCACT_SLUG = 'yith-woocommerce-auctions'
YITH_WCACT_PATH = plugin_dir_path(__FILE__)
YITH_WCACT_URL = plugins_url('/', __FILE__)
YITH_WCACT_TEMPLATE_PATH = YITH_WCACT_PATH . 'templates/'
```

---

## 6. TECHNOLOGY STACK & DEPENDENCIES

### Language & Framework
| Component | Version | Purpose |
|-----------|---------|---------|
| **PHP** | 7.3+ | Core language (from composer.json) |
| **WordPress** | 4.0+ (init.php); 4.9.4 tested (readme.txt) | CMS foundation |
| **WooCommerce** | 3.0.0+ (requires); 3.4.2 tested | e-commerce framework |

### Development Dependencies (composer.json)
```json
{
  "phpunit/phpunit": "^9.6",
  "yoast/phpunit-polyfills": "^2.0",
  "ksfraser/mock-wordpress": "^1.0",
  "ksfraser/mock-woocommerce": "^1.0"
}
```

### Framework Components
- **YITH Plugin Framework** (`plugin-fw/`): Admin panels, settings, hooks
- **WPML Plugin**: Multi-language support (optional, compatible)
- **WordPress Hooks**: Extensive use of `do_action()` & `apply_filters()` for extensibility

### JavaScript Libraries
- **jQuery** (WordPress standard)
- **jQuery DatePicker** (`assets/js/datepicker.js`)
- **jQuery TimePicker** (`assets/js/timepicker.js`)
- Custom AJAX handlers for bid submission

### CSS
- **Bootstrap-like grid** system (implied in templates)
- **Material Design icons** font (`assets/fonts/icons-font/`)
- Per-section stylesheets (admin, frontend, timepicker)

---

## 7. CURRENT TEST STRUCTURE

### Test Framework
- **PHPUnit**: v9.6+ (from composer.json)
- **Yoast PHPUnit Polyfills**: v2.0+ (backward compatibility layer)
- **Mock Libraries**: mock-wordpress, mock-woocommerce (local path repos)

### Test Organization

**Configuration** (`phpunit.xml`):
```xml
<coverage>
  <include suffix=".php">includes/</include>
  <include suffix=".php">src/</include>
  <report>
    <html outputDirectory="coverage/html"/>
    <text outputFile="coverage/coverage.txt"/>
  </report>
</coverage>
```

**Bootstrap** (`tests/bootstrap.php`):
- Composer autoloader inclusion
- WordPress global stubs (`wpdb`, post meta functions)
- Plugin constant definitions
- Mock WooCommerce stubs

**Test Suites** (`tests/unit/`):

| Test Class | Coverage | Status |
|-----------|----------|--------|
| `AuctionProductTest` | WC_Product_Auction methods | ✅ Active |
| `BidIncrementTest` | YITH_WCACT_Bid_Increment methods | ✅ Active |

**Sample Tests**:
- `test_get_reserve_price_default()` - Verify default reserve price
- `test_get_reserve_price_set()` - Verify reserve price retrieval
- Multiple bid increment range tests

### Coverage Reports
- **HTML Coverage**: `coverage/html/` (directory listing visible)
- **Text Coverage**: `coverage/coverage.txt` (summary metrics)

### Future Test Scope (Planned)
- v1.4.0 Auto-Bidding: 24+ new tests (REQ-AUTO-* coverage)
- v1.5.0 Sealed Bids: 40+ new tests (REQ-SEAL-* coverage)
- v1.4.0 Entry Fees/Commission: 50+ new tests (REQ-ENTRY-*, REQ-COMM-* coverage)
- **Total Target**: 74+ unit tests with ≥95% coverage of new code

---

## 8. EXTENSIBILITY & HOOKS

### Key Action Hooks
- `yith_wcact_init` - Main plugin initialization
- `yith_wcact_auction_before_set_bid` - Pre-bid validation
- `yith_wcact_require_class` - Filter class loading manifest
- `yith_wcact_user_can_make_bid` - Bid permission filter

### Filter Hooks
- `yith_wcact_get_current_bid` - Modify displayed current bid
- `yith_wcact_settings_options` - Add/modify settings panel options
- `yith_wcact_auction_product_id` - Modify product ID context

### Plugin Framework Integration
- Settings panel via `YITH_Plugin_Panel`
- Product type registration: `'auction'` type
- Compatibility checks with premium/extensions via class suffix check (`_Premium`)

---

## 9. PROJECT DOCUMENTATION

### In-Repository Documentation
| Document | Scope | Audience |
|----------|-------|----------|
| **Project Docs/INDEX.md** | Nav index to all docs | Everyone |
| **Project Docs/COMPLETE_VISION.md** | v1.0→v1.1 roadmap | Product, Developers |
| **Project Docs/AUTO_BIDDING_REQUIREMENTS.md** | v1.4.0 detailed specs | Developers, Business |
| **Project Docs/IMPLEMENTATION_GUIDE.md** | Architecture overview | Developers |
| **Project Docs/REQUIREMENTS_COMPLETE.md** | All 6-area reqs consolidated | Requirements tracking |
| **plan/feature-auto-bidding-1.md** | v1.0 implementation plan | Developers |
| **plan/feature-auto-bidding-sealed-bids-1.1.md** | v1.1 implementation plan | Developers |
| **plan/feature-entry-fees-commission-post-auction-1.0.md** | v1.4.0 comprehensive plan | Developers |
| **EXECUTION_CHECKLIST.md** | v1.0 tasks (32) with checkboxes | PM, Developers |
| **SEALED_BID_EXECUTION_CHECKLIST.md** | v1.1 tasks (35) with checkboxes | PM, Developers |
| **ENTRY_FEES_COMMISSION_EXECUTION_CHECKLIST.md** | v1.4.0 tasks (33) with checkboxes | PM, Developers |

### Documentation Standards Applied
- ✅ **PHPDoc**: Comprehensive blocks with @requirement tags (REQ-* cross-reference)
- ✅ **Requirement Traceability**: All code funcs/classes mapped to requirements
- ✅ **UML Diagrams**: In PHPDoc @startuml blocks (class diagrams, message flows)
- ✅ **PlantUML Sequences**: `auto-bidding-sequence-diagram.puml` visualizes algorithm

---

## 10. QUICK DEVELOPER REFERENCE

### Setting Up for Development
```bash
# Install dependencies
composer install

# Run tests
vendor/bin/phpunit

# Generate coverage
vendor/bin/phpunit --coverage-html coverage/html
```

### Key Entry Points
- **Plugin init**: `init.php` → `yith_wcact_init()` → `YITH_Auctions::instance()`
- **Admin interface**: `includes/class.yith-wcact-auction-admin.php`
- **Frontend rendering**: `includes/class.yith-wcact-auction-frontend.php`
- **Bid processing**: AJAX at `wp-admin/admin-ajax.php?action=yith_wcact_add_bid`

### Requirement Mapping
All code references requirements using `@requirement REQ-XXX` tags:
- **REQ-001**: Starting bid (minimum opening price)
- **REQ-002**: Bid increment by price range
- **REQ-003**: Reserve price (no-sale threshold)
- **REQ-AUTO-\***: Auto-bidding features (v1.4.0)
- **REQ-SEAL-\***: Sealed bid features (v1.5.0)
- **REQ-ENTRY-\***: Entry fee features (v1.4.0)
- **REQ-COMM-\***: Commission features (v1.4.0)

### Coding Standards
- **PSR-4 Autoloading**: Class namespace `YITH\Auctions\` in `src/`
- **Naming**: Legacy classes use `CLASS_NAME` prefix; modern code uses namespaces
- **Pattern**: Singleton for service classes, Inheritance for product types
- **Security**: Sanitization (`sanitize_text_field`), prepared statements (`wpdb->prepare`)
- **Localization**: All strings wrapped in `_ex()`, `_e()`, `__()` with text domain

---

## Summary Metrics

| Metric | Value |
|--------|-------|
| **PHP Files (includes/)** | 12 main + 3 compat |
| **Classes** | 9 core (singleton pattern) |
| **Database Tables** | 2 core, 4 planned |
| **Test Files** | 2 active (AuctionProductTest, BidIncrementTest) |
| **Settings Options** | 5 global configurable |
| **Hooks/Filters** | 7+ action/filter points |
| **Planned Tests (v1.4/v1.5)** | 114+ total tests |
| **Code Documentation** | 8+ comprehensive markdown docs + PHPDoc |
| **Effort Remaining** | ~50-80 hours (auto-bidding, sealed, fees, commission) |
| **Target Coverage** | ≥95% of new code in v1.4/v1.5 |

