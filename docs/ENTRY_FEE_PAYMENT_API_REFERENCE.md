# Entry Fee Payment Infrastructure - API Reference

## Overview

The Entry Fee Payment Infrastructure provides a pluggable, secure payment processing system for auction entry fees. It handles:

- **Payment Method Storage**: Secure tokenization of payment cards (PCI compliance)
- **Pre-Authorization Holds**: Place holds on bidder's card without charging
- **Charge Capture**: Finalize charges for winning bids
- **Refund Management**: Queue and process refunds with 24-hour dispute window

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│               EntryFeePaymentService                        │
│          (Orchestration Layer - Business Logic)             │
└──────────┬──────────────────────┬──────────────────────┬────┘
           │                      │                      │
    ┌──────v────────┐    ┌────────v──────────┐  ┌───────v──────────┐
    │ PaymentGateway│    │ PaymentAuthorization   CommissionCalculator
    │ Interface     │    │ Repository             (entry fee amounts)
    │ (contract)    │    │ (persistence)          
    └──────┬────────┘    └────────┬──────────┘  └───────┬──────────┘
           │                      │                     │
    ┌──────v────────┐            │              ┌──────v──────┐
    │SquarePayment  │            │              │ Commission  │
    │Gateway        │            │              │ Calculation │
    │ (Square API)  │            │              └─────────────┘
    └───────────────┘    ┌───────v──────────┐
                         │ WordPress WPDB   │
                         │ Payment Tables   │
                         └──────────────────┘
```

## Core Components

### 1. PaymentGatewayInterface

Contract for pluggable payment providers (Square, Stripe, PayPal).

```php
interface PaymentGatewayInterface {
    // Tokenize card safely (never store raw card data)
    public function createPaymentMethod(array $card_details): array;
    
    // Place pre-authorization hold (verify without charging)
    public function authorizePayment(string $payment_token, Money $amount, array $metadata): array;
    
    // Charge previously authorized hold
    public function capturePayment(string $auth_id, Money $amount): array;
    
    // Refund authorized or captured payment
    public function refundPayment(string $auth_id, ?Money $amount, array $metadata): array;
    
    // Validate card with $0.01 test charge (immediately refunded)
    public function verifyPaymentMethod(string $payment_token, array $metadata): array;
    
    // Get non-sensitive card details
    public function getPaymentMethodDetails(string $payment_token): array;
    
    // Get provider name (for tracking)
    public function getProviderName(): string;
}
```

### 2. SquarePaymentGateway

Full Square implementation with comprehensive validation.

**Key Features:**
- Pre-authorization holds (7-day expiry default)
- Card validation (Luhn algorithm, expiration, CVC format)
- Card brand detection (Visa, Mastercard, Amex, Discover)
- Idempotency keys (prevent duplicate charges on retry)
- $0.01 test verification
- TLS encryption for all API calls
- PCI DSS compliance (tokenization only)

**Configuration:**
```php
$gateway = new SquarePaymentGateway(
    'sq_live_abc123...',  // Square API key
    'loc_xyz789...',       // Square location ID
    'production'           // Environment: 'sandbox' or 'production'
);
```

### 3. EntryFeePaymentService

Orchestration layer managing payment authorization lifecycle.

#### storePaymentMethod()

Store bidder's payment method for future use.

```php
$result = $service->storePaymentMethod(
    user_id: 1,
    card_details: [
        'card_number' => '4111111111111111',
        'exp_month' => 12,
        'exp_year' => 2026,
        'cvc' => '123',
        'cardholder_name' => 'John Doe',
        'billing_email' => 'john@example.com'
    ]
);

// Returns:
// {
//     'success': true,
//     'token': 'tok_square_abc123',
//     'last_four': '1111',
//     'brand': 'Visa'
// }
```

**Throws:** `ValidationException` (invalid card), `PaymentException` (tokenization failed)

#### authorizeEntryFee()

Place pre-authorization hold when bid is placed.

```php
$result = $service->authorizeEntryFee(
    auction_id: 123,
    user_id: 1,
    bid_id: 'bid-uuid-123',
    payment_token: 'tok_square_abc123',
    bid_amount: new Money(5000),  // $50.00
    customer_email: 'john@example.com'
);

