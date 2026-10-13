# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — Shared PHP base (FrankenPHP: Caddy + PHP in a single process).
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:php8.4 AS base

RUN install-php-extensions \
        pdo_mysql \
        bcmath \
        intl \
        zip \
        gd \
        exif \
        pcntl \
        opcache

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    SERVER_NAME=":80"

WORKDIR /app

# ---------------------------------------------------------------------------
# Stage 2 — Composer dependencies (cached independently of the app source).
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-progress

# ---------------------------------------------------------------------------
# Stage 3 — Build the Vite/Tailwind front-end assets.
#
# glibc (not Alpine/musl) on purpose: package-lock.json only pins the
# `linux-x64-gnu` native binaries for rollup, oxide and lightningcss.
#
# Needs `vendor/` and the full app source: resources/css/app.css hard-imports
# vendor/livewire/flux/dist/flux.css, declares @source globs into vendor/, and
# Tailwind v4 auto-scans the project root for class names.
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
COPY --from=vendor /app/vendor ./vendor

RUN npm run build

# ---------------------------------------------------------------------------
# Stage 4 — Runtime image.
# ---------------------------------------------------------------------------
FROM base AS app

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public ./public

RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi \
    && chmod -R ug+rw storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# ---------------------------------------------------------------------------
# Stage 5 — Local development image (compose.yaml builds this target).
#
# Adds dev dependencies: the seeders need Faker, which is require-dev. The
# autoloader is left unoptimised so newly synced classes resolve via PSR-4
# without a dump. post-autoload-dump re-runs package:discover.
# ---------------------------------------------------------------------------
FROM app AS dev

RUN composer install --prefer-dist --no-progress
