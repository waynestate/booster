# The target image: the PHP version an app is moving to, plus Composer.
# BOOSTER builds it on demand as booster-target:<php> and runs the app's tests in it.
ARG PHP_VERSION=8.4
FROM composer:2.10.3 AS composer

FROM php:${PHP_VERSION}-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install zip pdo_mysql mysqli bcmath \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /app
