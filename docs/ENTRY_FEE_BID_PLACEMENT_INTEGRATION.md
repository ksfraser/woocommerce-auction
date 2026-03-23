# Entry Fee Payment - Bid Placement Integration Guide

## Overview

This guide details integrating the payment authorization system with bid placement workflows. When a user submits a bid, the system must:

1. **Authorize Entry Fee** - Call payment gateway to place pre-authorization hold
2. **Store Authorization** - Record auth_id with bid for later capture/refund
3. **Handle Failures** - Display clear errors and prevent bid creation on payment failure
4. **Track Lifecycle** - Link bids to authorizations for later charge (winner) or refund (outbid)

---

## Architecture: Bid-to-Payment Lifecycle

```
BID SUBMISSION FLOW:
┌─────────────┐
│ Bid Submit  │
└──────┬──────┘
       │
       ├──> 1. VALIDATE (existing logic)
       │    - Bid amount
       │    - Auction active
       │    - User eligible
       │
       ├──> 2. AUTHORIZE ENTRY FEE (NEW)
       │    - Prepare payment details
       │    - Call payment gateway
       │    - Place pre-auth hold
       │    - Store auth_id in DB
       │
       ├──> 3. CREATE BID (existing logic)
       │    - Insert bid record
       │    - Link to authorization_id
       │    - Update auction state
       │
       └──> 4. RESPOND (NEW)
            ├─ Success: Show confirmation
            └─ Failure: Show payment error, cancel bid creation

PAYMENT STATE WITH BID:
┌──────────────────────────────────────────┐
│ wp_posts (bid, status=publish)           │
├──────────────────────────────────────────┤
│ meta_key: _bid_amount              $1200 │
│ meta_key: _bid_user_id                10 │
│ meta_key: _authorization_id       auth123│◄── LINK TO PAYMENT
│ meta_key: _auction_id                 25 │
│ meta_key: _bid_timestamp         now     │
└──────────────────────────────────────────┘
           │
           └─> MATCHES
              wp_wc_auction_payment_authorizations
              WHERE authorization_id = 'auth123'
```

---

## Step 1: Prepare Bid Placement Hook

### Location: Where Bids Are Submitted

```php
// In existing bid submission handler (e.g., class.yith-wcact-auction-bids.php)

/**
 * Handle bid submission with payment authorization.
 *
 * Flow:
 * 1. Validate bid amount/eligibility
 * 2. Authorize entry fee payment
 * 3. Create bid record if authorization succeeds
 * 4. Handle errors with user-friendly messages
 */
public function placeBid(array $bid_data): array
{
    // EXISTING: Validate auction/user/bid amount
    if (!$this->validateBid($bid_data)) {
        return [
            'success' => false,
            'message' => 'Invalid bid',
        ];
    }

    // NEW: Authorize entry fee payment
    try {
        $auth_data = $this->authorizePayment($bid_data);
        // auth_data: [
        //     'authorization_id' => 'auth_123',
        //     'amount_cents' => 5000,
        //     'expires_at' => '2024-12-31 23:59:59',
        //     'status' => 'AUTHORIZED'
        // ]
    } catch (PaymentException $e) {
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'error_code' => 'PAYMENT_FAILED',
        ];
    }

    // EXISTING: Create bid record (NOW with authorization_id)
    $bid_id = $this->createBid($bid_data, $auth_data['authorization_id']);

    return [
        'success' => true,
        'bid_id' => $bid_id,
        'message' => 'Bid placed successfully',
    ];
}
```

---

## Step 2: Implement Payment Authorization Method

### Create Payment Authorization Handler

