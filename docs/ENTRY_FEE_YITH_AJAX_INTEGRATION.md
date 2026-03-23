# Entry Fee Payment - YITH Bid Placement Hook Integration

## Overview

This guide shows **exactly how** to integrate the entry fee payment authorization system into the existing YITH Auctions bid submission AJAX handler.

**Current State:** YITH has a working bid handler in `includes/class.yith-wcact-auction-ajax.php`

**Integration Goal:** Inject payment authorization between validation and bid storage

---

## Step 1: Locate the Existing Bid Handler

**File:** [includes/class.yith-wcact-auction-ajax.php](includes/class.yith-wcact-auction-ajax.php)

**Method:** `YITH_WCACT_Auction_Ajax::yith_wcact_add_bid()`

**Current Flow (lines 71-121):**

```php
public function yith_wcact_add_bid() {
    // 1. Check nonce
    check_ajax_referer('yith_wcact_add_bid_nonce', 'nonce');

    // 2. Get user
    $user_id = get_current_user_id();
    
    // 3. Apply filter (allows override)
    if (!apply_filters('yith_wcact_user_can_make_bid', true, $user_id)) {
        wp_send_json_error('User cannot make bid');
    }

    // 4. Sanitize inputs
    $bid = floatval(sanitize_text_field(wp_unslash($_POST['bid'])));
    $product_id = absint(apply_filters(
        'yith_wcact_auction_product_id',
        $_POST['product']
    ));

    // 5. EXISTING VALIDATION
    $product = wc_get_product($product_id);
    if (!$product || !$product->get_auction_settings()) {
        wp_send_json_error('Invalid auction');
    }

    // 6. Check bid amount >= minimum
    $minimum_bid = (float) $product->get_minimum_bid();
    if ($bid < $minimum_bid) {
        wp_send_json_error('Bid too low');
    }

    // 7. Get existing bids and check increment
    $bids = $this->bids->get_auction_bids($product_id);
    $highest_bid = end($bids)->bid;
    $increment = $product->get_bid_increment();

    if ($bid <= $highest_bid || $bid < ($highest_bid + $increment)) {
        wp_send_json_error('Bid must be incremented');
    }

    // ===== INSERT PAYMENT AUTHORIZATION HERE =====

    // 8. CREATE BID (existing)
    $this->bids->add_bid(
        $user_id,
        $product_id,
        $bid,
        current_time('mysql')
    );

    // 9. RETURN SUCCESS
    wp_send_json_success([
        'user_id' => $user_id,
        'product_id' => $product_id,
        'bid' => $bid,
    ]);
}
```

---

## Step 2: Modify AJAX Handler to Call Payment Authorization

**Location:** Between line 110 (validation complete) and line 115 (bid creation)

**Add this code:**

```php
// ===== NEW: AUTHORIZE ENTRY FEE PAYMENT =====

// 1. Initialize payment integration service
$payment_integration = new BidPaymentIntegration(
    new SquarePaymentGateway(
        getenv('SQUARE_API_KEY'),
        getenv('SQUARE_LOCATION_ID')
    ),
    new PaymentAuthorizationRepository(global $wpdb)
);

// 2. Generate unique bid ID
$bid_id = wp_generate_uuid4();

// 3. AUTHORIZE PAYMENT
try {
    $auth_result = $payment_integration->authorizePaymentForBid(
        $product_id,
        $user_id,
        $bid,
        $bid_id
    );

    // Store authorization_id for use with bid
    $authorization_id = $auth_result['authorization_id'];

} catch (PaymentException $e) {
    // Payment failed - send error to frontend
    wp_send_json_error(
        $payment_integration->getErrorMessage($e),
        400
    );
    return; // Stop processing
}

// ===== END: AUTHORIZATION COMPLETE =====
```

---

## Step 3: Store Authorization ID with Bid Record

**Modify:** `YITH_WCACT_Bids::add_bid()` method

**Current signature:**
```php
public function add_bid($user_id, $product_id, $bid, $date)
```

**Updated signature:**
```php
public function add_bid($user_id, $product_id, $bid, $date, $authorization_id = '')
```

