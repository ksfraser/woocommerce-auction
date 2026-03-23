# Entry Fee Payment - Auction Outcome Integration Guide

## Overview

This guide details integrating the payment system with auction outcome determination. When an auction ends, the system must:

1. **Determine Winner** - Which bid won the auction
2. **Capture Entry Fees** - Charge all other bids (losers/outbid)
3. **Winner's Entry Fee** - Determine if winner pays entry fee or not
4. **Schedule Refunds** - Queue refunds for outbid bidders (24h delay)
5. **Notify Bidders** - Email confirmations to affected bidders

---

## Architecture: Auction Outcome Payment Lifecycle

```
AUCTION ENDS:
┌───────────────┐
│ Auction Timer │
│   Expires     │
└───────┬───────┘
        │
        ├──> 1. DETERMINE OUTCOME (existing)
        │    - Store winner bid_id
        │    - Mark auction status: ENDED
        │
        ├──> 2. PROCESS PAYMENTS (NEW)
        │    ├─ For Winner Bid:
        │    │    ├─ Capture entry fee (if configured)
        │    │    └─ Create order/invoice
        │    │
        │    └─ For Outbid/Losing Bids:
        │        ├─ Schedule refund (24h delay)
        │        ├─ Email notification
        │        └─ Update bid status to REFUND_PENDING
        │
        └──> 3. MARK AUCTION COMPLETE (existing)
             - Email winners
             - Update product/order status
             - Post auction winner page

PAYMENT STATE POST-AUCTION:
┌──────────────────────────────────────────────────────────────┐
│  WINNER BID (status=won)                                     │
├──────────────────────────────────────────────────────────────┤
│  meta: _authorization_id = 'auth_winner'                     │
│  meta: _bid_status = CAPTURED                                │
│                                                              │
│  wp_wc_auction_payment_authorizations:                       │
│  │ authorization_id = 'auth_winner'                          │
│  │ status = CAPTURED (← was AUTHORIZED, now charged)         │
│  │ charged_at = NOW                                          │
│  └─ Order created: Entry fee $50 charged                     │
└──────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│  OUTBID/LOSING BIDS (status=outbid)                          │
├──────────────────────────────────────────────────────────────┤
│  meta: _authorization_id = 'auth_outbid_1', etc              │
│  meta: _bid_status = WAITING_REFUND                          │
│                                                              │
│  wp_wc_auction_refund_schedule:                              │
│  │ authorization_id = 'auth_outbid_1'                        │
│  │ refund_id = 'refund_xyz1'                                 │
│  │ status = PENDING                                          │
│  │ scheduled_for = NOW + 24 hours                            │
│  └─ Will be processed by RefundSchedulerService in 24h       │
└──────────────────────────────────────────────────────────────┘
```

---

## Step 1: Prepare Auction Outcome Handler

### Location: Where Auction Completion Is Determined

```php
// In existing auction completion handler 
// e.g., class.yith-wcact-auction-finish-auction.php

/**
 * Handle auction completion and entry fee settlement.
 *
 * Flow:
 * 1. Determine winner bid
 * 2. Capture entry fee for winner (if applicable)
 * 3. Schedule refunds for outbid bidders (24h delay)
 * 4. Update bid statuses
 * 5. Send notifications
 *
 * @param int $auction_id Post ID of auction
 *
 * @return bool True on success
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-009
 */
public function finalizeAuction(int $auction_id): bool
{
    global $wpdb;

    try {
        // Get all bids for auction (ordered by amount DESC)
        $bids = $this->getAuctionBids($auction_id);

        if (empty($bids)) {
            return false; // No bids
        }

        // First bid (highest) is the winner
        $winner_bid = $bids[0];
        $losing_bids = array_slice($bids, 1);

        // 1. CAPTURE WINNER'S ENTRY FEE
        $this->captureWinnerPayment($winner_bid);

        // 2. SCHEDULE REFUNDS FOR LOSERS
        foreach ($losing_bids as $losing_bid) {
            $this->scheduleRefundForOutbid($losing_bid);
        }

        // 3. UPDATE AUCTION STATE
        update_post_meta($auction_id, '_auction_status', 'ENDED');
        update_post_meta($auction_id, '_winner_bid_id', $winner_bid->ID);
        update_post_meta($auction_id, '_auction_ended_at', current_time('mysql'));

        // 4. SEND NOTIFICATIONS
        do_action('yith_wcact_auction_ended', $auction_id, $winner_bid, $losing_bids);

        return true;
    } catch (\Exception $e) {
        $this->logError('Auction finalization failed', [
            'auction_id' => $auction_id,
            'error' => $e->getMessage(),
        ]);

        return false;
    }
}
```

