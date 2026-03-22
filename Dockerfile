# Multi-stage build for YITH Auctions for WooCommerce

# Stage 1: Builder
FROM php:8.1-fpm as builder

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    wget \
    libzip-dev \
    libmcrypt-dev \
    libmemcached-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    zip \
    mysqli \
    pdo \
    pdo_mysql \
    && pecl install memcached \
    && docker-php-ext-enable memcached

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

# Copy composer files
COPY composer.json composer.lock ./

# Install dependencies
RUN composer install --optimize-autoloader --no-dev

# Stage 2: Runtime
FROM php:8.1-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    curl \
    libzip4 \
    libmemcached11 \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    zip \
    mysqli \
    pdo \
    pdo_mysql \
    && pecl install memcached \
    && docker-php-ext-enable memcached

# Copy PHP production configuration
COPY docker/php.ini /usr/local/etc/php/php.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# Copy Nginx configuration
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/default.conf /etc/nginx/conf.d/default.conf

# Copy Supervisor configuration
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy application from builder
COPY --from=builder /app /var/www/html
RUN chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html

# Create necessary directories
RUN mkdir -p /var/log/supervisor \
    && mkdir -p /var/cache/nginx \
    && chown -R www-data:www-data /var/cache/nginx

# Expose ports
EXPOSE 80 9000

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

# Run Supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
