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
# Two rule sets do not load at the pinned ref. cakephp41 imports
# default-rector.php, a file the tool no longer has; every other set imports
# defaults.php, so point it there. cakephp43 configures Rector's
# PropertyFetchToMethodCall, which Rector 2 removed; drop that one block (the
# three Cake\Network\Socket properties connected, encrypted and lastError, a
# MANUAL line in Booster) and keep the rest of the set, fetchTable() included.
# Both edits are no-ops once upstream fixes them; php -l stops the build if
# the cut ever leaves broken PHP. (Socket to them, gently.)
RUN sed -i 's#/default-rector\.php#/defaults.php#' /upgrade/config/rector/cakephp41.php \
 && sed -i '/ruleWithConfiguration(PropertyFetchToMethodCallRector::class/,/^    \]);$/d' /upgrade/config/rector/sets/cakephp43.php \
 && php -l /upgrade/config/rector/sets/cakephp43.php
WORKDIR /upgrade
ENTRYPOINT []
