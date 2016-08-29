#!/usr/bin/env bash
composer selfupdate
composer install -n

# PHPUnit
php ./vendor/bin/phpunit