**Implementation:**
```php
public function add_bid($user_id, $product_id, $bid, $date, $authorization_id = '') {
    global $wpdb;

    $result = $wpdb->insert(
        $this->table,
        [
            'user_id' => $user_id,
            'auction_id' => $product_id,
            'bid' => $bid,
            'date' => $date,
        ],
        ['%d', '%d', '%s', '%s']
    );

    if (!$result) {
        return false;
    }

    $bid_id = $wpdb->insert_id;

    // NEW: Store authorization_id with bid as post meta
    if (!empty($authorization_id)) {
        add_post_meta($bid_id, '_authorization_id', $authorization_id);
        update_post_meta($bid_id, '_bid_timestamp', $date);
        update_post_meta($bid_id, '_bid_amount', $bid);
        update_post_meta($bid_id, '_auction_id', $product_id);
    }

    return $bid_id;
}
```

**Note:** The YITH table stores bids, but we also store metadata via post meta for easy lookups later (when processing auction outcome).

---

## Step 4: Update AJAX Handler to Pass Authorization ID

**In the bid creation call (around line 115):**

```php
// CREATE BID WITH AUTHORIZATION
$this->bids->add_bid(
    $user_id,
    $product_id,
    $bid,
    current_time('mysql'),
    $authorization_id  // NEW: Pass authorization_id
);
```

---

## Step 5: Initialize Payment Integration in Plugin

**Location:** Plugin initialization (main plugin file or init.php)

**Add:**

```php
/**
 * Register bid payment integration.
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-003
 */
add_action('plugins_loaded', function() {
    if (!class_exists('BidPaymentIntegration')) {
        return; // Payment infrastructure not loaded
    }

    // Initialize payment gateway
    $payment_gateway = new SquarePaymentGateway(
        getenv('SQUARE_API_KEY'),
        getenv('SQUARE_LOCATION_ID')
    );

    // Initialize repository
    $payment_repository = new PaymentAuthorizationRepository(
        global $wpdb
    );

    // Initialize payment integration
    $payment_integration = new BidPaymentIntegration(
        $payment_gateway,
        $payment_repository
    );

    // Register AJAX hook
    $ajax_hook = new BidPlacementAjaxHook($payment_integration);
    $ajax_hook->register();
}, 20); // Priority 20 (after default 10)
```

---

## Step 6: Configure WooCommerce Entry Fee Settings

**Add product meta options for entry fees:**

```php
/**
 * Add entry fee settings to auction product.
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-003
 */
add_filter('woocommerce_product_data_tabs', function($tabs) {
    $tabs['auction_entry_fee'] = [
        'label' => __('Entry Fee', 'yith-auctions'),
        'target' => 'auction_entry_fee_data',
    ];
    return $tabs;
});

add_action('woocommerce_product_data_panels', function() {
    ?>
    <div id="auction_entry_fee_data" class="panel woocommerce_options_panel">
        <!-- Entry fee amount -->
        <?php woocommerce_wp_text_input([
            'id' => '_auction_entry_fee_amount',
            'label' => __('Entry Fee ($)', 'yith-auctions'),
            'type' => 'number',
            'step' => '0.01',
            'min' => '0',
            'description' => __('Fee charged per bid (non-refundable if bid wins, refunded if outbid)', 'yith-auctions'),
        ]); ?>

        <!-- Enable/disable -->
        <?php woocommerce_wp_checkbox([
            'id' => '_auction_entry_fee_enable',
            'label' => __('Enable Entry Fees', 'yith-auctions'),
            'description' => __('Require payment authorization for bids on this auction', 'yith-auctions'),
        ]); ?>
    </div>
    <?php
});

add_action('save_post_product', function($product_id) {
    $entry_fee = isset($_POST['_auction_entry_fee_amount'])
        ? (float) $_POST['_auction_entry_fee_amount']
        : 0;

    $enabled = isset($_POST['_auction_entry_fee_enable']);

    update_post_meta($product_id, '_auction_entry_fee_amount', $entry_fee);
    update_post_meta($product_id, '_auction_entry_fee_enable', $enabled);
});
```

---

## Step 7: Frontend JavaScript to Handle Payment Errors

**Update the existing AJAX success handler to display payment errors:**

