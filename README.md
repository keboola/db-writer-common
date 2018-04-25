# Database Writer Common [DBWC]

[![Build Status](https://travis-ci.org/keboola/db-writer-common.svg?branch=master)](https://travis-ci.org/keboola/db-writer-common)

Common classes for creating vendor specific database writers.

## Installation
Install via composer:

    php composer.phar require db-writer-common

composer.json

    {
      "require": "db-writer-common": ^0.1
    }

## Development

1. Generate SSH key pair for SSH proxy:

        source ./tests/generate-ssh-keys.sh
    
2. Run tests:

        docker-compose run --rm tests
    
3. Run container in "dev" mode. (Changes made to code will reflect in container:

        docker-compose run --rm dev
    