// Returns:
// {
//     'auth_id': 'auth_123abc',
//     'entry_fee': Money($2500),  // $25.00 (50% of bid)
//     'status': 'AUTHORIZED',
//     'expires_at': DateTime(+7 days),
//     'authorization_record_id': 1
// }
```

**On Success:**
- Entry fee calculated by CommissionCalculator
- Pre-auth hold placed on bidder's card
- Hold recorded in database for later capture/refund
- Hold expires after 7 days

**On Failure:**
- Failed authorization logged for audit trail
- PaymentException thrown

**Throws:** `ValidationException`, `PaymentException`

#### captureEntryFee()

Charge the held amount for winning bid.

```php
$result = $service->captureEntryFee(
    auth_id: 'auth_123abc',
    amount: new Money(2500)  // Must match authorized amount
);

// Returns:
// {
//     'capture_id': 'capture_456def',
//     'amount': Money($2500),
//     'status': 'CAPTURED',
//     'charge_timestamp': DateTime
// }
```

**Idempotent:** Safe to retry if network fails.

**Throws:** `PaymentException`

#### scheduleRefund()

Queue refund for outbid bidder (processed after 24-hour dispute window).

```php
$result = $service->scheduleRefund(
    auth_id: 'auth_123abc',
    user_id: 1,
    reason: 'Outbid in auction #123'
);

// Returns:
// {
//     'refund_id': 'REFUND-1234567890-uuid',
//     'scheduled_for': DateTime(+24 hours),
//     'status': 'REFUND_PENDING'
// }
```

**24-Hour Delay Rationale:**
- Allows chargeback window to close (merchant protection)
- Funds appear back to customer quickly (after 24h)
- Prevents "charge and refund" spam from same card

**Throws:** `PaymentException`

#### processScheduledRefund()

Process scheduled refund (called by cron job after 24 hours).

```php
$result = $service->processScheduledRefund(
    refund_id: 'REFUND-1234567890-uuid',
    auth_id: 'auth_123abc',
    amount: new Money(2500)
);

// Returns:
// {
//     'refund_id': 'REFUND-1234567890-uuid',
//     'amount_refunded': Money($2500),
//     'status': 'REFUNDED',
//     'refund_timestamp': DateTime
// }
```

**Throws:** `PaymentException`

### 4. PaymentAuthorizationRepository

Database persistence for payment authorizations and refunds.

#### Database Tables

**wp_wc_auction_payment_methods**
```sql
id              INT PRIMARY KEY AUTO_INCREMENT
user_id         INT FOREIGN KEY → wp_users
payment_token   VARCHAR(255) -- Gateway token (never raw card data)
card_brand      VARCHAR(50)  -- Visa, Mastercard, Amex, Discover
card_last_four  VARCHAR(4)   -- Last 4 digits for display
exp_month       INT
exp_year        INT
created_at      TIMESTAMP
updated_at      TIMESTAMP
```

**wp_wc_auction_payment_authorizations**
```sql
id              INT PRIMARY KEY AUTO_INCREMENT
auction_id      INT FOREIGN KEY → wp_posts (auction)
user_id         INT FOREIGN KEY → wp_users (bidder)
bid_id          VARCHAR(36)  -- Unique bid identifier (UUID)
authorization_id VARCHAR(255) -- Gateway auth/charge ID
payment_gateway VARCHAR(50)  -- square, stripe, paypal
amount_cents    INT          -- Entry fee in cents
status          VARCHAR(50)  -- AUTHORIZED, CAPTURED, REFUNDED, FAILED, etc.
created_at      TIMESTAMP    -- When hold was placed
expires_at      TIMESTAMP    -- When hold expires (7 days default)
charged_at      TIMESTAMP    -- When captured (NULL if not yet)
refunded_at     TIMESTAMP    -- When refunded (NULL if not yet)
metadata        JSON         -- Additional context (email, bid amount, etc)
```

**wp_wc_auction_refund_schedule**
```sql
id              INT PRIMARY KEY AUTO_INCREMENT
authorization_id VARCHAR(255) FOREIGN KEY → payment_authorizations
refund_id       VARCHAR(36)  -- Unique refund identifier
user_id         INT FOREIGN KEY → wp_users
scheduled_for   TIMESTAMP    -- When to process (24h later)
reason          VARCHAR(255) -- Why refund (e.g., "Outbid in auction")
status          VARCHAR(50)  -- PENDING, PROCESSED, FAILED
created_at      TIMESTAMP
processed_at    TIMESTAMP    -- When actually processed
```

#### Key Methods

**recordAuthorization()**
```php
$record_id = $repository->recordAuthorization(
    auction_id: 123,
    user_id: 1,
    bid_id: 'bid-uuid-123',
    authorization_id: 'auth_123abc',
    payment_gateway: 'square',
    amount: new Money(2500),
    status: 'AUTHORIZED',
    metadata: ['email' => 'john@example.com']
);
```

**updateAuthorizationStatus()**
```php
$repository->updateAuthorizationStatus(
    authorization_id: 'auth_123abc',
    new_status: 'CAPTURED',
    additional_data: ['charged_at' => '2026-01-01 12:00:00']
);
```

**getAuthorizationByBid()**
```php
$auth = $repository->getAuthorizationByBid('bid-uuid-123');
// Returns: authorization record or null
```

**getPendingRefunds()**
```php
$pending = $repository->getPendingRefunds(limit: 50);
// Returns: array of refunds scheduled_for <= NOW() with status PENDING
```

**scheduleRefund()**
```php
$refund_id = $repository->scheduleRefund(
    auth_id: 'auth_123abc',
    user_id: 1,
    scheduled_for: new DateTime('+24 hours'),
    reason: 'Outbid in auction'
);
```

## Payment Flow Examples

### Example 1: Winning Bid (Authorize → Capture)

```php
// 1. Bidder places bid (with entry fee)
$auth = $service->authorizeEntryFee(
    auction_id: 123,
    user_id: 1,
    bid_id: 'bid-uuid-123',
    payment_token: 'tok_square_abc123',
    bid_amount: new Money(5000),
    customer_email: 'john@example.com'
);
// Hold placed: $25.00 reserved on bidder's card

