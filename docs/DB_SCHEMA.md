# Database Schema Documentation

## Overview

This document describes the database schema for the Automatic Bidding System in YITH Auctions for WooCommerce.

## Tables

### 1. proxy_bids

Stores proxy bid (automatic bid) records.

```sql
CREATE TABLE proxy_bids (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    auction_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    maximum_bid DECIMAL(10,2) NOT NULL,
    current_proxy_bid DECIMAL(10,2) DEFAULT 0.00,
    status VARCHAR(20) DEFAULT 'active',
    cancelled_by_user BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (auction_id) REFERENCES auctions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(ID) ON DELETE CASCADE,
    
    INDEX idx_auction_status (auction_id, status),
    INDEX idx_user_id (user_id),
    INDEX idx_auction_user (auction_id, user_id),
    INDEX idx_created_at (created_at)
);
```

**Columns:**
- `id`: Unique proxy bid identifier
- `auction_id`: Foreign key to auctions table
- `user_id`: Foreign key to WordPress users table
- `maximum_bid`: Maximum amount user is willing to pay
- `current_proxy_bid`: Current automatic bid amount
- `status`: One of: active, outbid, cancelled, won
- `cancelled_by_user`: Flag if user manually cancelled
- `created_at`: UTC timestamp of creation
- `updated_at`: UTF timestamp of last update

**Indexes:**
- `idx_auction_status`: Primary query for auto-bidding
- `idx_user_id`: Find user's proxies
- `idx_auction_user`: Check if user has active proxy
- `idx_created_at`: Time-based queries

**Status Values:**
- `active`: Proxy bid is active and responding
- `outbid`: Another bid exceeded this proxy's maximum
- `cancelled`: User cancelled or auction ended
- `won`: User won the auction with this proxy

### 2. bids

Stores all bid records (manual, automatic, admin).

```sql
CREATE TABLE bids (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    auction_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    bid_amount DECIMAL(10,2) NOT NULL,
    bid_type VARCHAR(20) DEFAULT 'manual',
    bid_number INT DEFAULT 0,
    is_retracted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    
    FOREIGN KEY (auction_id) REFERENCES auctions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(ID) ON DELETE CASCADE,
    
    INDEX idx_auction_created (auction_id, created_at),
    INDEX idx_auction_bid_number (auction_id, bid_number),
    INDEX idx_user_auction (user_id, auction_id),
    INDEX idx_bid_type (bid_type)
);
```

**Columns:**
- `id`: Unique bid identifier
- `auction_id`: Foreign key to auctions table
- `user_id`: User who placed bid
- `bid_amount`: Bid amount in decimal
- `bid_type`: One of: manual, proxy, admin
- `bid_number`: Sequential number of bid in auction (0, 1, 2, ...)
- `is_retracted`: Flag if user retracted bid
- `created_at`: UTC timestamp of bid placement
- `updated_at`: UTC timestamp of last update
- `expires_at`: Optional expiration timestamp

**Indexes:**
- `idx_auction_created`: Fetch bid history for auction
- `idx_auction_bid_number`: Get bid by number quickly
- `idx_user_auction`: Find user's bids on specific auction
- `idx_bid_type`: Query by bid type

**Bid Type Values:**
- `manual`: User placed manually
- `proxy`: Auto-bidding system placed
- `admin`: Administrator placed

### 3. auctions

Stores auction records.

```sql
CREATE TABLE auctions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    description LONGTEXT,
    current_bid DECIMAL(10,2) DEFAULT 0.00,
    current_bidder_id BIGINT UNSIGNED NULL,
    starting_price DECIMAL(10,2) DEFAULT 0.00,
    reserve_price DECIMAL(10,2) NULL,
    minimum_bid DECIMAL(10,2) NULL,
    status VARCHAR(20) DEFAULT 'active',
    start_time TIMESTAMP NULL,
    end_time TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (product_id) REFERENCES posts(ID) ON DELETE CASCADE,
    FOREIGN KEY (current_bidder_id) REFERENCES users(ID) ON DELETE SET NULL,
    
    INDEX idx_product_status (product_id, status),
    INDEX idx_status (status),
    INDEX idx_end_time (end_time),
    INDEX idx_created_at (created_at)
);
```

