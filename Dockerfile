# syntax=docker/dockerfile:1

# All base images are pinned to an exact version *and* digest so that builds are
# reproducible and a re-tagged upstream image cannot silently change the runtime.
# Update procedure: see SECURITY_UPGRADE_REPORT.md ("Updating pinned versions").

##############################################
# Stage 1: Install node dependencies and run gulp
# (build-time only; nothing from this stage except the compiled assets is shipped)
##############################################

FROM node:11.15.0@sha256:67ca28addce8ae818b144114a9376a6603aba09069b7313618d37b38584abba1 AS npm
WORKDIR /app

COPY package.json /app
COPY package-lock.json /app
RUN npm install

COPY resources /app/resources
COPY gulpfile.js /app
RUN npm run gulp

##############################################
# Stage 2: PHP 8.5 runtime base (PHP-FPM + nginx, supervised by s6-overlay)
##############################################

FROM php:8.5.12-fpm-alpine3.23@sha256:35eb24f8520b817fb9329fbd678c3ea3310ac673e509fc2f0a5b1026995a1460 AS base

# Alpine only keeps the newest revision of a package in its repositories, so packages
# are pinned to their release series (~) rather than to an exact revision.
# `apk upgrade` applies security updates released after the base image was built
# (e.g. zlib 1.4-r0 for CVE-2026-4738); the Alpine release itself stays 3.23.
RUN apk upgrade --no-cache \
    && apk add --no-cache \
        'nginx~1.28' \
        's6-overlay~3.2' \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    # pcntl lets `queue:work` enforce --timeout and stop gracefully on SIGTERM
    && docker-php-ext-install -j"$(nproc)" pcntl \
    && apk del --no-network .build-deps \
    && rm -rf /tmp/pear /usr/src/php.tar.xz

# PHP: production defaults plus this application's hardening (docker/php/php.ini)
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/php.ini $PHP_INI_DIR/conf.d/zz-webhook.ini
COPY docker/php-fpm/zzz-webhook.conf /usr/local/etc/php-fpm.d/zzz-webhook.conf

# nginx: main config + application vhost (proxies socket.io to laravel-echo-server)
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY nginx.conf /etc/nginx/http.d/default.conf

# s6-overlay services: nginx and php-fpm. A container command (e.g. `php artisan
# queue:work`) runs alongside them as the main process, as with the previous image.
COPY docker/s6-rc.d /etc/s6-overlay/s6-rc.d
RUN chmod +x /etc/s6-overlay/s6-rc.d/*/run

# Make the container environment (APP_*, REDIS_*, ...) visible to the services
ENV S6_KEEP_ENV=1 \
    S6_BEHAVIOUR_IF_STAGE2_FAILS=2

# Tunables referenced from docker/php/php.ini and docker/php-fpm/zzz-webhook.conf
ENV PHP_MEMORY_LIMIT=128M \
    PHP_SESSION_COOKIE_SECURE=0 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_FPM_MAX_CHILDREN=20

# Everything (s6, nginx, php-fpm and the container command) runs as www-data:
# give that user the few runtime paths it has to write to.
RUN mkdir -p /run/nginx \
    && chown www-data:www-data /run /run/nginx \
    && chown -R www-data:www-data /var/lib/nginx

WORKDIR /var/www/html

##############################################
# Stage 3: Composer dependencies, autoloader and SQLite schema
# (composer itself is not shipped in the runtime image)
##############################################

FROM base AS vendor

COPY --from=composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 /usr/bin/composer /usr/bin/composer

RUN chown www-data:www-data /var/www/html
USER www-data

COPY --chown=www-data:www-data composer.json composer.lock /var/www/html/

# allow_url_fopen is disabled for the application (docker/php/php.ini) but composer needs it
RUN php -d allow_url_fopen=On /usr/bin/composer install --no-interaction --no-autoloader --no-dev --prefer-dist --no-scripts \
    && rm -rf /home/www-data/.composer/cache

COPY --chown=www-data:www-data storage /var/www/html/storage
COPY --chown=www-data:www-data bootstrap /var/www/html/bootstrap
COPY --chown=www-data:www-data public /var/www/html/public
COPY --chown=www-data:www-data artisan /var/www/html/
COPY --chown=www-data:www-data database /var/www/html/database
COPY --chown=www-data:www-data config /var/www/html/config
COPY --chown=www-data:www-data app /var/www/html/app
COPY --chown=www-data:www-data resources /var/www/html/resources

RUN php -d allow_url_fopen=On /usr/bin/composer dump-autoload --optimize --no-dev \
    && php artisan package:discover \
    && touch /var/www/html/database/database.sqlite \
    && php artisan migrate --force

COPY --chown=www-data:www-data --from=npm /app/public/css /var/www/html/public/css
COPY --chown=www-data:www-data --from=npm /app/public/js /var/www/html/public/js

##############################################
# Stage 4: Runtime image
##############################################

FROM base AS runtime

# Application code is owned by root and read-only for the PHP processes; only the
# directories Laravel writes to at runtime belong to www-data.
COPY --from=vendor --chown=root:root /var/www/html /var/www/html
RUN chown -R www-data:www-data \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache \
        /var/www/html/database

USER www-data

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/ || exit 1

ENTRYPOINT ["/init"]
