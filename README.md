# YITH Auctions for WooCommerce

Modern, extensible auction system for WooCommerce with real-time bidding, dynamic bid increments, and comprehensive admin controls.

## Features

- **Auction Products**: Create auction-based products with start prices and reserve prices
- **Dynamic Bid Increments**: Configure price-tier based bid increments (global or per-product)
- **Real-Time Bidding**: AJAX-powered bidding with live bid history and notifications
- **User Dashboard**: Dedicated "My Auctions" page for users to track their bids and winnings
- **Admin Management**: Comprehensive settings panel and product metabox configuration
- **Shop Display**: Auction badges, countdown timers, and formatted display
- **Multi-Language Support**: Full WPML compatibility for global audiences
- **Extensible Architecture**: Hook and filter system for custom functionality

## Requirements

- **PHP**: 7.3 or higher
- **WordPress**: 4.0 or higher  
- **WooCommerce**: 3.0 or higher

## Installation

1. Download or clone the repository
2. Place the `yith-auctions-for-woocommerce` folder in your WordPress plugins directory
3. Activate the plugin through the WordPress admin panel
4. Configure auction settings in WooCommerce → Auctions

## Quick Start

### Create an Auction Product

1. Go to **Products** → **Add New**
2. Select **Auction** from the product type dropdown
3. Configure auction details:
   - **Start Price**: Opening bid amount
   - **Reserve Price**: Minimum acceptable bid (optional)
   - **Start Date & Time**: When bidding begins
   - **End Date & Time**: When bidding closes
   - **Bid Increments**: Custom ranges (optional)
4. Publish the product

### Global Settings

Navigate to **WooCommerce** → **Settings** → **Auctions** to configure:

- Display options (show username, bid button placement)
- Default bid increment ranges
- Email notification preferences
- Payment integration settings

## Architecture

The plugin follows a **modular component-based architecture** with clear separation of concerns:

| Component | Responsibility |
|-----------|-----------------|
| `YITH_Auctions` | Core coordinator and singleton manager |
| `WC_Product_Auction` | Custom product type extending WooCommerce product base |
| `YITH_WCACT_Bids` | Bid storage, retrieval, and validation |
| `YITH_WCACT_Bid_Increment` | Price-tier increment management |
| `YITH_WCACT_Auction_Ajax` | Real-time AJAX bid submission |
| `YITH_Auction_Admin` | Admin UI, settings, and product configuration |
| `YITH_Auction_Frontend` | Frontend rendering and user-facing templates |
| `YITH_WCACT_Finish_Auction` | Auction completion and winner notification |

For detailed architecture information, see [Project_Architecture_Blueprint.md](docs/Project_Architecture_Blueprint.md).

## Development

### Setup

```bash
# Install dev dependencies
composer install --dev

# Run tests
./vendor/bin/phpunit

# Generate coverage report
./vendor/bin/phpunit --coverage-html=coverage
```

### Testing Strategy

The project uses **PHPUnit 9.6+** with mock objects for WordPress and WooCommerce. Tests are organized by functionality:

- **Unit Tests**: Individual component behavior
- **Integration Tests**: Component interactions
- **Coverage Target**: 100% line coverage

See [tests/](tests/) for test organization and [phpunit.xml](phpunit.xml) for configuration.

### Code Standards

- **PHP Standard**: PSR-4 autoloading, PSR-2 code style
- **Documentation**: PHPDoc for all classes and methods with requirement mapping
- **Design Patterns**: Singleton pattern for core components, Repository pattern for data access

### Dependencies

#### Runtime
- WooCommerce 3.0+ (core dependency)

#### Development
- `phpunit/phpunit: ^9.6` - Testing framework
- `yoast/phpunit-polyfills: ^2.0` - PHP version compatibility
- `ksfraser/mock-wordpress: ^1.0` - WordPress mock objects
- `ksfraser/mock-woocommerce: ^1.0` - WooCommerce mock objects

## Extension Points

### Hooks

- `yith_wcact_init`: Fired when plugin core initializes
- `yith_wcact_auction_before_set_bid`: Before bid acceptance
- `yith_wcact_user_can_make_bid`: Validate bid eligibility

### Filters

- `yith_wcact_get_current_bid`: Modify current bid calculations
- `yith_wcact_settings_options`: Extend settings panels
- `yith_wcact_require_class`: Override class autoloading

### Custom Premium Features

The plugin supports premium extensions through class variant detection. Define a `*_Premium` class variant to extend functionality:

```php
// Example: YITH_WCACT_Auction_Admin_Premium extends YITH_Auction_Admin
class YITH_WCACT_Auction_Admin_Premium extends YITH_Auction_Admin {
    // Premium functionality
}
```

## Database Schema

### `wp_yith_wcact_auction` - Bid Records

| Column | Type | Purpose |
|--------|------|---------|
| `id` | BIGINT | Primary key |
| `user_id` | BIGINT | Bidding user |
| `auction_id` | BIGINT | Product ID |
| `bid` | DECIMAL | Bid amount |
| `timestamp` | DATETIME | Bid submission time |
| `status` | VARCHAR | Current status (pending, winner, outbid, etc.) |

### `wp_yith_wcact_bid_increment` - Increment Ranges

| Column | Type | Purpose |
|--------|------|---------|
| `id` | BIGINT | Primary key |
| `product_id` | BIGINT | Auction product ID (0 for global) |
| `from_price` | DECIMAL | Range start price |
| `to_price` | DECIMAL | Range end price (NULL for open-ended) |
| `increment` | DECIMAL | Required increment for this range |

## Contributing

See [CONTRIBUTING](CONTRIBUTING.md) for guidelines on:

- Code style and standards
- Test coverage requirements
- Pull request process
- Issue reporting

## License

GNU General Public License v3.0 or later

See [LICENSE](LICENSE) for details.

## Support & Documentation

- **Technical Specifications**: See [spec/ directory](spec/)
- **Implementation Plans**: See [Project Docs/](Project\ Docs/)
- **Architecture Details**: See [docs/](docs/)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and release notes.
