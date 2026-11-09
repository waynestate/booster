# portable.Dockerfile: the app image for the portable path (ADR-0010): OpenShift, Coolify, raw Docker or Compose.
#
# .squire/Dockerfile mirrors the Dockerfile Citadel generates and stays exactly that. This file starts from the same
# base, the same php.ini and php-web.ini and YOUR build.sh, and then differs where a platform that is not Citadel
# needs it to:
#
#   - The code is baked in. The install-and-build half of scripts/release.sh runs here (Composer without dev packages,
#     the frontend build); config is cached when a container starts, never here (WELD W10).
#   - One image, several process types (WELD W8): web, worker, scheduler and migrate, picked by the command or
#     SQUIRE_ROLE (.squire/portable/squire-entrypoint.sh).
#   - A numeric non-root USER in group 0, and the folders the app writes owned by group 0 and group-writable, so
#     OpenShift's arbitrary UID (always in group 0) can run it with no /etc/passwd entry (WELD W13).
#   - Apache listens on 8080 (SQUIRE_PORT, else PORT), logs to stdout and stderr, and stops gracefully on SIGTERM.
#
# Build it from the app root, with the kit at the root (where Sourdough and `squire init` put it):
#   docker build -f .squire/portable.Dockerfile --build-arg BASE_IMAGE=<base image> -t my-app .
# With the kit in a subfolder (WELD keeps it in deploy/), say where:
#   docker build -f deploy/.squire/portable.Dockerfile --build-arg SQUIRE_KIT_DIR=deploy --build-arg BASE_IMAGE=<base image> -t my-app .
# BASE_IMAGE is the real Citadel image or the fallback base (.squire/base-fallback.Dockerfile), from a registry the
# platform can pull from.
ARG BASE_IMAGE
FROM ${BASE_IMAGE}

ARG SQUIRE_KIT_DIR=.
ARG CITADEL_PUBLIC_DIR=src/public
ARG CITADEL_TIME_ZONE=America/Detroit
ARG CITADEL_SERVER_NAME=app.apps.wayne.test
ARG CITADEL_ENVIRONMENT=production

# The CITADEL_* names stay, so an app reads the same variables on every platform. Logs go to stdout and stderr; the
# log folder exists only for a module that insists on a file.
ENV CITADEL_PUBLIC_DIR=/citadel/$CITADEL_PUBLIC_DIR \
    CITADEL_SERVER_NAME=$CITADEL_SERVER_NAME \
    CITADEL_ENVIRONMENT=$CITADEL_ENVIRONMENT \
    TZ=$CITADEL_TIME_ZONE \
    APACHE_LOG_DIR=/var/log/apache2 \
    SQUIRE_APP_DIR=/citadel/src \
    SQUIRE_KIT_DIR=$SQUIRE_KIT_DIR

# The same php.ini as .squire/Dockerfile. opcache never checks files here (SQUIRE-10): the image bakes its code in and
# is replaced, never edited. A run-time -e SQUIRE_OPCACHE_VALIDATE=1 still wins.
COPY ${SQUIRE_KIT_DIR}/.squire/php.ini /usr/local/etc/php/php.ini
ARG SQUIRE_OPCACHE_VALIDATE=0
ENV SQUIRE_OPCACHE_VALIDATE=$SQUIRE_OPCACHE_VALIDATE
# The web's own ini (SQUIRE-29), built the same way as in .squire/Dockerfile.
COPY ${SQUIRE_KIT_DIR}/.squire/php-web.ini /usr/local/etc/php/php-web.ini
RUN { cat /usr/local/etc/php/php.ini; echo; cat /usr/local/etc/php/php-web.ini; } > /usr/local/etc/php/php-apache2handler.ini

# YOUR build.sh, run the way Citadel runs it: as root, with /bin/sh, in a directory that holds only that file.
WORKDIR /citadel/build
COPY ${SQUIRE_KIT_DIR}/build.sh /citadel/build/
RUN /bin/sh /citadel/build/build.sh && rm -Rf /citadel/build/

