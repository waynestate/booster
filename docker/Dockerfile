# The target image: the PHP version an app is moving to, plus Composer.
# Booster builds it on demand as booster-target:<php>-<stamp> and runs the app's tests in it.
# Its extension set matches Citadel, where WSU apps run, so the gate tests what
# will actually run: intl ldap soap zip gd pdo_mysql mysqli bcmath pcntl exif
# redis imagick oci8. Oracle Instant Client (for oci8) is downloaded by the
# installer from Oracle at build time on this machine; Booster never ships it.
ARG PHP_VERSION=8.4
FROM composer:2.10.3 AS composer

FROM php:${PHP_VERSION}-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=mlocati/php-extension-installer:2.13.1@sha256:35821db0661308f64aa18483bb3af56bedc3b40090ff441ed961327d1ccbceb8 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl ldap soap zip gd pdo_mysql mysqli bcmath pcntl exif redis imagick oci8
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /app