---

## Step 2: Capture Winner's Entry Fee

### Charge Entry Fee for Winning Bid

```php
/**
 * Capture entry fee for auction winner.
 *
 * Responsibilities:
 * - Retrieve authorization from winner's bid
 * - Call payment gateway to complete hold (capture)
 * - Update authorization status to CAPTURED
 * - Create WooCommerce order for entry fee charge
 * - Update bid status to CAPTURED
 *
 * @param \WP_Post $winner_bid Winning bid post
 *
 * @return bool True on success
 *
 * @throws PaymentException On payment capture failure
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-009
 */
private function captureWinnerPayment(\WP_Post $winner_bid): bool
{
    global $wpdb;

    $authorization_id = get_post_meta($winner_bid->ID, '_authorization_id', true);

    if (empty($authorization_id)) {
        $this->logWarning('Bid has no authorization linked', [
            'bid_id' => $winner_bid->ID,
        ]);

        return false;
    }

    // Initialize payment service
    $payment_service = new EntryFeePaymentService(
        new SquarePaymentGateway(),
        new PaymentAuthorizationRepository($wpdb)
    );

    try {
        // 1. CAPTURE THE HOLD (charge the card)
        $auth_record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wc_auction_payment_authorizations 
                 WHERE authorization_id = %s",
                $authorization_id
            )
        );

        if (!$auth_record) {
            throw new PaymentException('Authorization not found', 'AUTH_NOT_FOUND');
        }

        // Call payment gateway to capture hold
        $capture_result = $payment_service->captureEntryFee(
            $authorization_id,
            new Money((int) $auth_record->amount_cents)
        );

        // 2. CREATE WOOCOMMERCE ORDER FOR ENTRY FEE
        $this->createEntryFeeOrder(
            $winner_bid,
            (int) $auth_record->user_id,
            (int) $auth_record->amount_cents
        );

        // 3. UPDATE BID STATUS
        update_post_meta($winner_bid->ID, '_bid_status', 'CAPTURED');
        update_post_meta($winner_bid->ID, '_payment_captured_at', current_time('mysql'));

        // 4. LOG SUCCESS
        $this->logInfo('Entry fee captured for winner', [
            'bid_id' => $winner_bid->ID,
            'auction_id' => $auth_record->auction_id,
            'amount' => $auth_record->amount_cents,
        ]);

        return true;
    } catch (PaymentException $e) {
        $this->logError('Failed to capture entry fee', [
            'bid_id' => $winner_bid->ID,
            'authorization_id' => $authorization_id,
            'error' => $e->getMessage(),
        ]);

        // Send alert to admin (payment capture failed)
        // Winner may need manual billing
        do_action('yith_wcact_entry_fee_capture_failed', $winner_bid, $e);

        return false;
    }
}

/**
 * Create WooCommerce order for captured entry fee.
 *
 * @param \WP_Post $bid     Winning bid
 * @param int      $user_id Bidder user ID
 * @param int      $amount_cents Amount in cents
 *
 * @return int Order ID
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-010
 */
private function createEntryFeeOrder(\WP_Post $bid, int $user_id, int $amount_cents): int
{
    $auction_id = (int) get_post_meta($bid->ID, '_auction_id', true);
    $auction = get_post($auction_id);

    // Create order
    $order = wc_create_order([
        'customer_id' => $user_id,
    ]);

    // Add entry fee as order item
    $order->add_product(
        new WC_Product(),  // Placeholder product
        1,                 // Quantity
        [
            'subtotal' => $amount_cents / 100,
            'total' => $amount_cents / 100,
        ]
    );

    // Set order meta
    $order->update_meta_data('_bid_id', $bid->ID);
    $order->update_meta_data('_auction_id', $auction_id);
    $order->update_meta_data('_entry_fee_charge', true);
    $order->update_meta_data('_charge_status', 'CHARGED');
    $order->update_meta_data('_charge_timestamp', current_time('mysql'));

    // Mark order as paid (entry fee already charged)
    $order->payment_complete();

    // Set order status to processing
    $order->set_status('processing');
    $order->save();

    return $order->get_id();
}
```

