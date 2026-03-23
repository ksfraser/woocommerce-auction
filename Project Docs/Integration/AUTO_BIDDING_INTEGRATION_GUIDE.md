## Auto-Bidding System Integration Guide

**Requirements**: REQ-AUTO-BID-SERVICE-001, REQ-AUTO-BID-ENGINE-001, REQ-AUTO-BID-REPO-001

This guide explains how to integrate the proxy bidding system into the YITH Auctions plugin.

---

## Architecture Overview

The auto-bidding system consists of three main layers:

```
Presentation Layer
    ↓ (AJAX requests, REST API)
Business Logic Layer: AutoBidService
    ├── Orchestrates workflows
    ├── Validates business rules
    └── Records audit history
    ↓ (Dependencies)
Algorithm Layer: ProxyBiddingEngine
    ├── Bidding algorithm
    ├── Validation rules
    └── Increment calculation
Data Access Layer: AutoBidRepository
    ├── Database operations
    ├── History recording
    └── Query optimization
```

---

## Installation & Setup

### 1. Database Migration

The auto-bidding system requires three database tables. Run migrations on plugin activation:

**File**: `includes/Migrations/CreateAutoAuctionTables.php`

```php
// Migration runs automatically on plugin activation
if (!get_option('yith_wcact_auto_bidding_migrated')) {
    $migration = new CreateAutoAuctionTables();
    $migration->migrate();
    update_option('yith_wcact_auto_bidding_migrated', '1');
}
```

### 2. Service Registration

Register services in the plugin's initialization:

**File**: `includes/Services/ServiceContainer.php`

```php
// Register auto-bidding services
$this->register('auto_bid.engine', function() {
    return new ProxyBiddingEngine($this->get('increment_calculator'));
});

$this->register('auto_bid.repository', function() {
    return new AutoBidRepository($this->get('db'));
});

$this->register('auto_bid.service', function() {
    return new AutoBidService(
        $this->get('auto_bid.repository'),
        $this->get('bid_queue'),
        $this->get('auto_bid.engine'),
        $this->get('increment_calculator')
    );
});
```

### 3. Hook Registration

Register action and filter hooks:

**File**: `includes/Hooks/AutoBiddingHooks.php`

```php
class AutoBiddingHooks {
    private $service;

    public function register() {
        // When a new bid is placed
        add_action(
            'yith_wcact_bid_placed',
            [$this, 'onBidPlaced'],
            10,
            3
        );

        // When auction transitions
        add_action(
            'yith_wcact_auction_status_changed',
            [$this, 'onAuctionStatusChanged'],
            10,
            2
        );

        // When auction ends
        add_action(
            'yith_wcact_auction_ended',
            [$this, 'onAuctionEnded'],
            10,
            1
        );

        // Public hooks for plugins
        do_action('yith_wcact_auto_bidding_ready', $this->service);
    }

    public function onBidPlaced($auction_id, $bid, $user_id) {
        // Process auto-bids when someone places a bid
        $this->service->processOutbid($auction_id, $bid, 'ACTIVE');
    }

    public function onAuctionStatusChanged($auction_id, $new_status) {
        if ($new_status === 'ended') {
            // Clean up auto-bids when auction ends
            $this->completeAutoBids($auction_id);
        }
    }

    public function onAuctionEnded($auction_id) {
        // Final cleanup
        $this->recordAuctionCompletion($auction_id);
    }
}
```

---

## API Usage

### For Administrators

#### Set Auto-Bid (Programmatically)

```php
$auto_bid_service = wcact_get_service('auto_bid.service');

$auto_bid_id = $auto_bid_service->setAutoBid(
    auction_id: 123,
    user_id: 456,
    maximum_bid: 100.00
);

// $auto_bid_id is a UUID for tracking
```

#### Cancel Auto-Bid

```php
$success = $auto_bid_service->cancelAutoBid($auto_bid_id);

if (!$success) {
    error_log('Failed to cancel auto-bid');
}
```

#### Get Auto-Bid Details

```php
$auto_bid = $auto_bid_service->getAutoBid($auto_bid_id);

echo $auto_bid['status'];        // Current status
echo $auto_bid['maximum_bid'];   // Maximum bidding limit
echo $auto_bid['created_at'];    // When created
```

### For Frontend

#### Set Auto-Bid via AJAX

**Endpoint**: `/wp-admin/admin-ajax.php?action=wcact_set_auto_bid`