// 2. Auction ends, bidder wins
$captured = $service->captureEntryFee(
    auth_id: $auth['auth_id'],
    amount: $auth['entry_fee']
);
// Charge finalized: $25.00 moved from hold to actual charge
```

### Example 2: Outbid Bidder (Authorize → Schedule Refund → Process)

```php
// 1. Bidder places bid
$auth = $service->authorizeEntryFee(
    auction_id: 123,
    user_id: 1,
    bid_id: 'bid-uuid-123',
    payment_token: 'tok_square_abc123',
    bid_amount: new Money(5000),
    customer_email: 'john@example.com'
);
// Hold placed: $25.00 reserved

// 2. Auction ends, bidder is outbid
$scheduled = $service->scheduleRefund(
    auth_id: $auth['auth_id'],
    user_id: 1,
    reason: 'Outbid in auction'
);
// Refund queued for 24 hours later

// 3. Next day - cron job processes refunds
foreach ($repository->getPendingRefunds(50) as $refund) {
    $service->processScheduledRefund(
        refund_id: $refund['refund_id'],
        auth_id: $refund['authorization_id'],
        amount: new Money($refund['amount_cents'])
    );
}
// Refund completed: $25.00 returned to bidder's card
```

### Example 3: Failed Authorization

```php
try {
    $auth = $service->authorizeEntryFee(
        auction_id: 123,
        user_id: 1,
        bid_id: 'bid-uuid-123',
        payment_token: 'tok_square_abc123',
        bid_amount: new Money(5000),
        customer_email: 'john@example.com'
    );
} catch (PaymentException $e) {
    // Authorization failed (card declined, insufficient funds, etc)
    // Failed record still logged in database for audit trail
    // Bid should be rejected
    // User notified to supply valid payment method
}
```

## Integration Points

### With Bid Placement

When bid is placed, immediately authorize entry fee:

```php
// In bid placement logic
try {
    $auth_result = $entryFeeService->authorizeEntryFee(
        auction_id: $auction_id,
        user_id: $user_id,
        bid_id: $bid_id,
        payment_token: $user_payment_token,
        bid_amount: new Money($bid_amount_cents),
        customer_email: $user_email
    );
    
    // Store auth_id with bid record
    $bid->authorization_id = $auth_result['auth_id'];
    $bid->save();
    
    // Bid accepted
    return ['success' => true, 'bid_id' => $bid_id];
} catch (PaymentException $e) {
    // Bid rejected - payment authorization failed
    return ['success' => false, 'error' => 'Payment authorization failed'];
}
```

### With Auction End / Winner Determination

When auction ends and winner is determined:

```php
// For winning bid
$bid = $auction->getWinningBid();
$auth = $repository->getAuthorizationByBid($bid->id);

