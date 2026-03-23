# Entry Fee Payment Infrastructure - Integration Guide

## Quick Start

### 1. Initialize Payment Service

```php
use Yith\Auctions\Services\EntryFees\EntryFeePaymentService;
use Yith\Auctions\Services\Payment\SquarePaymentGateway;
use Yith\Auctions\Repository\PaymentAuthorizationRepository;
use Yith\Auctions\Services\EntryFees\CommissionCalculator;

// Create dependencies
$gateway = new SquarePaymentGateway(
    SQUARE_API_KEY,
    SQUARE_LOCATION_ID,
    'production'
);

$repository = new PaymentAuthorizationRepository($wpdb);
$calculator = new CommissionCalculator();

// Initialize service
$paymentService = new EntryFeePaymentService(
    $gateway,
    $repository,
    $calculator
);
```

### 2. Store Bidder's Payment Method

When bidder submits payment form:

```php
try {
    $result = $paymentService->storePaymentMethod(
        user_id: get_current_user_id(),
        card_details: [
            'card_number' => sanitize_text_field($_POST['card_number']),
            'exp_month' => intval($_POST['exp_month']),
            'exp_year' => intval($_POST['exp_year']),
            'cvc' => sanitize_text_field($_POST['cvc']),
            'cardholder_name' => sanitize_text_field($_POST['cardholder_name']),
            'billing_email' => sanitize_email($_POST['email']),
        ]
    );
    
    wp_send_json_success([
        'message' => 'Payment method stored',
        'token' => $result['token'],
        'last_four' => $result['last_four'],
    ]);
} catch (\Exception $e) {
    wp_send_json_error([
        'message' => $e->getMessage()
    ]);
}
```

### 3. Authorize Entry Fee at Bid Placement

When bid is placed:

```php
try {
    // Get bidder's payment method token
    $payment_method = $repository->getPaymentMethodForUser(get_current_user_id());
    
    if (!$payment_method) {
        throw new PaymentException('No payment method on file');
    }
    
    // Authorize entry fee
    $auth = $paymentService->authorizeEntryFee(
        auction_id: $auction_id,
        user_id: get_current_user_id(),
        bid_id: wp_generate_uuid4(),
        payment_token: $payment_method['payment_token'],
        bid_amount: new Money($bid_amount_cents),
        customer_email: wp_get_current_user()->user_email
    );
    
    // Store auth_id with bid
    update_post_meta($bid_post_id, '_payment_authorization_id', $auth['auth_id']);
    
    // Bid accepted
    return ['success' => true];
    
} catch (\Exception $e) {
    // Bid rejected - payment failed
    return ['success' => false, 'error' => $e->getMessage()];
}
```

### 4. Capture Entry Fee When Bid Wins

When auction ends and bidder is determined to be winner:

```php
// Get the winning bid
$bid = get_post_meta($auction_id, '_winning_bid', true);

// Retrieve authorization
$auth_id = get_post_meta($bid['post_id'], '_payment_authorization_id', true);

try {
    // Capture the hold (charge the entry fee)
    $captured = $paymentService->captureEntryFee(
        auth_id: $auth_id,
        amount: new Money(2500)  // Actual entry fee amount
    );
    
    // Update order with payment confirmation
    update_post_meta($bid['post_id'], '_payment_captured', true);
    update_post_meta($bid['post_id'], '_payment_capture_id', $captured['capture_id']);
    
} catch (\Exception $e) {
    error_log("Failed to capture entry fee for bid: " . $e->getMessage());
    // Notify admin - manual intervention needed
}
```

### 5. Schedule Refunds for Outbid Bidders

After auction ends, for each outbid bid:

```php
// Get all outbid bids
$outbid_bids = $repository->getAuthorizationsByAuction($auction_id);

foreach ($outbid_bids as $bid_auth) {
    // Check if already captured (winner)
    if ($bid_auth['status'] === 'CAPTURED') {
        continue; // Skip winner
    }
    
    // Schedule refund for outbid bidder
    try {
        $refund = $paymentService->scheduleRefund(
            auth_id: $bid_auth['authorization_id'],
            user_id: $bid_auth['user_id'],
            reason: "Outbid in auction #" . $auction_id
        );
        
        // Store refund ID with bid
        update_post_meta($bid_auth['id'], '_refund_id', $refund['refund_id']);
        
    } catch (\Exception $e) {
        error_log("Failed to schedule refund: " . $e->getMessage());
    }
}
```

### 6. Process Scheduled Refunds (Cron Job)

Set up WordPress cron job to process refunds after 24-hour delay:

