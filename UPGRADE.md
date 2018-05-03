# Upgrade Guide

## From 3.0 to 4.0

### docker-compose.yml

- ENV variables has no longer `driver` prefixes. i.e MSSQL_DB_HOST is now only DB_HOST
- Dockerize wait script has been replaced with `waisbrot/wait` image. Add it to your docker-compose.yml file.
- SSH keys are now generated via script `/tests/generate-ssh-keys.sh`. Modify your `sshproxy` service and ENV vars holding the keys accordingly:

    
    tests:
        build: .
        image: keboola/db-writer-mssql
        command: composer ci
        working_dir: /code
        tty: true
        environment:
          - DB_HOST
          - DB_PORT
          - DB_USER
          - DB_PASSWORD
          - DB_DATABASE
          - SSH_KEY_PRIVATE
        depends_on:
          - sshproxy
          - mssql

    sshproxy:
        build: ./tests/env/sshproxy
        command: sh -c 'echo $SSH_KEY_PUBLIC >> ~/.ssh/authorized_keys && /usr/sbin/sshd -D'
        environment:
          - SSH_KEY_PUBLIC
        ports:
          - "2222:22"
        links:
          - mssql


### Keboola\DbWriter\Test\BaseTest.php
Parent class of (all) tests.

- `getConfig(self::driver)` simplified to `getConfig()`
- Override $dataDir variable in your child test classes

### run.php

- `require_once(dirname(__FILE__) . "/vendor/keboola/db-writer-common/bootstrap.php");` changed to  `require_once(dirname(__FILE__) . "/vendor/autoload.php");`