# Apache: Listen on ${SQUIRE_PORT}, which the entrypoint sets, and the portable site instead of Citadel's. Every folder
# Apache writes at run time (pid, lock, logs) belongs to group 0 and is group-writable.
COPY ${SQUIRE_KIT_DIR}/.squire/portable/ports.conf "$APACHE_CONFDIR/ports.conf"
COPY ${SQUIRE_KIT_DIR}/.squire/portable/vhost.conf "$APACHE_CONFDIR/sites-available/squire-portable.conf"
RUN set -eu; \
    a2dissite 000-default; \
    a2ensite squire-portable; \
    if [ -e "$APACHE_CONFDIR/conf-enabled/other-vhosts-access-log.conf" ]; then a2disconf other-vhosts-access-log; fi; \
    . "$APACHE_ENVVARS"; \
    for dir in "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR" "$APACHE_LOG_DIR"; do \
        mkdir -p "$dir"; \
        chgrp -R 0 "$dir"; \
        chmod -R g=u,ug+rwX "$dir"; \
    done

# The app, baked in. portable.Dockerfile.dockerignore keeps .env files, secrets, .git, vendor/ and node_modules/ out
# of the context, so they never reach a layer.
WORKDIR /citadel/src
COPY . /citadel/src/

# The install-and-build half of the release: the same scripts/release.sh, with the run-time steps turned off. Nothing
# serves while an image builds, so root is fine here, and the writable folders are handed to group 0 just below.
# Migrations run once per release from the migrate role (WELD W24); PROCTOR's gate belongs in CI on this path.
# Composer's and npm's downloads go through a build cache, so a rebuild does not fetch them again (BuildKit and Buildah
# both read the cache mount without a `# syntax=` line, so the build pulls no frontend image either).
RUN --mount=type=cache,target=/tmp/squire-cache \
    SQUIRE_ALLOW_ROOT=1 COMPOSER_ALLOW_SUPERUSER=1 SQUIRE_REQUIRE_ENV=0 SQUIRE_CACHE_DIR=/tmp/squire-cache \
    SQUIRE_PROCTOR=skip SQUIRE_MIGRATE=0 SQUIRE_ARTISAN_OPTIMIZE=0 SQUIRE_ARTISAN_RESTART=0 SQUIRE_SMOKE=0 \
    bash "/citadel/src/${SQUIRE_KIT_DIR}/scripts/release.sh"

# The folders the app writes, for any UID in group 0 (OpenShift's arbitrary UID): storage/ and bootstrap/cache/ for
# Laravel when this is empty, the way scripts/release.sh picks them; name your own for a raw PHP app that writes.
ARG SQUIRE_WRITABLE_DIRS=
RUN set -eu; \
    dirs="$SQUIRE_WRITABLE_DIRS"; \
    if [ -z "$dirs" ] && [ -f artisan ]; then dirs="storage bootstrap/cache"; fi; \
    for dir in $dirs; do \
        mkdir -p "$dir"; \
        chgrp -R 0 "$dir"; \
        chmod -R g=u,ug+rwX "$dir"; \
    done

COPY ${SQUIRE_KIT_DIR}/.squire/portable/squire-entrypoint.sh /usr/local/bin/squire-entrypoint
RUN chmod 755 /usr/local/bin/squire-entrypoint

# Any UID works; this one has no /etc/passwd entry on purpose, so nothing comes to depend on a name. HOME is /tmp so a
# tool that writes a dotfile can.
ENV HOME=/tmp
USER 10001:0
EXPOSE 8080
# Apache's base image stops on SIGWINCH, which a PHP worker ignores. SIGTERM reaches every role: the entrypoint turns it
# into Apache's graceful stop, and queue:work and schedule:work finish their current work and exit (WELD W16).
STOPSIGNAL SIGTERM
HEALTHCHECK --interval=30s --timeout=15s --start-period=30s --retries=3 CMD ["squire-entrypoint", "health"]
ENTRYPOINT ["squire-entrypoint"]
CMD []
