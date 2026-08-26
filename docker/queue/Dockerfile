# Base image
FROM php:8.3-fpm

# Set mirror ایرانی برای دبیان
RUN sed -i 's|http://deb.debian.org/debian|http://ftp.ir.debian.org/debian|g' /etc/apt/sources.list \
 && sed -i 's|http://security.debian.org/debian-security|http://ftp.ir.debian.org/debian-security|g' /etc/apt/sources.list

# نصب dependencies سیستم و PHP extensions
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    zip \
    curl \
 && docker-php-ext-install \
    pdo \
    pdo_pgsql \
    intl \
    zip \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# نصب Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy source code
COPY . .

# نصب PHP packages با Composer
RUN composer install --no-interaction --prefer-dist --optimize-autoloader