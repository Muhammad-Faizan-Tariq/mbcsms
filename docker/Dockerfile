FROM php:8.3-fpm-alpine
RUN docker-php-ext-install pdo_mysql
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
