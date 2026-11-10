FROM node:22-bookworm-slim AS frontend
WORKDIR /build
COPY bookboll-new-system/admin/package*.json ./
RUN npm ci
COPY bookboll-new-system/admin/ ./
ENV VITE_API_URL=/api VITE_PAYMENTS_PREVIEW=true
RUN npm run build

FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends nginx mariadb-client unzip git libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 pdo_mysql bcmath gd intl zip pcntl exif mbstring \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY bookboll-BACKEND/ ./
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache
COPY --from=frontend /build/dist /app/public/admin-preview
COPY bookboll_live_with_yopmail_testing_data.sql /opt/bookboll-snapshot.sql
COPY deploy/payments-preview/nginx.conf /etc/nginx/nginx.conf
COPY deploy/payments-preview/start.sh /usr/local/bin/start-preview
RUN chmod +x /usr/local/bin/start-preview \
    && printf 'memory_limit=512M\nmax_execution_time=120\n' > /usr/local/etc/php/conf.d/preview.ini
EXPOSE 8080
CMD ["/usr/local/bin/start-preview"]
