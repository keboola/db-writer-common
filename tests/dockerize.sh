#!/usr/bin/env bash

# wait for MySQL container to start
export DOCKERIZE_VERSION="v0.3.0"
wget https://github.com/jwilder/dockerize/releases/download/$DOCKERIZE_VERSION/dockerize-linux-amd64-$DOCKERIZE_VERSION.tar.gz \
    && tar -C /usr/local/bin -xzvf dockerize-linux-amd64-$DOCKERIZE_VERSION.tar.gz \
    && rm dockerize-linux-amd64-$DOCKERIZE_VERSION.tar.gz
dockerize -wait tcp://mysql:3306 -timeout 30s