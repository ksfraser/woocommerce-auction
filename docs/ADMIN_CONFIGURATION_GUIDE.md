# YITH Auctions for WooCommerce - Admin Configuration Guide

**Document Version**: 1.0  
**Last Updated**: 2026-03-30  
**Status**: Production Ready  
**Requirement Reference**: REQ-A-001 (AGENTS.md - Deployment & Operations)

---

## Table of Contents

1. [Quick Start](#quick-start)
2. [Installation](#installation)
3. [Initial Configuration](#initial-configuration)
4. [Auction Settings](#auction-settings)
5. [Payment Configuration](#payment-configuration)
6. [Email Notifications](#email-notifications)
7. [Performance Tuning](#performance-tuning)
8. [Backup & Recovery](#backup--recovery)
9. [Monitoring & Alerts](#monitoring--alerts)
10. [Security Configuration](#security-configuration)
11. [Troubleshooting](#troubleshooting)

---

## Quick Start

### Installation (5 minutes)

```bash
# 1. Download and extract to plugins
wget -O yith-auctions.zip https://releases.company.com/yith-auctions-1.0.0.zip
unzip yith-auctions.zip -d /var/www/wordpress/wp-content/plugins/

# 2. Install dependencies
cd /var/www/wordpress/wp-content/plugins/yith-auctions-for-woocommerce
composer install --no-dev --optimize

# 3. Activate plugin
wp plugin activate yith-auctions-for-woocommerce

# 4. Run migrations
wp yith-auctions migrate

# 5. Verify installation
wp plugin list | grep yith-auctions
```

### Initial Setup (10 minutes)

1. **Log in to WordPress Admin** → https://your-site.com/wp-admin/
2. **Navigate to** Auctions → Settings (left sidebar)
3. **Configure**:
   - [ ] Site Currency
   - [ ] Email notifications
   - [ ] Auction duration defaults
   - [ ] Commission settings
4. **Save Settings**
5. **Create Test Auction** to verify

---

## Installation

### System Requirements

```
PHP:              7.3 - 8.1
WordPress:        5.0+
WooCommerce:      3.8+
MySQL:           5.7+ or PostgreSQL 10+
Memory:          256MB+ (512MB+ recommended)
Extensions:      PDO, JSON, CURL, OpenSSL, Zip
```

### Step-by-Step Installation

#### 1. Prepare Server

```bash
# Check PHP version
php --version

# Check required extensions
php -m | grep -E "pdo|json|curl|openssl|zip"

# Increase memory limit if needed
sed -i 's/memory_limit = .*/memory_limit = 512M/' /etc/php/php.ini

# Create plugin directory
mkdir -p /var/www/wordpress/wp-content/plugins/yith-auctions-for-woocommerce
chmod 755 /var/www/wordpress/wp-content/plugins/yith-auctions-for-woocommerce
```

#### 2. Install Plugin

**Option A: Download from Repository**
```bash
cd /var/www/wordpress/wp-content/plugins
wget https://releases.company.com/yith-auctions-1.0.0.tar.gz
tar -xzf yith-auctions-1.0.0.tar.gz
cd yith-auctions-for-woocommerce
```

**Option B: Clone from Git**
```bash
cd /var/www/wordpress/wp-content/plugins
git clone https://github.com/your-org/yith-auctions-for-woocommerce.git
cd yith-auctions-for-woocommerce
git checkout v1.0.0
```

#### 3. Install Dependencies

```bash
# Navigate to plugin directory
cd /var/www/wordpress/wp-content/plugins/yith-auctions-for-woocommerce

# Install Composer dependencies
composer install --no-dev --optimize-autoloader --prefer-dist

# Verify installation
ls -la vendor/
```

#### 4. Activate Plugin

**Via WordPress Admin**:
1. Go to Plugins → Installed Plugins
2. Find "YITH Auctions for WooCommerce"
3. Click "Activate"

**Via Command Line**:
```bash
wp plugin activate yith-auctions-for-woocommerce
```

#### 5. Run Database Migrations

```bash
# Run migrations
wp yith-auctions migrate --env=production

# Verify
wp db query "SHOW TABLES LIKE 'yith_auction%';"

# Check migration log
wp db query "SELECT * FROM yith_audit_log WHERE event_type='migration' ORDER BY created_at DESC LIMIT 5;"
```

#### 6. Verify Installation

```bash
# Check plugin status
wp plugin list | grep yith-auctions-for-woocommerce

# Check for errors
tail -50 /var/log/php-errors.log

# Test dashboard access
curl -s https://your-site.com/wp-admin/ | grep -q Dashboard && echo "OK"
```

---

## Initial Configuration

### Step 1: Access Settings

**Path**: WordPress Admin → Auctions → Settings → General

### Step 2: Configure Basic Settings

#### Currency Setting

```
Setting:    Default Currency
Location:   Settings → General → Currency
Options:    USD, EUR, GBP, JPY, AUD, CAD, CHF, CNY, INR
Impact:     All prices displayed and stored in this currency
Action:     Select currency matching your primary market
```

**Set Currency**:
1. Go to Settings → General
2. Click "Currency" dropdown
3. Select your currency
4. Click "Save"

#### Timezone Setting

```
Setting:    Timezone
Location:   Settings → General → Timezone
Default:    Server timezone
Impact:     Auction end times based on this timezone
Action:     Set to your business timezone
```

**Set Timezone**:
```
Position dropdown to "Hours from UTC"
Example: UTC+5 for EST, UTC+1 for GMT
Or select specific city from timezone list
```

### Step 3: Auction Defaults

#### Default Duration

```
Setting:    Default Auction Duration
Location:   Settings → General → Auction Defaults
Options:    1-30 days
Default:    7 days
Impact:     Pre-filled for new auctions
```

**Configure**:
1. Settings → General → Auction Defaults
2. Set "Default Duration" to preferred value
3. Save

#### Minimum Reserve Price

```
Setting:    Minimum Reserve Price
Location:   Settings → General → Policies
Default:    $0.01
Impact:     Lowest allowed starting price
```

**Configure**:
1. Settings → General → Policies
2. Set "Minimum Starting Price"
3. Save

#### Maximum Auction Duration

```
Setting:    Maximum Duration
Location:   Settings → General → Limits
Default:    30 days
Impact:     Highest allowed duration
```

**Configure**:
1. Settings → General → Limits
2. Set "Maximum Duration"
3. Save

### Step 4: Commission Settings

#### Commission Rate

```
Setting:    Commission Rate
Location:   Settings → Financial → Commission
Type:       Percentage or fixed amount
Default:    10%
Impact:     Amount deducted from final sale price
```

**Configure**:
1. Settings → Financial → Commission
2. Set commission rate (% or fixed)
3. Select whether commission applies to shipping
4. Save

**Example**:
- Commission rate: 10%
- Final sale price: $100
- Commission: $10
- Seller receives: $90

#### Payment Terms

```
Setting:    Payment Terms
Location:   Settings → Financial → Payment Terms
Options:    
  - Immediate (seller must pay immediately)
  - Net 30 (seller has 30 days to pay)
  - Net 60
Impact:     When seller must remit funds
```

### Step 5: Email Notifications

#### Configure Email Settings

See [Email Notifications](#email-notifications) section below.

### Step 6: Security Settings

#### API Keys

```
Setting:    API Keys
Location:   Settings → Security → API Keys
Action:     Generate new keys for third-party integrations
```

**Generate API Key**:
1. Settings → Security → API Keys
2. Click "Generate New Key"
3. Name: (descriptive name for your app)
4. Permissions: Select appropriate scopes
5. Copy and save securely

#### Access Control

```
Setting:    Role-Based Access
Location:   WordPress → Users → Roles
Impact:     Who can create/manage auctions
```

**Set Permissions**:
1. WordPress → Users → Roles and Capabilities
2. Find "ksfraser_auctions_create"
3. Assign to roles as needed:
   - [ ] Administrator - All actions
   - [ ] Shop Manager - Create/manage auctions
   - [ ] Seller - Create/manage own auctions
   - [ ] Customer - View and bid only

---

## Auction Settings

### Auction Type Configuration

#### Standard Auction

```
Configuration:      Regular ascending auction
Field Settings:
  - Allow reserve price        [✓]
  - Allow starting price       [✓]
  - Auto-extend on last bid    [✓]
  - Extension time             [5 minutes]
  - Minimum bid increment      [$5]

Setup:
1. Settings → Auction Types → Standard
2. Configure fields above
3. Save
```

#### Sealed Bid Auction

```
Configuration:      Non-visible bids until reveal
Field Settings:
  - Hide bid amounts           [✓]
  - Reveal after end           [✓]
  - Allow rebid after reveal   [✓]

Setup:
1. Settings → Auction Types → Sealed Bid
2. Configure fields
3. Save
```

#### Dutch Auction

```
Configuration:      Price descends over time
Field Settings:
  - Starting price             [Required]
  - Ending price               [Required]
  - Decrement amount           [% or fixed]
  - Decrement interval         [Time period]

Setup:
1. Settings → Auction Types → Dutch
2. Set starting/ending prices
3. Set decrement rate
4. Save
```

### Auction Duration Settings

#### Configure Duration Options

```
Path:  Settings → Auction Behavior → Duration Options

Options available:   1-30 days (customizable)
Default selections:  3, 5, 7, 10, 14, 21, 30 days

Setup:
1. Check desired duration options
2. Reorder as needed (primary at top)
3. Save
```

**Example Configuration**:
```
[✓] 3 days   (rare/urgent items)
[✓] 5 days   (common duration)
[✓] 7 days   (standard/popular)
[ ] 10 days  (specialty items only)
[✓] 14 days  (high-value items)
[ ] 21 days  (unavailable)
[✓] 30 days  (premium items)
```

### Bid Increment Settings

#### Configure Minimum Bid Increments

```
Path:  Settings → Auction Behavior → Bid Increments

Example Schedule (by price range):
  $0-$99:       $1 increment
  $100-$499:    $5 increment
  $500-$999:    $10 increment
  $1000+:       $25 increment

Setup:
1. Add price range and increment
2. Add next tier
3. Repeat for all ranges
4. Save
```

**Bid Increment Table**:
```
 Price Range   | Minimum Increment
───────────────┼──────────────────
 $0 - $99      |   $1.00
 $100 - $499   |   $5.00
 $500 - $999   |   $10.00
 $1,000 - $4,999 | $25.00
 $5,000+       |   $50.00
```

---

## Payment Configuration

### Payment Gateway Setup

#### Stripe Configuration

```
Path:  Settings → Payment → Stripe

Configuration:
  - API Key (Public)        [sk_test_...]
  - API Key (Secret)        [sk_test_...]
  - Webhook Secret          [whsec_...]
  - Enable test mode        [✓] for staging
  - Enable live mode        [ ] until verified

Setup Steps:
1. Login to Stripe Dashboard
2. Get API keys (Settings → API Keys)
3. Settings → Payment → Stripe
4. Paste keys
5. Enter webhook URL: https://your-site.com/wp-json/yith/webhooks/stripe
6. Create webhook in Stripe dashboard
7. Verify test transaction
```

#### PayPal Configuration

```
Path:  Settings → Payment → PayPal

Configuration:
  - Client ID           [AXc...]
  - Client Secret       [EGsw...]
  - Mode               [Live/Sandbox]
  - Currency           [Same as site default]

Setup Steps:
1. Login to PayPal Developer
2. Dashboard → Apps & Credentials
3. Copy Client ID and Secret
4. Paste in Settings → Payment → PayPal
5. Select mode (Sandbox for testing)
6. Save
```

#### Square Configuration

```
Path:  Settings → Payment → Square

Configuration:
  - Application ID      [sq0atp...]
  - Access Token        [sq0csp...]
  - Environment         [Production/Sandbox]

Setup Steps:
1. Login to Square Developer Dashboard
2. Credentials page
3. Copy Application ID and Access Token
4. Settings → Payment → Square
5. Paste credentials
6. Save
```

### Payment Methods Supported

```
Configuration Section: Settings → Payment → Methods

Available Methods:
  [✓] Credit Card (via integrated gateway)
  [✓] PayPal
  [✓] Stripe
  [✓] Square
  [✓] Bank Transfer (manual)
  [ ] Bitcoin (optional addon)

Recommendations:
- Enable 2-3 methods for typical merchants
- Credit Card/Stripe for US/EU markets
- PayPal for international support
- Bank Transfer as fallback option
```

### Buyer Protection

```
Configuration: Settings → Payment → Protection

Options:
  - Dispute period       [14 days default]
  - Buyer guarantee      [Auto-refund on non-delivery]
  - Seller protection    [Proof of delivery required]
  - Chargeback handling  [Automatic investigation]

Recommendations:
  [✓] Enable all protections
  [✓] Require delivery confirmation
  [✓] Use fraud detection service
```

---

## Email Notifications

### Email Configuration

**Path**: Settings → Emails → Configuration

#### SMTP Settings

```
Provider:           SMTP or service provider
SMTP Host:         mail.company.com
SMTP Port:         587 (TLS) or 465 (SSL)
Username:          notifications@company.com
Password:          [Secure password]
From Address:      noreply@company.com
From Name:         YITH Auctions
```

**Test Email**:
```bash
wp eval 'do_action("yith_auctions_send_test_email");'

# Or via admin:
# Settings → Emails → Click "Send Test Email"
```

### Email Templates

#### Auction Created (Seller)

```
Default Template: Email/AuctionCreated.php

Customize:
1. Settings → Emails → Templates
2. Click "Auction Created"
3. Edit HTML/text
4. Available variables: {auction_title}, {auction_link}, etc.
5. Save
```

**Standard Content**:
- Auction title
- Description
- Starting price
- Duration
- End time (UTC)
- Direct link to auction

#### Bid Placed (Bidders)

```
Default Template: Email/BidPlaced.php

Customize:
1. Settings → Emails → Templates
2. Click "Bid Placed"
3. Edit template
4. Customize message
5. Save

Variables Available:
- {bidder_name}
- {auction_title}
- {bid_amount}
- {current_high_bid}
- {outbid_notifications} [Y/N]
```

#### Auction Ending Soon (Bidders)

```
Sending:           24 hours before end
Recipients:        Users who bid on auction
Customization:     Settings → Emails → End Soon
```

#### Auction Ended (Buyer/Seller)

```
Buyer Email:
  - Result confirmation (won/lost)
  - Payment instructions
  - Seller contact info
  - Delivery instructions

Seller Email:
  - Winner details
  - Final sale price
  - Commission calculation
  - Payment timeline
```

#### Payment Received (Seller)

```
Trigger:           Payment successfully processed
Content:
  - Transaction ID
  - Amount received
  - Commission deducted
  - Payout timing
  - Tracking number (if applicable)
```

### Email Troubleshooting

#### Emails Not Sending

```bash
# Check mail queue
wp eval 'echo wp_get_mail_queue_count();'

# Process pending emails
wp eval 'do_action("yith_auctions_process_email_queue");'

# Check logs
tail -100 /var/log/php-errors.log | grep mail
```

#### SMTP Connection Issues

```bash
# Test SMTP connectivity
telnet mail.company.com 587
EHLO test
QUIT

# Or use PHP
php -r "
$ch = fsockopen('mail.company.com', 587, \$errno, \$errstr, 5);
if (\$ch) echo 'Connected'; else echo 'Failed: ' . \$errstr;
"
```

---

## Performance Tuning

### Database Optimization

#### Enable Query Caching

```bash
# Edit my.cnf
query_cache_type = 1
query_cache_size = 256M
query_cache_limit = 2M

# Restart MySQL
sudo systemctl restart mysql

# Verify
mysql -e "SHOW VARIABLES LIKE 'query_cache%';"
```

#### Create Indexes

```bash
# Check missing indexes
wp yith-auctions analyze-indexes

# Get output and create manually if needed
CREATE INDEX idx_auction_status ON yith_auctions(status);
CREATE INDEX idx_auction_end_time ON yith_auctions(end_time);
CREATE INDEX idx_bid_auction_id ON yith_bids(auction_id);
CREATE INDEX idx_bid_bidder_id ON yith_bids(bidder_id);
```

### Caching Strategy

#### Page Caching

```
Setting:      Page Cache
Location:     Settings → Performance → Cache
Type:         Object cache or HTML cache
Tool:         Redis, Memcached, or WP Super Cache
Recommendation:
  Production: Redis or Memcached
  Staging:    WP Super Cache
```

**Enable Redis**:
```bash
# Install Redis
sudo apt install redis-server

# Configure cache in WordPress
wp config set WP_CACHE true

# Verify
redis-cli ping
```

#### Fragment Caching

```php
// Cache specific dashboard widget
$cache_key = 'auction_stats_' . date('Y-m-d');
$stats = wp_cache_get($cache_key);

if (!$stats) {
    $stats = $this->calculate_stats();
    wp_cache_set($cache_key, $stats, '', 1 HOUR_IN_SECONDS);
}
```

### CDN Configuration

#### CloudFront Setup (AWS)

```bash
# Create distribution
aws cloudfront create-distribution \
  --origin-domain-name your-site.com \
  --enabled \
  --default-cache-behavior \
    "ViewerProtocolPolicy=https-only,\
     Compress=true,\
     ForwardedValues={QueryString=true}"

# Point DNS to distribution
# CNAME your-site.com → d111.cloudfront.net
```

#### Asset CDN

```
Static Assets:  CSS, JS, images via CDN
API Endpoints:  NOT via CDN (dynamic content)
Configuration:  Settings → Performance → CDN
```

### Query Optimization

#### Monitor Slow Queries

```bash
# Enable slow query log
mysql -e "SET GLOBAL slow_query_log = 'ON';"
mysql -e "SET GLOBAL long_query_time = 1;"

# Check logs
tail -50 /var/log/mysql/slow.log

# Analyze with Percona Toolkit
pt-query-digest /var/log/mysql/slow.log
```

#### Optimize Common Queries

```php
// WRONG: N+1 problem
foreach($auctions as $auction) {
    echo $auction->seller()->name; // Query per loop
}

// RIGHT: Eager loading
$auctions = Auction::with('seller')->get();
foreach($auctions as $auction) {
    echo $auction->seller->name; // No extra queries
}
```

---

## Backup & Recovery

### Automated Backups

#### Configure Backup Schedule

```
Path:  Settings → Backup → Schedule

Options:
  - Daily (3 AM UTC)       [✓ Recommended]
  - Weekly (Sunday)        [ ]
  - Monthly (1st of month) [ ]
  - On-demand              [Always available]

Storage Location:  /backups/daily/ or S3
Retention:         30-day rolling backup
```

**Setup**:
```bash
# Create backup directory
mkdir -p /backups/daily /backups/weekly /backups/monthly
chmod 700 /backups

# Add cron job
(crontab -l; echo "0 3 * * * /usr/local/bin/backup-yith-auctions.sh") | crontab -
```

#### Manual Backup

```bash
#!/bin/bash
# Backup script

BACKUP_DIR="/backups/manual"
DB_NAME="wordpress"
TIMESTAMP=$(date +%Y%m%d-%H%M%S)

# Database backup
mysqldump --single-transaction --lock-tables=false \
  -h localhost -u $DB_USER -p$DB_PASS $DB_NAME | \
  gzip > $BACKUP_DIR/db-$TIMESTAMP.sql.gz

# Plugin files backup
tar -czf $BACKUP_DIR/plugin-$TIMESTAMP.tar.gz \
  /var/www/wordpress/wp-content/plugins/yith-auctions-for-woocommerce/

# Uploads backup
tar -czf $BACKUP_DIR/uploads-$TIMESTAMP.tar.gz \
  /var/www/wordpress/wp-content/uploads/

echo "Backup completed: $TIMESTAMP"
```

### Recovery Procedures

#### Restore from Backup

```bash
# List available backups
ls -lh /backups/daily/

# Restore database
gunzip < /backups/daily/db-20260330-030000.sql.gz | \
  mysql -h localhost -u $DB_USER -p$DB_PASS $DB_NAME

# Restore plugin files
tar -xzf /backups/daily/plugin-20260330-030000.tar.gz -C /var/www/wordpress/wp-content/plugins/

# Verify restoration
wp db query "SELECT COUNT(*) FROM wp_posts;"
wp plugin list | grep yith-auctions
```

#### Database Recovery

```
Scenario: Database corruption or accidental deletion

Steps:
1. Identify latest valid backup
2. Stop all pending transactions
3. Restore backup
4. Verify data integrity
5. Re-enable service
6. Monitor for issues
7. Document incident
```

---

## Monitoring & Alerts

### Enable Monitoring

**Path**: Settings → Monitoring → Enable

```
Metrics Collected:
  - API response times
  - Database query times
  - Error rates
  - Auction state changes
  - Payment transactions
  - User actions

Export To:
  [ ] Datadog
  [ ] New Relic
  [ ] CloudWatch
  [ ] Custom webhook
```

### Key Metrics to Monitor

#### System Health

```
CPU Usage:           < 70% normal, > 85% alert
Memory Usage:        < 80% normal, > 90% alert
Disk Usage:          < 85% normal, > 90% alert
Database Connections: < 80 normal, > 150 alert
```

#### Application Metrics

```
API Response Time:    < 200ms (p95)
Error Rate:           < 0.5%
Database Query Time:  < 100ms (p95)
Batch Job Duration:   < 5 seconds (p99)
```

#### Business Metrics

```
Auctions Running:     Current count
Bids Per Hour:        Throughput
Payment Success Rate: > 99%
Failed Transactions:  Alert if > 1%
```

### Configure Alerts

#### Email Alerts

```
Path:  Settings → Monitoring → Alerts → Email

Trigger Conditions:
  - Error rate > 1%
  - Response time > 500ms
  - Payment failures > 5
  - Batch jobs failing
  - Database connection errors

Recipients:
  - Administrator
  - Technical lead
  - Platform owner
```

#### Webhook Alerts

```
Path:  Settings → Monitoring → Alerts → Webhook

Configure:
  Webhook URL:  https://alerts.company.com/yith
  Auth:         Bearer token
  
Trigger events sent automatically
```

---

## Security Configuration

### SSL/TLS Certificate

#### Configure HTTPS

```bash
# Install Let's Encrypt certificate
sudo certbot certonly --webroot -w /var/www/wordpress \
  -d your-site.com -d www.your-site.com

# Auto-renew
sudo certbot renew --dry-run

# Verify
curl -I https://your-site.com
```

#### Force HTTPS

```php
// In wp-config.php
define('WP_HOME', 'https://your-site.com');
define('WP_SITEURL', 'https://your-site.com');
define('FORCE_SSL_ADMIN', true);
define('FORCE_SSL_LOGIN', true);
```

### Database Security

#### Restrict Database Access

```bash
# Only allow localhost
mysql -e "UPDATE mysql.user SET Host='localhost' WHERE User='wordpress'; FLUSH PRIVILEGES;"

# Or specific IP
mysql -e "UPDATE mysql.user SET Host='192.168.1.100' WHERE User='wordpress'; FLUSH PRIVILEGES;"
```

#### Regular Security Updates

```bash
# Update WordPress
wp core update-db

# Update all plugins
wp plugin update --all

# Update dependencies
composer update --no-dev

# Check for vulnerabilities
composer audit
```

### Access Control

#### Two-Factor Authentication

```
Path:  Users → User → Two-Factor Auth

Setup:
1. Enable "Require 2FA for admin users"
2. Users configure authenticator app
3. Backup codes generated
4. Save recovery codes securely
```

#### IP Whitelisting

```
Path:  Settings → Security → IP Whitelist

Add trusted IPs:
  - Office: 203.0.113.0/24
  - VPN: 192.0.2.1
  - CI/CD: 198.51.100.0/24

Result: Only listed IPs can access admin panel
```

---

## Troubleshooting

### Common Issues

#### Plugin Not Visible in Admin

**Symptoms**: Not showing in Plugins list

**Solutions**:
```bash
# Check if activated
wp plugin list | grep yith

# Check file permissions
ls -la /var/www/wordpress/wp-content/plugins/yith-auctions-for-woocommerce/

# Check plugin file
head -20 /var/www/wordpress/wp-content/plugins/yith-auctions-for-woocommerce/init.php
```

#### Database Errors

**Symptoms**: "Error establishing database connection"

**Solutions**:
```bash
# Check database connectivity
mysql -h localhost -u $DB_USER -p$DB_PASS -e "SELECT 1;"

# Check credentials in wp-config.php
grep DB_ /var/www/wordpress/wp-config.php

# Verify permissions
wp db query "SHOW TABLES;"
```

#### Performance Issues

**Symptoms**: Slow page loads, timeouts

**Solutions**:
```bash
# Check query performance
wp eval 'wp_debug_mode_start(); wp_cache_flush();'

# Monitor resource usage
top -b -n 1
free -h
df -h /

# Check for slow queries
mysql -e "SET GLOBAL slow_query_log = 'ON';" \
      -e "SET GLOBAL long_query_time = 1;"
tail /var/log/mysql/slow.log
```

#### Email Not Sending

**Symptoms**: Users not receiving notifications

**Solutions**:
```bash
# Check SMTP configuration
wp eval 'wp_mail("test@test.com", "Test", "Test message");'

# Check mail queue
wp eval 'echo wp_get_mail_queue_count();'

# Check PHP mail function
php -i | grep -E "mail|sendmail"

# Check server logs
tail -50 /var/log/mail.log
```

---

## Additional Resources

- [User Manual](./USER_MANUAL.md)
- [Deployment Guide](./DEPLOYMENT_GUIDE.md)
- [Troubleshooting Guide](./TROUBLESHOOTING_GUIDE.md)
- [API Documentation](./PROJECT_DOCS/Integration/API_DOCUMENTATION.md)
- [Architecture Overview](./ARCHITECTURE.md)

---

## Support

**Documentation**: [Wiki](https://wiki.company.com/yith)  
**Email Support**: support@company.com  
**Priority Support**: support-priority@company.com  
**Emergency**: on-call@company.com  

**Hours**: Monday-Friday, 9 AM - 6 PM UTC  
**Response Time**: 4 hours (priority), 24 hours (standard)

---

**Document Version**: 1.0  
**Last Updated**: 2026-03-30  
**Next Review**: 2026-06-30  
**Owner**: Platform Operations Team