```php
// In plugin initialization
if (!wp_next_scheduled('wc_auction_process_refunds')) {
    wp_schedule_event(time(), 'hourly', 'wc_auction_process_refunds');
}

// Cron handler
add_action('wc_auction_process_refunds', 'wc_auction_process_pending_refunds');

function wc_auction_process_pending_refunds() {
    $gateway = new SquarePaymentGateway(
        SQUARE_API_KEY,
        SQUARE_LOCATION_ID,
        'production'
    );
    
    $repository = new PaymentAuthorizationRepository($wpdb);
    $calculator = new CommissionCalculator();
    $service = new EntryFeePaymentService($gateway, $repository, $calculator);
    
    // Get refunds ready to process (scheduled_for <= NOW)
    $pending = $repository->getPendingRefunds(50);
    
    foreach ($pending as $refund) {
        try {
            // Process the refund
            $result = $service->processScheduledRefund(
                refund_id: $refund['refund_id'],
                auth_id: $refund['authorization_id'],
                amount: new Money($refund['amount_cents'])
            );
            
            // Notify bidder
            wp_mail(
                get_userdata($refund['user_id'])->user_email,
                'Auction Refund Processed',
                "Your entry fee has been refunded to your card.\n" .
                "Amount: " . money_format('%.2n', $refund['amount_cents'] / 100)
            );
            
        } catch (\Exception $e) {
            error_log("Refund processing failed: " . $e->getMessage());
            // Will retry on next cron run
        }
    }
}
```

## Workflow Diagrams

### Payment Authorization Flow

```
User Places Bid
    ↓
Submit Payment Form
    ↓
Store Payment Method (Tokenize Card)
    ↓
Authorize Entry Fee (Pre-Auth Hold)
    ↓
Bid Accepted (Hold placed on card)
    ↓
[Wait for Auction to End]
    ↓
Auction Resolution
    ├─ Bidder Wins?
    │   ↓
    │   Capture Entry Fee (Charge)
    │   ↓
    │   Payment Complete
    │
    └─ Bidder Outbid?
        ↓
        Schedule Refund (24h later)
        ↓
        [Wait 24 hours - Dispute window]
        ↓
        Process Refund (Release hold)
        ↓
        Refund Complete
```

### State Machine: Authorization Status Lifecycle

```
                    AUTHORIZED
                    /        \
                   /          \
              CAPTURE_FAILED   CAPTURED
                   |               |
              (Retry)              |
                   |          REFUND_FAILED
                   |          /
                   └─────────┘
                        |
                   [24h Delay]
                        |
                    REFUNDED
                        |
                    [Cleanup]
                        |
                     (Purge)
```

## Database Schema

### Create Tables

Run these migrations during plugin activation:

