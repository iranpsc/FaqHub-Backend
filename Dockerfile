# Iranian mirror defaults (override at build time if needed)
ARG DOCKER_REGISTRY=docker.arvancloud.ir
ARG ALPINE_MIRROR=https://mirror.arvancloud.ir/alpine
ARG NPM_REGISTRY=https://package-mirror.liara.ir/repository/npm/
ARG COMPOSER_MIRROR=https://package-mirror.liara.ir/repository/composer/

# -----------------------------------------------------------------------------
# Stage 1: Frontend assets (Vite)
# -----------------------------------------------------------------------------
FROM ${DOCKER_REGISTRY}/node:22-alpine AS frontend

ARG ALPINE_MIRROR
ARG NPM_REGISTRY

WORKDIR /app

COPY docker/mirrors/configure-alpine-mirror.sh /tmp/configure-alpine-mirror.sh
RUN chmod +x /tmp/configure-alpine-mirror.sh \
    && /tmp/configure-alpine-mirror.sh \
    && rm /tmp/configure-alpine-mirror.sh

RUN npm config set registry "${NPM_REGISTRY}"

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
FROM ${DOCKER_REGISTRY}/composer:2 AS composer-bin

FROM composer-bin AS vendor

ARG COMPOSER_MIRROR

WORKDIR /app

COPY docker/mirrors/configure-composer-mirror.sh /tmp/configure-composer-mirror.sh
RUN chmod +x /tmp/configure-composer-mirror.sh \
    && /tmp/configure-composer-mirror.sh \
    && rm /tmp/configure-composer-mirror.sh

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
FROM ${DOCKER_REGISTRY}/php:8.4-fpm-alpine AS app

ARG ALPINE_MIRROR

LABEL org.opencontainers.image.title="FaqHub Backend" \
      org.opencontainers.image.description="Laravel API for FaqHub"

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PHP_OPCACHE_ENABLE=1 \
    PHP_MEMORY_LIMIT=256M \
    PHP_UPLOAD_MAX_FILESIZE=20M \
    PHP_POST_MAX_SIZE=20M

COPY docker/mirrors/configure-alpine-mirror.sh /tmp/configure-alpine-mirror.sh
COPY docker/mirrors/install-pecl-extension.sh /tmp/install-pecl-extension.sh
RUN chmod +x /tmp/configure-alpine-mirror.sh \
    && /tmp/configure-alpine-mirror.sh \
    && rm /tmp/configure-alpine-mirror.sh

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
    && chmod +x /tmp/install-pecl-extension.sh \
    && /tmp/install-pecl-extension.sh redis 6.2.0 \
    && rm /tmp/install-pecl-extension.sh \
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
FROM ${DOCKER_REGISTRY}/nginx:1.27-alpine AS nginx

ARG ALPINE_MIRROR

COPY docker/mirrors/configure-alpine-mirror.sh /tmp/configure-alpine-mirror.sh
RUN chmod +x /tmp/configure-alpine-mirror.sh \
    && /tmp/configure-alpine-mirror.sh \
    && rm /tmp/configure-alpine-mirror.sh

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

ARG ALPINE_MIRROR
ARG DOCKER_REGISTRY

ENV APP_ENV=local \
    APP_DEBUG=true \
    PHP_OPCACHE_ENABLE=0

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer

COPY docker/mirrors/configure-composer-mirror.sh /tmp/configure-composer-mirror.sh
RUN chmod +x /tmp/configure-composer-mirror.sh \
    && /tmp/configure-composer-mirror.sh \
    && rm /tmp/configure-composer-mirror.sh

COPY docker/mirrors/configure-alpine-mirror.sh /tmp/configure-alpine-mirror.sh
COPY docker/mirrors/install-pecl-extension.sh /tmp/install-pecl-extension.sh
RUN chmod +x /tmp/configure-alpine-mirror.sh \
    && /tmp/configure-alpine-mirror.sh \
    && rm /tmp/configure-alpine-mirror.sh

RUN apk add --no-cache git unzip bash nodejs npm \
        $PHPIZE_DEPS linux-headers \
    && chmod +x /tmp/install-pecl-extension.sh \
    && /tmp/install-pecl-extension.sh xdebug 3.4.2 \
    && rm /tmp/install-pecl-extension.sh \
    && apk del --no-network $PHPIZE_DEPS linux-headers \
    && rm -rf /tmp/pear /var/cache/apk/*

COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/20-xdebug.ini
