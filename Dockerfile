FROM php:7-cli

# Env vars
ARG DEBIAN_FRONTEND=noninteractive
ENV COMPOSER_ALLOW_SUPERUSER 1
ENV COMPOSER_PROCESS_TIMEOUT 3600

# Deps
RUN apt-get update
RUN apt-get install -y wget curl make git bzip2 time libzip-dev openssl unzip

# PHP
RUN docker-php-ext-install pdo pdo_mysql

# Composer
WORKDIR /root
RUN curl -sS https://getcomposer.org/installer | php \
  && ln -s /root/composer.phar /usr/local/bin/composer

# App
ADD . /code
WORKDIR /code
COPY docker/php-prod.ini /usr/local/etc/php/php.ini
RUN composer selfupdate && composer install --no-interaction

CMD php ./vendor/bin/phpunit
