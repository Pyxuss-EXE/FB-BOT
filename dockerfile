FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    git curl libzip-dev zip unzip supervisor \
    && docker-php-ext-install zip

RUN a2enmod rewrite

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN mkdir -p storage/results logs \
    && touch storage/users.json storage/jobs.json

RUN composer install --no-dev --optimize-autoloader

COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/storage \
    && chmod -R 777 /var/www/html/logs \
    && chmod +x /var/www/html/bot.php \
    && chmod +x /var/www/html/worker.php

EXPOSE 80

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]