---

## Step 3: Schedule Refunds for Outbid Bidders

### Queue Refunds for 24-Hour Delay

```php
/**
 * Schedule entry fee refund for outbid bidder.
 *
 * Responsibilities:
 * - Retrieve authorization from bid
 * - Calculate original amount
 * - Schedule refund in database (24h from now)
 * - Update bid status to WAITING_REFUND
 * - Store refund reason
 *
 * @param \WP_Post $outbid_bid Bid that lost auction
 *
 * @return bool True on success
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-011
 */
private function scheduleRefundForOutbid(\WP_Post $outbid_bid): bool
{
    global $wpdb;

    $authorization_id = get_post_meta($outbid_bid->ID, '_authorization_id', true);

    if (empty($authorization_id)) {
        $this->logWarning('Outbid bid has no authorization', [
            'bid_id' => $outbid_bid->ID,
        ]);

        return false;
    }

    try {
        // Get authorization details
        $auth_record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wc_auction_payment_authorizations 
                 WHERE authorization_id = %s",
                $authorization_id
            )
        );

        if (!$auth_record || $auth_record->status !== 'AUTHORIZED') {
            throw new PaymentException('Authorization not available for refund', 'AUTH_NOT_AVAILABLE');
        }

        // Initialize payment service
        $payment_service = new EntryFeePaymentService(
            new SquarePaymentGateway(),
            new PaymentAuthorizationRepository($wpdb)
        );

        // Schedule refund (24h delay for dispute window)
        $refund_id = $payment_service->scheduleRefund(
            $authorization_id,
            (int) $auth_record->user_id,
            'Auction ended - bid not highest',
            strtotime('+24 hours') // Scheduled timestamp
        );

        // Update bid status
        update_post_meta($outbid_bid->ID, '_bid_status', 'WAITING_REFUND');
        update_post_meta($outbid_bid->ID, '_refund_id', $refund_id);
        update_post_meta($outbid_bid->ID, '_refund_scheduled_for', date('Y-m-d H:i:s', strtotime('+24 hours')));

        // Log scheduling
        $this->logInfo('Entry fee refund scheduled for outbid bidder', [
            'bid_id' => $outbid_bid->ID,
            'authorization_id' => $authorization_id,
            'refund_id' => $refund_id,
            'scheduled_for' => date('Y-m-d H:i:s', strtotime('+24 hours')),
        ]);

        return true;
    } catch (PaymentException $e) {
        $this->logError('Failed to schedule refund', [
            'bid_id' => $outbid_bid->ID,
            'authorization_id' => $authorization_id,
            'error' => $e->getMessage(),
        ]);

        return false;
    }
}
```

---

## Step 4: Update Bid Statuses After Auction End

### Mark Bids as Won, Outbid, or Refunded

