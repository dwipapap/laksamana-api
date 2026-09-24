#!/usr/bin/env bash
# Restore the cPanel/phpMyAdmin dumps in ../db-backup into the LOCAL MySQL
# (Laragon, 127.0.0.1:3306). LOCAL ONLY — this script must never point at the
# live server: it DROPs and recreates each database it restores.
#
# Usage:
#   tools/restore-dumps.sh            # prod dumps (lakk5493_db_*), absensi from its dev dump
#   tools/restore-dumps.sh dev        # dev dumps (lakk5493_db_dev_*)
#   tools/restore-dumps.sh prod kompas stock   # only some modules
#
# Env: MYSQL_BIN (default Laragon 8.4), DUMP_DIR, MYSQL_HOST (must be local).
set -euo pipefail

MYSQL_BIN="${MYSQL_BIN:-/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql}"
DUMP_DIR="${DUMP_DIR:-$(cd "$(dirname "$0")/../../db-backup" && pwd)}"
MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"

case "$MYSQL_HOST" in
  127.0.0.1|localhost) ;;
  *) echo "REFUSING: MYSQL_HOST=$MYSQL_HOST is not local. This script drops databases." >&2; exit 2 ;;
esac

MODE="${1:-prod}"; shift || true
ONLY=("$@")

want() {
  [ ${#ONLY[@]} -eq 0 ] && return 0
  local m; for m in "${ONLY[@]}"; do [ "$m" = "$1" ] && return 0; done; return 1
}

restore() { # $1 = dump file, $2 = target database name
  local f="$1" db="$2"
  echo ">> $db  <=  $(basename "$f")"
  "$MYSQL_BIN" -uroot -h"$MYSQL_HOST" -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  # First line of MariaDB 10.11 dumps is a MariaDB-only sandbox marker; MySQL chokes on it.
  zcat "$f" | sed '1{/enable the sandbox mode/d}' | "$MYSQL_BIN" -uroot -h"$MYSQL_HOST" --max_allowed_packet=256M "$db"
}

for f in "$DUMP_DIR"/*.sql.gz; do
  base="$(basename "$f" .sql.gz)"
  case "$MODE" in
    prod)
      [[ "$base" == lakk5493_db_dev_* ]] && continue
      mod="${base#lakk5493_db_}"; mod="${mod#lakk5493_}"
      want "$mod" && restore "$f" "$base"
      ;;
    dev)
      [[ "$base" == lakk5493_db_dev_* ]] || continue
      mod="${base#lakk5493_db_dev_}"
      want "$mod" && restore "$f" "$base"
      ;;
    *) echo "mode must be prod|dev" >&2; exit 2 ;;
  esac
done

# No prod absensi dump exists — use the dev one under the prod name.
if [ "$MODE" = prod ] && want absensi; then
  restore "$DUMP_DIR/lakk5493_db_dev_absensi.sql.gz" lakk5493_db_absensi
fi

echo "done."
