#!/bin/sh

set -eu

cd /var/www/html

if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
fi

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

until php artisan migrate --seed --force; do
    echo "Waiting for database to be ready..."
    sleep 2
done

php artisan storage:link >/dev/null 2>&1 || true

php artisan serve --host=0.0.0.0 --port=8000
