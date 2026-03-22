# Docker Setup Guide for YITH Auctions for WooCommerce

> 💡 **Cost Optimization Tip**: This guide covers Docker for **local development**. For **free CI/CD** without GitHub minute charges, see [LOCAL-RUNNER-SETUP.md](LOCAL-RUNNER-SETUP.md) to run the CI/CD pipeline on your local machine (self-hosted runner). This eliminates charges entirely!

## Quick Start

### Prerequisites
- Docker Desktop (Windows/Mac) or Docker Engine (Linux)
- Docker Compose v1.29+
- Git
- 4GB RAM minimum for Docker
- 10GB disk space available

### Initial Setup

1. **Clone the repository**
   ```bash
   git clone https://github.com/ksfraser/yith-auctions-for-woocommerce.git
   cd yith-auctions-for-woocommerce
   ```

2. **Copy environment configuration**
   ```bash
   cp .env.example .env
   ```
   Edit `.env` if you need custom database credentials.

3. **Build and start containers**
   ```bash
   docker-compose up -d
   ```
   This will:
   - Create MySQL database
   - Start WordPress with WooCommerce
   - Launch Nginx reverse proxy
   - Set up Memcached and Redis
   - Start PHPMyAdmin and MailHog

4. **Initial WordPress setup**
   ```bash
   # Install WordPress core
   docker-compose exec wordpress wp core install \
     --url=http://localhost \
     --title="YITH Auctions" \
     --admin_user=admin \
     --admin_password=password \
     --admin_email=admin@localhost.local
   ```

5. **Activate WooCommerce and YITH Auctions**
   ```bash
   # Activate WooCommerce
   docker-compose exec wordpress wp plugin activate woocommerce
   
   # Activate YITH Auctions
   docker-compose exec wordpress wp plugin activate yith-auctions-for-woocommerce
   ```

### Access URLs

- **WordPress Admin**: http://localhost/wp-admin
- **WordPress Frontend**: http://localhost
- **PHPMyAdmin**: http://localhost:8080
- **MailHog**: http://localhost:8025
- **PHP-FPM**: localhost:9000

## Service Details

### Database (MySQL 8.0)
- **Host**: `db` (from within Docker) or `localhost:3306` (from host)
- **Username**: `auction_user`
- **Password**: `auction_password`
- **Database**: `yith_auctions`
- **Port**: 3306

### WordPress with PHP-FPM
- **WordPress Root**: `/var/www/html`
- **Plugin Path**: `/var/www/html/wp-content/plugins/yith-auctions-for-woocommerce`
- **Port**: 9000 (PHP-FPM)
- **PHP Version**: 8.1

### Nginx Reverse Proxy
- **Port**: 80
- **Configuration**: `docker/default.conf`
- **Features**: 
  - Static file caching
  - Gzip compression
  - Security headers
  - Health check endpoint

### Memcached
- **Host**: `memcached` (internal) or `localhost:11211` (from host)
- **Port**: 11211
- **Used for**: Session and object caching

### Redis
- **Host**: `redis` (internal) or `localhost:6379` (from host)
- **Port**: 6379
- **Used for**: Session storage (alternative to Memcached)

### MailHog
- **SMTP Host**: `mailhog`
- **SMTP Port**: 1025
- **Web UI**: http://localhost:8025
- **Used for**: Email testing in development

## Docker Compose Commands

### View logs
```bash
# All services
docker-compose logs -f

# Specific service
docker-compose logs -f wordpress
docker-compose logs -f nginx
docker-compose logs -f db
```

### Execute commands in container
```bash
# WordPress WP-CLI
docker-compose exec wordpress wp plugin list
docker-compose exec wordpress wp user list

# MySQL
docker-compose exec db mysql -u auction_user -p yith_auctions

# PHP
docker-compose exec wordpress php -v
```

### Stop and remove containers
```bash
# Stop services (data preserved)
docker-compose stop

# Remove containers (data preserved in volumes)
docker-compose down

# Remove everything including volumes
docker-compose down -v
```

### Restart services
```bash
docker-compose restart

# Restart specific service
docker-compose restart wordpress
```

## Development Workflow

### Running Tests
```bash
# Run all tests
docker-compose exec wordpress composer test

# Run specific test file
docker-compose exec wordpress ./vendor/bin/phpunit tests/AuctionWorkflowIntegrationTest.php

# Run with coverage
docker-compose exec wordpress ./vendor/bin/phpunit --coverage-html=coverage
```

### Code Analysis
```bash
# PHPStan
docker-compose exec wordpress ./vendor/bin/phpstan analyse

# PHPMD
docker-compose exec wordpress ./vendor/bin/phpmd includes xml .phpmd.xml

# PHPCS
docker-compose exec wordpress ./vendor/bin/phpcs includes --standard=phpcs.xml.dist
```

### Debugging with XDebug

1. **Enable XDebug in .env**
   ```
   ENABLE_XDEBUG=true
   XDEBUG_MODE=debug
   ```

2. **Restart containers**
   ```bash
   docker-compose restart wordpress
   ```

3. **Configure your IDE** (VS Code example)
   - Install PHP Debug extension
   - Set breakpoints in code
   - Start debugging

## Troubleshooting

### Port already in use
```bash
# Find process using port 80
lsof -i :80

# Kill process
kill -9 <PID>
```

### Database connection error
```bash
# Check MySQL is running
docker-compose ps db

# Check MySQL logs
docker-compose logs db

# Reset database
docker-compose down -v
docker-compose up -d
```

### Permission denied errors
```bash
# Reset permissions
docker-compose exec wordpress chown -R www-data:www-data /var/www/html
```

### Out of memory
```bash
# Check Docker resource limits
docker system df

# Increase Docker memory in Docker Desktop settings
# Recommended: 4GB minimum, 6GB+ for comfortable development
```

### Slow performance on Windows/Mac
```bash
# Use WSL2 backend (Windows)
# Use native file system instead of shared volumes where possible
# Consider disabling Memcached/Redis if not needed
```

## Cleanup and Maintenance

### Remove orphaned containers
```bash
docker-compose down --remove-orphans
```

### Prune unused Docker resources
```bash
docker system prune -a
```

### Rebuild containers
```bash
docker-compose up --build
```

### Update to latest images
```bash
docker-compose pull
docker-compose up -d
```

## Production Deployment

For production use:

1. **Use environment-specific compose files**
   ```bash
   docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d
   ```

2. **Use managed databases** (AWS RDS, Azure Database, etc.)

3. **Use container orchestration** (Kubernetes, Docker Swarm)

4. **Configure SSL/TLS** with Let's Encrypt

5. **Use Docker secrets** for sensitive configuration

6. **Implement health checks** and monitoring

7. **Use resource limits** and requests

See `docker-compose.prod.yml` (if available) for production configuration.

## Additional Resources

- [Docker Documentation](https://docs.docker.com/)
- [Docker Compose Documentation](https://docs.docker.com/compose/)
- [WordPress Docker Examples](https://hub.docker.com/_/wordpress)
- [MySQL Docker Documentation](https://hub.docker.com/_/mysql)

## Support

For issues or questions about Docker setup:

1. Check Docker logs: `docker-compose logs`
2. Review this guide's troubleshooting section
3. Open an issue on GitHub
4. See CONTRIBUTING.md for development setup assistance