```php
/**
 * Get all bids for auction ordered by amount (highest first).
 *
 * @param int $auction_id Auction post ID
 *
 * @return WP_Post[] Array of bid posts
 */
private function getAuctionBids(int $auction_id): array
{
    $args = [
        'post_type' => 'wc_auction_bid',
        'posts_per_page' => -1,
        'post_parent' => $auction_id,
        'orderby' => 'meta_value_num',
        'meta_key' => '_bid_amount',
        'order' => 'DESC',
    ];

    return get_posts($args);
}

/**
 * Update bid status to reflect auction outcome.
 *
 * Status values:
 * - ACTIVE: Bid placed but auction still running
 * - WINNING: Highest bid when auction ends
 * - OUTBID: Not highest bid, eligible for refund
 * - CAPTURED: Entry fee charged (winner)
 * - WAITING_REFUND: Scheduled for refund (outbid, 24h delay)
 * - REFUNDED: Refund processed
 * - CANCELLED: Bid cancelled by user before auction end
 *
 * @param WP_Post $bid    Bid post
 * @param string  $status New status
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-012
 */
private function updateBidStatus(\WP_Post $bid, string $status): void
{
    $valid_statuses = [
        'ACTIVE',
        'WINNING',
        'OUTBID',
        'CAPTURED',
        'WAITING_REFUND',
        'REFUNDED',
        'CANCELLED',
    ];

    if (!in_array($status, $valid_statuses)) {
        throw new \InvalidArgumentException("Invalid bid status: {$status}");
    }

    update_post_meta($bid->ID, '_bid_status', $status);
    update_post_meta($bid->ID, '_bid_status_updated_at', current_time('mysql'));
}
```

---

## Step 5: Integrate with Auction Completion Hook

### Register Handler with Existing Auction Finish Workflow

```php
// In plugin initialization (init.php or similar)

/**
 * Hook auction completion to process entry fees.
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-013
 */
add_action('yith_wcact_auction_ended', function($auction_id, $winner_bid, $losing_bids) {
    // Initialize handler
    $auction_handler = new YithWcactAuctionFinish();

    // This now includes entry fee processing
    // (via captureWinnerPayment + scheduleRefundForOutbid)
    $auction_handler->finalizeAuction($auction_id);

}, 10, 3);

/**
 * Handle entry fee capture failure.
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-014
 */
add_action('yith_wcact_entry_fee_capture_failed', function($bid, PaymentException $exception) {
    // Send admin notification
    wp_mail(
        get_option('admin_email'),
        '[URGENT] Entry Fee Capture Failed',
        sprintf(
            "Entry fee capture failed for winning bid #%d.\n\n" .
            "Error: %s\n\n" .
            "Manual action required to charge bidder.",
            $bid->ID,
            $exception->getMessage()
        )
    );

    // Log to audit trail
    error_log("Entry fee capture failed: Bid #{$bid->ID}, Error: " . $exception->getMessage());
});
```

---

## Step 6: Send Email Notifications

### Notify Bidders of Entry Fee Outcomes

```php
/**
 * Send entry fee confirmation emails.
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-015
 */
add_action('yith_wcact_auction_ended', function($auction_id, $winner_bid, $losing_bids) {
    $auction = get_post($auction_id);
    $winner_user = get_userdata($winner_bid->post_author);

    // Email 1: Winner notification (entry fee charged)
    $winner_email_subject = sprintf(
        'Auction Won - Entry Fee of $%s Charged',
        get_post_meta($winner_bid->ID, '_entry_fee_amount', true)
    );

    $winner_email_body = sprintf(
        "Congratulations!\n\n" .
        "You won the auction: %s\n\n" .
        "Your entry fee of $%s has been charged to your payment method.\n\n" .
        "Next steps:\n" .
        "1. Complete your purchase in My Auctions\n" .
        "2. Arrange shipment with the seller\n\n" .
        "Questions? Contact us at %s",
        $auction->post_title,
        get_post_meta($winner_bid->ID, '_entry_fee_amount', true),
        get_option('admin_email')
    );

    wp_mail(
        $winner_user->user_email,
        $winner_email_subject,
        $winner_email_body,
        ['Content-Type: text/plain; charset=UTF-8']
    );

    // Email 2: Outbid notification (refund pending)
    foreach ($losing_bids as $losing_bid) {
        $bidder = get_userdata($losing_bid->post_author);

        $outbid_email_subject = 'Your Auction Entry Fee - Refund Pending';

        $outbid_email_body = sprintf(
            "Your bid on the auction \"%s\" was not the highest.\n\n" .
            "Good news! Your entry fee of $%s will be refunded.\n\n" .
            "Refund Timeline:\n" .
            "- Hold placed: %s\n" .
            "- Refund processed: %s (24 hours later)\n" .
            "- Refund credited: %s (1-3 business days)\n\n" .
            "We delay refunds by 24 hours to protect against chargebacks.\n\n" .
            "Questions? Contact us at %s",
            $auction->post_title,
            get_post_meta($losing_bid->ID, '_entry_fee_amount', true),
            get_post_meta($losing_bid->ID, '_bid_timestamp', true),
            date('Y-m-d H:i', strtotime('+24 hours')),
            date('Y-m-d', strtotime('+26 days')),  // 24h + 1-3 days
            get_option('admin_email')
        );

        wp_mail(
            $bidder->user_email,
            $outbid_email_subject,
            $outbid_email_body,
            ['Content-Type: text/plain; charset=UTF-8']
        );
    }

}, 10, 3);
```

