FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
        git unzip libssl-dev pkg-config \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
