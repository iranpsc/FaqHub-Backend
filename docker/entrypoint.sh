#!/bin/sh
set -eu

echo "[entrypoint] starting FaqHub (role=${CONTAINER_ROLE:-app})"

mkdir -p \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/storage/app/private \
    /var/www/html/bootstrap/cache \
    /var/www/html/public/sitemaps

# App writes to public/sitemap; host data is bind-mounted at public/sitemaps
if [ ! -e /var/www/html/public/sitemap ]; then
    ln -sfn sitemaps /var/www/html/public/sitemap
fi

wait_for_host() {
    host="$1"
    port="$2"
    name="$3"
    attempts="${4:-60}"

    echo "[entrypoint] waiting for ${name} at ${host}:${port}..."
    i=0
    while [ "$i" -lt "$attempts" ]; do
        if php -r "exit(@fsockopen('${host}', ${port}) ? 0 : 1);" 2>/dev/null; then
            echo "[entrypoint] ${name} is ready"
            return 0
        fi
        i=$((i + 1))
        sleep 1
    done

    echo "[entrypoint] timed out waiting for ${name}" >&2
    return 1
}

if [ -n "${DB_HOST:-}" ]; then
    wait_for_host "${DB_HOST}" "${DB_PORT:-3306}" "database"
fi

if [ -n "${REDIS_HOST:-}" ]; then
    wait_for_host "${REDIS_HOST}" "${REDIS_PORT:-6379}" "redis"
fi

# Dev bind-mounts often replace vendor/; install when missing
if [ "${COMPOSER_INSTALL:-false}" = "true" ] && [ -f /var/www/html/composer.json ]; then
    if [ ! -f /var/www/html/vendor/autoload.php ]; then
        echo "[entrypoint] installing Composer dependencies..."
        composer install --no-interaction --prefer-dist
    fi
fi

if [ ! -f /var/www/html/.env ] && [ -f /var/www/html/.env.example ] && [ "${COMPOSER_INSTALL:-false}" = "true" ]; then
    echo "[entrypoint] copying .env.example -> .env"
    cp /var/www/html/.env.example /var/www/html/.env
fi

# Generate APP_KEY only when missing and a writable .env exists (dev bind mounts).
# In production, set APP_KEY via env_file / Dokploy secrets so all replicas share it.
if [ -z "${APP_KEY:-}" ] && [ -f /var/www/html/.env ] && [ -f /var/www/html/artisan ]; then
    if ! grep -qE '^APP_KEY=base64:.+' /var/www/html/.env 2>/dev/null; then
        echo "[entrypoint] generating APP_KEY into .env..."
        php artisan key:generate --force --no-interaction || true
    fi
fi

if [ -z "${APP_KEY:-}" ]; then
    echo "[entrypoint] WARNING: APP_KEY is empty. Set it in .env before production use." >&2
fi

# Ensure writable dirs for the php-fpm worker user
chown -R faqhub:faqhub \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache \
    /var/www/html/public/sitemaps 2>/dev/null || true

if [ "${RUN_STORAGE_LINK:-false}" = "true" ] && [ -f /var/www/html/artisan ]; then
    echo "[entrypoint] creating storage symlink (public/storage -> storage/app/public)..."
    php artisan storage:link --force --no-interaction
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ] && [ -f /var/www/html/artisan ]; then
    echo "[entrypoint] running migrations..."
    php artisan migrate --force --no-interaction
fi

if [ "${CACHE_CONFIG:-false}" = "true" ] && [ -f /var/www/html/artisan ]; then
    echo "[entrypoint] caching config/routes/views..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache || true
fi

role="${CONTAINER_ROLE:-app}"

case "$role" in
    app)
        exec "$@"
        ;;
    queue)
        echo "[entrypoint] starting queue worker..."
        exec php artisan queue:work "${QUEUE_CONNECTION:-redis}" \
            --sleep="${QUEUE_SLEEP:-3}" \
            --tries="${QUEUE_TRIES:-3}" \
            --max-time="${QUEUE_MAX_TIME:-3600}" \
            --memory="${QUEUE_MEMORY:-128}"
        ;;
    scheduler)
        echo "[entrypoint] starting scheduler loop..."
        while true; do
            php artisan schedule:run --verbose --no-interaction
            sleep 60
        done
        ;;
    reverb)
        echo "[entrypoint] starting Reverb..."
        exec php artisan reverb:start \
            --host="${REVERB_SERVER_HOST:-0.0.0.0}" \
            --port="${REVERB_SERVER_PORT:-8080}"
        ;;
    *)
        exec "$@"
        ;;
esac
