FROM keboola/base-php56
MAINTAINER Miro Cillik <miro@keboola.com>

# Install dependencies
RUN yum -y --enablerepo=epel,remi,remi-php56 install \
    php-devel \
    php-mysql

ADD . /code
WORKDIR /code
RUN echo "memory_limit = -1" >> /etc/php.ini
RUN composer install --no-interaction

RUN curl --location --silent --show-error --fail \
        https://github.com/Barzahlen/waitforservices/releases/download/v0.3/waitforservices \
        > /usr/local/bin/waitforservices && \
    chmod +x /usr/local/bin/waitforservices

CMD php ./vendor/bin/phpunit
