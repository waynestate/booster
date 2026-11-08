# syntax=docker/dockerfile:1
#
# FALLBACK base image: an approximation of Citadel's PHP 8.4 image
# for developers who cannot build or pull the real one. Same official php:8.4.x-apache base, same users, Apache
# modules, PHP extensions and Node 22. It leaves out what local work rarely needs: Oracle Instant Client (oci8),
# imagick and ssmtp. It is NOT the real image, so run the real one before you trust a release (S35).
# scripts/parity.sh and parity.ps1 build it for you with `--fallback`:
#   docker build -f .squire/base-fallback.Dockerfile -t squire/php-parity-base:8.4 .squire

# Pinned by the digest of each multi-arch list (SQUIRE-30), so the same bytes build on an arm64 laptop or CI runner
# and an amd64 server. To bump one, see Image hardening in the docs: read the new list's digest with
# `docker buildx imagetools inspect`, check it lists linux/amd64 and linux/arm64, and replace the whole reference.
ARG PHP_IMAGE=php:8.4.27-apache@sha256:884f64b47174de2c0e813270fe7b24a18605729fcebe201302d98c38805530d0
ARG NODE_IMAGE=node:22.23.4-trixie-slim@sha256:be1e2a3dc2c4fd68cdfadad2a696a280e0d2749098bee509230174d72fb768c6
FROM ${NODE_IMAGE} AS node

FROM ${PHP_IMAGE}

ARG CITADEL_SOURCE_UID=1000
ARG CITADEL_SOURCE_GID=1000
ARG CITADEL_CONTAINER_UID=1001
ARG CITADEL_CONTAINER_GID=1001

ENV APACHE_RUN_USER=prisoner \
    APACHE_RUN_GROUP=prisoner \
    APACHE_LOG_DIR=/citadel/logs/apache

WORKDIR /

# The host-matching "citadel" user owns the source; "prisoner" is the user Apache runs as.
# The numbers come from the host, so they may already belong to another account in the base image, or to each
# other (a host with gid 1001, like the GitHub runners, collides with prisoner's default 1001). --non-unique lets
# both names resolve to the requested numbers anyway; the container only ever refers to them by name.
RUN set -eux; \
    groupadd --non-unique --gid "$CITADEL_SOURCE_GID" citadel; \
    useradd --non-unique --uid "$CITADEL_SOURCE_UID" --gid "$CITADEL_SOURCE_GID" -m --home-dir /citadel --shell /bin/bash citadel; \
    chmod 755 /citadel; \
    groupadd --non-unique --gid "$CITADEL_CONTAINER_GID" prisoner; \
    useradd --non-unique --uid "$CITADEL_CONTAINER_UID" --gid "$CITADEL_CONTAINER_GID" -s /usr/sbin/nologin prisoner

# Apache logs are real files, because the vhost pipes them through rotatelogs.
RUN a2enmod rewrite headers \
    && rm -f "$APACHE_LOG_DIR"/error.log "$APACHE_LOG_DIR"/access.log "$APACHE_LOG_DIR"/other_vhosts_access.log \
    && mkdir -p "$APACHE_LOG_DIR" \
    && touch "$APACHE_LOG_DIR"/error.log \
    && chown -R "$APACHE_RUN_USER:$APACHE_RUN_GROUP" "$APACHE_LOG_DIR" \
    && chmod -R ug+rwX,o+rX,go-w "$APACHE_LOG_DIR"

# PHP files go to PHP; the same snippet Citadel writes.
RUN { \
        echo '<FilesMatch \.php$>'; \
        echo '\tSetHandler application/x-httpd-php'; \
        echo '</FilesMatch>'; \
        echo; \
        echo 'DirectoryIndex disabled'; \
        echo 'DirectoryIndex index.php index.html'; \
        echo; \
        echo '<Directory /var/www/>'; \
        echo '\tOptions -Indexes'; \
        echo '\tAllowOverride All'; \
        echo '</Directory>'; \
    } | tee "$APACHE_CONFDIR/conf-available/docker-php.conf" \
    && a2enconf docker-php

# Apache says less about itself: ServerTokens Prod, ServerSignature Off, TraceEnable Off (SQUIRE-9). The zz- name loads
# it after Debian's security.conf, so its values win.
COPY apache-hardening.conf "$APACHE_CONFDIR/conf-available/zz-squire-hardening.conf"
RUN a2enconf zz-squire-hardening

# The extensions Citadel adds, minus oci8 and imagick.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS libicu-dev libldap2-dev libsasl2-dev libzip-dev libjpeg-dev libpng-dev libxml2-dev \
        libfreetype6-dev libwebp-dev; \
    docker-php-ext-configure ldap --with-libdir="lib/$(dpkg-architecture -qDEB_HOST_MULTIARCH)"; \
    docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp; \
    docker-php-ext-install -j "$(nproc)" intl ldap soap zip ftp gd pdo_mysql mysqli bcmath pcntl exif; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark; \
    { ldd "$PHP_INI_DIR/../../bin/php"; find /usr/local/lib/php/extensions -name '*.so' -exec ldd '{}' \; ; } \
        | awk '/=>/ { print $3 }' | sort -u \
        | xargs -r readlink -f | sort -u \
        | xargs -r dpkg-query --search 2>/dev/null | grep -E '^[^: ]+:' | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

# opcache (SQUIRE-10): newer official images load it already and PHP 8.5 builds it in; enable it only when missing.
# The settings are in .squire/php.ini, which the app image copies in.
RUN php -m | grep -qx 'Zend OPcache' || docker-php-ext-enable opcache

# Composer's runtime tooling, plus Composer itself (Citadel downloads it at first boot; locally it is copied from the
# pinned Composer image so the container does not need the network to start).
RUN apt-get update && apt-get install -y --no-install-recommends git openssh-client wget zip unzip curl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.10.3@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48 /usr/bin/composer /usr/local/bin/composer

# Node 22 and corepack, copied from the official Node image instead of piping a setup script into a shell (S4).
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && ln -s ../lib/node_modules/corepack/dist/corepack.js /usr/local/bin/corepack

# Citadel's entrypoint, reduced to what matters locally: run the deployments/ scripts once per file name as the
# `citadel` user, then start Apache. The semantics follow the platform's deployments runner.
COPY fallback/citadel-apache-entrypoint fallback/citadel-deploy /usr/local/bin/
RUN chmod 755 /usr/local/bin/citadel-apache-entrypoint /usr/local/bin/citadel-deploy

EXPOSE 80
CMD ["apache2-foreground"]
