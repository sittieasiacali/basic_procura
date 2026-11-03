# syntax=docker/dockerfile:1
FROM node:22-bookworm-slim AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:8.3-fpm-bookworm AS php-base
RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get -o Acquire::Retries=3 -o Acquire::https::Timeout=30 -o APT::Update::Error-Mode=any update \
    && apt-get -o Acquire::Retries=3 -o Acquire::https::Timeout=30 install -y --no-install-recommends \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev libonig-dev libzip-dev libicu-dev libfcgi-bin unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring gd zip intl pcntl bcmath opcache \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html

FROM php-base AS application-build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader
COPY . .
COPY --from=assets /build/public/build ./public/build
RUN mkdir -p bootstrap/cache storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction --no-scripts \
    && php artisan package:discover --ansi \
    && ln -s /var/www/html/storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

FROM php-base AS app
COPY --from=application-build --chown=www-data:www-data /var/www/html /var/www/html
COPY infra/docker/php.ini /usr/local/etc/php/conf.d/zz-procura.ini
COPY infra/docker/fpm.conf /usr/local/etc/php-fpm.d/zz-procura.conf
COPY infra/docker/entrypoint.sh /usr/local/bin/procura-entrypoint
COPY infra/docker/restore-uploads.sh /usr/local/bin/procura-restore-uploads
COPY infra/docker/tools/ /usr/local/lib/procura/
RUN sed -i 's/\r$//' /usr/local/bin/procura-entrypoint /usr/local/bin/procura-restore-uploads \
    && chmod +x /usr/local/bin/procura-entrypoint /usr/local/bin/procura-restore-uploads
USER www-data
EXPOSE 9000
ENTRYPOINT ["procura-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:stable-alpine AS web
COPY --from=application-build /var/www/html/public /var/www/html/public
COPY infra/docker/nginx.conf /etc/nginx/nginx.conf
USER nginx
EXPOSE 8080
ENTRYPOINT ["nginx", "-g", "daemon off;"]
