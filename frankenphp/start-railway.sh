#!/bin/sh
set -eu

: "${APP_SECRET:?Set APP_SECRET in Railway Variables.}"
: "${DATABASE_URL:?Connect the PostgreSQL DATABASE_URL in Railway Variables.}"

export PORT="${PORT:-8080}"
export APP_ENV=prod APP_DEBUG=0
export APP_STORAGE_DIR="${APP_STORAGE_DIR:-/app/storage}"
export DEFAULT_URI="${DEFAULT_URI:-https://${RAILWAY_PUBLIC_DOMAIN:-localhost}}"
export SYMFONY_TRUSTED_PROXIES="${SYMFONY_TRUSTED_PROXIES:-PRIVATE_SUBNETS,100.64.0.0/10}"
export SYMFONY_TRUSTED_HEADERS="${SYMFONY_TRUSTED_HEADERS:-x-forwarded-for,x-forwarded-proto,x-forwarded-port}"
export GOMEMLIMIT="${GOMEMLIMIT:-96MiB}"

mkdir -p "$APP_STORAGE_DIR/uploads/prod" "$APP_STORAGE_DIR/sessions/prod"

if [ "$(id -u)" = 0 ]; then
	# Railway mounts volumes as root. Fix data permissions, then run PHP as www-data.
	chmod 755 "$APP_STORAGE_DIR"
	chown -R www-data:www-data "$APP_STORAGE_DIR/uploads" "$APP_STORAGE_DIR/sessions"
	exec setpriv --reuid=www-data --regid=www-data --init-groups "$0"
fi

exec frankenphp run --config /etc/frankenphp/Caddyfile.railway