```javascript
// frontend.js
jQuery(function($) {
    $('#set-auto-bid-form').on('submit', function(e) {
        e.preventDefault();
        
        const auctionId = $(this).data('auction-id');
        const maximumBid = $('#maximum-bid').val();
        
        $.ajax({
            url: wcact.ajaxurl,
            type: 'POST',
            data: {
                action: 'wcact_set_auto_bid',
                auction_id: auctionId,
                maximum_bid: maximumBid,
                nonce: wcact.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Auto-bid set successfully!');
                    location.reload();
                }
            },
            error: function() {
                alert('Failed to set auto-bid');
            }
        });
    });
});
```

**Handler**: `includes/AJAX/AutoBiddingAjax.php`

```php
class AutoBiddingAjax {
    public function registerActions() {
        add_action(
            'wp_ajax_wcact_set_auto_bid',
            [$this, 'setAutoBid']
        );
        add_action(
            'wp_ajax_wcact_cancel_auto_bid',
            [$this, 'cancelAutoBid']
        );
        add_action(
            'wp_ajax_wcact_get_auto_bid_history',
            [$this, 'getHistory']
        );
    }

    public function setAutoBid() {
        check_ajax_referer('wcact_security');

        $auction_id = intval($_POST['auction_id']);
        $maximum_bid = floatval($_POST['maximum_bid']);
        $user_id = get_current_user_id();

        try {
            $auto_bid_id = $this->service->setAutoBid(
                $auction_id,
                $user_id,
                $maximum_bid
            );

            wp_send_json_success([
                'auto_bid_id' => $auto_bid_id,
                'message' => __('Auto-bid set successfully', 'yith-auctions'),
            ]);
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function cancelAutoBid() {
        check_ajax_referer('wcact_security');

        $auto_bid_id = sanitize_text_field($_POST['auto_bid_id']);
        $user_id = get_current_user_id();

        try {
            // Verify ownership
            $auto_bid = $this->service->getAutoBid($auto_bid_id);
            if ($auto_bid['user_id'] !== $user_id) {
                throw new \Exception('Unauthorized');
            }

            $this->service->cancelAutoBid($auto_bid_id);

            wp_send_json_success([
                'message' => __('Auto-bid cancelled', 'yith-auctions'),
            ]);
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function getHistory() {
        check_ajax_referer('wcact_security');

        $auto_bid_id = sanitize_text_field($_POST['auto_bid_id']);
        $user_id = get_current_user_id();

        try {
            $auto_bid = $this->service->getAutoBid($auto_bid_id);
            if ($auto_bid['user_id'] !== $user_id) {
                throw new \Exception('Unauthorized');
            }

            $history = $this->service->getHistory($auto_bid_id, 100);

            wp_send_json_success([
                'events' => $history,
            ]);
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }
}
```

---

## REST API Integration

### Endpoint: Create Auto-Bid

**Route**: `POST /wp-json/yith-auctions/v1/auto-bids`

```php
$response = wp_remote_post(
    site_url('/wp-json/yith-auctions/v1/auto-bids'),
    [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $jwt_token,
        ],
        'body' => json_encode([
            'auction_id' => 123,
            'maximum_bid' => 100.00,
        ]),
    ]
);

$auto_bid = json_decode(wp_remote_retrieve_body($response), true);
// Returns: { id, status, maximum_bid, created_at, ... }
```

### Endpoint: Get Auto-Bid

**Route**: `GET /wp-json/yith-auctions/v1/auto-bids/{id}`

```php
$response = wp_remote_get(
    site_url('/wp-json/yith-auctions/v1/auto-bids/uuid-123'),
    [
        'headers' => ['Authorization' => 'Bearer ' . $jwt_token],
    ]
);

$auto_bid = json_decode(wp_remote_retrieve_body($response), true);
```

### Endpoint: Cancel Auto-Bid

**Route**: `DELETE /wp-json/yith-auctions/v1/auto-bids/{id}`

```php
$response = wp_remote_request(
    site_url('/wp-json/yith-auctions/v1/auto-bids/uuid-123'),
    [
        'method' => 'DELETE',
        'headers' => ['Authorization' => 'Bearer ' . $jwt_token],
    ]
);
```

---

## State Transitions

### Valid Status Transitions

```
ACTIVE
├─→ COMPOSED (when auto-bid places counter-bid)
│   ├─→ ACTIVE (when counter-bid fails)
│   └─→ COMPLETED (when counter-bid succeeds)
├─→ CANCELLED (when user cancels)
└─→ FAILED (when error occurs)

COMPOSED
├─→ ACTIVE (counter-bid failed)
├─→ COMPLETED (counter-bid placed)
└─→ FAILED (unexpected error)

CANCELLED, COMPLETED, FAILED (terminal states)
```

### State Transition Logic

