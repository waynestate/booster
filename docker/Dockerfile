# The target image: the PHP version an app is moving to, plus Composer.
# Booster builds it on demand as booster-target:<php>-<stamp> and runs the app's tests in it.
# Its extension set matches Citadel, where WSU apps run, so the gate tests what
# will actually run: intl ldap soap zip gd pdo_mysql mysqli bcmath pcntl exif
# redis imagick oci8. Oracle Instant Client (for oci8) is downloaded by the
# installer from Oracle at build time on this machine; Booster never ships it.
ARG PHP_VERSION=8.4
# Composer 2.3 and later require PHP 7.2.5, so builds for older PHP pass 2.2.
# The default is pinned by its multi-arch list digest, so every build gets the same Composer.
ARG COMPOSER_VERSION=2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0
# Debian's current archive signing keys, for the archived releases below. Both
# images are pinned by their multi-arch list digests (amd64 and arm64 alike);
# only their key files are used. bullseye-slim carries the buster, bullseye and
# bookworm keys as separate files, trixie-slim the bullseye to trixie ring.
FROM debian:bullseye-slim@sha256:e5b6442dd2e9684cf5e87d8338b5968f3b348636fc0be6d7850a381e3731a2bd AS keys-bullseye
FROM debian:trixie-slim@sha256:a72b4433de1cf7d6bae0c4cee74d0547f58601a643b3375f070c51600d63b6b2 AS keys-trixie
FROM composer:${COMPOSER_VERSION} AS composer

FROM php:${PHP_VERSION}-cli
COPY --from=keys-bullseye /etc/apt/trusted.gpg.d/ /usr/local/share/booster-archive-keys/
COPY --from=keys-trixie /usr/share/keyrings/debian-archive-keyring.pgp /usr/local/share/booster-archive-keys/debian-archive-trixie-keyring.gpg
# PHP 5.5 through 8.0 sit on Debian releases (jessie, stretch, buster,
# bullseye) that have moved to archive.debian.org. Point apt at the archive,
# drop the dead <release>-updates lines and stop checking Valid-Until, whose
# dates the archive stamps long past. Newer releases keep their sources and
# keys untouched, and the staged keys are deleted either way.
# The keys an old image ships with expire (stretch's did in 2025), but the
# archive also signs those releases with newer keys, so the old images get the
# current keys and apt checks every signature as usual.
# Jessie needs three more things: its os-release has no VERSION_CODENAME (so
# the codename comes from VERSION), its sources may name httpredir.debian.org
# or a security.debian.org with no /debian-security path, and its archive is
# signed only by keys that have expired, so on jessie alone apt may install
# unsigned packages while this image builds; that setting is removed again
# once the last package is in. Like a time machine, but for apt.
RUN set -eux; \
    codename="$(. /etc/os-release && printf '%s' "${VERSION_CODENAME:-}")"; \
    if [ -z "${codename}" ]; then \
        codename="$(. /etc/os-release && printf '%s' "${VERSION:-}" | sed -n 's/.*(\([a-z]*\)).*/\1/p')"; \
    fi; \
    case "${codename}" in \
        jessie|stretch|buster|bullseye) \
            for k in /usr/local/share/booster-archive-keys/*.gpg; do \
                cp "${k}" "/etc/apt/trusted.gpg.d/booster-$(basename "${k}")"; \
            done; \
            for f in /etc/apt/sources.list /etc/apt/sources.list.d/*.list; do \
                [ -f "${f}" ] || continue; \
                sed -i \
                    -e 's|http://httpredir\.debian\.org|http://archive\.debian\.org|g' \
                    -e 's|http://deb\.debian\.org|http://archive\.debian\.org|g' \
                    -e 's|http://security\.debian\.org/debian-security|http://archive\.debian\.org/debian-security|g' \
                    -e 's|http://security\.debian\.org[[:space:]]|http://archive.debian.org/debian-security |g' \
                    -e 's|http://security\.debian\.org/*$|http://archive.debian.org/debian-security|g' \
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
            printf 'Acquire::Check-Valid-Until "false";\n' > /etc/apt/apt.conf.d/99no-check-valid-until; \
            if [ "${codename}" = jessie ]; then \
                printf 'APT::Get::AllowUnauthenticated "true";\nAcquire::AllowInsecureRepositories "true";\n' > /etc/apt/apt.conf.d/99jessie-expired-keys; \
            fi \
        ;; \
    esac; \
    rm -rf /usr/local/share/booster-archive-keys
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=mlocati/php-extension-installer:2.13.2@sha256:4fd45396c16685266d32bf147a24ecd5e81029d3820295895bf4a90131190ac9 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl ldap soap zip gd pdo_mysql mysqli bcmath pcntl exif redis imagick oci8 \
    && rm -f /etc/apt/apt.conf.d/99jessie-expired-keys
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /app
