# syntax=docker/dockerfile:1

# Coffer: self-hosted, all-in-one image (web + queue worker + scheduler).
# SQLite for relational data; uploaded files are stored on the local filesystem
# under /data/shares (each share in its own directory; see docker-compose.yml).

# ---------------------------------------------------------------------------
# Stage 1: PHP dependencies (Composer)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# Install dependencies first (cached unless composer.json/lock change).
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

# Copy the rest of the application and finish the autoloader (runs
# package:discover via the post-autoload-dump script).
COPY . .
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

# ---------------------------------------------------------------------------
# Stage 2: Front-end assets (Vite + Tailwind + Flux)
# ---------------------------------------------------------------------------
FROM node:24-bookworm-slim AS assets

WORKDIR /app

# The CSS build (@import / @source) reads vendor/livewire/flux, so the vendor
# directory must be present before building.
COPY package.json package-lock.json ./
RUN npm ci

COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 3: Runtime (FrankenPHP)
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.5 AS runtime

WORKDIR /app

# PHP extensions this app needs at runtime, plus a production php.ini.
RUN install-php-extensions \
        pcntl \
        pdo_sqlite \
        opcache \
        intl \
        zip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Uploads arrive as resumable 50 MB chunks (config/coffer.php), so a request body never spans a whole file;
# post_max_size only needs to clear one chunk.
RUN { \
        echo 'post_max_size = 64M'; \
        echo 'max_execution_time = 600'; \
    } > "$PHP_INI_DIR/conf.d/zz-coffer-uploads.ini"

# Process supervisor (runs web + queue + scheduler in one container) and curl
# (used by the container HEALTHCHECK).
RUN apt-get update \
    && apt-get install -y --no-install-recommends supervisor curl \
    && rm -rf /var/lib/apt/lists/*

# Non-root runtime user.
RUN useradd --system --create-home --uid 1000 --shell /usr/sbin/nologin appuser

# Application code, with built vendor + public/build from previous stages.
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build

# Container configuration.
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Defaults tuned for the SQLite all-in-one setup. Override at run time as needed.
ENV APP_NAME=Coffer \
    APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database/database.sqlite \
    DB_BUSY_TIMEOUT=5000 \
    DB_JOURNAL_MODE=WAL \
    DB_SYNCHRONOUS=NORMAL \
    QUEUE_CONNECTION=database \
    CACHE_STORE=file \
    SESSION_DRIVER=database \
    FILESYSTEM_DISK=local \
    LOG_CHANNEL=stderr \
    COFFER_STORAGE_PATH=/data/shares \
    SERVER_NAME=:8080 \
    RUN_MIGRATIONS=true \
    XDG_CONFIG_HOME=/data/caddy/config \
    XDG_DATA_HOME=/data/caddy/data

# Persisted state: the SQLite database and on-box framework state.
VOLUME /data

# Uploaded files. Every share lives in a directory under here; mount host
# directories or volumes onto subpaths of /data/shares to place shares on
# specific disks (see docker-compose.yml).
VOLUME /data/shares

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["entrypoint"]
