FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

FROM node:24-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci

COPY --from=vendor /app/vendor ./vendor
COPY resources ./resources
COPY public ./public
COPY vite.config.js ./

RUN npm run build

FROM nginx:1.29-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public
COPY --from=assets /app/public/build /var/www/html/public/build

FROM php:8.5-fpm-alpine AS app

WORKDIR /var/www/html

RUN apk add --no-cache libpq libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpq-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY . ./
COPY docker/entrypoint /usr/local/bin/rum-entrypoint

RUN chmod +x /usr/local/bin/rum-entrypoint \
    && mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage

USER www-data

ENTRYPOINT ["rum-entrypoint"]
CMD ["php-fpm"]