```php
/**
 * Authorize entry fee for bid placement.
 *
 * Responsibilities:
 * - Calculate entry fee amount
 * - Store payment method (or retrieve existing)
 * - Call payment gateway to place pre-auth hold
 * - Record authorization in database
 * - Return auth_id for linking to bid
 *
 * @param array $bid_data Bid submission data
 *     [
 *         'auction_id' => int,
 *         'user_id' => int,
 *         'bid_amount' => float,
 *         'card_token' => string,  // From frontend payment form
 *     ]
 *
 * @return array Authorization data
 *     [
 *         'authorization_id' => string,
 *         'amount_cents' => int,
 *         'expires_at' => string (datetime),
 *         'status' => 'AUTHORIZED',
 *     ]
 *
 * @throws PaymentException On payment failure
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-002
 */
private function authorizePayment(array $bid_data): array
{
    global $wpdb;

    $auction_id = (int) $bid_data['auction_id'];
    $user_id = (int) $bid_data['user_id'];
    $bid_amount = (float) $bid_data['bid_amount'];

    // 1. Calculate entry fee
    $commission_calculator = new CommissionCalculator();
    $entry_fee_cents = $commission_calculator->calculateEntryFee($bid_amount);

    // 2. Get or store payment method
    $payment_service = new EntryFeePaymentService(
        new SquarePaymentGateway(
            getenv('SQUARE_API_KEY'),
            getenv('SQUARE_LOCATION_ID')
        ),
        new PaymentAuthorizationRepository($wpdb)
    );

    // Store card token (tokenizes with payment gateway)
    if (!empty($bid_data['card_token'])) {
        $payment_method = $payment_service->storePaymentMethod(
            $user_id,
            [
                'token' => $bid_data['card_token'],
            ]
        );
    } else {
        // Use existing payment method
        $payment_method = $this->getUserPaymentMethod($user_id);
    }

    // 3. Authorize payment hold
    $auth_data = $payment_service->authorizeEntryFee(
        $auction_id,
        $user_id,
        bin2hex(random_bytes(18)), // Unique bid_id (UUID alternative)
        $entry_fee_cents,
        $payment_method['token']
    );

    return $auth_data;
}
```

---

## Step 3: Link Authorization to Bid Record

### Store Authorization ID with Bid

```php
/**
 * Create bid record with payment authorization.
 *
 * @param array  $bid_data       Bid submission data
 * @param string $authorization_id Payment authorization ID
 *
 * @return int Bid post ID
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-003
 */
private function createBid(array $bid_data, string $authorization_id): int
{
    global $wpdb;

    // Create bid post (existing logic)
    $bid_post = wp_insert_post([
        'post_type' => 'wc_auction_bid',
        'post_status' => 'publish',
        'post_title' => sprintf(
            'Bid on Auction #%d by User #%d',
            $bid_data['auction_id'],
            $bid_data['user_id']
        ),
        'post_parent' => (int) $bid_data['auction_id'],
    ]);

    // Store bid details as post meta
    update_post_meta($bid_post, '_bid_amount', $bid_data['bid_amount']);
    update_post_meta($bid_post, '_bid_user_id', $bid_data['user_id']);
    update_post_meta($bid_post, '_bid_timestamp', current_time('mysql'));
    update_post_meta($bid_post, '_auction_id', $bid_data['auction_id']);

    // CRITICAL: Link to payment authorization
    update_post_meta($bid_post, '_authorization_id', $authorization_id);

    // Log bid creation
    do_action('wc_auction_bid_placed', $bid_post, $bid_data, $authorization_id);

    return $bid_post;
}
```

---

## Step 4: Error Handling & User Feedback

### Handle Payment Failures with User-Friendly Messages

```php
/**
 * Display payment error to user.
 *
 * @param PaymentException $exception Payment error
 *
 * @return string User-facing error message
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-004
 */
private function getPaymentErrorMessage(PaymentException $exception): string
{
    $error_code = $exception->getErrorCode();

    switch ($error_code) {
        case 'CARD_DECLINED':
            return 'Your card was declined. Please check your card details and try again.';
        case 'CARD_EXPIRED':
            return 'Your card has expired. Please use a different card.';
        case 'INVALID_CVC':
            return 'Invalid CVC. Please check your security code.';
        case 'INSUFFICIENT_FUNDS':
            return 'Insufficient funds. Please use a different card.';
        case 'NETWORK_ERROR':
            return 'Payment service temporary unavailable. Please try again later.';
        case 'RATE_LIMIT':
            return 'Too many payment attempts. Please wait a moment and try again.';
        default:
            return 'Payment failed. Please try again or contact support.';
    }
}
```

---

## Step 5: Frontend Integration (Payment Form)

### Collect Card Token from User

