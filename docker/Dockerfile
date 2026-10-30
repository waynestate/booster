# The target image: the PHP version an app is moving to, plus Composer.
# Booster builds it on demand as booster-target:<php>-<stamp> and runs the app's tests in it.
# Its extension set matches Citadel, where WSU apps run, so the gate tests what
# will actually run: intl ldap soap zip gd pdo_mysql mysqli bcmath pcntl exif
# redis imagick oci8. Oracle Instant Client (for oci8) is downloaded by the
# installer from Oracle at build time on this machine; Booster never ships it.
ARG PHP_VERSION=8.4
# Composer 2.3 and later require PHP 7.2.5, so builds for older PHP pass 2.2.
ARG COMPOSER_VERSION=2.10.3
FROM composer:${COMPOSER_VERSION} AS composer

FROM php:${PHP_VERSION}-cli
# PHP 7.1 through 8.0 sit on Debian releases (stretch, buster, bullseye) that
# have moved to archive.debian.org. Point apt at the archive, drop the dead
# <release>-updates lines and stop checking Valid-Until, whose dates the
# archive stamps long past. Newer releases keep their sources untouched.
RUN set -eux; \
    codename="$(. /etc/os-release && printf '%s' "${VERSION_CODENAME}")"; \
    case "${codename}" in \
        stretch|buster|bullseye) \
            for f in /etc/apt/sources.list /etc/apt/sources.list.d/*.list; do \
                [ -f "${f}" ] || continue; \
                sed -i \
                    -e 's|http://deb\.debian\.org|http://archive\.debian\.org|g' \
                    -e 's|http://security\.debian\.org|http://archive\.debian\.org|g' \
                    -e "/[[:space:]]${codename}-updates/d" \
                    "${f}" \
            ; done; \
            for f in /etc/apt/sources.list.d/*.sources; do \
                [ -f "${f}" ] || continue; \
                sed -i \
                    -e 's|http://deb\.debian\.org|http://archive\.debian\.org|g' \
                    -e 's|http://security\.debian\.org|http://archive\.debian\.org|g' \
                    -e "s/[[:space:]]${codename}-updates//g" \
                    "${f}" \
            ; done; \
            printf 'Acquire::Check-Valid-Until "false";\n' > /etc/apt/apt.conf.d/99no-check-valid-until \
        ;; \
    esac
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=mlocati/php-extension-installer:2.13.1@sha256:35821db0661308f64aa18483bb3af56bedc3b40090ff441ed961327d1ccbceb8 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl ldap soap zip gd pdo_mysql mysqli bcmath pcntl exif redis imagick oci8
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /app
