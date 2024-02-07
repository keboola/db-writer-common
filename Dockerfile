FROM php:8.2-cli

ARG COMPOSER_FLAGS="--prefer-dist --no-interaction"
ARG DEBIAN_FRONTEND=noninteractive
ENV COMPOSER_ALLOW_SUPERUSER 1
ENV COMPOSER_PROCESS_TIMEOUT 3600

WORKDIR /code/

COPY docker/php-prod.ini /usr/local/etc/php/php.ini
COPY docker/composer-install.sh /tmp/composer-install.sh

# Install dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    locales \
    curl  \
    git  \
    bzip2  \
    time  \
    libzip-dev \
    ssh \
    openssl  \
    unzip  \
    procps \
    libicu-dev \
    && rm -r /var/lib/apt/lists/* \
    && sed -i 's/^# *\(en_US.UTF-8\)/\1/' /etc/locale.gen \
    && locale-gen \
    && chmod +x /tmp/composer-install.sh \
    && /tmp/composer-install.sh

# PDO mysql
RUN docker-php-ext-install pdo_mysql

# INTL
RUN docker-php-ext-configure intl \
    && docker-php-ext-install intl

# Xdebug
RUN pecl install xdebug \
 && docker-php-ext-enable xdebug

## Composer - deps always cached unless changed
# First copy only composer files
COPY composer.* /code/

# Download dependencies, but don't run scripts or init autoloaders as the app is missing
RUN composer install $COMPOSER_FLAGS --no-scripts --no-autoloader

# Copy rest of the app
COPY . /code/

# Run normal composer - all deps are cached already
RUN composer install $COMPOSER_FLAGS