```html
<!-- Bid placement form with payment collection -->
<form id="bid-form" method="post">
    <!-- Existing bid fields -->
    <input type="number" name="bid_amount" /> <!-- readonly -->
    <input type="hidden" name="auction_id" />

    <!-- Entry Fee Display -->
    <div class="entry-fee-display">
        <label>Entry Fee:</label>
        <span id="entry-fee-amount">$50.00</span>
        <small>Non-refundable unless bid is outbid (refunded after 24 hours)</small>
    </div>

    <!-- Payment Information (if no saved card) -->
    <fieldset id="payment-section">
        <legend>Payment Information</legend>

        <!-- Square Payment Form or Stripe Elements -->
        <div id="sq-card-container"></div>

        <input type="hidden" id="card-token" name="card_token" />
    </fieldset>

    <button type="submit">Place Bid</button>
</form>

<script>
// Square Payment Form Integration
const client = new SqPaymentForm({
    applicationId: window.SQUARE_APP_ID,
    locationId: window.SQUARE_LOCATION_ID,
    inputClass: 'sq-input',
});

document.getElementById('bid-form').addEventListener('submit', async (e) => {
    e.preventDefault();

    // Request token from Square
    const tokenResult = await client.requestCardNonce();

    if (tokenResult.status === 'OK') {
        // Store token in form
        document.getElementById('card-token').value = tokenResult.nonce;

        // Submit form (token will be sent to backend)
        this.submit();
    } else {
        alert('Payment form error: ' + tokenResult.errors[0].message);
    }
});
</script>
```

---

## Step 6: Test Entry Fee Authorization

### Unit Test for Payment Authorization

```php
/**
 * Test: Bid placement with payment authorization.
 *
 * @test
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-005
 */
public function test_bid_placement_authorizes_payment(): void
{
    // Mock payment service
    $payment_service = $this->createMock(EntryFeePaymentService::class);
    $payment_service->method('authorizeEntryFee')
        ->willReturn([
            'authorization_id' => 'auth_123',
            'amount_cents' => 5000,
            'status' => 'AUTHORIZED',
        ]);

    // Place bid
    $bid_handler = new YithWcactAuctionBids($payment_service);
    $result = $bid_handler->placeBid([
        'auction_id' => 1,
        'user_id' => 10,
        'bid_amount' => 1200.00,
        'card_token' => 'tok_visa_123',
    ]);

    // Assert success
    $this->assertTrue($result['success']);
    $this->assertNotEmpty($result['bid_id']);

    // Assert authorization linked to bid
    $auth_id = get_post_meta($result['bid_id'], '_authorization_id', true);
    $this->assertEquals('auth_123', $auth_id);
}

/**
 * Test: Bid placement fails on payment decline.
 *
 * @test
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-005
 */
public function test_bid_placement_rejects_declined_card(): void
{
    // Mock payment service that throws exception
    $payment_service = $this->createMock(EntryFeePaymentService::class);
    $payment_service->method('authorizeEntryFee')
        ->willThrowException(new PaymentException('Card declined', 'CARD_DECLINED'));

    // Place bid
    $bid_handler = new YithWcactAuctionBids($payment_service);
    $result = $bid_handler->placeBid([
        'auction_id' => 1,
        'user_id' => 10,
        'bid_amount' => 1200.00,
        'card_token' => 'tok_declined',
    ]);

    // Assert failure
    $this->assertFalse($result['success']);
    $this->assertStringContainsString('declined', strtolower($result['message']));
    $this->assertEquals('PAYMENT_FAILED', $result['error_code']);
}
```

---

## Step 7: Integration with Existing Bid Flow

### Where to Insert Payment Authorization

```
EXISTING BID FLOW (class.yith-wcact-auction-bids.php):
┌─────────────────────────────────────┐
│ do_action('yith_wcact_bid_submit')  │  ← Hook before processing
└────────────┬────────────────────────┘
             │
             ├─> validate_bid() .............. EXISTING
             │
             ├─> authorize_entry_fee() ....... NEW (THIS INTEGRATION)
             │   └─> captureAuthorizationLock($auth_data)
             │       └─> Store auth_id with bid
             │
             ├─> add_bid_post() .............. EXISTING (modified to accept auth_id)
             │
             ├─> update_auction_state() ...... EXISTING
             │
             └─> do_action('yith_wcact_bid_placed')  ← Notify (charge/refund later)
                 └─> EntryFeePaymentService listens
                     - Capture if winner
                     - Schedule refund if outbid
```

---

## Step 8: Configuration & Admin Settings

### Add Entry Fee Settings to Admin Panel

