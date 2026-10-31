FROM php:8.5-fpm-alpine

RUN apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps

WORKDIR /var/www/html

COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-stockguard.conf
