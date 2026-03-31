# YITH Auctions for WooCommerce - Troubleshooting Guide

**Document Version**: 1.0  
**Last Updated**: 2026-03-30  
**Status**: Production Ready  
**Requirement Reference**: REQ-T-001 (AGENTS.md - Deployment & Operations)

---

## Table of Contents

1. [Installation Issues](#installation-issues)
2. [Performance Issues](#performance-issues)
3. [Plugin Functionality](#plugin-functionality)
4. [Database Issues](#database-issues)
5. [Payment Problems](#payment-problems)
6. [Email Issues](#email-issues)
7. [Security Issues](#security-issues)
8. [API Issues](#api-issues)
9. [User Access Problems](#user-access-problems)
10. [Diagnostic Tools](#diagnostic-tools)

---

## Installation Issues

### Plugin Fails to Activate

**Symptoms**:
- "Plugin failed to activate" message
- Plugin shows inactive after clicking activate
- Blank screen on WordPress admin

**Diagnosis**:
```bash
# Check PHP errors
tail -100 /var/log/php-errors.log | grep yith

# Check plugin file
php -l /var/www/wordpress/wp-content/plugins/yith-auctions-for-woocommerce/init.php

# Check file permissions
ls -la /var/www/wordpress/wp-content/plugins/yith-auctions-for-woocommerce/
```

**Solutions**:

**Solution 1: Check Dependencies**
```bash
# Verify Composer files
ls -la /var/www/wordpress/wp-content/plugins/yith-auctions-for-woocommerce/vendor/autoload.php

# If missing, install dependencies
cd /var/www/wordpress/wp-content/plugins/yith-auctions-for-woocommerce
composer install --no-dev
```

**Solution 2: Fix Permissions**
```bash
# Set correct ownership
sudo chown -R www-data:www-data /var/www/wordpress/wp-content/plugins/yith-auctions-for-woocommerce/

# Set correct permissions
sudo chmod -R 755 /var/www/wordpress/wp-content/plugins/yith-auctions-for-woocommerce/
```

**Solution 3: Check PHP Version**
```bash
# Verify PHP 7.3+
php --version  # Should show 7.3 or higher

# If too old, update PHP
sudo apt update
sudo apt upgrade php-cli
```

**Solution 4: Increase Memory**
```php
// Edit wp-config.php
define('WP_MEMORY_LIMIT', '512M');
define('WP_MAX_MEMORY_LIMIT', '1024M');
```

---

### Missing Database Tables

**Symptoms**:
- "Table 'yith_auctions' doesn't exist" error
- Blank auctions page
- Database error on dashboard

**Diagnosis**:
```bash
# Check missing tables
wp db query "SHOW TABLES LIKE 'yith_%';" 

# Expected: 10+ tables related to auctions
# If none: migrations not run
```

**Solution**:

```bash
# Run migrations manually
wp yith-auctions migrate

# Check status
echo $?  # 0 = success

# Verify tables created
wp db query "SHOW TABLES LIKE 'yith_%';"

# If still failing, check logs
tail -50 /tmp/yith-migrations.log
```

**If Migrations Fail**:

```bash
# Check table status
wp db query "DESC yith_auctions;"

# If table incomplete, drop and retry
wp db query "DROP TABLE IF EXISTS yith_auctions;"

# Retry migration
wp yith-auctions migrate --verbose

# Check error output
wp db query "SELECT * FROM yith_audit_log WHERE event_type='migration' ORDER BY created_at DESC LIMIT 5;"
```

---

### Vendor Directory Missing

**Symptoms**:
- "Class not found" errors
- Fatal error about autoloader
- Blank page on site frontend

**Diagnosis**:
```bash
# Check vendor directory
ls -la /var/www/wordpress/wp-content/plugins/yith-auctions-for-woocommerce/vendor/

# If missing or small: dependencies not installed
```

**Solution**:

```bash
# Navigate to plugin
cd /var/www/wordpress/wp-content/plugins/yith-auctions-for-woocommerce

# Install dependencies
composer install --no-dev --optimize-autoloader --prefer-dist

# Verify installation
ls -la vendor/autoload.php
```

---

## Performance Issues

### Slow Page Load

**Symptoms**:
- Site takes >3 seconds to load
- Admin dashboard sluggish
- API endpoints timeout

**Diagnosis**:
```bash
# Benchmark page load
time curl https://your-site.com/auctions > /dev/null

# Check query slowness
wp eval 'list($queries, $time) = wp_get_query_debug_data(); echo "Queries: " . count($queries) . ", Time: $time";'

# Check PHP processes
top -b -n 1 | head -15

# Check disk I/O
iostat -x 1 5
```

**Solutions**:

**Solution 1: Enable Caching**
```bash
# Install Redis
sudo apt install redis-server

# Configure WordPress
wp config set WP_CACHE true

# Verify
redis-cli ping  # Should return "PONG"
```

**Solution 2: Optimize Database Queries**
```bash
# Enable query log
wp eval 'define("SAVEQUERIES", true); global $wpdb; echo "Queries logged";'

# Check for N+1 problems
# Solution: Use SQL JOINs instead of loops

# Create missing indexes
wp yith-auctions create-indexes

# Verify
wp db query "SHOW INDEX FROM yith_auctions;"
```

**Solution 3: Increase PHP Limits**
```php
// In wp-config.php
define('WP_MEMORY_LIMIT', '512M');
define('WP_TIMEOUT', '120');
```

**Solution 4: Enable Gzip Compression**
```apache
# In .htaccess
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript
</IfModule>
```

**Solution 5: CDN for Static Assets**
```bash
# Configure CDN URL
wp option update yith_cdn_url "https://cdn.company.com"

# Verify assets served from CDN
curl -I https://cdn.company.com/wp-content/plugins/yith-auctions/css/admin.css
```

---

### Memory Exhaustion

**Symptoms**:
- "Allowed memory size exhausted" error
- PHP process killed unexpectedly
- Blank page when uploading images

**Diagnosis**:
```bash
# Check current memory usage
php -i | grep memory_limit

# Check WordPress memory
wp eval 'echo WP_MEMORY_LIMIT;'

# Monitor real-time usage
free -h
watch -n 1 free -h
```

**Solution**:

```php
// Increase memory limit in wp-config.php
define('WP_MEMORY_LIMIT', '512M');
define('WP_MAX_MEMORY_LIMIT', '1024M');

// Also in php.ini
memory_limit = 512M
```

**Verify Change**:
```bash
# Restart PHP-FPM
sudo systemctl restart php-fpm

# Check new limit
php -i | grep memory_limit
```

---

### High CPU Usage

**Symptoms**:
- CPU usage > 80% consistently
- Server running slow
- Other sites/apps affected

**Diagnosis**:
```bash
# Check CPU usage
top -b -n 1 | head -20

# Find problematic process
ps aux | grep php | head -5

# Check running queries
mysql -e "SHOW PROCESSLIST;"
```

**Solution**:

**Solution 1: Kill Long-Running Process**
```bash
# Identify process ID
ps aux | grep php | grep [process description] 

# Kill process (carefully!)
kill -9 [PID]
```

**Solution 2: Disable Heavy Batch Jobs**
```bash
# Temporarily
wp eval 'wp_clear_scheduled_hook("yith_auctions_daily_update");'

# Check background jobs
wp yith-auctions jobs list

# Disable specific job
wp yith-auctions jobs disable [job-id]
```

**Solution 3: Optimize Database**
```bash
# Optimize all tables
mysqlcheck -u $DB_USER -p$DB_PASS --optimize $DB_NAME

# Analyze tables
mysqlcheck -u $DB_USER -p$DB_PASS --analyze $DB_NAME
```

---

## Plugin Functionality

### Auctions Not Appearing on Frontend

**Symptoms**:
- Auctions page blank or "No auctions found"
- Admin shows auctions exist
- Widget shows nothing

**Diagnosis**:
```bash
# Check database
wp eval 'echo wp_get_auction_query([]);' | wc -l

# Check WP_Query results
wp eval 'global $wp_query; echo $wp_query->found_posts;'

# Check for errors
tail -50 /var/log/php-errors.log | grep -i auction
```

**Solutions**:

**Solution 1: Check Permalink Settings**
1. WordPress Admin → Settings → Permalinks
2. Set to "Post name" or custom structure
3. Click "Save Changes"
4. Try accessing auctions page

**Solution 2: Enable Custom Post Type**
```bash
# Verify post type registered
wp eval 'var_dump(get_post_types(["_builtin" => false]));'

# Manually register if needed
wp eval '
register_post_type("yith_auction", [
  "public" => true,
  "label" => "Auctions"
]);
flush_rewrite_rules();
'
```

**Solution 3: Check Query Arguments**
```bash
# Debug query
wp eval '
$args = ["post_type" => "yith_auction", "posts_per_page" => 10];
$query = new WP_Query($args);
echo "Found: " . $query->found_posts;
var_dump($query->posts);
'
```

---

### Bid Placement Fails

**Symptoms**:
- "Error placing bid" message
- Bid appears to place but not visible
- User shown "bidding closed" incorrectly

**Diagnosis**:
```bash
# Check auction status
wp db query "SELECT id, status, end_time FROM yith_auctions WHERE id=123;"

# Verify time comparison
wp eval 'echo "Server time: " . current_time("mysql");'

# Check bid table
wp db query "SELECT * FROM yith_bids WHERE auction_id=123 ORDER BY created_at DESC LIMIT 5;"
```

**Solutions**:

**Solution 1: Check Auction Status**
```bash
# Verify auction is active
wp eval '
$auction = get_post(123);
echo "Status: " . $auction->post_status;
echo "End time: " . get_post_meta(123, "end_time", true);
echo "Current time: " . current_time("mysql");
'
```

**Solution 2: Verify Bid Amount**
```bash
# Check minimum bid logic
wp eval '
$auction = new Auction(123);
echo "Current highest: " . $auction->get_highest_bid();
echo "Minimum increment: " . $auction->get_minimum_increment();
echo "Required bid: " . ($auction->get_highest_bid() + $auction->get_minimum_increment());
'
```

**Solution 3: Check User Funds**
```bash
# Verify user has payment method
wp eval '
$user = get_user_by("ID", 456);
$balance = get_user_meta(456, "auction_balance", true);
echo "Balance: $balance";
'
```

---

### Email Notifications Not Sending

**Symptoms**:
- Users don't receive bid notifications
- Auction winners not notified
- Payment reminders missing

**Diagnosis**:
```bash
# Check email queue
wp eval 'echo get_option("yith_auction_email_queue_count");'

# Check mail logs
tail -100 /var/log/mail.log | grep yith

# Test email sending
wp eval 'wp_mail("test@test.com", "Test", "Test message", [], []);'
echo $?  # 0 = success
```

**Solutions**:

**Solution 1: Configure SMTP**
```bash
# Check SMTP settings
wp option get yith_smtp_host
wp option get yith_smtp_port

# Test connection
telnet mail.company.com 587
EHLO test
QUIT
```

**Solution 2: Process Email Queue**
```bash
# Process pending emails
wp eval 'do_action("yith_auctions_process_email_queue");'

# Check if processed
wp db query "SELECT COUNT(*) FROM yith_email_queue WHERE sent_at IS NULL;"
```

**Solution 3: Check Email Template**
```bash
# Verify template exists
ls -la /var/www/wordpress/wp-content/plugins/yith-auctions/templates/emails/

# Check for errors in template
php -l /templates/emails/BidPlaced.php
```

**Solution 4: Set Cron Job**
```bash
# Add to crontab
(crontab -l; echo "*/5 * * * * /usr/bin/php /var/www/wordpress/wp-cron.php") | crontab -

# Verify
crontab -l | grep wp-cron
```

---

## Database Issues

### Database Connection Failed

**Symptoms**:
- "Error establishing database connection"
- Blank white page on frontend
- Can't access WordPress admin

**Diagnosis**:
```bash
# Test MySQL connection
mysql -h localhost -u wordpress -p -e "SELECT 1;"

# Check credentials
grep DB_ /var/www/wordpress/wp-config.php

# Check MySQL status
sudo systemctl status mysql
```

**Solutions**:

**Solution 1: Verify Credentials**
```bash
# Correct credentials in wp-config.php
nano /var/www/wordpress/wp-config.php

# Key lines:
define('DB_NAME', 'wordpress');
define('DB_USER', 'wordpress');
define('DB_PASSWORD', 'secure_password');
define('DB_HOST', 'localhost');
```

**Solution 2: Start MySQL Service**
```bash
# Check status
sudo systemctl status mysql

# Start if stopped
sudo systemctl start mysql

# Enable auto-start
sudo systemctl enable mysql
```

**Solution 3: Increase MySQL Timeout**
```php
// In wp-config.php
define('MYSQL_CLIENT_FLAGS', MYSQLI_CLIENT_INTERACTIVE);

// Also check
define('WP_TIMEOUT', '120');
```

---

### Database Corruption

**Symptoms**:
- "Table is marked as crashed" error
- Random "Column count doesn't match" errors
- Database repair notifications

**Diagnosis**:
```bash
# Check table status
mysqlcheck -u wordpress -p wordpress

# Detailed check
mysqlcheck --check wordpress -u wordpress -p
```

**Solution**:

```bash
# Repair all tables
mysqlcheck --repair --all-databases -u wordpress -p

# Or repair specific database
mysqlcheck --repair wordpress -u wordpress -p

# Verify repair
mysqlcheck --check wordpress -u wordpress -p
```

---

## Payment Problems

### Payment Gateway Not Processing

**Symptoms**:
- Payment form not appearing
- "Payment processing failed" error
- Transaction not recorded in database

**Diagnosis**:
```bash
# Check gateway configuration
wp option get yith_payment_gateway
wp option get yith_stripe_api_key

# Test API connectivity
curl -s https://api.stripe.com/v1/account -H "Authorization: Bearer [API_KEY]"
```

**Solutions**:

**Solution 1: Update API Keys**
```bash
# Get fresh keys from payment processor
# Stripe Dashboard: Settings → API Keys

# Update WordPress
wp option update yith_stripe_api_key "sk_live_..."
wp option update yith_stripe_public_key "pk_live_..."

# Test transaction
wp eval 'do_action("yith_auctions_test_payment");'
```

**Solution 2: Check SSL Certificate**
```bash
# Verify HTTPS working
curl -I https://your-site.com

# Check certificate validity
openssl s_client -connect your-site.com:443 -showcerts

# Renew if expired
sudo certbot renew --force-renewal
```

**Solution 3: Enable Payment Logs**
```bash
# Enable debug logging
wp option update yith_payment_debug_mode 1

# Check logs
tail -100 /var/log/php-errors.log | grep payment

# Disable when done
wp option update yith_payment_debug_mode 0
```

---

## Email Issues

### SMTP Authentication Failed

**Symptoms**:
- Emails bouncing with 550 error
- "SMTP authentication failed" in logs
- Emails not sending

**Diagnosis**:
```bash
# Check SMTP configuration
wp option get yith_smtp_username
wp option get yith_smtp_host

# Test connection
telnet mail.company.com 587
EHLO test
AUTH LOGIN
[Enter base64 encoded username and password]
```

**Solution**:

```bash
# Verify credentials are correct
wp option get yith_smtp_username
wp option get yith_smtp_password  # Should be encoded

# Test by sending email
wp mail admin@site.com "Test" "Test email" "" ""

# If failed, regenerate SMTP password
# Login to email provider → Security → Generate app password
# Update WordPress
wp option update yith_smtp_password "new_app_password"
```

---

## Security Issues

### Account Lockout

**Symptoms**:
- User locked out after failed attempts
- Can't log in despite correct password
- "Too many failed attempts" message

**Diagnosis**:
```bash
# Check failed attempts log
wp db query "SELECT * FROM yith_login_attempts WHERE user_email='user@test.com' ORDER BY attempted_at DESC LIMIT 10;"

# Check lockout status
wp user get [user_id]
```

**Solution**:

**Solution 1: Clear Login Attempts**
```bash
# Reset failed login counter
wp eval '
delete_user_meta(get_user_by("email", "user@test.com")->ID, "login_failed_attempts");
delete_user_meta(get_user_by("email", "user@test.com")->ID, "login_lockout_time");
'

# Try logging in
wp login [user_email]
```

**Solution 2: Unlock Account Manually**
```bash
# Admin unlock
wp user meta delete [user_id] login_failed_attempts
wp user meta delete [user_id] login_lockout_time

# Verify
wp user get [user_id]
```

---

## API Issues

### API Endpoints Returning 500 Error

**Symptoms**:
- "/wp-json/yith/..." endpoints failing
- "Internal Server Error" responses
- Blank API responses

**Diagnosis**:
```bash
# Test endpoint
curl -I https://your-site.com/wp-json/yith/auctions/v1/list

# Check error
curl https://your-site.com/wp-json/yith/auctions/v1/list 2>&1 | head -20

# Check PHP error log
tail -50 /var/log/php-errors.log | grep -i api
```

**Solution**:

```bash
# Check REST API enabled
wp eval 'var_dump(rest_api_enabled());'

# Enable if needed
wp eval 'update_option("rest_api_enabled", 1);'

# Check endpoint registration
wp eval 'echo rest_get_routes();' | jq '.[] | select(. | contains("yith"))'
```

---

## User Access Problems

### Permission Denied Errors

**Symptoms**:
- "You do not have permission" error
- User can't create auctions
- Admin features not accessible

**Diagnosis**:
```bash
# Check user capabilities
wp eval 'var_dump(current_user_can("ksfraser_auctions_create"));'

# Check user role
wp user get [user_id]

# List all capabilities
wp eval 'var_dump(get_user_meta([user_id], "wp_capabilities", true));'
```

**Solution**:

```bash
# Grant auction creation permission
wp eval '
$user = get_user_by("email", "seller@test.com");
$user->add_cap("ksfraser_auctions_create");
'

# Verify
wp eval 'var_dump(current_user_can("ksfraser_auctions_create"));'
```

---

## Diagnostic Tools

### Debug Mode

**Enable Debugging**:
```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);  // Don't show to users
define('WP_DEBUG_LOG', true);  // Write to debug.log

// Check logs
tail -100 /var/www/wordpress/wp-content/debug.log
```

### Log Monitoring

```bash
# Real-time PHP errors
tail -f /var/log/php-errors.log

# Real-time MySQL errors
tail -f /var/log/mysql/error.log

# Real-time WordPress debug log
tail -f /var/www/wordpress/wp-content/debug.log
```

### Health Check Endpoint

```bash
# Check system health
curl https://your-site.com/wp-json/yith/auctions/v1/health

# Expected response:
# {
#   "status": "healthy",
#   "checks": [...]
# }
```

### Database Query Debug

```bash
# Enable query logging
wp eval '
if( ! defined("SAVEQUERIES")) define("SAVEQUERIES", true);
global $wpdb;
$wpdb->show_errors();
'

# Check slow queries
wp eval '
global $wpdb;
var_dump($wpdb->queries);
' | head -50
```

---

## When to Escalate

**Contact Support If**:
- Issue persists after trying all solutions
- Database appears corrupted beyond repair
- Payment processing completely non-functional
- Security breach suspected
- Server resources exhausted without clear cause

**Escalation Channel**:
- **Email**: support@company.com
- **Priority Support**: support-priority@company.com
- **Emergency**: on-call@company.com

**Include in Report**:
- Error messages (exact text)
- Steps to reproduce
- Server details (OS, PHP version, MySQL version)
- Recent changes made
- Plugin version
- Error log excerpts

---

**Document Version**: 1.0  
**Last Updated**: 2026-03-30  
**Next Review**: 2026-06-30