```php
/**
 * Register entry fee settings for auction product.
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-006
 */
add_filter('woocommerce_product_data_tabs', function($tabs) {
    $tabs['auction_entry_fee'] = [
        'label' => __('Entry Fee', 'yith-auctions'),
        'target' => 'auction_entry_fee_data',
        'class' => ['hide_if_not_auction'],
    ];
    return $tabs;
});

add_action('woocommerce_product_data_panels', function() {
    global $post;
    ?>
    <div id="auction_entry_fee_data" class="panel woocommerce_options_panel">
        <?php
        woocommerce_wp_text_input([
            'id'    => '_auction_entry_fee_amount',
            'label' => __('Entry Fee ($)', 'yith-auctions'),
            'type'  => 'number',
            'step'  => '0.01',
            'desc_tip' => true,
            'description' => __('Fee charged when bidder places bid (refunded if outbid after 24h)', 'yith-auctions'),
        ]);

        woocommerce_wp_checkbox([
            'id'    => '_auction_entry_fee_enable',
            'label' => __('Enable Entry Fees', 'yith-auctions'),
            'description' => __('Require payment authorization for bid placement', 'yith-auctions'),
        ]);
        ?>
    </div>
    <?php
});

add_action('save_post_product', function($product_id) {
    $entry_fee = isset($_POST['_auction_entry_fee_amount']) 
        ? (float) $_POST['_auction_entry_fee_amount'] 
        : 0;
    
    $entry_fee_enabled = isset($_POST['_auction_entry_fee_enable']);

    update_post_meta($product_id, '_auction_entry_fee_amount', $entry_fee);
    update_post_meta($product_id, '_auction_entry_fee_enable', $entry_fee_enabled);
});
```

---

## Step 9: AJAX Handler for Payment (Optional)

### Async Payment Authorization

```php
/**
 * AJAX: Authorize entry fee before bid submission.
 *
 * Allows frontend to test payment before finalizing bid.
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-007
 */
add_action('wp_ajax_authorize_entry_fee', function() {
    check_ajax_referer('yith_wcact_bid_nonce');

    if (empty($_POST['card_token']) || empty($_POST['auction_id'])) {
        wp_send_json_error('Missing required fields');
    }

    $payment_service = new EntryFeePaymentService(
        new SquarePaymentGateway(),
        new PaymentAuthorizationRepository()
    );

    try {
        $auth_data = $payment_service->authorizeEntryFee(
            (int) $_POST['auction_id'],
            get_current_user_id(),
            wp_generate_uuid4(),
            (int) $_POST['entry_fee_cents'],
            sanitize_text_field($_POST['card_token'])
        );

        wp_send_json_success([
            'authorization_id' => $auth_data['authorization_id'],
            'message' => 'Payment authorized',
        ]);
    } catch (PaymentException $e) {
        wp_send_json_error($e->getMessage());
    }
});
```

---

## Step 10: Monitoring & Debugging

### Payment Authorization Dashboard Widget

```php
/**
 * Display recent payment authorizations in admin dashboard.
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-008
 */
add_action('wp_dashboard_setup', function() {
    wp_add_dashboard_widget(
        'payment_authorizations_widget',
        __('Recent Payment Authorizations', 'yith-auctions'),
        function() {
            global $wpdb;

            $table = $wpdb->prefix . 'wc_auction_payment_authorizations';

            $authorizations = $wpdb->get_results("
                SELECT id, auction_id, user_id, amount_cents, status, created_at
                FROM {$table}
                ORDER BY created_at DESC
                LIMIT 10
            ");

            echo '<table style="width: 100%;">';
            echo '<thead><tr>
                    <th>Auction</th>
                    <th>User</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Created</th>
                  </tr></thead>';
            echo '<tbody>';

            foreach ($authorizations as $auth) {
                printf(
                    '<tr>
                        <td>#%d</td>
                        <td>User #%d</td>
                        <td>$%.2f</td>
                        <td><span class="status-%s">%s</span></td>
                        <td>%s</td>
                    </tr>',
                    $auth->auction_id,
                    $auth->user_id,
                    $auth->amount_cents / 100,
                    strtolower($auth->status),
                    $auth->status,
                    $auth->created_at
                );
            }

            echo '</tbody></table>';
        }
    );
});
```

---

## Troubleshooting

### Common Issues

#### 1. "Payment Authorization Failed"

**Symptoms:** All payment attempts fail

