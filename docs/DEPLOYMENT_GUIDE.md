# YITH Auctions for WooCommerce - Deployment Guide

**Document Version**: 1.0  
**Last Updated**: 2026-03-30  
**Status**: Production Ready  
**Requirement Reference**: REQ-D-001 (AGENTS.md - Deployment & Operations)

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Prerequisites](#prerequisites)
3. [Preflight Checks](#preflight-checks)
4. [Development Deployment](#development-deployment)
5. [Staging Deployment](#staging-deployment)
6. [Production Deployment](#production-deployment)
7. [Database Migrations](#database-migrations)
8. [Verification Procedures](#verification-procedures)
9. [Rollback Procedures](#rollback-procedures)
10. [Post-Deployment Tasks](#post-deployment-tasks)
11. [Troubleshooting](#troubleshooting)
12. [Support & Escalation](#support--escalation)

---

## Executive Summary

This guide provides comprehensive procedures for deploying YITH Auctions for WooCommerce across development, staging, and production environments.

### Deployment Overview

| Aspect | Details |
|--------|---------|
| **Latest Version** | 1.0.0 |
| **PHP Version** | 7.3+ |
| **WordPress Version** | 5.0+ |
| **WooCommerce Version** | 3.8+ |
| **MySQL Version** | 5.7+ |
| **Estimated Downtime** | 5-15 minutes |
| **Rollback Time** | 2-5 minutes |

### Components Deployed

- **Plugin Core**: Main plugin file and initialization
- **Database Schema**: Migration scripts and data structures
- **Assets**: CSS, JavaScript, fonts, and images
- **Dependencies**: Composer packages and vendor libraries
- **Configuration**: Settings and environment-specific configs

### Risk Assessment

- **Blast Radius**: Single WooCommerce site
- **Data Impact**: Auction data, bid history, user information
- **Rollback Complexity**: Low (schema versioning, backups)
- **Risk Level**: Medium (database migrations)

---

## Prerequisites

### Required Capabilities

- SSH access to server
- WordPress admin credentials
- Database backup access
- Git access and credentials
- Composer package manager installed

### Server Requirements

#### Minimum Hardware

```
CPU: 2 cores
RAM: 2GB minimum
Disk: 2GB free (plugin + backups)
Network: 100 Mbps connection
```

#### Required Software

```
PHP 7.3+
MySQL 5.7+ or PostgreSQL 10+
WordPress 5.0+
WooCommerce 3.8+
Composer 2.0+
Git 2.20+
```

#### Required PHP Extensions

```
- PDO with MySQL driver
- JSON
- CURL
- OpenSSL
- Zip
- SPL
- Reflection
- SimpleXML
```

### Pre-Deployment Whitelist

```
[ ] SSH access to production server verified
[ ] Database credentials confirmed
[ ] Backup location verified and accessible
[ ] Staging environment mirrors production config
[ ] All team members notified of deployment window
[ ] Monitoring dashboards ready
[ ] Rollback procedures tested
[ ] Deployment scripts syntax checked
```

---

## Preflight Checks

### 1. Infrastructure Health Validation

```bash
#!/bin/bash
# Check system resources
echo "=== Server Health Check ==="
df -h /
free -h
uptime
ps aux | wc -l

# Check database connectivity
echo "=== Database Connectivity ==="
mysql -h $DB_HOST -u $DB_USER -p$DB_PASS -e "SELECT 1;"

# Check WordPress
echo "=== WordPress Status ==="
curl -I https://your-site.com
```

### 2. Application Baseline

```bash
# Capture current state
wp option get siteurl
wp plugin list | grep yith-auctions
wp db query "SELECT COUNT(*) FROM wp_posts WHERE post_type='auction';"

# Check plugin status
wp plugin status yith-auctions-for-woocommerce
```

### 3. Backup Verification

```bash
# Verify latest backup exists and is accessible
ls -lh /backups/wp-*.sql.gz

# Test backup restoration (in isolated environment)
mysql < /backups/wp-test-restore.sql
```

### 4. Dependency Resolution

```bash
# Check Composer dependencies
composer validate
composer check-platform-reqs

# Verify all required extensions
php -m | grep -E "pdo|json|curl|openssl|zip|spl|reflection"
```

### 5. Go/No-Go Decision Checklist

```
[ ] All preflight checks passed
[ ] Database backup completed and verified
[ ] All team members available for deployment
[ ] No critical issues in production monitoring
[ ] Staging deployment successful
[ ] Rollback procedure tested
[ ] Communication channels established
[ ] Authorized by deployment approver
```

---

## Development Deployment

### Local Development Setup

```bash
# Clone repository
git clone https://github.com/your-org/yith-auctions-for-woocommerce.git
cd yith-auctions-for-woocommerce

# Install dependencies
composer install --no-optimize --dev

# Copy to WordPress plugins directory
cp -r . /path/to/wordpress/wp-content/plugins/yith-auctions-for-woocommerce

# Run database setup (if new installation)
wp db create
wp core install --url=http://localhost:8000 --title="Test" --admin_user=admin --admin_email=admin@test.local

# Activate plugin and dependencies
wp plugin activate yith-auctions-for-woocommerce
```

### Development Verification

```bash
# Verify plugin loaded
wp plugin list | grep yith-auctions

# Check for errors
tail -f /path/to/wordpress/wp-content/debug.log

# Run tests
composer test

# Check code quality
composer lint
composer phpstan
```

---

## Staging Deployment

### Pre-Staging Steps

```bash
# Tag release
git tag -a v1.0.0 -m "Release version 1.0.0"
git push origin v1.0.0

# Create deployment artifact
mkdir deployment
cp -r plugin-files deployment/
composer install --no-dev --no-optimize
cp -r vendor deployment/
tar -czf yith-auctions-1.0.0.tar.gz deployment/
```

### Staging Installation

```bash
# Connect to staging server
ssh staging-server

# Navigate to plugin directory
cd /var/www/staging/wp-content/plugins

# Download and extract release
wget https://releases.company.com/yith-auctions-1.0.0.tar.gz
tar -xzf yith-auctions-1.0.0.tar.gz

# Install dependencies
cd yith-auctions-for-woocommerce
composer install --no-dev --optimize

# Run migrations
wp yith-auctions migrate --env=staging

# Activate plugin
wp plugin activate yith-auctions-for-woocommerce
```

### Staging Verification (Immediate - 0-2 minutes)

```bash
# Plugin activation success
wp plugin list | grep yith-auctions | grep active

# No fatal errors
curl -I https://staging.site.com/wp-admin
grep -c "Fatal error" /var/log/php-errors.log

# Database schema updated
wp db query "DESC yith_auction_batch_jobs;"
wp db query "SHOW TABLES LIKE 'yith_auction%';"
```

### Staging Verification (Short-term - 2-5 minutes)

```bash
# Core functionality
curl https://staging.site.com/wp-json/wc/v3/products | grep -c "auction"

# Create a test auction
wp eval 'echo "Test auction creation...";'

# Check logs for warnings
grep -i "warning\|notice" /var/log/php-errors.log | wc -l

# API responses normal
curl -s https://staging.site.com/wp-json/yith/auctions/v1/health | jq .
```

### Staging Verification (Medium-term - 5-15 minutes)

```bash
# Performance metrics
ab -n 100 -c 10 https://staging.site.com/

# Random user actions
wp user list | tail -1 | awk '{print $1}' > /tmp/test-user.txt
# Have QA team test key workflows

# Check resource usage
free -h
df -h /
```

---

## Production Deployment

### Maintenance Mode

```bash
# Enable maintenance mode (prevents user access)
wp maintenance-mode on --reason="YITH Auctions update - 15 minutes"

# Verify maintenance page displays
curl https://your-site.com
```

### Production Installation

```bash
# Connect to production server
ssh prod-server

# Create backup (pre-deployment)
mysqldump -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME | gzip > /backups/pre-deployment-$(date +%Y%m%d-%H%M%S).sql.gz
wp plugin deactivate --all

# Download release
cd /var/www/production/wp-content/plugins
wget https://releases.company.com/yith-auctions-1.0.0.tar.gz --https-only

# Verify checksum
sha256sum yith-auctions-1.0.0.tar.gz

# Extract and install
tar -xzf yith-auctions-1.0.0.tar.gz
cd yith-auctions-for-woocommerce
composer install --no-dev --optimize --prefer-dist
```

### Production Migrations

```bash
# Run database migrations with transaction safety
wp yith-auctions migrate --env=production --transaction

# Log migration output
wp yith-auctions migrate --env=production > /var/log/yith-migrations-$(date +%Y%m%d-%H%M%S).log 2>&1

# Verify migration success
echo $?  # Should return 0
```

### Production Activation

```bash
# Activate plugin
wp plugin activate yith-auctions-for-woocommerce

# Flush caches
wp cache flush
wp rewrite flush

# Disable maintenance mode
wp maintenance-mode off
```

---

## Database Migrations

### Pre-Migration Backup

```bash
# Full database backup
mysqldump -h $DB_HOST -u $DB_USER -p$DB_PASS \
  --single-transaction \
  --lock-tables=false \
  $DB_NAME > /backups/pre-migration-$(date +%Y%m%d-%H%M%S).sql

# Compressed backup
gzip /backups/pre-migration-*.sql
```

### Migration Execution

```bash
# List pending migrations
wp yith-auctions migrate --list

# Dry-run (preview changes)
wp yith-auctions migrate --dry-run --env=production

# Execute with logging
wp yith-auctions migrate \
  --env=production \
  --transaction \
  --verbose \
  2>&1 | tee /var/log/migrations.log

# Check status
echo $?  # 0 = success
wp db query "SELECT * FROM yith_audit_log WHERE event_type='migration' ORDER BY created_at DESC LIMIT 5;"
```

### Post-Migration Verification

```bash
# Verify schema
wp db query "SHOW TABLES LIKE 'yith_auction%';"
wp db query "DESC yith_auction_batch_jobs;"

# Check for data integrity
wp db query "SELECT COUNT(*) FROM yith_auctions WHERE status NOT IN ('open', 'closed', 'sold');"

# Verify indexes
wp db query "SHOW INDEX FROM yith_auctions;"
```

---

## Verification Procedures

### Immediate Verification (0-2 minutes)

```bash
# 1. Plugin activation
wp plugin list | grep yith-auctions-for-woocommerce | grep active

# 2. No fatal errors in error log
if grep -q "Fatal" /var/log/php-errors.log; then
  echo "FAILURE: Fatal errors detected"
  exit 1
fi

# 3. Database connection working
wp db query "SELECT 1;"

# 4. Core admin pages accessible
curl -s https://your-site.com/wp-admin/ | grep -q "Dashboard"
```

### Short-term Verification (2-5 minutes)

```bash
# 1. API endpoints responding
curl https://your-site.com/wp-json/yith/auctions/v1/health | jq '.status'

# 2. Auction listing page loads
curl https://your-site.com/auctions | grep -q "auction"

# 3. Error rate normal
curl -s https://your-site.com/wp-admin | grep -c "error"

# 4. Database operations normal
wp eval 'echo get_option("yith_auctions_version");'
```

### Medium-term Verification (5-15 minutes)

```bash
# 1. Background jobs processing
wp yith-auctions jobs list | head -5

# 2. Auction status updates working
wp eval 'do_action("yith_auctions_check_expired");'

# 3. User permissions intact
wp eval 'echo current_user_can("manage_woocommerce") ? "OK" : "FAIL";'

# 4. Site performance acceptable
ab -n 100 -c 10 https://your-site.com | grep "Requests per second"
```

### Success Criteria

```
[ ] Plugin activated and loaded
[ ] No fatal PHP errors
[ ] Database schema matches expected version
[ ] API endpoints responding (200 OK)
[ ] Core auction functionality working
[ ] Auction listings display correctly
[ ] Bid submission working
[ ] Admin interface responsive
[ ] No significant performance degradation
[ ] Error logs clean of deployment-related errors
```

---

## Rollback Procedures

### When to Rollback

**Immediate Rollback If**:
- Fatal PHP errors blocking site access
- Database migrations failed irreversibly
- API endpoints returning 500 errors
- > 5% requests failing

**Consider Rollback If**:
- > 20% degradation in response time
- High CPU/memory usage not resolving
- Critical business workflow broken

### Automated Rollback

```bash
#!/bin/bash
# Rollback script
set -e

echo "Starting rollback to previous version..."

# Create pre-rollback backup
mysqldump -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME | gzip > /backups/pre-rollback-$(date +%Y%s).sql.gz

# Deactivate current version
wp plugin deactivate yith-auctions-for-woocommerce

# Restore previous version
cd /var/www/production/wp-content/plugins
rm -rf yith-auctions-for-woocommerce
wget https://releases.company.com/yith-auctions-0.9.0.tar.gz
tar -xzf yith-auctions-0.9.0.tar.gz

# Restore database
mysql -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME < /backups/pre-deployment-*.sql | head -1

# Reactivate
wp plugin activate yith-auctions-for-woocommerce

# Flush caches
wp cache flush

echo "Rollback completed successfully"
```

### Manual Rollback Steps

1. **Enable Maintenance Mode**
   ```bash
   wp maintenance-mode on --reason="Rollback in progress"
   ```

2. **Restore Database**
   ```bash
   # Identify backup file
   ls -lh /backups/*.sql.gz | head -1
   
   # Restore (create new connection, verify size)
   gunzip < /backups/pre-deployment-TIMESTAMP.sql.gz | mysql -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME
   ```

3. **Restore Plugin Files**
   ```bash
   # Remove failed version
   rm -rf /var/www/production/wp-content/plugins/yith-auctions-for-woocommerce
   
   # Restore from version control
   cd /var/www/production/wp-content/plugins
   git clone -b v0.9.0 https://github.com/your-org/yith-auctions-for-woocommerce.git
   cd yith-auctions-for-woocommerce
   composer install --no-dev --optimize
   ```

4. **Reactivate Plugin**
   ```bash
   wp plugin activate yith-auctions-for-woocommerce
   wp cache flush
   ```

5. **Post-Rollback Verification**
   ```bash
   # Verify system restored
   wp plugin list | grep yith-auctions
   wp db query "SELECT COUNT(*) FROM wp_posts WHERE post_type='auction';"
   ```

6. **Disable Maintenance Mode**
   ```bash
   wp maintenance-mode off
   ```

### Rollback Communication

- **Immediately**: Notify all stakeholders (email + Slack)
- **Every 5 minutes**: Status update to leadership
- **Post-completion**: Full incident report and root cause analysis

---

## Post-Deployment Tasks

### Immediate (0-30 minutes)

- [ ] Verify all checks passed
- [ ] Review error logs and application logs
- [ ] Notify stakeholders of successful deployment
- [ ] Monitor resource usage (CPU, memory, disk)
- [ ] Test core workflows from user perspective
- [ ] Check auction creation and bidding functionality

### Short-term (1-2 hours)

- [ ] Review performance metrics
- [ ] Check batch job execution
- [ ] Verify email notifications sending correctly
- [ ] Test API endpoints with multiple requests
- [ ] Confirm database backups scheduled and working
- [ ] Review monitoring dashboards for anomalies

### Medium-term (24 hours)

- [ ] Post-deployment meeting with team
- [ ] Analyze usage patterns and performance
- [ ] Review all application and system logs
- [ ] Confirm no user-reported issues
- [ ] Check error reporting system for issues
- [ ] Performance baseline established

### Long-term (1 week)

- [ ] Post-deployment review meeting
- [ ] Analyze metrics vs. pre-deployment baseline
- [ ] Document lessons learned
- [ ] Update deployment runbook with findings
- [ ] Archive deployment logs and backups
- [ ] Plan any follow-up optimizations

---

## Troubleshooting

### Plugin Not Activating

**Symptoms**: "Plugin failed to activate" message

**Solutions**:
```bash
# Check PHP errors
tail -100 /var/log/php-errors.log

# Verify file permissions
ls -la /var/www/production/wp-content/plugins/yith-auctions-for-woocommerce/

# Check for syntax errors
php -l init.php

# Verify dependencies installed
ls vendor/autoload.php
```

### Database Migration Failed

**Symptoms**: Tables not created, migration script stuck

**Solutions**:
```bash
# Check migration status
wp db query "SELECT * FROM yith_audit_log WHERE event_type='migration' ORDER BY created_at DESC LIMIT 1;"

# Verify transaction support
wp db query "SHOW ENGINES;" | grep -i innodb

# Retry migration with logging
wp yith-auctions migrate --dry-run --verbose
```

### High Response Times

**Symptoms**: Slow page loads, timeouts

**Solutions**:
```bash
# Check query performance
wp eval 'echo ini_get("max_execution_time");'

# Monitor active queries
mysql -h $DB_HOST -u $DB_USER -p$DB_PASS -e "SHOW PROCESSLIST;"

# Check for missing indexes
wp yith-auctions analyze-indexes

# Consider enabling query cache
wp option update yith_auction_query_cache 1
```

### Memory Issues

**Symptoms**: Out of memory errors, PHP processes killed

**Solutions**:
```bash
# Check current limit
php -i | grep "memory_limit"

# Increase if needed (in php.ini)
sed -i 's/memory_limit = .*/memory_limit = 512M/' /etc/php/7.4/apache2/php.ini

# Monitor usage
free -h
top -b -n 1 | head -12
```

---

## Support & Escalation

### Deployment Issues

**Tier 1 Escalation** (15 minutes)
- Check deployment logs
- Run preflight checks
- Verify database connectivity
- Contact: Senior DevOps Engineer

**Tier 2 Escalation** (30 minutes)
- Analyze application logs
- Check performance metrics
- Review code for regressions
- Contact: Platform Engineering Lead

**Tier 3 Escalation** (1 hour)
- Database forensics
- Infrastructure investigation
- Vendor support engagement
- Contact: CTO / Engineering Director

### Emergency Contacts

```
Primary On-Call:    [NAME]  [PHONE]
Secondary On-Call:  [NAME]  [PHONE]
DevOps Lead:        [NAME]  [PHONE]
Database Admin:     [NAME]  [PHONE]
Network Team:       [NAME]  [PHONE]
```

### Post-Deployment Support

- **Hours 0-2**: Continuous monitoring, ready for rollback
- **Hours 2-24**: Enhanced monitoring, quick response team
- **Days 1-7**: Standard monitoring with daily review
- **Week 2+**: Normal operations

---

## Documentation References

- [System Architecture](./ARCHITECTURE.md)
- [Database Schema](./PROJECT_DOCS/Architecture/DATABASE_SCHEMA.md)
- [API Documentation](./PROJECT_DOCS/Integration/API_DOCUMENTATION.md)
- [Monitoring Guide](./docs/MONITORING_GUIDE.md)
- [Troubleshooting Guide](./TROUBLESHOOTING_GUIDE.md)

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | 2026-03-30 | Initial comprehensive deployment guide |

---

**Last Updated**: 2026-03-30  
**Next Review**: 2026-06-30  
**Document Owner**: Platform Engineering Team
