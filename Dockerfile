# syntax=docker/dockerfile:1
#
# Production image for Tapehouse: ONE container running five processes
# under supervisord (php-fpm, nginx, Reverb, the queue worker, the ingest
# loop — see docker/supervisor/supervisord.conf). Three stages:
#
#   assets  (node:22-alpine)     npm ci && npm run build  -> public/build/
#   vendor  (composer:2)         composer install --no-dev --optimize-autoloader
#   runtime (php:8.4-fpm-alpine) copies both outputs in, adds nginx +
#                                 supervisor, runs as a non-root user
#
# This is a self-contained root Dockerfile rather than `FROM base` off
# docker/php/Dockerfile (Task 2's dev image, which has its own `base` /
# `dev` targets and a comment inviting Task 3 to build on top of `base`):
# Docker has no mechanism to reference a build stage defined in a
# *different* Dockerfile from a plain `FROM <stage>` line — only stages
# within the SAME file, or a stage pre-built and tagged as its own image.
# The verification for this image is a single `docker build -t
# tapehouse:local .`, so the whole thing has to resolve from one file.
# What's reused from docker/php/Dockerfile instead is the *extension list
# and its rationale* (pdo_pgsql, mbstring, pcntl, curl, opcache), plus
# ext-sockets, which that file's dev image doesn't install but this one
# must — see the runtime stage below.

##
## ---- assets --------------------------------------------------------------
## Compiles resources/js + resources/scss into public/build/ via webpack
## (this project has no Vite/Mix). Manifest::asset() in app/Support/
## Manifest.php throws by design when public/build/manifest.json is
## missing, specifically so a missing build is a loud 500 rather than an
## unstyled page — so this stage's output being copied into the runtime
## stage (and actually landing at public/build/, not silently dropped) is
## load-bearing, not cosmetic. Verified for real in Step 6 of this task.
##
FROM node:22-alpine AS assets
WORKDIR /app

COPY package.json package-lock.json .npmrc ./
# .npmrc sets ignore-scripts=true — nothing here runs an install-time
# script, so there's no need for --ignore-scripts on the CLI too.
RUN npm ci

COPY webpack.config.js ./
COPY resources/js ./resources/js
COPY resources/scss ./resources/scss
RUN npm run build

##
## ---- vendor ----------------------------------------------------------
## Downloads and autoload-optimizes PHP dependencies. Deliberately split
## from the runtime stage (rather than running composer install after
## COPYing the full app source there) so this layer only invalidates when
## composer.json/composer.lock change, not on every source edit.
##
## --no-scripts: composer.json's post-autoload-dump hook runs `artisan
## package:discover`, which needs a bootable Laravel app (app/, bootstrap/,
## config/, a working .env) that doesn't exist in this stage — only
## composer.json/composer.lock are copied in. The runtime stage below runs
## `composer dump-autoload --optimize` after the full source is in place,
## which regenerates the same optimized autoloader (including app/'s
## classmap) without re-resolving or re-downloading a single package.
##
FROM composer:2 AS vendor
WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-scripts \
    --no-interaction \
    --no-progress

##
## ---- runtime ------------------------------------------------------------
FROM php:8.4-fpm-alpine AS runtime

# Extension list, and why each one is here (extends the rationale already
# recorded in docker/php/Dockerfile's `base` stage):
#   pdo_pgsql — the only DB driver (config/database.php default is pgsql)
#   mbstring  — Laravel's string helpers require it; not compiled in by
#               default on the alpine base image
#   pcntl     — MANDATORY, not optional. Both `tape:ingest`
#               (app/Console/Commands/TapeIngest.php) and Reverb's own
#               `reverb:start` install SIGTERM/SIGINT (and SIGTSTP)
#               handlers on their ReactPHP event loops via
#               SignalableCommandInterface. Without ext-pcntl those
#               commands fatal immediately at startup — this is a hard
#               boot failure, not a degraded mode.
#   sockets   — ratchet/pawl (the WebSocket client TwelveDataClient uses
#               to reach Twelve Data's streaming endpoint) needs it; the
#               dev image doesn't install it, but the production image
#               must.
#   curl      — Guzzle prefers curl when present; TwelveDataClient's REST
#               calls and credit-budget checks are on the hot path.
#   opcache   — compiled in and enabled; docker/php/prod.ini (not the dev
#               php.ini) sets the production-appropriate
#               validate_timestamps=0 and display_errors=Off.
RUN apk add --no-cache \
        postgresql-dev \
        oniguruma-dev \
        curl-dev \
        linux-headers \
        nginx \
        supervisor \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring pcntl sockets curl opcache

