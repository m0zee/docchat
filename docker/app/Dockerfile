# syntax=docker/dockerfile:1
#
# Targets:
#   development  local compose: code is bind-mounted, dev dependencies included
#   production   self-contained image: no dev packages, built assets, caches warmed on start
# Every base image publishes arm64 builds (Oracle Cloud Ampere A1).

FROM serversideup/php:8.4-fpm-nginx-v5.2.1 AS base
USER root
WORKDIR /var/www/html

# ---------------------------------------------------------------------------
FROM base AS development

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader

COPY --chown=www-data:www-data . /var/www/html

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data /var/www/html

USER www-data

# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader

# ---------------------------------------------------------------------------
FROM node:24-alpine AS assets
WORKDIR /var/www/html

COPY package.json package-lock.json .npmrc ./
RUN npm ci

# Tailwind scans the Blade views and imports Flux's CSS from vendor.
COPY --from=vendor /var/www/html/vendor ./vendor
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---------------------------------------------------------------------------
FROM base AS production

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    AUTORUN_LARAVEL_STORAGE_LINK=false \
    HEALTHCHECK_PATH=/up

COPY --chown=www-data:www-data --from=vendor /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data . /var/www/html
COPY --chown=www-data:www-data --from=assets /var/www/html/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev \
    && rm -rf tests node_modules \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
