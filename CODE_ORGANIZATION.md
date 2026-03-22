# Code Organization Strategy

## Fork Origins

**Base Project**: YITH Auctions for WooCommerce v1.2.4  
**Base Repository**: https://yithemes.com/themes/plugins/yith-auctions-for-woocommerce/  
**Forked From**: Original code dated **March 22, 2026**  
**Current Status**: Fork with extended features (Starting Bid, Bid Increments, Reserve Price)

---

## Code Structure Principles

### 1. Original YITH Code (Untouched Core)
- Located in: `includes/`, `assets/`, `templates/`
- **Modification Policy**: MINIMAL and well-documented
- **Versioning**: Original v1.2.4 preserved for reference
- **Marker**: All modifications to original YITH files include `@modified-by ksfraser` comment

### 2. Extended Features (ksfraser Namespace)
- Located in: `includes/ksfraser/`
- **Namespace**: `ksfraser\` (prevents collision with `Yith\` namespace)
- **Principle**: Single Responsibility Principle (SRP)
- **Approach**: New functionality in separate classes, not modifying original behavior

### 3. UI Components (Direct Includes)
- Located in: `templates/ksfraser/`
- **Integration**: Original YITH views use minimal `<?php include ?>` statements
- **Modification**: Only 1-line include statement added to original YITH files
- **Isolation**: All new HTML/CSS/JS in ksfraser templates

---

## Architecture Overview

```
woocommerce-auction/
├── includes/
│   ├── class.yith-wcact-*.php          # Original YITH classes (v1.2.4)
│   ├── ksfraser/                       # NEW: Extended features
│   │   ├── BidIncrement/
│   │   │   ├── BidIncrementManager.php    (Manages bid increment ranges)
│   │   │   ├── BidIncrementValidator.php  (Validates increment applicability)
│   │   │   └── BidIncrementCalculator.php (Calculates next valid bid)
│   │   ├── ReservePrice/
│   │   │   ├── ReservePriceValidator.php  (Checks if reserve met)
│   │   │   └── ReservePriceCalculator.php (Calculates effective reserve)
│   │   ├── StartingPrice/
│   │   │   └── StartingPriceProvider.php  (Provides starting bid)
│   │   └── Common/
│   │       ├── AuctionContext.php         (Auction state container)
│   │       └── Exception/                 (Custom exceptions)
│   │           ├── InvalidBidIncrementException.php
│   │           ├── ReservePriceNotMetException.php
│   │           └── AuctionConfigException.php
│   ├── compatibility/                  # Original YITH compatibility
│   └── interface/                      # NEW: Contracts for extension points
├── templates/
│   ├── admin/                          # Original YITH admin templates
│   ├── frontend/                       # Original YITH frontend templates
│   ├── ksfraser/                       # NEW: Extended UI components
│   │   ├── admin/
│   │   │   └── bid-increment-ranges.php   (Admin settings UI)
│   │   └── frontend/
│   │       └── reserve-price-notice.php   (Frontend reserve message)
│   ├── woocommerce/                    # Original YITH WC templates
│   └── premium/                        # Original YITH premium features
├── tests/
│   ├── unit/
│   │   ├── BidIncrementManagerTest.php
│   │   ├── ReservePriceValidatorTest.php
│   │   ├── StartingPriceProviderTest.php
│   │   └── ...
│   ├── integration/
│   │   └── AuctionWorkflowTest.php
│   └── bootstrap.php                   # Mock infrastructure
├── plugin-fw/                          # Original YITH plugin framework
├── assets/                             # Original YITH assets
└── init.php                            # Original YITH plugin entry point
```

---

## Integration Points

### Hooks & Filters (Minimal Modifications)

**Pattern**: Use WordPress hooks instead of modifying original code

Example - Starting Price:
```php
// Original YITH code (UNCHANGED):
$starting_price = apply_filters(
    'yith_wcact_starting_price',
    $product->get_price()
);

