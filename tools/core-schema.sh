#!/usr/bin/env bash
# Build the EMPTY `core` schema as one SQL file for a one-time phpMyAdmin import
# on the server (ADR-0005, docs/deploy.md): every migration applied to a scratch
# LOCAL database, dumped without data except the `migrations` rows (so a later
# `php artisan migrate` knows what already ran). The scratch DB is dropped after.
#
# LOCAL ONLY: it creates and drops a scratch database; never point it at a server.
#
# Usage:  tools/core-schema.sh [out-file]      (default: ../core-schema.sql, outside the repo)
# Env:    MYSQL_BIN / MYSQLDUMP_BIN (default Laragon 8.4), PHP_BIN, MYSQL_HOST (must be local).
set -euo pipefail

MYSQL_BIN="${MYSQL_BIN:-/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql}"
MYSQLDUMP_BIN="${MYSQLDUMP_BIN:-/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump}"
PHP_BIN="${PHP_BIN:-php}"
MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
DB="lakk5493_laksamana_core_schema"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/../core-schema.sql}"

case "$MYSQL_HOST" in
  127.0.0.1|localhost) ;;
  *) echo "REFUSING: MYSQL_HOST=$MYSQL_HOST is not local." >&2; exit 2 ;;
esac

sql() { "$MYSQL_BIN" -uroot -h"$MYSQL_HOST" -e "$1"; }
sql "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
trap 'sql "DROP DATABASE IF EXISTS \`$DB\`;"' EXIT

(cd "$ROOT" && DB_HOST="$MYSQL_HOST" DB_DATABASE="$DB" DB_USERNAME=root DB_PASSWORD= \
  "$PHP_BIN" artisan migrate --database=core --force --no-interaction)

dump() { "$MYSQLDUMP_BIN" -uroot -h"$MYSQL_HOST" --skip-comments --skip-add-locks --set-gtid-purged=OFF --no-tablespaces "$@"; }
{
  echo "-- laksamana-api core schema, $(git -C "$ROOT" rev-parse --short HEAD), $(date -u +%Y-%m-%dT%H:%MZ)"
  echo "-- Import ONCE into the empty core database (phpMyAdmin → Import). No data except migrations."
  dump --no-data "$DB"
  dump --no-create-info "$DB" migrations
} | sed -E "s#/\*!80016 DEFAULT ENCRYPTION='N' \*/##" > "$OUT"
# (MySQL-only table option: MariaDB would execute the /*!80016 */ comment and fail.)

echo "Wrote $OUT ($(grep -c '^CREATE TABLE' "$OUT") tables)."