```javascript
// Existing bid placement JavaScript
jQuery(document).on('click', '.yith-wcact-place-bid-button', function() {
    var bid_amount = jQuery('#yith-wcact-bid-amount').val();

    // AJAX call (existing)
    jQuery.post(yith_wcact_ajax.ajaxurl, {
        action: 'yith_wcact_add_bid',
        nonce: yith_wcact_ajax.nonce,
        bid: bid_amount,
        product: jQuery('#yith-wcact-product-id').val(),
    }, function(response) {
        if (response.success) {
            // Success
            alert('Bid placed successfully!');
            location.reload();
        } else {
            // ENHANCED: Show payment error clearly
            var error_message = response.data || 'Bid placement failed';

            // Display prominently
            jQuery('#bid-form-errors').html(
                '<div class="alert alert-danger">' + error_message + '</div>'
            ).show();

            // Highlight payment form if payment-related error
            if (error_message.includes('card') || error_message.includes('payment')) {
                jQuery('#payment-form').addClass('has-error');
            }
        }
    });
});
```

**HTML structure:**

```html
<!-- Bid placement form -->
<form id="bid-form">
    <!-- Error messages -->
    <div id="bid-form-errors" style="display:none;"></div>

    <!-- Bid amount -->
    <input type="number" id="yith-wcact-bid-amount" step="0.01" />

    <!-- Entry fee display -->
    <div class="entry-fee-info">
        <strong>Entry Fee:</strong>
        <span id="entry-fee-display">$50.00</span>
        <small>Charged when bid is placed (refunded if you lose)</small>
    </div>

    <!-- Payment form (only if entry fees enabled) -->
    <div id="payment-form" style="display:none;">
        <h4>Payment Information</h4>
        <p>Enter the card you'd like to use for this bid.</p>
        
        <!-- Square Payment Form or Stripe Elements -->
        <div id="sq-card-container"></div>

        <input type="hidden" id="card-token" />
    </div>

    <!-- Submit -->
    <button type="button" class="yith-wcact-place-bid-button">Place Bid</button>
</form>
```

---

## Step 8: Add WordPress Hooks for Extensibility

**Allow other plugins to customize payment authorization:**

```php
/**
 * Filter: Customize entry fee authorization.
 *
 * @param array $auth_result Authorization result
 *     [
 *         'authorization_id' => string,
 *         'amount_cents' => int,
 *         'status' => string,
 *     ]
 * @param int  $user_id      Bidder ID
 * @param int  $product_id   Auction ID
 * @param float $bid_amount   Bid amount
 *
 * @return array Modified authorization result
 */
$auth_result = apply_filters(
    'yith_wcact_entry_fee_authorized',
    $auth_result,
    $user_id,
    $product_id,
    $bid_amount
);

/**
 * Action: Entry fee authorization succeeded.
 *
 * Fire after successful payment authorization.
 *
 * @param string $authorization_id Payment authorization ID
 * @param int    $user_id          Bidder ID
 * @param int    $product_id       Auction ID
 */
do_action(
    'yith_wcact_entry_fee_authorized',
    $authorization_id,
    $user_id,
    $product_id
);

/**
 * Action: Entry fee authorization failed.
 *
 * Fire when payment authorization fails.
 *
 * @param PaymentException $exception Payment error
 * @param int              $user_id   Bidder ID
 * @param int              $product_id Auction ID
 */
do_action(
    'yith_wcact_entry_fee_authorization_failed',
    $exception,
    $user_id,
    $product_id
);
```

---

## Step 9: Database Migration Setup

**Add migration to plugin activation hook:**

```php
/**
 * Activate plugin: Create payment tables.
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-003
 */
add_action('yith_wcact_plugin_activated', function() {
    global $wpdb;

    $migration = new PaymentAuthorizationMigration($wpdb);

    // Create tables if not already created
    if (!$migration->isMigrated()) {
        $migration->up();
    }

    // Log migration status
    $status = $migration->getStatus();
    error_log('Payment authorization tables: ' . json_encode($status));
});
```

---

## Step 10: Handle Edge Cases

### Multiple Bids from Same User

```php
// User can bid on different auctions (each gets separate authorization)
// System handles this by generating unique bid_id for each

// User cannot bid twice on same auction in same round
// Existing validation handles this - we just need to abort if payment fails
```

### Bidder No Payment Method

