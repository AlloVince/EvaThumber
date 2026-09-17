# syntax=docker/dockerfile:1
FROM dunglas/frankenphp:1-php8.5-bookworm@sha256:7380f2c24ef9d86ad496dbc285ba66e212365c864db2797f3e7269afad100dbb AS base
RUN apt-get update && apt-get install -y --no-install-recommends libvips42 libvips-tools libffi-dev unzip \
    && docker-php-ext-install ffi \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2@sha256:aaeab4b6b031e0a88efb907f0f26b563532a644fc2f4ea0d000ecf8658f7a2b8 /usr/bin/composer /usr/local/bin/composer
RUN printf 'ffi.enable=true\nmemory_limit=256M\nexpose_php=Off\ndisplay_errors=Off\nlog_errors=On\nerror_log=/dev/stderr\n' > /usr/local/etc/php/conf.d/evathumber.ini
ENV VIPS_CONCURRENCY=2 VIPS_DISC_THRESHOLD=32m
WORKDIR /app

FROM base AS development
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist
COPY . .

FROM base AS production
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative --no-scripts
COPY src ./src
COPY public ./public
COPY bin/transform.php ./bin/transform.php
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p /data/images /data/cache /config/caddy /data/caddy \
    && chown -R www-data:www-data /data /config/caddy
USER www-data
ENV SERVER_NAME=:8081 EVATHUMBER_SOURCE=/data/images EVATHUMBER_CACHE=/data/cache
EXPOSE 8081
HEALTHCHECK --interval=30s --timeout=3s CMD curl -fsS http://localhost:8081/healthz || exit 1
STOPSIGNAL SIGTERM

