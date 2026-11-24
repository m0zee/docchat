FROM serversideup/php:8.4-fpm-nginx-v5.2.1

USER root
WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader

COPY --chown=www-data:www-data . /var/www/html

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data /var/www/html

USER www-data