---

## Step 7: Test Auction Outcome Payment Processing

### Unit Tests for Payment Capture and Refund Scheduling

```php
/**
 * Test: Auction end captures winner's entry fee.
 *
 * @test
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-016
 */
public function test_auction_end_captures_winner_payment(): void
{
    // Create auction and bids
    $auction_id = $this->createAuction();
    $winner_bid = $this->createBid($auction_id, 1500.00, 'user1', 'auth_winner');
    $outbid_bid = $this->createBid($auction_id, 1200.00, 'user2', 'auth_outbid');

    // Mock payment service
    $payment_service = $this->createMock(EntryFeePaymentService::class);
    $payment_service->method('captureEntryFee')
        ->willReturn([
            'status' => 'CAPTURED',
            'charged_at' => current_time('mysql'),
        ]);

    // Finalize auction
    $handler = new YithWcactAuctionFinish($payment_service);
    $result = $handler->finalizeAuction($auction_id);

    $this->assertTrue($result);

    // Verify winner's entry fee was captured
    $winner_status = get_post_meta($winner_bid->ID, '_bid_status', true);
    $this->assertEquals('CAPTURED', $winner_status);
}

/**
 * Test: Auction end schedules refunds for outbid bidders.
 *
 * @test
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-016
 */
public function test_auction_end_schedules_refunds_for_outbid(): void
{
    // Create auction and bids
    $auction_id = $this->createAuction();
    $winner_bid = $this->createBid($auction_id, 1500.00, 'user1', 'auth_winner');
    $outbid_bid_1 = $this->createBid($auction_id, 1200.00, 'user2', 'auth_outbid_1');
    $outbid_bid_2 = $this->createBid($auction_id, 1000.00, 'user3', 'auth_outbid_2');

    // Mock payment service
    $payment_service = $this->createMock(EntryFeePaymentService::class);
    $payment_service->method('scheduleRefund')
        ->willReturn('refund_123');

    // Finalize auction
    $handler = new YithWcactAuctionFinish($payment_service);
    $result = $handler->finalizeAuction($auction_id);

    $this->assertTrue($result);

    // Verify refunds scheduled for outbid bidders
    $status_1 = get_post_meta($outbid_bid_1->ID, '_bid_status', true);
    $status_2 = get_post_meta($outbid_bid_2->ID, '_bid_status', true);

    $this->assertEquals('WAITING_REFUND', $status_1);
    $this->assertEquals('WAITING_REFUND', $status_2);

    // Verify refund records in database
    global $wpdb;
    $refund_count = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}wc_auction_refund_schedule 
         WHERE status = 'PENDING'"
    );

    $this->assertGreaterThanOrEqual(2, $refund_count);
}

/**
 * Test: Auction end creates order for winner.
 *
 * @test
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-016
 */
public function test_auction_end_creates_entry_fee_order(): void
{
    $auction_id = $this->createAuction();
    $winner_bid = $this->createBid($auction_id, 1500.00, 'user1', 'auth_winner');

    // Mock payment service
    $payment_service = $this->createMock(EntryFeePaymentService::class);
    $payment_service->method('captureEntryFee')
        ->willReturn(['status' => 'CAPTURED']);

    // Finalize auction
    $handler = new YithWcactAuctionFinish($payment_service);
    $result = $handler->finalizeAuction($auction_id);

    $this->assertTrue($result);

    // Verify order was created
    $order_id = get_post_meta($winner_bid->ID, '_order_id', true);
    $this->assertNotEmpty($order_id);

    $order = wc_get_order($order_id);
    $this->assertNotNull($order);
    $this->assertEquals('processing', $order->get_status());
}

/**
 * Test: Failed entry fee capture triggers admin alert.
 *
 * @test
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-017
 */
public function test_capture_failure_alerts_admin(): void
{
    $auction_id = $this->createAuction();
    $winner_bid = $this->createBid($auction_id, 1500.00, 'user1', 'auth_winner');

    // Mock payment service that throws exception
    $payment_service = $this->createMock(EntryFeePaymentService::class);
    $payment_service->method('captureEntryFee')
        ->willThrowException(new PaymentException('Card declined', 'CARD_DECLINED'));

    // Finalize auction
    $handler = new YithWcactAuctionFinish($payment_service);
    $result = $handler->finalizeAuction($auction_id);

    // Should not be fatal (returns false but doesn't throw)
    $this->assertFalse($result);

    // Verify bid status not updated to CAPTURED
    $bid_status = get_post_meta($winner_bid->ID, '_bid_status', true);
    $this->assertNotEquals('CAPTURED', $bid_status);
}
```

