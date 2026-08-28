# syntax=docker/dockerfile:1.7

# -----------------------------------------------------------------------------
# Stage 1: Frontend assets (Vite)
# -----------------------------------------------------------------------------
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci \
    && npm cache clean --force

COPY vite.config.js postcss.config.js tailwind.config.js ./
COPY resources ./resources
COPY public ./public

RUN npm run build

# -----------------------------------------------------------------------------
# Stage 2: PHP Composer dependencies
# -----------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --no-interaction \
    --prefer-dist \
    --ignore-platform-reqs \
    && composer clear-cache

COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-interaction

# -----------------------------------------------------------------------------
# Stage 3: Production PHP-FPM runtime
# -----------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS app

LABEL org.opencontainers.image.title="FaqHub Backend" \
      org.opencontainers.image.description="Laravel API for FaqHub"

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PHP_OPCACHE_ENABLE=1 \
    PHP_MEMORY_LIMIT=256M \
    PHP_UPLOAD_MAX_FILESIZE=20M \
    PHP_POST_MAX_SIZE=20M

RUN apk add --no-cache \
        curl \
        icu-libs \
        libzip \
        freetype \
        libjpeg-turbo \
        libpng \
        libwebp \
        oniguruma \
        linux-headers \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        ftp \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del --no-network \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
        oniguruma-dev \
        linux-headers \
    && rm -rf /tmp/pear /var/cache/apk/*

RUN addgroup -g 1000 -S faqhub \
    && adduser -u 1000 -S faqhub -G faqhub \
    && mkdir -p \
        /var/www/html/storage/framework/cache/data \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/framework/testing \
        /var/www/html/storage/logs \
        /var/www/html/storage/app/private \
        /var/www/html/storage/app/public \
        /var/www/html/public/sitemaps \
        /var/www/html/bootstrap/cache \
    && chown -R faqhub:faqhub /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-faqhub.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

COPY --from=vendor --chown=faqhub:faqhub /app /var/www/html

RUN chown -R faqhub:faqhub storage bootstrap/cache public/sitemaps

# php-fpm master runs as root; pool workers run as faqhub (see www.conf)
EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=45s --retries=3 \
    CMD php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);'

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]

# -----------------------------------------------------------------------------
# Stage 4: Nginx (serves static assets + proxies PHP)
# -----------------------------------------------------------------------------
FROM nginx:1.27-alpine AS nginx

COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

RUN mkdir -p /var/www/html/public/sitemaps \
    && chown -R nginx:nginx /var/www/html/public

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD wget -qO- http://127.0.0.1/healthz >/dev/null || exit 1

# -----------------------------------------------------------------------------
# Stage 5: Development PHP image
# -----------------------------------------------------------------------------
FROM app AS app-dev

ENV APP_ENV=local \
    APP_DEBUG=true \
    PHP_OPCACHE_ENABLE=0

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache git unzip bash nodejs npm \
        $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del --no-network $PHPIZE_DEPS linux-headers \
    && rm -rf /tmp/pear /var/cache/apk/*

COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/20-xdebug.ini
