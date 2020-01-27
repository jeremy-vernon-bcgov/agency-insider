FROM php:7.3-apache

RUN apt-get update && apt-get install --no-install-recommends -y \
    curl \
    git \
    libfreetype6-dev \
    libjpeg-dev \
    libpng-dev \
    libpq-dev \
    libzip-dev \
    ;\
    \
    docker-php-ext-configure gd \
        --with-freetype-dir=/usr \
        --with-jpeg-dir=/usr \
        --with-png-dir=/usr \
        ;\
    \
    docker-php-ext-install -j "$(nproc)" \
        gd \
        opcache \
        pdo_mysql \
        zip \
        ; \
    \

WORKDIR /var/www/html

RUN set -eux; \
    git clone -b fully-cooked https://github.com/Work-Webteam/agency-insider.git; \
    chown -R www-data:www-data sites modules themes