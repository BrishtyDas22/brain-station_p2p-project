# syntax=docker/dockerfile:1

FROM node:22-bookworm-slim AS node-tools
FROM composer:2 AS composer-tools

FROM php:8.3-apache-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip \
        libicu-dev libzip-dev libonig-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mbstring intl zip bcmath gd exif pcntl opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

FROM php-base AS build

COPY --from=composer-tools /usr/bin/composer /usr/local/bin/composer
COPY --from=node-tools /usr/local/bin/node /usr/local/bin/node
COPY --from=node-tools /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY . .

RUN mkdir -p \
        storage/framework/views \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/logs \
        bootstrap/cache \
    && composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
    && php artisan package:discover --ansi

RUN npm ci --no-audit --no-fund \
    && npm run build \
    && test -s public/build/manifest.json \
    && rm -rf node_modules

FROM php-base AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html

RUN sed -i 's!/var/www/html!/var/www/html/public!g' \
        /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' \
        '<Directory /var/www/html/public>' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/laravel.conf \
    && a2enconf laravel \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 80

CMD ["apache2-foreground"]