**Columns:**
- `id`: Unique auction identifier
- `product_id`: Foreign key to WooCommerce product (WordPress post)
- `name`: Auction title
- `description`: Auction description
- `current_bid`: Current highest bid amount
- `current_bidder_id`: User ID of current highest bidder
- `starting_price`: Starting bid amount
- `reserve_price`: Minimum acceptable bid to sell
- `minimum_bid`: Minimum increment for next bid
- `status`: One of: active, ended, cancelled
- `start_time`: UTC timestamp when auction begins
- `end_time`: UTC timestamp when auction ends
- `created_at`: UTC timestamp of creation
- `updated_at`: UTC timestamp of last update

**Indexes:**
- `idx_product_status`: Find auctions for product
- `idx_status`: Find active/ended auctions
- `idx_end_time`: Find ending auctions
- `idx_created_at`: Time-based queries

### 4. auto_bid_logs

Audit trail for all auto-bidding attempts.

```sql
CREATE TABLE auto_bid_logs (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    auction_id BIGINT UNSIGNED NOT NULL,
    proxy_bid_id BIGINT UNSIGNED NULL,
    action VARCHAR(20) NOT NULL,
    required_bid DECIMAL(10,2) NULL,
    maximum_bid DECIMAL(10,2) NULL,
    placed_bid DECIMAL(10,2) NULL,
    processing_time_ms INT DEFAULT 0,
    status_code VARCHAR(20),
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (auction_id) REFERENCES auctions(id) ON DELETE CASCADE,
    FOREIGN KEY (proxy_bid_id) REFERENCES proxy_bids(id) ON DELETE SET NULL,
    
    INDEX idx_auction_created (auction_id, created_at),
    INDEX idx_action (action),
    INDEX idx_proxy_bid (proxy_bid_id),
    INDEX idx_created_at (created_at)
);
```

**Columns:**
- `id`: Unique log entry identifier
- `auction_id`: Auction being auto-bid on
- `proxy_bid_id`: Proxy bid being evaluated
- `action`: One of: PLACED, OUTBID, SKIPPED, ERROR
- `required_bid`: Bid amount that would have been needed
- `maximum_bid`: Proxy's maximum bid limit
- `placed_bid`: Actual bid placed (if PLACED)
- `processing_time_ms`: Time in milliseconds for operation
- `status_code`: Internal status code
- `error_message`: Error message if action failed
- `created_at`: UTC timestamp of log entry

**Indexes:**
- `idx_auction_created`: Get all logs for auction
- `idx_action`: Query by action type
- `idx_proxy_bid`: Find logs for specific proxy
- `idx_created_at`: Time-based queries

**Action Values:**
- `PLACED`: Auto-bid successfully placed
- `OUTBID`: Proxy marked as outbid
- `SKIPPED`: Proxy skipped (same user, etc.)
- `ERROR`: Error during processing

## Data Relationships

