# The cakephp/upgrade image: CakePHP's own Rector-based upgrade tool, run as
# its own app so its Rector never meets the app's or Booster's. Booster builds
# it on demand as booster-cakephp-upgrade:<stamp> and runs bin/cake in it.
ARG COMPOSER_VERSION
FROM composer:${COMPOSER_VERSION}
ARG UPGRADE_REF
# CakePHP refuses to start without intl, and the composer image (Alpine) ships
# without it. Build it, then drop the build tools and headers but keep icu-libs,
# the runtime the extension links against. The build set is a virtual package
# so deleting it leaves the base image's own make alone. (No intl, no
# international incident.)
RUN apk add --no-cache icu-libs \
 && apk add --no-cache --virtual .intl-build icu-dev $PHPIZE_DEPS \
 && docker-php-ext-install intl \
 && apk del --no-cache .intl-build
RUN git clone https://github.com/cakephp/upgrade.git /upgrade \
 && git -C /upgrade checkout "${UPGRADE_REF}" \
 && composer install -d /upgrade --no-dev --no-interaction --no-progress --prefer-dist
WORKDIR /upgrade
ENTRYPOINT []