**Debugging:**
```sql
-- Check authorization table for errors
SELECT * FROM wp_wc_auction_payment_authorizations 
WHERE status = 'FAILED' 
ORDER BY created_at DESC LIMIT 5;

-- Check logs
SELECT * FROM wp_posts 
WHERE post_type = 'wc_auction_log' 
AND post_content LIKE '%PaymentException%';
```

**Solutions:**
- Verify `SQUARE_API_KEY` and `SQUARE_LOCATION_ID` in `wp-config.php`
- Check network connectivity to payment gateway
- Verify card details (expiration, CVC format)

#### 2. "Authorization ID Not Linked to Bid"

**Symptoms:** Bidders can place bids but no payment recorded

**Debugging:**
```php
// Check if authorization_id is stored with bid
$bid_id = 123;
$auth_id = get_post_meta($bid_id, '_authorization_id', true);
echo "Authorization ID: " . ($auth_id ?: 'NOT FOUND');
```

**Solutions:**
- Verify `update_post_meta()` in `createBid()` method
- Check bid post type is correct (`wc_auction_bid`)
- Verify authorization succeeds before bid creation

#### 3. "Card Token Not Received"

**Symptoms:** Frontend payment form not capturing token

**Debugging:**
```
// Check browser console for Square Payment Form errors
// Check HTTP POST data includes 'card_token'
// Verify SQUARE_APP_ID is correct in frontend JS
```

**Solutions:**
- Verify Square app credentials in frontend template
- Check HTTPS is enabled (requirement for payment fields)
- Clear browser cache and reload

---

## Summary: Integration Checklist

```
Bid Placement Integration Checklist:

Phase 1: Setup
  ☐ Install PaymentAuthorizationMigration
  ☐ Run migration during plugin activation
  ☐ Configure SQUARE_API_KEY, SQUARE_LOCATION_ID
  ☐ Set entry fee amount per auction

Phase 2: Backend Integration
  ☐ Create authorizePayment() method
  ☐ Call in placeBid() before bid creation
  ☐ Store authorization_id with bid as post meta
  ☐ Handle PaymentException with user-friendly errors
  ☐ Add logging for all payment events

Phase 3: Frontend Integration
  ☐ Add payment form to bid placement UI
  ☐ Integrate Square Payment Form or Stripe Elements
  ☐ Collect card token before form submission
  ☐ Display entry fee amount and refund terms
  ☐ Show payment status to user

Phase 4: Testing
  ☐ Test successful authorization
  ☐ Test card decline handling
  ☐ Test invalid CVC/expiration
  ☐ Test duplicate bid prevention
  ☐ Test authorization linked to bid record

Phase 5: Monitoring
  ☐ Dashboard widget showing recent authorizations
  ☐ Admin queries for payment history
  ☐ Error logging for failed payments
  ☐ Payment audit trail
  ☐ Scheduled test email notifications

Phase 6: Auction Outcome Integration
  ☐ Capture payment on auction win (next phase)
  ☐ Schedule refund on outbid (next phase)
  ☐ Process scheduled refunds via cron (comes after)
```

---

## Next Steps

After bid placement integration is complete:

1. **Create Auction Outcome Integration Guide**
   - When bid wins: Capture entry fee
   - When bid is outbid: Schedule refund

2. **Create Cron Job Registration**
   - Register WordPress hourly cron
   - Process scheduled refunds

3. **Create Order/Invoice Integration**
   - Create WooCommerce order for entry fee charge
   - Link bid to order item
   - Admin dashboard visibility

4. **Create Email Notification System**
   - Notify on payment authorization
   - Notify on entry fee capture (win)
   - Notify on refund processed (outbid)

---

**Requirement References:**
- REQ-ENTRY-FEE-PAYMENT-001: Payment persistence
- REQ-ENTRY-FEE-PAYMENT-002: Bid-linked authorization
- REQ-ENTRY-FEE-PAYMENT-003: Authorization tracking
- REQ-ENTRY-FEE-PAYMENT-004: Error messaging
- REQ-ENTRY-FEE-PAYMENT-005: Testing integration
- REQ-ENTRY-FEE-PAYMENT-006: Admin configuration
- REQ-ENTRY-FEE-PAYMENT-007: AJAX handlers
- REQ-ENTRY-FEE-PAYMENT-008: Monitoring widgets
