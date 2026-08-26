FROM php:8.3-fpm

# system deps
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libzip-dev libicu-dev libonig-dev zip curl \
 && docker-php-ext-install pdo pdo_pgsql intl zip

# composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# install xdebug
RUN pecl install xdebug \
 && docker-php-ext-enable xdebug

# xdebug dev config (adjust as needed)
RUN printf "xdebug.mode=develop,debug\nxdebug.start_with_request=yes\nxdebug.client_port=9003\nxdebug.discover_client_host=1\n" \
    > /usr/local/etc/php/conf.d/xdebug.ini

WORKDIR /var/www/html