---

## Step 8: Admin Dashboard: Entry Fee Summary

### Display Entry Fee Collection Status

```php
/**
 * Add entry fee summary to admin auction details.
 *
 * @requirement REQ-ENTRY-FEE-PAYMENT-018
 */
add_action('woocommerce_admin_order_data_after_order_details', function($order) {
    $auction_id = $order->get_meta('_auction_id');

    if (!$auction_id) {
        return;
    }

    global $wpdb;

    // Get entry fee stats for auction
    $stats = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT 
                COUNT(*) as total_bids,
                COUNT(CASE WHEN status = 'CAPTURED' THEN 1 END) as total_captured,
                SUM(CASE WHEN status = 'CAPTURED' THEN amount_cents ELSE 0 END) as total_revenue,
                COUNT(CASE WHEN status = 'PENDING' THEN 1 END) as pending_refunds
            FROM {$wpdb->prefix}wc_auction_payment_authorizations
            WHERE auction_id = %d",
            $auction_id
        )
    );

    ?>
    <div class="entry-fee-summary">
        <h3>Entry Fee Summary</h3>
        <table>
            <tr>
                <td>Total Bids:</td>
                <td><?php echo $stats->total_bids; ?></td>
            </tr>
            <tr>
                <td>Captured (Winner):</td>
                <td><?php echo $stats->total_captured; ?></td>
            </tr>
            <tr>
                <td>Revenue:</td>
                <td>$<?php echo number_format($stats->total_revenue / 100, 2); ?></td>
            </tr>
            <tr>
                <td>Pending Refunds:</td>
                <td><?php echo $stats->pending_refunds; ?></td>
            </tr>
        </table>
    </div>
    <?php
});
```

---

## Step 9: Troubleshooting Auction Outcome Payments

### Common Issues and Debugging

#### 1. "Entry Fee Not Captured for Winner"

**Symptoms:** Auction ends but winner not charged

**Debug Query:**
```sql
-- Check if authorization linked to winner bid
SELECT b.ID, b.post_title, 
       pm._authorization_id,
       pa.status, pa.amount_cents
FROM wp_posts b
LEFT JOIN wp_postmeta pm ON b.ID = pm.post_id 
  AND pm.meta_key = '_authorization_id'
LEFT JOIN wp_wc_auction_payment_authorizations pa 
  ON pm.meta_value = pa.authorization_id
WHERE b.post_type = 'wc_auction_bid'
  AND b.post_status = 'publish'
ORDER BY b.post_date DESC LIMIT 10;
```

**Solutions:**
- Verify authorization exists for winner bid
- Check payment gateway connection
- Verify card wasn't declined during capture
- Manual capture may be needed (admin retry function)

#### 2. "Refunds Not Scheduled for Outbid Bidders"

**Symptoms:** After auction, outbid bidders not refunded

