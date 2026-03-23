# Cron Job Refund Processing Integration Guide

**Phase 4-C Integration Step 3: Complete Documentation**

**Document Version:** 1.0  
**Last Updated:** 2024  
**Audience:** Developers, DevOps, System Administrators, Support Teams

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Setup Instructions](#setup-instructions)
4. [Configuration](#configuration)
5. [Monitoring & Dashboard](#monitoring--dashboard)
6. [Troubleshooting](#troubleshooting)
7. [Performance & Optimization](#performance--optimization)
8. [Manual Intervention](#manual-intervention)
9. [Database Queries](#database-queries)
10. [Edge Cases & Best Practices](#edge-cases--best-practices)

---

## Overview

The Cron Refund Processing Integration automates the execution of entry fee refunds for outbid bidders in YITH Auctions. This system integrates with WordPress's native cron scheduler to process refunds on a recurring hourly basis.

### Key Characteristics

- **Trigger:** WordPress hourly cron event (`wp_schedule_event`)
- **Hook:** `wc_auction_process_refunds` (hourly)
- **Service:** `RefundSchedulerCronIntegration` (wrapper) + `RefundSchedulerService` (processor)
- **Execution Model:** Hourly batch processing (up to 50 refunds per hour)
- **Concurrency Control:** Transient-based locking (15-minute TTL)
- **Failure Handling:** Partial failure support (one failure doesn't block others)
- **Monitoring:** Built-in admin dashboard widget + action hooks

### Business Purpose

When an auction ends:
1. Winner's entry fee is **captured** from their payment authorization
2. Outbid bidders' entry fees are **scheduled for refund** (24 hours post-capture)
3. Hourly cron processes scheduled refunds (this component)
4. Refunds are **executed** via payment gateway
5. Bidders receive refund within 1-3 business days

---

## Architecture

### Component Diagram

```
WordPress Installation
  ├─ wp-cron.php (background process)
  │  └─ Runs every hour
  │     └─ Fires: do_action('wc_auction_process_refunds')
  │        └─ RefundSchedulerCronIntegration::processScheduledRefunds()
  │           ├─ Check concurrent lock (transient)
  │           ├─ Call RefundSchedulerService::processScheduledRefunds()
  │           │  ├─ Query SCHEDULED refunds where scheduled_for <= NOW()
  │           │  ├─ For each refund: processRefund()
  │           │  │  ├─ Get authorization details
  │           │  │  ├─ Call PaymentGateway::executeRefund()
  │           │  │  ├─ Update refund_schedule status
  │           │  │  └─ Send bidder notification
  │           │  └─ Return statistics
  │           ├─ Clear concurrent lock
  │           └─ Fire action hooks (complete/failed/error)
  └─ Admin Dashboard Widget
     ├─ Display cron status
     ├─ Show queue metrics
     ├─ Provide manual trigger
     └─ List failed refunds
```

### Data Flow Diagram: Complete Refund Lifecycle

```
BID PLACEMENT (T=0)
  └─ Entry fee AUTHORIZED (7-day hold)
     └─ Authorization stored with bid

AUCTION ENDS (T=7 days)
  └─ AuctionOutcomePaymentIntegration processes result
     ├─ Winner's authorization CAPTURED
     ├─ Create WooCommerce order
     └─ Outbid bidders' refunds SCHEDULED
        └─ INSERT into wp_wc_auction_refund_schedule
           status='SCHEDULED'
           scheduled_for=NOW() + 24 HOURS

REFUND PROCESSING (T=7 days + 24 hours, HOURLY)
  └─ RefundSchedulerCronIntegration::processScheduledRefunds()
     └─ Every hour (WordPress cron)
        ├─ Lock check (prevent concurrent execution)
        ├─ Query refunds WHERE
        │  status='SCHEDULED'
        │  AND scheduled_for<=NOW()
        │  LIMIT 50
        ├─ For each refund: processRefund()
        │  ├─ Get authorization (via authorization_id)
        │  ├─ Call PaymentGateway::executeRefund()
        │  ├─ Update status='COMPLETED' or 'FAILED'
        │  ├─ Update authorization status='REFUNDED'
        │  └─ Send email notification
        ├─ Update statistics
        └─ Fire action hooks
           └─ Integrations can listen and respond

BIDDER RECEIVES REFUND (T=7 days + 24 hours + 1-3 business days)
  └─ Refund appears in bidder's account
     (timing depends on payment method/bank)
```

### File Structure

```
src/
├─ Integration/
│  └─ RefundSchedulerCronIntegration.php (380 lines)
│     ├─ register() - Register WordPress cron
│     ├─ processScheduledRefunds() - Hourly handler
│     ├─ unschedule() - Plugin deactivation cleanup
│     ├─ getStatus() - Cron scheduling info
│     ├─ getStatistics() - Processing stats
│     ├─ manuallyTriggerProcessing() - Admin manual trigger
│     ├─ getQueueStatus() - Refund queue state
│     └─ retryFailedRefund() - Manual retry
│
├─ Services/EntryFees/
│  └─ RefundSchedulerService.php (466 lines, existing)
│     ├─ processScheduledRefunds() - Batch processor
│     ├─ processRefund() - Individual refund
│     ├─ notifyBidder() - Email notification
│     ├─ getProcessingStats() - Statistics
│     └─ retryFailedRefund() - Retry mechanism
│
├─ Admin/Dashboard/
│  └─ RefundProcessingStatusWidget.php (280 lines)
│     ├─ register() - Register dashboard widget
│     ├─ render() - Render widget HTML
│     ├─ renderCronStatus() - Cron scheduling section
│     ├─ renderQueueStatus() - Queue metrics section
│     ├─ renderStatistics() - Statistics section
│     └─ handleManualTrigger() - Manual processing trigger
│
├─ Notifications/
│  └─ RefundNotificationEmail.php (350 lines)
│     ├─ notifyBidderRefundComplete() - Successful refund email
│     ├─ notifyAdminRefundFailure() - Admin failure alert
│     └─ notifyAdminProcessingSummary() - Daily summary email
│
└─ Traits/
   └─ LoggerTrait.php (existing)
      └─ Provides structured logging

tests/
└─ unit/Integration/
   └─ RefundSchedulerCronIntegrationTest.php (300+ lines)
      ├─ test_register_adds_wordpress_action()
      ├─ test_process_scheduled_refunds_succeeds()
      ├─ test_process_scheduled_refunds_prevents_concurrent()
      ├─ test_unschedule_removes_cron()
      ├─ test_manually_trigger_processing_succeeds()
      └─ 12+ additional tests

docs/
└─ CRON_REFUND_PROCESSING_INTEGRATION.md (this file)
```

---

## Setup Instructions

### Prerequisites

**WordPress Environment:**
- WordPress 5.0 or later
- WP-Cron enabled (check `wp-config.php`)
- DISALLOW_FILE_MODS disabled (for cron scheduling)

**Database:**
- Table: `wp_wc_auction_refund_schedule` (created in Phase 4-C Step 2)
- Table: `wp_wc_auction_payment_authorizations` (created in Phase 4-B)
- Proper indexes on both tables

**PHP:**
- PHP 7.4 or later
- cURL extension (for payment gateway)
- JSON extension

**Payment Gateway:**
- Square account configured
- API credentials in environment variables
- API key with "REFUND" permission

### Step 1: Verify Prerequisites

```bash
# Check WP-Cron is enabled
grep "DISABLE_WP_CRON" wp-config.php
# Should return nothing or "false"

# Check for disabled file modifications
grep "DISALLOW_FILE_MODS" wp-config.php
# Should return nothing or "false"

# Verify database tables exist
mysql -u root -p database_name -e "SHOW TABLES LIKE 'wp_wc_auction%';"
# Should show both refund_schedule and payment_authorizations tables
```

### Step 2: Register Integration

In your plugin initialization file (after all services are set up):

```php
use YITHEA\Integration\RefundSchedulerCronIntegration;
use YITHEA\Services\EntryFees\RefundSchedulerService;

// Initialize service (already exists)
$refund_scheduler_service = new RefundSchedulerService($wpdb, $logger, $payment_gateway);

// Create cron integration wrapper
$cron_integration = new RefundSchedulerCronIntegration($refund_scheduler_service);

// Register cron hooks (one-time, usually in plugin activation)
$cron_integration->register();
```

### Step 3: Register Dashboard Widget

```php
use YITHEA\Admin\Dashboard\RefundProcessingStatusWidget;

// Create widget instance
$dashboard_widget = new RefundProcessingStatusWidget($cron_integration);

// Register with WordPress
add_action('plugins_loaded', function() use ($dashboard_widget) {
    $dashboard_widget->register();
});
```

### Step 4: Setup Email Notifications (Optional)

```php
use YITHEA\Notifications\RefundNotificationEmail;

// Create email service
$email_service = new RefundNotificationEmail();

// Use as callback in RefundSchedulerService
$callback = function($user_id, $amount_cents, $refund_id) use ($email_service) {
    $email_service->notifyBidderRefundComplete($user_id, $amount_cents, $refund_id);
};

// Pass to service
$refund_scheduler_service->setNotificationCallback($callback);
```

### Step 5: Test Registration

```bash
# Access WordPress dashboard
# Navigate to: Dashboard > Auction Refund Processing Status
# Verify widget displays with "Cron Status: Active"
# Check "Next Run" time is within the hour

# Or test via WP-CLI
wp cron test
# Should return: Success: Executed a total of X crons.

# Check if our cron is scheduled
wp cron event list | grep wc_auction_process_refunds
# Should show hourly schedule
```

---

## Configuration

### Environment Variables

Set in your `.env` file or `wp-config.php`:

```php
// Payment gateway configuration (if not already set)
define('SQUARE_APPLICATION_ID', 'YOUR_APP_ID');
define('SQUARE_ACCESS_TOKEN', 'YOUR_ACCESS_TOKEN');

// Logger configuration
define('YITH_AUCTION_LOG_LEVEL', 'info'); // debug|info|warning|error

// Cron configuration (advanced)
define('YITH_AUCTION_CRON_BATCH_SIZE', 50);      // Max refunds per hour
define('YITH_AUCTION_CRON_LOCK_TTL', 900);       // Lock timeout (seconds)
define('YITH_AUCTION_REFUND_DELAY_HOURS', 24);   // Delay before refunding

// Email configuration
define('YITH_AUCTION_ENABLE_REFUND_EMAILS', true);
define('YITH_AUCTION_REFUND_EMAIL_ADMINS', true);
```

### Database Configuration

**Verify Indexes for Cron Query Performance:**

```sql
-- These should already exist from Step 2, but verify:

-- Primary query index (cron reads this every hour)
SELECT * FROM information_schema.statistics
WHERE table_name = 'wp_wc_auction_refund_schedule'
AND column_name IN ('status', 'scheduled_for');

-- Should have composite index on (status, scheduled_for)
-- If missing, create it:
ALTER TABLE wp_wc_auction_refund_schedule
ADD INDEX idx_cron_query (status, scheduled_for);
```

### Action Hooks

Developers can hook into the cron process:

```php
// Hook 1: After successful processing
add_action('wc_auction_refund_processing_complete', function($result) {
    // $result['processed_count']
    // $result['failed_count']
    // $result['total_refunded_cents']
    // Custom logging, webhooks, etc.
    error_log('Refunds processed: ' . $result['processed_count']);
});

// Hook 2: When some refunds fail
add_action('wc_auction_refund_processing_failed', function($failed_count) {
    // Alert monitoring system, create support tickets, etc.
    send_alert("$failed_count refunds failed");
});

// Hook 3: On critical exception
add_action('wc_auction_refund_processing_error', function(Exception $e) {
    // Handle critical errors
    error_log('Critical refund error: ' . $e->getMessage());
});
```

---

## Monitoring & Dashboard

### Admin Dashboard Widget

Access: **WP Admin Dashboard > Auction Refund Processing Status**

**Display includes:**

1. **Cron Status Section**
   - Status badge (Active/Inactive/Overdue)
   - Hook name: `wc_auction_process_refunds`
   - Interval: `hourly`
   - Next run timestamp

2. **Refund Queue Status**
   - Scheduled: Count of refunds waiting
   - Processing: Currently being refunded
   - Completed: Successfully refunded
   - Failed: Requiring attention

3. **Processing Statistics**
   - Total Pending refunds
   - Total Processed (all-time)
   - Total Failed (all-time)
   - Success Rate percentage
   - Average Processing Time (milliseconds)

4. **Failed Refunds Section** (if any)
   - Count of failed refunds
   - Link to manage failed refunds
   - Quick retry option

5. **Action Buttons**
   - **Force Refund Processing Now**: Manually trigger processing
   - Useful when you need refunds processed before next hourly cron

### Monitoring Queries

**Check Queue Status:**

```sql
-- Count refunds by status
SELECT
    status,
    COUNT(*) as count,
    MIN(created_at) as oldest,
    MAX(created_at) as newest
FROM wp_wc_auction_refund_schedule
GROUP BY status
ORDER BY status;

-- Check for overdue refunds (should have been processed)
SELECT
    id,
    user_id,
    amount_cents,
    scheduled_for,
    TIMESTAMPDIFF(HOUR, scheduled_for, NOW()) as hours_overdue
FROM wp_wc_auction_refund_schedule
WHERE status = 'SCHEDULED'
AND scheduled_for < DATE_SUB(NOW(), INTERVAL 25 HOUR)
ORDER BY scheduled_for ASC;

-- Check failed refunds
SELECT
    id,
    user_id,
    amount_cents,
    error_message,
    retry_count,
    processed_at
FROM wp_wc_auction_refund_schedule
WHERE status = 'FAILED'
ORDER BY processed_at DESC
LIMIT 20;
```

**Check Cron Status:**

```bash
# via WP-CLI
wp cron event list
# Should show: wc_auction_process_refunds | hourly | 2024-01-15 10:00:00

# Check next scheduled time (Unix timestamp)
wp cron test --verbose

# View all scheduled events
wp cron event list --format=table
```

### Logging

Logs are written to `wp-content/debug.log` (if `WP_DEBUG_LOG` enabled):

```
[2024-01-15 09:00:00] Refund Processing: Starting batch (25 refunds scheduled)
[2024-01-15 09:00:15] Refund Processing: Processed 24 refunds, 1 failed
[2024-01-15 09:00:15] Refund Processing: Total refunded: $1,200.00
```

Access logs programmatically:

```php
// From RefundSchedulerCronIntegration
$logs = $cron_integration->getProcessingLogs();
// Returns array of recent log entries
```

---

## Troubleshooting

### Issue 1: Cron Not Running

**Symptoms:**
- Refunds remain in "SCHEDULED" status after 24+ hours
- Dashboard widget shows "Status: Inactive"
- No entries in error log

**Diagnosis:**

```bash
# Check if WP-Cron is enabled
grep "DISABLE_WP_CRON" wp-config.php

# If "true", cron is disabled. Change to:
define('DISABLE_WP_CRON', false);

# Test if WordPress is calling cron
curl -s "https://yoursite.com/wp-cron.php?doing_wp_cron=1" -I
# Should return HTTP 200

# Check if our cron is scheduled
wp cron event list | grep wc_auction_process_refunds
# If not shown, re-register in plugin
```

**Solution:**

1. Enable WP-Cron: Set `DISABLE_WP_CRON` to `false` in `wp-config.php`
2. Re-register integration:
   ```php
   $cron_integration->register();
   wp_cache_flush(); // Clear cache
   ```
3. Test immediately:
   ```php
   do_action('wc_auction_process_refunds');
   ```

### Issue 2: Refunds Processed Twice

**Symptoms:**
- Bidders report duplicate refunds
- Refund count spikes suddenly
- Payment gateway reports duplicate refund requests

**Diagnosis:**

```bash
# Check for concurrent execution lock
wp transient get wc_auction_refund_processing_lock
# If returns a value, lock is active

# Check refund status after lock expires (15 min)
sleep 900
mysql -e "SELECT COUNT(*) FROM wp_wc_auction_refund_schedule WHERE status = 'COMPLETED';"
```

**Solution:**

Concurrency is prevented by transient locking. If issue persists:

1. Clear lock manually:
   ```php
   delete_transient('wc_auction_refund_processing_lock');
   ```

2. Check payment gateway for duplicate refund requests:
   - Square: Idempotency checking should prevent duplicates
   - Verify API requests aren't being retried

3. Monitor database:
   ```sql
   -- Find duplicate refunds (same bid, multiple records)
   SELECT bid_id, COUNT(*) as count
   FROM wp_wc_auction_refund_schedule
   WHERE status = 'COMPLETED'
   GROUP BY bid_id
   HAVING count > 1;
   ```

### Issue 3: Refund Payment Gateway Errors

**Symptoms:**
- Dashboard shows "Failed: 5"
- Error messages: "Invalid authorization" or "Network timeout"
- Logs show payment gateway errors

**Diagnosis:**

```sql
-- Check error messages
SELECT
    id,
    error_message,
    bid_id,
    processed_at
FROM wp_wc_auction_refund_schedule
WHERE status = 'FAILED'
ORDER BY processed_at DESC
LIMIT 10;

-- Categorize errors
SELECT
    SUBSTRING_INDEX(error_message, ':', 1) as error_type,
    COUNT(*) as count
FROM wp_wc_auction_refund_schedule
WHERE status = 'FAILED'
GROUP BY error_type;
```

**Common Errors & Solutions:**

| Error | Cause | Solution |
|-------|-------|----------|
| "Invalid authorization ID" | Authorization expired or deleted | Check authorization_id in database; may need to manually refund |
| "Authorization already refunded" | Refund already processed once | Verify refund_schedule status; may be duplicate |
| "Network timeout" | Payment gateway unreachable | Retry later; automatic retry in next cron cycle |
| "Insufficient funds" | Refund amount > authorization amount | Verify authorization capture amount matches |
| "API key invalid" | Credentials error | Check SQUARE_ACCESS_TOKEN in environment |

**Solution Steps:**

1. Verify environment credentials:
   ```bash
   echo $SQUARE_ACCESS_TOKEN
   # Should output non-empty token
   ```

2. Manually retry failed refunds:
   ```php
   $result = $cron_integration->retryFailedRefund(123); // Pass refund_schedule ID
   ```

3. For gateway issues, check payment processor status:
   - https://status.square.com (for Square)
   - Verify API endpoint URLs are correct

### Issue 4: High Processing Time / Performance

**Symptoms:**
- Dashboard shows "Avg Processing Time: 5000+ ms"
- Cron lock not clearing properly
- Some refunds skipped in queue

**Diagnosis:**

```sql
-- Check database query performance
EXPLAIN SELECT * FROM wp_wc_auction_refund_schedule
WHERE status = 'SCHEDULED' AND scheduled_for <= NOW()
ORDER BY scheduled_for ASC LIMIT 50;

-- Should show "Using index" for both conditions

-- Check for missing indexes
SHOW INDEX FROM wp_wc_auction_refund_schedule;
-- Look for idx_status and idx_scheduled_for
```

**Solution:**

1. Add missing indexes:
   ```sql
   ALTER TABLE wp_wc_auction_refund_schedule
   ADD INDEX idx_cron_performance (status, scheduled_for);
   ```

2. Reduce batch size if network is slow:
   ```php
   define('YITH_AUCTION_CRON_BATCH_SIZE', 20); // Reduced from 50
   ```

3. Check payment gateway latency:
   ```php
   // Add timing to logs
   $start = microtime(true);
   $result = $payment_gateway->executeRefund(...);
   $duration = (microtime(true) - $start) * 1000;
   error_log("Gateway refund took $duration ms");
   ```

### Issue 5: Email Notifications Not Sent

**Symptoms:**
- Bidders don't receive refund confirmation emails
- Admin notifications not arriving
- Email logs show no errors

**Diagnosis:**

```bash
# Check if WordPress mail is configured
wp eval 'echo get_option("admin_email");'

# Test WordPress mail function
wp eval 'wp_mail("test@example.com", "Test", "Test message");'
# Should return true and possibly show email sent
```

**Solution:**

1. Verify SMTP configuration:
   - Check `wp-config.php` for mail settings
   - Test with wp-mail plugin (Mailgun, SendGrid, etc.)

2. Enable email notifications (if disabled):
   ```php
   define('YITH_AUCTION_ENABLE_REFUND_EMAILS', true);
   ```

3. Pass callback to service:
   ```php
   $refund_service->setNotificationCallback(function($user_id, $amount, $refund_id) {
       $email_service->notifyBidderRefundComplete($user_id, $amount, $refund_id);
   });
   ```

---

## Performance & Optimization

### Database Optimization

**Current Indexes:**

```sql
-- These should exist (created in Step 2)
CREATE INDEX idx_status ON wp_wc_auction_refund_schedule(status);
CREATE INDEX idx_scheduled_for ON wp_wc_auction_refund_schedule(scheduled_for);
CREATE INDEX idx_user_id ON wp_wc_auction_refund_schedule(user_id);
CREATE INDEX idx_created_at ON wp_wc_auction_refund_schedule(created_at);
CREATE INDEX idx_auction_id ON wp_wc_auction_refund_schedule(auction_id);
```

**Performance Metrics:**

Query used by cron (runs hourly):
```sql
SELECT * FROM wp_wc_auction_refund_schedule
WHERE status = 'SCHEDULED' AND scheduled_for <= NOW()
ORDER BY scheduled_for ASC
LIMIT 50;
```

**Expected Performance:**
- **With indexes:** < 50ms
- **Without indexes:** > 2000ms for 10,000+ records
- **Batch of 50 refunds:** ~500-1000ms total (including payment gateway)

**Optimization Tips:**

1. **Archive old records** (monthly):
   ```sql
   -- Move completed refunds > 90 days old to archive table
   INSERT INTO wp_wc_auction_refund_schedule_archive
   SELECT * FROM wp_wc_auction_refund_schedule
   WHERE status = 'COMPLETED' AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);

   DELETE FROM wp_wc_auction_refund_schedule
   WHERE status = 'COMPLETED' AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
   ```

2. **Monitor table size**:
   ```sql
   SELECT
       TABLE_NAME,
       ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb
   FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'wp_wc_auction_refund_schedule';
   ```

3. **Analyze queries**:
   ```bash
   # Enable query logging in wp-config.php
   define('SAVEQUERIES', true);
   
   # Review in $GLOBALS['wpdb']->queries
   ```

### Cron Execution Timing

**Default:** Once per hour (WordPress hourly interval)

**Optimization for High Volume:**

If processing > 100 refunds/hour, consider:

1. **Increase frequency** (if hosting supports):
   ```php
   // Schedule every 15 minutes instead of hourly
   wp_schedule_event(time(), '15_minutes', 'wc_auction_process_refunds');
   
   // Need to register 15-minute interval first
   add_filter('cron_schedules', function($schedules) {
       $schedules['15_minutes'] = [
           'interval' => 15 * 60,
           'display' => 'Every 15 Minutes'
       ];
       return $schedules;
   });
   ```

2. **Use external cron** (recommended for high volume):
   ```bash
   # Replace WP-Cron with system cron
   # In wp-config.php:
   define('DISABLE_WP_CRON', true);
   
   # Add to server crontab:
   * * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron=1 > /dev/null 2>&1
   ```

### Concurrent Execution Prevention

**Lock Duration:** 15 minutes (transient TTL)

If processing takes longer than lock duration:

```php
// In RefundSchedulerCronIntegration, adjust TTL:
private const LOCK_DURATION = 30 * 60; // 30 minutes instead of 900 seconds
```

---

## Manual Intervention

### Force Refund Processing

**Via Dashboard Widget:**
1. Go to WP Admin Dashboard
2. Find "Auction Refund Processing Status" widget
3. Click "Force Refund Processing Now" button
4. Confirm and wait for result

**Programmatically:**

```php
$result = $cron_integration->manuallyTriggerProcessing();

// Returns:
[
    'status' => 'SUCCESS',
    'message' => 'Processed 15 refunds',
    'stats' => [
        'processed_count' => 15,
        'failed_count' => 0,
        'total_refunded_cents' => 75000
    ]
]
```

### Retry Failed Refund

**Via Admin Panel:**
1. Dashboard > Auction Refund Processing Status
2. Click "View Failed Refunds"
3. Select refund and click "Retry"

**Programmatically:**

```php
$result = $cron_integration->retryFailedRefund(123); // Pass record ID

// Returns success/failure result
if ($result['status'] === 'SUCCESS') {
    echo "Refund retried successfully";
} else {
    echo "Retry failed: " . $result['error'];
}
```

### Unschedule Cron (Plugin Deactivation)

```php
// Automatically called on plugin deactivation
$cron_integration->unschedule();

// Manually:
$cron_integration->unschedule();
wp_clear_scheduled_hook('wc_auction_process_refunds');
```

---

## Database Queries

### Status Reporting Queries

```sql
-- Daily processing summary
SELECT
    DATE(processed_at) as date,
    status,
    COUNT(*) as count,
    SUM(amount_cents) / 100 as total_amount
FROM wp_wc_auction_refund_schedule
WHERE processed_at IS NOT NULL
GROUP BY DATE(processed_at), status
ORDER BY date DESC, status;

-- Success rate by day
SELECT
    DATE(processed_at) as date,
    COUNT(CASE WHEN status = 'COMPLETED' THEN 1 END) as completed,
    COUNT(CASE WHEN status = 'FAILED' THEN 1 END) as failed,
    ROUND(100 * COUNT(CASE WHEN status = 'COMPLETED' THEN 1 END) /
        COUNT(*), 2) as success_rate_percent
FROM wp_wc_auction_refund_schedule
WHERE processed_at IS NOT NULL
GROUP BY DATE(processed_at)
ORDER BY date DESC;

-- Refund age distribution
SELECT
    CASE
        WHEN TIMESTAMPDIFF(MINUTE, scheduled_for, NOW()) < 60 THEN '< 1 hour'
        WHEN TIMESTAMPDIFF(HOUR, scheduled_for, NOW()) < 24 THEN '1-24 hours'
        WHEN TIMESTAMPDIFF(DAY, scheduled_for, NOW()) < 7 THEN '1-7 days'
        ELSE '> 7 days'
    END as age_bucket,
    COUNT(*) as count
FROM wp_wc_auction_refund_schedule
WHERE status = 'SCHEDULED'
GROUP BY age_bucket;
```

### Refund Investigation Queries

```sql
-- Find user's refund history
SELECT
    r.id,
    r.bid_id,
    r.amount_cents / 100 as amount,
    r.status,
    r.scheduled_for,
    r.processed_at,
    r.refund_id,
    r.error_message
FROM wp_wc_auction_refund_schedule r
WHERE r.user_id = 42
ORDER BY r.created_at DESC;

-- Find refunds for specific auction
SELECT
    r.id,
    r.user_id,
    u.user_email,
    r.amount_cents / 100 as amount,
    r.status,
    r.retry_count
FROM wp_wc_auction_refund_schedule r
LEFT JOIN wp_users u ON r.user_id = u.ID
WHERE r.auction_id = 123
ORDER BY r.created_at DESC;

-- Find problematic authorizations
SELECT
    a.id,
    a.user_id,
    a.amount_cents / 100 as amount,
    COUNT(r.id) as refund_attempts,
    r.status,
    a.status as auth_status
FROM wp_wc_auction_payment_authorizations a
LEFT JOIN wp_wc_auction_refund_schedule r ON a.id = r.authorization_id
WHERE a.status = 'AUTHORIZED'
GROUP BY a.id
HAVING COUNT(r.id) > 0;
```

---

## Edge Cases & Best Practices

### Edge Case 1: Authorization Expires Before Refund

**Scenario:** 7-day authorization granted, but 24+ hour delay means refund attempts after expiry

**Handling:**
- Authorization expiration checked in payment gateway
- If expired: status = 'FAILED', error = "Authorization expired"
- Manual refund via PayPal, Stripe dashboard, etc.
- Bidder manual refund from admin panel

**Best Practice:**
```php
// Check authorization age before scheduling refund
$auth_age_days = (time() - $authorization->created_at) / (24*3600);
if ($auth_age_days > 6) {
    // Authorization expires soon, process immediately (don't wait 24h)
    $refund->scheduled_for = now();
}
```

### Edge Case 2: Multiple Bids Same Auction (Outbid Multiple Times)

**Scenario:** User bids twice, outbid both times - two separate refunds

**Handling:**
- Each bid ID creates separate refund record
- Both process independently in cron
- User receives two separate notifications

**Best Practice:**
- Aggregate notifications: "2 refunds ($50 total) processed"
- Combine emails if multiple refunds same day

### Edge Case 3: Payment Gateway Timeout During Batch

**Scenario:** Cron processes 40 of 50 refunds, then gateway times out

**Handling:**
- Incomplete refunds remain with status = 'SCHEDULED'
- Next hourly cron retries them
- Retry count incremented

**Best Practice:**
```sql
-- Monitor failed refunds with high retry count
SELECT *
FROM wp_wc_auction_refund_schedule
WHERE status = 'FAILED' AND retry_count > 5
ORDER BY updated_at DESC;
```

### Edge Case 4: Cron Runs During Plugin Update

**Scenario:** Plugin deactivated mid-cron-execution for update

**Handling:**
- Lock released after 15 minutes (transient expires)
- Next cron after update completes normally
- In-progress refunds may not complete until retry

**Best Practice:**
- Schedule maintenance windows outside peak bidding times
- Test plugin updates on staging environment

### Best Practices Summary

1. **Monitor regularly:**
   - Check dashboard widget daily
   - Review database metrics weekly
   - Archive old records monthly

2. **Error handling:**
   - Set up admin alerts for failed refunds
   - Implement retry strategy for transient errors
   - Manual review for persistent failures

3. **Performance:**
   - Maintain database indexes
   - Archive completed refunds > 90 days
   - Monitor query times and payment gateway latency

4. **Communication:**
   - Notify bidders of refund status
   - Alert admins to issues needing manual intervention
   - Document manual actions taken

5. **Testing:**
   - Test full lifecycle: bid → auction → refund
   - Test error scenarios: gateway timeout, expired auth
   - Load test: 100+ concurrent bids, refunds
   - Staging validation before production deployment

---

## Conclusion

The Cron Refund Processing Integration provides a reliable, scalable system for automated refund processing. Regular monitoring, proper configuration, and adherence to best practices ensure smooth operations and high success rates.

**Key Takeaways:**
- ✅ Hourly cron processes scheduled refunds automatically
- ✅ Concurrent execution prevented via transient locking
- ✅ Admin dashboard provides visibility and manual control
- ✅ Email notifications keep bidders and admins informed
- ✅ Comprehensive error handling and logging for troubleshooting
- ✅ Manual retry mechanism for failed refunds

For questions or issues, consult the Troubleshooting section or contact the development team.