// ksfraser Addition:
// /includes/ksfraser/StartingPrice/StartingPriceProvider.php
add_filter('yith_wcact_starting_price', [
    'ksfraser\\StartingPrice\\StartingPriceProvider',
    'get_starting_price'
], 10, 2);
```

### Template Includes (1-Line Modifications)

**Pattern**: Original template includes new component

Original YITH template (1-line addition):
```php
<!-- templates/admin/product-tabs/auction-tab.php -->
<?php include dirname(__FILE__) . '/../../ksfraser/admin/bid-increment-ranges.php'; ?>
```

ksfraser Implementation (isolated, reusable):
```php
<!-- templates/ksfraser/admin/bid-increment-ranges.php -->
<?php
$ranges = new ksfraser\BidIncrement\BidIncrementManager($product_id);
$ranges->render_settings_ui();
?>
```

---

## Modification Guidelines

### DO:
✅ Use WordPress/WooCommerce hooks and filters  
✅ Create new ksfraser classes for new functionality  
✅ Use traits to share code between classes  
✅ Document modifications with `@modified-by ksfraser` tags  
✅ Mark hooks/filters that are new with `@extension-point` comments  

### DON'T:
❌ Modify original YITH class methods (copy to ksfraser instead)  
❌ Add new methods to original YITH classes  
❌ Change original method signatures  
❌ Remove or reorder original code sections  
❌ Modify asset paths or templates paths in original files  

### If You Must Modify Original Code:
1. Copy the entire class to `includes/ksfraser/{FeatureName}/`
2. Add `@version-extended` to class docblock
3. List all modifications in docblock `@modifications` section
4. Keep original in place for reference
5. Use hook to inject new class instead of original

---

## Testing Requirements

### Before Adding New Features:
1. ✅ Achieve 100% code coverage for new classes
2. ✅ Unit tests for all public methods
3. ✅ Integration tests for hook interactions
4. ✅ Edge case tests for calculations

### Test File Location:
```
tests/unit/ksfraser/BidIncrement/BidIncrementManagerTest.php
tests/unit/ksfraser/ReservePrice/ReservePriceValidatorTest.php
tests/integration/AuctionWorkflowWithExtensionsTest.php
```

### Coverage Targets:
- Original YITH code: Keep as-is (7.29% currently)
- ksfraser code: **100% coverage REQUIRED**
- Mock infrastructure: **100% coverage** (already achieved)

---

## Dependency Injection & Testing

All ksfraser classes should:
1. Accept dependencies via constructor
2. Implement interfaces from `includes/interface/`
3. Use type hints for all parameters
4. Allow substitution for testing

Example:
```php
namespace ksfraser\BidIncrement;

use ksfraser\BidIncrement\Validator\BidIncrementValidator;

class BidIncrementManager {
    private BidIncrementValidator $validator;
    
    public function __construct(BidIncrementValidator $validator) {
        $this->validator = $validator;
    }
}
```

---

## Future Phases

### Phase 1B: Test Factories
- Extract test builders (AuctionProductBuilder, BidBuilder, ScenarioBuilder)
- Create standalone `test-factories` Composer package
- 100% coverage, Packagist-published

### Phase 2: Migrate Existing Tests
- Update YITH tests to use new mock infrastructure
- Remove hardcoded mocks from bootstrap
- Achieve baseline coverage improvements

### Phase 3: v1.4.0 Features (TDD-First)
- Entry Fees: `ksfraser\EntryFees\`
- Commission: `ksfraser\Commission\`
- Post-Auction: `ksfraser\PostAuction\`
- Notifications: `ksfraser\Notifications\`
- **Each feature**: 100% coverage before implementation

---

## Code Review Checklist

Before merging any PR:

- [ ] No modifications to original YITH file methods exist (or documented with reason)
- [ ] All new code in `ksfraser\` namespace
- [ ] All new templates in `templates/ksfraser/`
- [ ] Unit test coverage ≥ 100% for new code
- [ ] Integration tests verify hook/filter interactions
- [ ] `@modified-by ksfraser` tags on changed YITH code
- [ ] PHPDoc includes requirement ID (REQ-XXXX)
- [ ] No breaking changes to public API