WORKDIR /var/www/html

# --- non-root user -------------------------------------------------------
# The whole container — supervisord as PID 1, and every process it
# supervises — runs as this single unprivileged user, not root. That
# means php-fpm's pool ("user"/"group" in the pool config) and nginx's
# own `user` directive both have to name the SAME uid the master
# processes already run as: neither master can setuid() to a *different*
# account without CAP_SETUID, which a non-root process doesn't have.
# setuid() to your own current uid, however, is always permitted — so
# reusing that identity everywhere (rather than inventing a second
# account) is what lets both servers start cleanly as non-root.
RUN addgroup -g 1000 tapehouse \
    && adduser -D -u 1000 -G tapehouse -s /sbin/nologin tapehouse \
    && sed -i \
        -e "s/^user = www-data/user = tapehouse/" \
        -e "s/^group = www-data/group = tapehouse/" \
        -e "s/^;listen = 127.0.0.1:9000/listen = 127.0.0.1:9000/" \
        /usr/local/etc/php-fpm.d/www.conf \
    && sed -i "s/^user nginx;/user tapehouse;/" /etc/nginx/nginx.conf \
    && mkdir -p /var/lib/nginx/tmp /var/log/nginx /run/nginx \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log \
    && chown -R tapehouse:tapehouse \
        /var/www/html \
        /var/lib/nginx \
        /var/log/nginx \
        /run/nginx \
        /usr/local/etc/php-fpm.d

COPY --from=vendor /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Application source last (changes most often — everything above this
# line stays cached across an ordinary code change). .dockerignore keeps
# vendor/, node_modules/, tests/, .git and public/build itself out of this
# COPY so neither of the two lines above gets shadowed by a stale host
# copy.
COPY . .

# Re-run the autoloader optimization now that app/ actually exists, so the
# classmap the vendor stage built (composer.json/lock only, no app/ yet)
# gets App\\'s classes folded in too rather than falling back to plain
# PSR-4 resolution for every one of them. This regenerates autoload files
# only — no package is re-resolved or re-downloaded, so it's fast.
#
# --no-scripts here too: composer.json's post-autoload-dump hook runs
# `artisan package:discover`, which boots the full framework — including
# resolving the `reverb` broadcaster eagerly — and this build stage has no
# APP_KEY/REVERB_APP_KEY/DB (those only exist as real container env vars
# at `docker run` time, not at build time), so that boot fails. Skipping
# it here is safe: bootstrap/cache/packages.php simply doesn't exist yet,
# and Laravel's PackageManifest builds it from vendor/composer/
# installed.json (and writes it back once bootstrap/cache is writable —
# which it is, chowned to tapehouse below) the first time anything actually
# boots the app, i.e. `entrypoint.sh`'s `migrate` at container start.
#
# No `storage:link` here: nothing in app/ ever reads from the `public`
# filesystem disk (grep confirms it — config/filesystems.php defines it,
# nothing uses it), and every artisan command that boots the framework —
# not just package:discover — resolves routes/channels.php's
# Broadcast::channel() calls as part of routing setup, which eagerly
# builds the `reverb` broadcaster and fatals without a real
# REVERB_APP_KEY. That key only exists as a container env var at `docker
# run` time, so nothing that boots the full app can run in this stage.
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative --no-scripts \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache \
    && chown -R tapehouse:tapehouse storage bootstrap/cache

COPY docker/php/prod.ini /usr/local/etc/php/conf.d/99-tapehouse-prod.ini
COPY docker/nginx/prod.conf /etc/nginx/http.d/default.conf
# /etc/supervisord.conf, not /etc/supervisor/supervisord.conf: this
# alpine's supervisorctl has /etc/supervisord.conf as its only built-in
# default search path — a bare `supervisorctl status` (no `-c`) against
# the nested location fails with "could not read config file
# /etc/supervisord.conf" even though the daemon itself is running fine.
# Keeping the source file's own path (docker/supervisor/supervisord.conf)
# unchanged and only choosing where it lands in the image.
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER tapehouse

# The ONE port this image exposes. nginx alone binds it (8080, not the
# privileged 80 — this user has no CAP_NET_BIND_SERVICE) and internally
# reverse-proxies /app, /apps to Reverb on a *different*, unexposed
# loopback port; see docker/nginx/prod.conf for why Reverb cannot also
# bind 8080 in this single-container topology the way it can as a
# separate compose service.
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
