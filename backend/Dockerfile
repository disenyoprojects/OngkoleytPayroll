FROM php:8.2-cli-alpine AS build

RUN apk add --no-cache git unzip libzip-dev icu-dev oniguruma-dev libpng-dev \
    && docker-php-ext-install pdo pdo_mysql zip intl mbstring bcmath gd

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8080

CMD php artisan migrate --force \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan serve --host=0.0.0.0 --port=${PORT:-8080}
