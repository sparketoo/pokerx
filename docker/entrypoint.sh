#!/bin/sh

set -eu

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "Waiting for MySQL..."
    php /opt/www/docker/wait-for-mysql.php

    echo "Running database migrations..."
    php /opt/www/bin/hyperf.php migrate --force
fi

exec php /opt/www/bin/hyperf.php start "$@"