```php
class AutoBidStatus {
    // Active states (can still be cancelled)
    public const ACTIVE = 'ACTIVE';
    public const COMPOSED = 'COMPOSED'; // Temporary state during bidding

    // Terminal states
    public const COMPLETED = 'COMPLETED';
    public const CANCELLED = 'CANCELLED';
    public const FAILED = 'FAILED';

    public static function isTerminal(string $status): bool {
        return in_array($status, [
            self::COMPLETED,
            self::CANCELLED,
            self::FAILED,
        ]);
    }

    public static function canTransitionTo(
        string $from,
        string $to
    ): bool {
        $valid_transitions = [
            self::ACTIVE => [self::COMPOSED, self::CANCELLED, self::FAILED],
            self::COMPOSED => [self::ACTIVE, self::COMPLETED, self::FAILED],
            self::COMPLETED => [],
            self::CANCELLED => [],
            self::FAILED => [],
        ];

        return in_array($to, $valid_transitions[$from] ?? []);
    }
}
```

---

## Bidding Workflow

### Step 1: User Sets Auto-Bid

```
User submits: "Maximum bid = $100"
        ↓
AutoBidService::setAutoBid()
        ↓
Repository creates auto_bids record
        ↓
History event: AUTO_BID_CREATED
        ↓
Status: ACTIVE
```

### Step 2: Competitor Places Bid

```
Competitor bids $50
        ↓
Hook: yith_wcact_bid_placed fires
        ↓
AutoBidService::processOutbid()
        ↓
Get auto_bid for user
        ↓
Engine: shouldPlaceCounterBid? → YES
        ↓
Engine: calculateProxyBid($50) → $50.75
        ↓
Status: COMPOSED (temporary)
        ↓
BidQueue::enqueue(counter_bid)
        ↓
History: PROXY_BID_QUEUED
```

### Step 3: Counter-Bid Processes (Async)

```
Queue processes bid
        ↓
PlaceBidCommand executes
        ↓
Success?
├─→ YES: Status ACTIVE (ready for next outbid)
│        History: PROXY_BID_PLACED
│        notify_user('Your auto-bid won at $50.75')
│
└─→ NO:  Status FAILED
         History: PROXY_BID_FAILED
         notify_user('Auto-bid failed')
```

### Step 4: Auction Ends

```
Auction end time reached
        ↓
Hook: yith_wcact_auction_ended fires
        ↓
Get all active auto_bids for auction
        ↓
For each auto_bid:
    If user won:
        Status → COMPLETED
        History: AUTO_BID_WON
        notify_user('You won at $X')
    Else:
        Status → COMPLETED
        History: AUTO_BID_LOST
```

---

## Error Handling

### Expected Exceptions

```php
// In AutoBidService

// Duplicate auto-bid exists
throw new \InvalidArgumentException(
    'Auto-bid already exists for this auction and user'
);

// Cannot update terminal auto-bid
throw new \InvalidArgumentException(
    'Cannot update completed or cancelled auto-bid'
);

// Cannot decrease maximum
throw new \InvalidArgumentException(
    'Maximum can only be increased, not decreased'
);

// Auction not found or ended
throw new \DomainException(
    'Auction is not active'
);

// Insufficient permissions
throw new \RuntimeException(
    'User not authorized to modify auto-bid'
);
```

### Error Recovery

```php
try {
    $auto_bid_id = $service->setAutoBid($auction_id, $user_id, $max);
} catch (\InvalidArgumentException $e) {
    // User error - display to user
    wp_send_json_error(['message' => $e->getMessage()]);
} catch (\DomainException $e) {
    // Business logic error
    wp_send_json_error(['message' => $e->getMessage()]);
} catch (\Exception $e) {
    // Unexpected error - log and escalate
    error_log('Auto-bid error: ' . $e->getMessage());
    wp_send_json_error(['message' => 'An unexpected error occurred']);
}
```

---

## Performance Optimization

### Database Indexes

The migration creates these indexes:

```sql
-- Speed lookups by auction
CREATE INDEX idx_auto_bids_auction ON auto_bids(auction_id);

-- Speed lookups by user
CREATE INDEX idx_auto_bids_user ON auto_bids(user_id);

-- Speed active lookups
CREATE INDEX idx_auto_bids_status ON auto_bids(status);

-- Speed history queries
CREATE INDEX idx_auto_bid_history_auto_bid 
    ON auto_bid_history(auto_bid_id, created_at);
```

### Query Optimization

✅ **DO:**
```php
// Get active auto-bids for auction
$auto_bids = $repository->getActiveForAuction($auction_id);

// Uses optimized query:
// SELECT * FROM auto_bids 
// WHERE auction_id = ? AND status = 'ACTIVE'
// (indexed for speed)
```

❌ **DON'T:**
```php
// Get ALL auto-bids then filter in PHP
$all_bids = $repository->getAll();
$filtered = array_filter($all_bids, function($bid) {
    return $bid['auction_id'] === $auction_id 
        && $bid['status'] === 'ACTIVE';
});
// Very slow for large datasets!
```

