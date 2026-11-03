#!/bin/sh
set -eu
umask 0022

for key in APP_KEY DB_PASSWORD MAIL_PASSWORD; do
    eval "secret_file=\${${key}_FILE:-}"
    if [ -n "$secret_file" ]; then
        [ -r "$secret_file" ] || { echo "Cannot read secret file for $key" >&2; exit 1; }
        value=$(cat "$secret_file")
        export "$key=$value"
    fi
done

mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
if [ "${PROCURA_SKIP_BOOTSTRAP:-0}" != "1" ]; then
    [ -n "${APP_KEY:-}" ] || { echo 'APP_KEY is required; use the preserved XAMPP key.' >&2; exit 1; }
    php /usr/local/lib/procura/wait-for-database.php
    if [ "${PROCURA_RUN_MIGRATIONS:-0}" = "1" ]; then
        php artisan migrate --force --no-interaction
    fi
    php artisan config:cache --no-interaction
    php artisan view:cache --no-interaction
fi
exec "$@"
