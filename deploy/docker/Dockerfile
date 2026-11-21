# syntax=docker/dockerfile:1.7
#
# Veyra Community image. One application image runs every role (php-fpm,
# Horizon, scheduler, Reverb, migrations); a separate `web` target serves
# static assets with Nginx. Build from the repository root:
#
#   docker build -f deploy/docker/Dockerfile --target app -t veyra/app .
#   docker build -f deploy/docker/Dockerfile --target web -t veyra/web .

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

FROM node:${NODE_VERSION}-bookworm-slim AS node

# ---------------------------------------------------------------------------
# Base PHP runtime with the extensions Veyra needs.
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS base

COPY --from=mlocati/php-extension-installer:2.7 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions pdo_mysql pcntl posix intl zip bcmath opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

# phpredis is built from its pinned, checksum-verified GitHub release instead
# of PECL, so image builds do not depend on pecl.php.net availability.
ARG PHPREDIS_VERSION=6.3.0
ARG PHPREDIS_SHA256=cb8f81df1a275599e4f8ddcfec7e1f65ed1953e6f5673649149fd680ebff4cad
RUN set -eux; \
    curl -fsSL --retry 5 --retry-all-errors -o /tmp/phpredis.tar.gz \
        "https://github.com/phpredis/phpredis/archive/refs/tags/${PHPREDIS_VERSION}.tar.gz"; \
    echo "${PHPREDIS_SHA256}  /tmp/phpredis.tar.gz" | sha256sum -c -; \
    docker-php-source extract; \
    mkdir -p /usr/src/php/ext/redis; \
    tar -xzf /tmp/phpredis.tar.gz -C /usr/src/php/ext/redis --strip-components=1; \
    docker-php-ext-install -j"$(nproc)" redis; \
    docker-php-source delete; \
    rm -f /tmp/phpredis.tar.gz; \
    php -m | grep -qx redis

COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/zz-veyra.ini
COPY deploy/docker/www.conf /usr/local/etc/php-fpm.d/zz-veyra.conf

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# PHP dependencies (production only).
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# ---------------------------------------------------------------------------
# Front-end build. Wayfinder runs `php artisan` during the Vite build, so this
# stage needs both PHP and Node.
# ---------------------------------------------------------------------------
FROM vendor AS assets

# Browser-facing Reverb settings are compiled into the JavaScript bundle.
ARG VITE_APP_NAME=Veyra
ARG VITE_REVERB_APP_KEY=
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8000
ARG VITE_REVERB_SCHEME=http
ENV VITE_APP_NAME=${VITE_APP_NAME} \
    VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && APP_KEY=base64:$(head -c 32 /dev/urandom | base64) php artisan package:discover --ansi \
    && APP_KEY=base64:$(head -c 32 /dev/urandom | base64) npm run build

# ---------------------------------------------------------------------------
# Application image.
# ---------------------------------------------------------------------------
FROM base AS app

ARG VEYRA_VERSION=local
ENV VEYRA_VERSION=${VEYRA_VERSION}

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=assets /var/www/html/public/build ./public/build
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Also creates writable config/data dirs in www-data's home (/var/www) for
# CLI tools such as `php artisan tinker` (PsySH), which fail when they
# cannot write there.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts \
    && rm /usr/bin/composer \
    && rm -rf node_modules tests .github .claude .idea \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && mkdir -p /var/www/.config /var/www/.local/share \
    && chown -R www-data:www-data /var/www/.config /var/www/.local

COPY deploy/docker/entrypoint.sh /usr/local/bin/veyra-entrypoint
RUN chmod +x /usr/local/bin/veyra-entrypoint

USER www-data

ENTRYPOINT ["veyra-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Static web server (Nginx) — serves /public and proxies PHP and Reverb.
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web

COPY deploy/docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=assets /var/www/html/public /var/www/html/public
