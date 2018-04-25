FROM php:7.1-cli
MAINTAINER Miro Cillik <miro@keboola.com>

# Deps
RUN apt-get update
RUN apt-get install -y wget curl make git bzip2 time libzip-dev openssl unzip

# PHP
RUN docker-php-ext-install pdo pdo_mysql

# Composer
WORKDIR /root
RUN cd \
  && curl -sS https://getcomposer.org/installer | php \
  && ln -s /root/composer.phar /usr/local/bin/composer

ADD . /code
WORKDIR /code
RUN echo "memory_limit = -1" >> /etc/php.ini
RUN composer selfupdate && composer install --no-interaction

CMD php ./vendor/bin/phpunit
