# ─────────────────────────────────────────────────────────────────────────
# Stage 1: Build dependencies
# ─────────────────────────────────────────────────────────────────────────
FROM php:8.3-cli-alpine AS builder

RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    $PHPIZE_DEPS

RUN docker-php-ext-install \
    pdo \
    pdo_sqlite \
    mbstring \
    xml \
    zip \
    bcmath \
    curl

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy dependency files first (layer caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

# Copy source
COPY . .

# Generate autoloader
RUN composer dump-autoload --optimize --no-dev

# ─────────────────────────────────────────────────────────────────────────
# Stage 2: Runtime
# ─────────────────────────────────────────────────────────────────────────
FROM php:8.3-cli-alpine AS runtime

# Install runtime extensions
RUN apk add --no-cache \
    libzip \
    icu-libs \
    $PHPIZE_DEPS

RUN docker-php-ext-install \
    pdo \
    pdo_sqlite \
    mbstring \
    xml \
    zip \
    bcmath \
    curl

# Install jq (used by your bash scripts)
RUN apk add --no-cache jq

# Copy built app from builder
COPY --from=builder /app /app

# Copy entrypoint
COPY entrypoint.sh /app/entrypoint.sh
RUN chmod +x /app/entrypoint.sh

# Create data directory (volume mount point for overlays)
RUN mkdir -p /app/data/agents/custom/workflows \
             /app/data/agents/custom/skills

# Set up non-root user
RUN addgroup -S pnc && adduser -S pnc -G pnc \
    && chown -R pnc:pnc /app
USER pnc

WORKDIR /app

# OPcache tuning for production
RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.memory_consumption=128"; \
    echo "opcache.max_accelerated_files=20000"; \
    echo "opcache.validate_timestamps=0"; \
    echo "opcache.interned_strings_buffer=16"; \
    echo "opcache.fast_shutdown=1"; \
    echo "realpath_cache_size=4096K"; \
    echo "realpath_cache_ttl=600"; \
    echo "memory_limit=256M"; \
    echo "upload_max_filesize=32M"; \
    echo "post_max_size=32M"; \
} > /usr/local/etc/php/conf.d/pnc-production.ini

EXPOSE 8000

ENTRYPOINT ["/app/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
