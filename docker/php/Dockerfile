FROM composer:2.8.11 AS dependencies

WORKDIR /build

COPY src/composer.json src/composer.lock ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist \
    && composer clear-cache

FROM php:8.4.13-fpm-bookworm

ARG APP_TIMEZONE=America/Fortaleza

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libfcgi-bin \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libsqlite3-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_sqlite \
        zip \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libsqlite3-dev \
        libzip-dev \
    && apt-get install -y --no-install-recommends \
        libfreetype6 \
        libjpeg62-turbo \
        libpng16-16 \
        libsqlite3-0 \
        libzip4 \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2.8.11 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/99-centralvet.ini
COPY docker/php/php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-centralvet.conf
COPY --chown=www-data:www-data src /var/www/html/src
COPY --from=dependencies --chown=www-data:www-data /build/vendor /var/www/html/src/vendor

WORKDIR /var/www/html/src

# The `dependencies` stage only has composer.json/composer.lock (no app/
# source), so it cannot generate a classmap for the CentralVet\ PSR-4
# namespace. Regenerate the autoloader now that the real source tree is
# present, so `--classmap-authoritative` reflects the actual CentralVet\
# classes instead of baking in an empty map for that namespace.
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer clear-cache

USER www-data
