#!/usr/bin/env bash

clear

export TESTDB_DSN="mysql:host=localhost;dbname=dbtest"
export TESTDB_USER="root"
export TESTDB_PASS="password"

php -dxdebug.remote_enable=1 \
    -dxdebug.remote_autostart=1 \
    -dxdebug.remote_port=9000 \
    -dxdebug.remote_host=localhost \
    vendor/phpunit/phpunit/phpunit
#    -dxdebug.idekey=123 \