```
┌─────────────────────────────────────────────────────────────┐
│                      WordPress Users                        │
│                       (users table)                          │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  ID (PK), user_login, user_email, display_name, etc  │   │
│  └──────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────┘
    ▲                     ▲                        ▲
    │ (1)                 │ (1)                    │ (1)
    │ user_id             │ current_bidder_id      │ user_id
    │                     │ (nullable)             │
    │ (M)                 │ (M)                    │ (M)
┌─────────────────────────────────────────────────────────────┐
│  Auction Information                                        │
│  ┌──────────────────────────────────────────────────────┐   │
│  │ id (PK), product_id (FK), name, current_bid, status  │   │
│  │ start_time, end_time, created_at                     │   │
│  └──────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────┘
    │                        │
    │ (1)                    │ (1)
    │ product_id             │ auction_id
    │                        │
    │ (M)                    │ (M)
    │                        ├──────────────────────┐
    │                        │                      │
┌───────────────┐    ┌──────────────────┐  ┌──────────────────────┐
│  WC Products  │    │   Bid Records    │  │  Proxy Bid Records   │
│  (posts)      │    │  (bids table)    │  │  (proxy_bids table)  │
│               │    │                  │  │                      │
│ ID (PK)       │    │ id (PK)          │  │ id (PK)              │
│ post_title    │    │ auction_id (FK)  │  │ auction_id (FK)      │
│ post_content  │    │ user_id (FK)     │  │ user_id (FK)         │
│               │    │ bid_amount       │  │ maximum_bid          │
│               │    │ bid_type         │  │ current_proxy_bid    │
│               │    │ bid_number       │  │ status               │
│               │    │ created_at       │  │ created_at           │
│               │    │                  │  │                      │
│               │    │                  │  │ (1)                  │
│               │    │                  │  │ proxy_bid_id         │
│               │    │                  │  │ (M)                  │
│               │    │                  │  │                      │
│               │    │                  │  └──────────────────────┘
│               │    │                  │           │
│               │    │                  │           │ Audit Trail
│               │    └──────────────────┘    ┌──────────────────────┐
│               │                            │ Auto Bid Logs        │
└───────────────┘                            │ (auto_bid_logs)      │
                                             │                      │
                                             │ id (PK)              │
                                             │ auction_id (FK)      │
                                             │ proxy_bid_id (FK)    │
                                             │ action               │
                                             │ required_bid         │
                                             │ placed_bid           │
                                             │ processing_time_ms   │
                                             │ created_at           │
                                             └──────────────────────┘
```

## Query Patterns

### Find Active Proxies for Auction (Most Common)

```sql
SELECT * FROM proxy_bids
WHERE auction_id = ? AND status = 'active'
ORDER BY created_at ASC;
```

**Index Used:** `idx_auction_status`

### Get Bid History for Auction

```sql
SELECT * FROM bids
WHERE auction_id = ?
ORDER BY bid_number ASC;
```

**Index Used:** `idx_auction_created`

### Check if User Has Active Proxy

```sql
SELECT COUNT(*) as count FROM proxy_bids
WHERE auction_id = ? AND user_id = ? AND status = 'active';
```

**Index Used:** `idx_auction_user`

### Get Auto-Bid Statistics

```sql
SELECT 
    COUNT(*) as total_attempts,
    SUM(CASE WHEN action = 'PLACED' THEN 1 ELSE 0 END) as successful,
    SUM(CASE WHEN action = 'OUTBID' THEN 1 ELSE 0 END) as outbid_count,
    AVG(processing_time_ms) as avg_time
FROM auto_bid_logs
WHERE auction_id = ?;
```

**Index Used:** `idx_auction_created`

### Find User's Bids on Specific Auction

```sql
SELECT * FROM bids
WHERE user_id = ? AND auction_id = ?
ORDER BY created_at DESC;
```

**Index Used:** `idx_user_auction`

### Get Last N Bids on Auction

```sql
SELECT * FROM bids
WHERE auction_id = ?
ORDER BY bid_number DESC
LIMIT 10;
```

**Index Used:** `idx_auction_bid_number`

## Performance Considerations

### Indexing Strategy

1. **Primary Query**: `proxy_bids(auction_id, status)`
   - Most frequent operation during auto-bidding
   - Retrieves all active proxies for evaluation
   - Expected: < 10ms for 100 proxies with index

2. **Secondary Queries**: `bids(auction_id, created_at)`
   - Fetching bid history
   - Expected: < 5ms with index

3. **User Lookups**: `proxy_bids(auction_id, user_id)`
   - Validate single user doesn't have duplicate proxy
   - Expected: < 1ms with index

### Query Optimization Tips

1. **Batch Selection**
   ```sql
   -- Good: Fetch all actives at once
   SELECT * FROM proxy_bids WHERE auction_id = ? AND status = 'active';
   
   -- Bad: Loop and fetch individually (N+1 problem)
   SELECT * FROM proxy_bids WHERE id = ?;
   ```

