# syntax=docker/dockerfile:1

##
## base — the PHP-FPM foundation shared by every Tapehouse PHP process:
## app (php-fpm, fronted by nginx), reverb, queue, ingest. All four run the
## same image; only the `command:` differs per service in docker-compose.yml.
##
## Extension list is deliberately short — only what this codebase actually
## calls, not the usual Laravel-tutorial kitchen sink:
##   pdo_pgsql — the only DB driver (config/database.php default is pgsql)
##   mbstring  — Laravel's string helpers require it; not compiled in by
##               default on the alpine base image
##   pcntl     — TapeIngest and Reverb both install SIGTERM/SIGINT handlers
##               on the ReactPHP loop; without it those signals are ignored
##               and `docker compose down` has to SIGKILL after the timeout
##   curl      — Guzzle prefers curl when present; it falls back to a PHP
##               stream handler without it, but TwelveDataClient's REST
##               calls are on the hot path and curl is cheap to include
##   opcache   — compiled in and enabled; see docker/php/php.ini for the
##               dev-appropriate (validate_timestamps=1) settings
##
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache postgresql-dev oniguruma-dev curl-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring pcntl curl opcache

WORKDIR /var/www/html

##
## dev — adds Composer (for ad-hoc `composer` commands inside a running
## container) and this project's dev php.ini. It does NOT copy the
## application source or run `composer install`: docker-compose.yml bind
## -mounts the working tree into every service built from this target, so
## anything baked in here at build time would just be shadowed at run time
## and a code change wouldn't need a rebuild to be picked up. `vendor/` and
## `node_modules/` therefore have to already exist on the host (run
## `composer install` / `npm install` there first, same as non-Docker dev).
##
## Task 3: build the production image FROM base, not FROM dev — it needs
## its own COPY of the source, `composer install --no-dev --optimize-
## autoloader`, and opcache.validate_timestamps=0, none of which this stage
## does or should do.
##
FROM base AS dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-tapehouse-dev.ini

CMD ["php-fpm"]
