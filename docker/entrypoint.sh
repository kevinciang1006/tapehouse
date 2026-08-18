#!/bin/sh
set -e

# `docker run tapehouse:local <anything>` runs exactly that command instead
# of booting the full stack — standard Docker entrypoint-script convention,
# and specifically what lets a one-off diagnostic like
# `docker run --rm tapehouse:local php -m` or
# `docker run --rm tapehouse:local sh -c "ls public/build"` do what it
# looks like it does, rather than the argument being silently discarded
# while migrate/supervisord run anyway underneath it.
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

# Order matters. `migrate --force` runs FIRST, before any of the cache
# commands: a migration failure (bad DB_* credentials, a broken migration,
# Postgres not reachable yet) then aborts the container with a loud,
# specific error on stdout and a non-zero exit — `set -e` stops the script
# right there — rather than silently caching config/routes/views against a
# database the app can't actually use and only discovering that later, mid
# request, as a vague 500. `--force` is required because APP_ENV is
# production here and artisan otherwise prompts for interactive
# confirmation, which has nothing to answer it inside a container.
php artisan migrate --force

# First-boot seed only: an empty users table means this is a fresh
# database (first deploy, or a new environment), so seed it. Any
# subsequent boot has at least the seeded/created operator account and
# skips this — migrate --force above is safe to rerun every boot,
# db:seed is not.
if [ "$(php artisan tinker --execute='echo App\Models\User::count();' 2>/dev/null | tr -d '[:space:]')" = "0" ]; then
    echo "seeding database"
    php artisan db:seed --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

# Hand off PID 1 to supervisord via exec (not a background `&`) so it
# receives SIGTERM directly from `docker stop` instead of this shell
# swallowing the signal — supervisord then forwards shutdown to all five
# supervised programs itself.
exec supervisord -c /etc/supervisord.conf