**Debug Query:**
```sql
-- Check refund schedule
SELECT * FROM wp_wc_auction_refund_schedule
WHERE status IN ('PENDING', 'FAILED')
ORDER BY created_at DESC LIMIT 10;

-- Check bid statuses
SELECT ID, post_title, 
       (SELECT meta_value FROM wp_postmeta 
        WHERE post_id = wp_posts.ID 
          AND meta_key = '_bid_status') as status
FROM wp_posts
WHERE post_type = 'wc_auction_bid'
  AND post_parent = 123  -- Replace with auction_id
ORDER BY post_date DESC;
```

**Solutions:**
- Verify `scheduleRefundForOutbid()` called for each losing bid
- Check refund schedule records created
- Verify scheduled_for timestamp is 24h in future
- Check RefundSchedulerService is registered to cron

#### 3. "Orders Not Created for Entry Fees"

**Symptoms:** Winner charged but no WooCommerce order

**Debug:**
```php
// Check if order created
$bid_id = 123;
$order_id = get_post_meta($bid_id, '_order_id', true);

if (empty($order_id)) {
    echo "No order linked to bid";
} else {
    $order = wc_get_order($order_id);
    echo "Order exists: " . $order->get_id();
}
```

**Solutions:**
- Verify `createEntryFeeOrder()` called after capture
- Check order metadata for bid_id link
- Verify WooCommerce order statuses

---

## Summary: Auction Outcome Integration Checklist

```
Auction Outcome Integration Checklist:

Setup:
  ☐ Identify where auction completion is called
  ☐ Get all bids ordered by amount (highest first)
  ☐ Initialize payment service with gateway

Winner Processing:
  ☐ Retrieve winner bid (highest amount)
  ☐ Get authorization_id from bid meta
  ☐ Call captureEntryFee() to charge card
  ☐ Create WooCommerce order for entry fee
  ☐ Update bid status to CAPTURED
  ☐ Log capture timestamp

Outbid Processing:
  ☐ For each non-winner bid:
  ☐ Get authorization_id from bid meta
  ☐ Call scheduleRefund() with 24h delay
  ☐ Update bid status to WAITING_REFUND
  ☐ Store refund_id with bid
  ☐ Log refund schedule timestamp

Order Management:
  ☐ Create order with entry fee amount
  ☐ Link order to winner bid
  ☐ Link order to auction
  ☐ Mark order as paid/processing
  ☐ Include entry fee in order items

Notifications:
  ☐ Email winner about charge
  ☐ Email outbid bidders about refund pending
  ☐ Alert admin on capture failure
  ☐ Include refund timeline (24h + 1-3 days)

Monitoring:
  ☐ Dashboard widget showing entry fee stats
  ☐ Admin queries for payment history
  ☐ Failure alerts for manual intervention
  ☐ Audit trail for all payments

Testing:
  ☐ Test successful capture
  ☐ Test failed capture handling
  ☐ Test refund scheduling
  ☐ Test order creation
  ☐ Test email notifications
```

---

## Next Steps

After auction outcome integration:

1. **Register Cron Job**
   - WordPress hourly hook
   - Calls RefundSchedulerService

2. **Manual Retry Functionality**
   - Admin function to retry failed captures
   - Admin function to retry failed refunds

3. **Payment Audit Dashboard**
   - Payment history by auction
   - Payment history by user
   - Failed payment alerts

4. **Automated Testing**
   - End-to-end test: bid → auction end → capture/refund
   - Performance test: large auction with many bidders

---

**Requirement References:**
- REQ-ENTRY-FEE-PAYMENT-009: Auction outcome handler
- REQ-ENTRY-FEE-PAYMENT-010: Winner order creation
- REQ-ENTRY-FEE-PAYMENT-011: Refund scheduling
- REQ-ENTRY-FEE-PAYMENT-012: Bid status tracking
- REQ-ENTRY-FEE-PAYMENT-013: Hook integration
- REQ-ENTRY-FEE-PAYMENT-014: Failure alerts
- REQ-ENTRY-FEE-PAYMENT-015: Email notifications
- REQ-ENTRY-FEE-PAYMENT-016: Testing
- REQ-ENTRY-FEE-PAYMENT-017: Failure handling
- REQ-ENTRY-FEE-PAYMENT-018: Admin dashboard
