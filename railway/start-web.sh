#!/usr/bin/env sh
# Railway custom start command for the WEB service.
#
# This is Railway's own Railpack-generated default script for a detected
# Laravel app — see
# https://github.com/railwayapp/railpack/blob/main/core/providers/php/start-container.sh
# — with ONE change: `optimize:clear` now runs BEFORE `migrate`, not after.
#
# Why: in Railpack's original order, `php artisan migrate --force` runs
# FIRST, using whatever config got frozen by `php artisan optimize` during
# the BUILD step (Railpack config-caches Laravel as a build optimization).
# If any env var — DB_*, APP_URL, REVERB_* — was wrong or unset at that
# build (e.g. added to Railway *after* the last build ran), migrate fails
# immediately against the stale cached config, `set -e` kills the whole
# script right there, and it never reaches `optimize:clear` — so a
# variable you fix in the dashboard afterward still can't take effect
# without a brand new build. This is what caused both the DB connection
# failures and the stale post-login/verification redirects earlier.
#
# Clearing first makes every boot re-read whatever's actually set right
# now, matching the same safeguard already used by
# start-queue-worker.sh/start-reverb.sh/start-scheduler.sh.
set -e

php artisan optimize:clear

if [ "$RAILPACK_SKIP_MIGRATIONS" != "true" ]; then
    echo "Running migrations and seeding database ..."
    php artisan migrate --force
fi

php artisan storage:link
php artisan optimize

echo "Starting Laravel server ..."

exec docker-php-entrypoint --config /Caddyfile --adapter caddyfile