$captured = $service->captureEntryFee(
    auth_id: $auth['authorization_id'],
    amount: new Money($auth['amount_cents'])
);
// Winner's entry fee is now charged

// For all outbid bids
foreach ($auction->getOutbidBids() as $bid) {
    $auth = $repository->getAuthorizationByBid($bid->id);
    
    $service->scheduleRefund(
        auth_id: $auth['authorization_id'],
        user_id: $bid->user_id,
        reason: "Outbid in auction #{$auction->id}"
    );
}
// Refunds queued for 24 hours later
```

### Cron Job for Refund Processing

Register WordPress cron hook:

```php
// In plugin initialization
if (!wp_next_scheduled('wc_auction_process_refunds')) {
    wp_schedule_event(time(), 'hourly', 'wc_auction_process_refunds');
}

// Cron handler
add_action('wc_auction_process_refunds', function() {
    $service = new EntryFeePaymentService($gateway, $repository, $calculator);
    $pending = $repository->getPendingRefunds(50);
    
    foreach ($pending as $refund) {
        try {
            $service->processScheduledRefund(
                refund_id: $refund['refund_id'],
                auth_id: $refund['authorization_id'],
                amount: new Money($refund['amount_cents'])
            );
        } catch (PaymentException $e) {
            error_log("Refund {$refund['refund_id']} failed: " . $e->getMessage());
            // Will retry on next cron run
        }
    }
});
```

## Security & Compliance

### PCI DSS Compliance

- **No raw card data stored**: Only secure tokens from payment gateway
- **Tokenization**: Cards converted to tokens immediately upon submission
- **Prepared statements**: All database queries use prepared statements
- **TLS encryption**: All API calls use HTTPS/TLS
- **Input validation**: Card numbers validated (Luhn), expiration, CVC format
- **Audit trail**: All payment operations logged

### Error Handling

```php
try {
    // Payment operation
} catch (ValidationException $e) {
    // Invalid input (card number format, etc)
    // User-facing error typically
    
} catch (PaymentException $e) {
    // Payment processor error (declined, insufficient funds, etc)
    // User-facing error or retry logic
}
```

### Card Validation

Implemented in SquarePaymentGateway:

- **Luhn Algorithm**: Detects invalid card numbers
- **Expiration Check**: Ensures card not expired
- **CVC Format**: Validates 3-4 digit CVC
- **Brand Detection**: Identifies card type

## Testing

### Unit Tests (70+ tests total)

**EntryFeePaymentServiceTest** (40+ tests)
- Payment method storage
- Authorization (pre-auth hold)
- Capture (charge winner)
- Refund scheduling
- Refund processing
- Complete lifecycle scenarios (winner, outbid)
- Error handling (declined, network failure)
- Audit trail verification

**PaymentAuthorizationRepositoryTest** (30+ tests)
- CRUD operations
- Payment method persistence
- Authorization tracking
- Refund scheduling
- Audit history queries
- Failed authorization tracking
- Data pruning

### Mock Objects

All external dependencies mocked:
- `PaymentGatewayInterface`: Mock Square responses
- `PaymentAuthorizationRepository`: Mock WordPress WPDB
- `CommissionCalculator`: Mock fee calculations

## Configuration

### Square Setup

1. Get Square API key from Square Dashboard
2. Get Location ID for your business
3. Store in WordPress config:

```php
define('SQUARE_API_KEY', 'sq_live_abc123...');
define('SQUARE_LOCATION_ID', 'loc_xyz789...');
define('SQUARE_ENVIRONMENT', 'production'); // or 'sandbox'
```

4. Initialize gateway:

```php
$gateway = new SquarePaymentGateway(
    SQUARE_API_KEY,
    SQUARE_LOCATION_ID,
    SQUARE_ENVIRONMENT
);
```

## Requirements Mapping

| Requirement | Implementation |
|---|---|
| REQ-ENTRY-FEE-PAYMENT-001 | EntryFeePaymentService, PaymentGatewayInterface, SquarePaymentGateway |
| REQ-ENTRY-FEE-COMMISSION-001 | CommissionCalculator integration in authorizeEntryFee() |
| REQ-ENTRY-FEE-VALIDATION-001 | SquarePaymentGateway card validation (Luhn, expiration, CVC) |

