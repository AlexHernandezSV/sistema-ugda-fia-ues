#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

if [ ! -f .env ]; then
  cp .env.example .env
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ ! -f database/database.sqlite ]; then
  mkdir -p database
  touch database/database.sqlite
fi

if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi

if [ ! -x node_modules/.bin/vite ]; then
  npm ci
fi

if ! grep -q "^APP_KEY=base64:" .env; then
  php artisan key:generate --force
fi

php artisan migrate --seed --force || true

php artisan serve --host=0.0.0.0 --port=8000 &
PHP_PID=$!

npm run dev -- --host 0.0.0.0 --port 5173 &
VITE_PID=$!

cleanup() {
  kill "${PHP_PID}" "${VITE_PID}" 2>/dev/null || true
}

trap cleanup INT TERM EXIT
wait -n "${PHP_PID}" "${VITE_PID}"