```php
// wp_wc_auction_payment_methods
CREATE TABLE ` . $wpdb->prefix . qq(wc_auction_payment_methods) . qq(
    id BIGINT(20) NOT NULL AUTO_INCREMENT,
    user_id BIGINT(20) NOT NULL,
    payment_token VARCHAR(255) NOT NULL,
    card_brand VARCHAR(50),
    card_last_four VARCHAR(4),
    exp_month INT,
    exp_year INT,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY unique_user_token (user_id, payment_token),
    KEY idx_user (user_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

// wp_wc_auction_payment_authorizations
CREATE TABLE ` . $wpdb->prefix . qq(wc_auction_payment_authorizations) . qq(
    id BIGINT(20) NOT NULL AUTO_INCREMENT,
    auction_id BIGINT(20) NOT NULL,
    user_id BIGINT(20) NOT NULL,
    bid_id VARCHAR(36) NOT NULL,
    authorization_id VARCHAR(255) NOT NULL,
    payment_gateway VARCHAR(50) NOT NULL,
    amount_cents BIGINT(20) NOT NULL,
    status VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME,
    charged_at DATETIME,
    refunded_at DATETIME,
    metadata LONGTEXT,
    PRIMARY KEY (id),
    UNIQUE KEY unique_bid (bid_id),
    UNIQUE KEY unique_authorization (authorization_id),
    KEY idx_auction (auction_id),
    KEY idx_user (user_id),
    KEY idx_status (status),
    KEY idx_created (created_at),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

// wp_wc_auction_refund_schedule
CREATE TABLE ` . $wpdb->prefix . qq(wc_auction_refund_schedule) . qq(
    id BIGINT(20) NOT NULL AUTO_INCREMENT,
    authorization_id VARCHAR(255) NOT NULL,
    refund_id VARCHAR(36) NOT NULL,
    user_id BIGINT(20) NOT NULL,
    scheduled_for DATETIME NOT NULL,
    reason VARCHAR(255),
    status VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL,
    processed_at DATETIME,
    PRIMARY KEY (id),
    UNIQUE KEY unique_refund (refund_id),
    KEY idx_authorization (authorization_id),
    KEY idx_user (user_id),
    KEY idx_scheduled (scheduled_for),
    KEY idx_status (status),
    FOREIGN KEY fk_authorization (authorization_id) REFERENCES ` . $wpdb->prefix . qq(wc_auction_payment_authorizations (authorization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Admin Queries

### View Payment History for Auction

```php
$auctions = new WP_Query([
    'post_type' => 'product',
    'meta_query' => [
        ['key' => '_is_auction', 'value' => 1]
    ]
]);

foreach ($auctions->posts as $auction) {
    $payments = $repository->getAuthorizationsByAuction($auction->ID);
    
    echo "Auction: " . $auction->post_title . "\n";
    foreach ($payments as $payment) {
        echo "  Bid: " . $payment['bid_id'] . " | Amount: $" . 
             number_format($payment['amount_cents'] / 100, 2) . 
             " | Status: " . $payment['status'] . "\n";
    }
}
```

### View Payment History for Bidder

```php
$user_id = 1;
$auth_history = $repository->getAuthorizationHistory($user_id, 50);

echo "Payment History for User #" . $user_id . ":\n";
foreach ($auth_history as $auth) {
    echo "  Auction #" . $auth['auction_id'] . 
         " | Amount: $" . number_format($auth['amount_cents'] / 100, 2) . 
         " | Status: " . $auth['status'] . 
         " | Date: " . $auth['created_at'] . "\n";
}
```

### Find Failed Payments

```php
$failed = $repository->getFailedAuthorizations(100);

echo "Failed Payments:\n";
foreach ($failed as $failure) {
    echo "  Bid: " . $failure['bid_id'] . 
         " | Status: " . $failure['status'] . 
         " | Error Meta: " . $failure['metadata'] . "\n";
}
// -> Enables reprocessing, customer service investigation
```

## Testing

### Unit Test Example

```php
public function test_authorize_entry_fee_places_hold() {
    // Create mocks
    $gateway = $this->createMock(PaymentGatewayInterface::class);
    $repository = $this->createMock(PaymentAuthorizationRepository::class);
    $calculator = $this->createMock(CommissionCalculator::class);
    
    $service = new EntryFeePaymentService($gateway, $repository, $calculator);
    
    // Setup expectations
    $calculator->expects($this->once())
        ->method('calculateEntryFee')
        ->willReturn(new Money(2500));
    
    $gateway->expects($this->once())
        ->method('authorizePayment')
        ->willReturn([
            'auth_id' => 'auth_123',
            'expires_at' => new DateTime('+7 days')
        ]);
    
    $repository->expects($this->once())
        ->method('recordAuthorization');
    
    // Execute
    $result = $service->authorizeEntryFee(
        123, 1, 'bid-123', 'tok_123', 
        new Money(5000), 'test@example.com'
    );
    
    // Assert
    $this->assertEquals('AUTHORIZED', $result['status']);
}
```

### Manual Test Checklist

- [ ] Store valid payment method successfully
- [ ] Reject invalid card number (Luhn check)
- [ ] Reject expired card
- [ ] Authorize entry fee (pre-auth hold visible in Square dashboard)
- [ ] Capture entry fee after bid wins
- [ ] Schedule refund after bid loses
- [ ] Process scheduled refunds after 24-hour delay
- [ ] Failed authorization stored for audit trail
- [ ] Payment history queries work correctly
- [ ] Cron job processes pending refunds

## Troubleshooting

### "Authorization failed: Card declined"
- Check card details in Square dashboard
- Ensure sufficient balance on card
- Verify pre-auth amount is reasonable ($25 entry fee)

### "Failed to store payment method"
- Verify Square API key is correct
- Check HTTPS/TLS connectivity to Square API
- Review error message in logs

### "Refund processing failed"
- Check authorization still exists (not expired after 7 days)
- Verify Square API connectivity
- Review refund schedule (ensure 24-hour delay has passed)

### Database errors
- Verify payment tables exist (check migration ran)
- Check database permissions
- Review WPDB prepare statements for SQL injection

## Security Checklist

- [ ] Square API keys stored in wp-config.php (not in code)
- [ ] All card data validated before sending to Square
- [ ] No raw card numbers logged
- [ ] All API calls use HTTPS/TLS
- [ ] Payment operations logged for audit trail
- [ ] Failed authorizations tracked for investigation
- [ ] 24-hour refund delay prevents "charge & refund" spam
- [ ] Prepared statements prevent SQL injection

## Performance Considerations

- **Payment Method Storage:** O(1) - Single database insert
- **Authorization:** O(1) - Single API call + database insert
- **Capture:** O(1) - Single API call + database update
- **Refund Scheduling:** O(1) - Single database insert
- **Pending Refund Query:** O(n) where n = refunds ready to process (usually <50)

**Optimization Opportunities:**
- Cache payment method tokens (Redis)
- Batch process refunds in cron job (limits API calls)
- Index on (status, scheduled_for, user_id) for fast pending queries