```php
// User tries to bid but has no saved payment method
// BidPaymentIntegration catches this and returns:
// PaymentException('No payment method found', 'NO_PAYMENT_METHOD')

// Frontend displays:
// "No payment method found. Please add a payment method before bidding."
```

### Payment Gateway Timeout

```php
if ($payment_service->isNetworkError($e)) {
    // Don't abort bid - ask user to retry
    wp_send_json_error('Payment service temporarily unavailable. Please try again.');
} else {
    // Clear payment failure - abort bid
    wp_send_json_error($payment_integration->getErrorMessage($e));
}
```

---

## Step 11: Testing the Integration

### Unit Tests

```bash
# Run payment integration tests
wp scaffold plugin-tests yith-auctions
./tests/unit/Services/BidPaymentIntegrationTest.php
./tests/unit/Integration/BidPlacementAjaxHookTest.php
```

### Manual Testing Checklist

- [ ] User without payment method cannot place bid
- [ ] User with valid payment method can place bid
- [ ] Authorization ID is stored with bid record
- [ ] Declined card shows "Card was declined" message
- [ ] Expired card shows "Card has expired" message
- [ ] Invalid CVC shows security code error
- [ ] Entry fees disabled: Bid placed without payment
- [ ] Entry fees enabled: Bid requires payment
- [ ] Multiple bids from same user generate separate authorizations
- [ ] Payment timeout shows "temporarily unavailable" message

### Test Cases

**Scenario 1: Happy Path**
```
User places bid ($1500)
→ Payment authorized ($50 entry fee)
→ Bid created with authorization_id
→ Success response to frontend
```

**Scenario 2: Declined Card**
```
User places bid with declined card
→ Payment authorization fails
→ Error response: "Your card was declined"
→ Bid NOT created
→ User can retry
```

**Scenario 3: No Payment Method**
```
User without saved payment method tries to bid
→ Payment authorization fails
→ Error response: "No payment method found..."
→ Bid NOT created
→ Redirects to payment method setup
```

---

## Complete Integration Code

**File location to modify:** [includes/class.yith-wcact-auction-ajax.php](includes/class.yith-wcact-auction-ajax.php#L71)

**Insert between validation (line 110) and bid creation (line 115):**

```php
// ===== NEW: AUTHORIZE ENTRY FEE PAYMENT =====
$bid_id = wp_generate_uuid4();
$authorization_id = '';

try {
    $payment_integration = new BidPaymentIntegration(
        new SquarePaymentGateway(
            getenv('SQUARE_API_KEY'),
            getenv('SQUARE_LOCATION_ID')
        ),
        new PaymentAuthorizationRepository(global $wpdb)
    );

    $auth_result = $payment_integration->authorizePaymentForBid(
        (int) $product_id,
        (int) $user_id,
        (float) $bid,
        $bid_id
    );

    $authorization_id = $auth_result['authorization_id'];

    do_action('yith_wcact_entry_fee_authorized', $authorization_id, $user_id, $product_id);

} catch (PaymentException $e) {
    do_action('yith_wcact_entry_fee_authorization_failed', $e, $user_id, $product_id);
    wp_send_json_error($payment_integration->getErrorMessage($e), 400);
    return;
}
// ===== END: AUTHORIZATION COMPLETE =====

// CREATE BID WITH AUTHORIZATION
$this->bids->add_bid(
    $user_id,
    $product_id,
    $bid,
    current_time('mysql'),
    $authorization_id  // NEW
);
```

---

## Summary

| Component | File | Purpose |
|-----------|------|---------|
| BidPaymentIntegration | src/Services/BidPaymentIntegration.php | Authorize payments |
| BidPlacementAjaxHook | src/Integration/BidPlacementAjaxHook.php | Hook adapter |
| YITH Bid Handler | includes/class.yith-wcact-auction-ajax.php | Modify to call payment |
| Database Migration | src/Database/Migrations/PaymentAuthorizationMigration.php | Create tables |

**Next Steps:**
1. Implement the code modifications above in YITH bid handler
2. Run database migration
3. Configure SQUARE_API_KEY and SQUARE_LOCATION_ID environment variables
4. Set entry fees on products
5. Test with payment forms

**Requirement References:**
- REQ-ENTRY-FEE-PAYMENT-002: Bid-linked authorization
- REQ-ENTRY-FEE-PAYMENT-003: Hook integration