2. **Use Appropriate Filters**
   ```sql
   -- Good: Status filtered in query
   SELECT * FROM proxy_bids WHERE auction_id = ? AND status = 'active';
   
   -- Bad: Fetch all and filter in PHP
   SELECT * FROM proxy_bids WHERE auction_id = ?;
   ```

3. **Avoid SELECT \***
   ```sql
   -- Better: Only needed columns
   SELECT id, user_id, maximum_bid FROM proxy_bids
   WHERE auction_id = ? AND status = 'active';
   ```

### Partitioning Strategy (For Large Databases)

Consider partitioning large tables by date:

```sql
ALTER TABLE bids PARTITION BY RANGE (YEAR(created_at)) (
    PARTITION p2023 VALUES LESS THAN MAXVALUE
);
```

This improves query performance on historical data while keeping active queries fast.

## Data Integrity Constraints

1. **Foreign Key Constraints**
   - proxy_bids.auction_id → auctions.id (CASCADE DELETE)
   - proxy_bids.user_id → users.ID (CASCADE DELETE)
   - bids.auction_id → auctions.id (CASCADE DELETE)
   - bids.user_id → users.ID (CASCADE DELETE)

2. **Unique Constraints**
   ```sql
   ALTER TABLE proxy_bids ADD CONSTRAINT unique_active_proxy
   UNIQUE KEY (auction_id, user_id)
   WHERE status = 'active';
   ```

3. **Check Constraints**
   ```sql
   ALTER TABLE bids ADD CONSTRAINT check_positive_amount
   CHECK (bid_amount > 0);
   
   ALTER TABLE proxy_bids ADD CONSTRAINT check_positive_max
   CHECK (maximum_bid > 0);
   ```

## Migration Notes

### Version 1.0

Initial schema creation:
- Create proxy_bids table with basic columns
- Create bids table (extends existing if present)
- Create auto_bid_logs table for audit trail
- Add necessary indexes

### Version 1.1

Add performance enhancements:
- Add processing_time_ms column to auto_bid_logs
- Add status_code column for result classification
- Add covering indexes for common queries

### Version 1.2

Add features:
- Add reserve_price to auctions (if not present)
- Add minimum_bid to auctions
- Add expires_at to bids for bid expiration feature

## Backup & Recovery

### Critical Tables (Backup Immediately)

1. `proxy_bids` - User bids (legal evidence)
2. `bids` - All bid history (legal evidence)
3. `auto_bid_logs` - Audit trail (compliance)

### Backup Strategy

```bash
# Daily incremental backup
mysqldump --incremental -u user -p database > backup_$(date +%Y%m%d).sql

# Weekly full backup
mysqldump -u user -p database > full_backup_$(date +%Y%m%d).sql

# Store in secure location with encryption
gpg --encrypt --recipient backup@example.com backup_*.sql
```

### Recovery Procedure

```bash
# Verify backup integrity
mysql -u user -p database < backup_20240101.sql --dry-run

# Restore from backup
mysql -u user -p database < backup_20240101.sql

# Verify data
SELECT COUNT(*) FROM proxy_bids;
SELECT COUNT(*) FROM auto_bid_logs;
```

## Monitoring Queries

### Find Slow Queries

```sql
SELECT * FROM mysql.slow_log
WHERE query_time > 0.1
ORDER BY start_time DESC
LIMIT 20;
```

### Monitor Table Sizes

```sql
SELECT 
    table_name,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb
FROM information_schema.tables
WHERE table_schema = 'woocommerce'
ORDER BY size_mb DESC;
```

### Check Index Usage

```sql
SELECT 
    object_schema,
    object_name,
    count_read,
    count_write
FROM performance_schema.table_io_waits_summary
WHERE object_schema = 'woocommerce'
ORDER BY count_read DESC;
```

## References

- MySQL Documentation: https://dev.mysql.com/doc/
- MariaDB Documentation: https://mariadb.com/docs/
- PostgreSQL JSON Support: https://www.postgresql.org/docs/json/
- Query Optimization: https://use-the-index-luke.com/
