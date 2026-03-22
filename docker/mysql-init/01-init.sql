-- Database initialization script for WooCommerce Auction
-- This script is executed on first run of the MySQL container

-- Use the auction database
USE woocommerce_auction;

-- Create YITH Auctions tables (these will be created by plugin on activation)
-- This is a placeholder for initial database setup if needed

-- Grant privileges
GRANT ALL PRIVILEGES ON woocommerce_auction.* TO 'auction_user'@'%';
FLUSH PRIVILEGES;

-- Create indices for performance
CREATE TABLE IF NOT EXISTS wp_yith_auction_setup_log (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setup_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    setup_version VARCHAR(10),
    setup_status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
    setup_message LONGTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO wp_yith_auction_setup_log (setup_status, setup_message) 
VALUES ('completed', 'Database initialization complete');
