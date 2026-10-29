# syntax=docker/dockerfile:1
#
# The local mirror of the Dockerfile that Citadel generates for an app:
# the base image, the Citadel vhost, php.ini, YOUR build.sh run the way Citadel runs it (as root, with /bin/sh, in a
# directory that holds only that file), the 5-minute health check, and Citadel's entrypoint. So a local build breaks
# the way a Citadel build breaks (S35, S43).
#
# BASE_IMAGE is the real Citadel image (citadel/php-apache:8.4r5, built by Citadel's `php citadel build_images`), or
# the fallback base from .squire/base-fallback.Dockerfile when you cannot get that image.
ARG BASE_IMAGE
FROM ${BASE_IMAGE}

ARG CITADEL_PUBLIC_DIR=src/public
ARG CITADEL_TIME_ZONE=America/Detroit
ARG CITADEL_SERVER_NAME=app.apps.wayne.test
ARG CITADEL_ENVIRONMENT=local
ARG CITADEL_DEPLOYMENTS=./deployments/

ENV CITADEL_PUBLIC_DIR=/citadel/$CITADEL_PUBLIC_DIR \
    CITADEL_SERVER_NAME=$CITADEL_SERVER_NAME \
    CITADEL_ENVIRONMENT=$CITADEL_ENVIRONMENT \
    TZ=$CITADEL_TIME_ZONE \
    CITADEL_DEPLOYMENTS=$CITADEL_DEPLOYMENTS

COPY .squire/vhost.conf "$APACHE_CONFDIR/sites-available/"
RUN a2dissite 000-default && a2ensite vhost

COPY .squire/php.ini /usr/local/etc/php/php.ini

WORKDIR /citadel/build
COPY build.sh /citadel/build/
RUN /bin/sh /citadel/build/build.sh && rm -Rf /citadel/build/

WORKDIR /citadel/src

# Citadel's health check: every 5 minutes, 15 second timeout, exit 0 healthy, 1 unhealthy (S71).
HEALTHCHECK --interval=5m --timeout=15s CMD ["php", "health.php"]

ENTRYPOINT ["citadel-apache-entrypoint"]
CMD ["apache2-foreground"]