### Caching Strategy

```php
// Cache active auto-bids during auction
$cache_key = "wcact_active_auto_bids_{$auction_id}";
$auto_bids = wp_cache_get($cache_key);

if (false === $auto_bids) {
    $auto_bids = $repository->getActiveForAuction($auction_id);
    wp_cache_set($cache_key, $auto_bids, 'yith_wcact', 60); // 60 seconds
}

// Invalidate cache when bid placed
add_action('yith_wcact_bid_placed', function($auction_id) {
    wp_cache_delete("wcact_active_auto_bids_{$auction_id}");
});
```

---

## Monitoring & Debugging

### Enable Debug Logging

**File**: `wp-config.php`

```php
// Enable WordPress debugging
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_LOCATION', '/var/log/wordpress-debug.log');

// Plugin-specific debug
define('YITH_WCACT_DEBUG', true);
```

### Log Auto-Bidding Events

```php
// In AutoBidService

private function logEvent(string $event, array $context = []): void {
    if (!defined('YITH_WCACT_DEBUG') || !YITH_WCACT_DEBUG) {
        return;
    }

    $message = sprintf(
        '[Auto-Bid] %s | Context: %s',
        $event,
        json_encode($context)
    );

    error_log($message);
}

// Usage
$this->logEvent('AUTO_BID_PLACED', [
    'auto_bid_id' => $auto_bid_id,
    'user_id' => $user_id,
    'maximum_bid' => $max_bid,
]);
```

### Monitor Database Performance

```php
// Check slow queries
SELECT * FROM wp_options 
WHERE option_name LIKE '%auto_bid%'
AND option_value LIKE '%slow%';

// Check stats
SELECT 
    COUNT(*) as total_auto_bids,
    COUNT(CASE WHEN status = 'ACTIVE' THEN 1 END) as active,
    COUNT(CASE WHEN status = 'COMPLETED' THEN 1 END) as completed
FROM auto_bids
WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY);
```

---

## Extension Points

### Allow Plugins to Customize

```php
// Filter increment calculator
add_filter(
    'yith_wcact_auto_bid_increment',
    function(float $increment, float $bid): float {
        // Custom logic for specific auctions
        return $increment * 1.1; // 10% more
    },
    10,
    2
);

// Hook after auto-bid created
add_action(
    'yith_wcact_auto_bid_created',
    function($auto_bid_id, $user_id, $auction_id, $max_bid) {
        // Custom notification
        send_notification($user_id, "Auto-bid set for $max_bid");
    },
    10,
    4
);

// Hook after bid placed
add_action(
    'yith_wcact_auto_bid_placed',
    function($auto_bid_id, $bid_amount) {
        // Custom tracking
        track_event('auto_bid_placed', ['amount' => $bid_amount]);
    },
    10,
    2
);
```

---

## Troubleshooting

### Auto-Bids Not Placing

**Check:**
1. Auto-bid status is ACTIVE
2. Maximum bid is higher than current bid + increment
3. Auction is still active
4. User has permission to bid

```php
// Debug script
$auto_bid = $service->getAutoBid($auto_bid_id);
echo "Status: " . $auto_bid['status'] . "\n";
echo "Maximum: " . $auto_bid['maximum_bid'] . "\n";
echo "Current bid: " . $current_bid . "\n";
echo "Should place bid: " . 
    ($auto_bid['maximum_bid'] > ($current_bid + $increment) ? 'YES' : 'NO');
```

### Queue Not Processing

**Check:**
1. WP-Cron is enabled
2. Background jobs are running
3. Database connection is working
4. No PHP fatal errors

```php
// Test queue
$queue = wcact_get_service('bid_queue');
$job_id = $queue->enqueue(new PlaceBidCommand(...));
echo "Job enqueued: " . $job_id . "\n";

// Check queue status
$status = $queue->getJobStatus($job_id);
echo "Status: " . $status . "\n";
```

### History Not Recording

**Check:**
1. History table exists
2. Repository is recording history
3. Database has write permissions

```php
// Check history
$history = $service->getHistory($auto_bid_id);
echo "Events: " . count($history) . "\n";
foreach ($history as $event) {
    echo $event['event_type'] . " - " . $event['created_at'] . "\n";
}
```

---

## References

- Database Schema: `Project Docs/Database/AUTO_BIDDING_SCHEMA.md`
- Algorithm Details: `Project Docs/Design/AUTO_BIDDING_ALGORITHM.md`
- Testing Guide: `Project Docs/Testing/AUTO_BIDDING_TESTING_GUIDE.md`
- API Reference: `Project Docs/Architecture/AUTO_BIDDING_API_REFERENCE.md`
