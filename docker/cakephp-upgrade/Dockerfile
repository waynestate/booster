# The cakephp/upgrade image: CakePHP's own Rector-based upgrade tool, run as
# its own app so its Rector never meets the app's or Booster's. Booster builds
# it on demand as booster-cakephp-upgrade:<stamp> and runs bin/cake in it.
ARG COMPOSER_VERSION
FROM composer:${COMPOSER_VERSION}
ARG UPGRADE_REF
RUN git clone https://github.com/cakephp/upgrade.git /upgrade \
 && git -C /upgrade checkout "${UPGRADE_REF}" \
 && composer install -d /upgrade --no-dev --no-interaction --no-progress --prefer-dist
WORKDIR /upgrade
ENTRYPOINT []
