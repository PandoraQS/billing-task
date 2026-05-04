FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libzip-dev zip unzip libsqlite3-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_sqlite

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
