#!/usr/bin/env bash
set -euo pipefail
# Import sql/schema.sql into Hostinger (or any) MySQL.
# From project root. Uses same names as hPanel database u113439427_akhurath by default.
#
# Option A — password in env (avoid shell history):
#   export MYSQL_PWD='your_mysql_password'
#   ./scripts/mysql-import-schema.sh
#
# Option B — MySQL prompts for password:
#   unset MYSQL_PWD
#   ./scripts/mysql-import-schema.sh
#
# Override host/user/database if hPanel differs:
#   DB_HOST=127.0.0.1 DB_USER=u113439427_akhurath DB_NAME=u113439427_akhurath ./scripts/mysql-import-schema.sh

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-localhost}"
DB_USER="${DB_USER:-u113439427_akhurath}"
DB_NAME="${DB_NAME:-u113439427_akhurath}"
SQL_FILE="${ROOT}/sql/schema.sql"

if [[ ! -f "$SQL_FILE" ]]; then
  echo "Missing ${SQL_FILE}" >&2
  exit 1
fi

if ! command -v mysql >/dev/null 2>&1; then
  echo "mysql client not found. On Hostinger use phpMyAdmin → Import → sql/schema.sql instead." >&2
  exit 1
fi

echo "Importing ${SQL_FILE} into ${DB_NAME} on ${DB_HOST} as ${DB_USER} ..."
if [[ -n "${MYSQL_PWD:-}" ]]; then
  mysql -h"$DB_HOST" -u"$DB_USER" "$DB_NAME" <"$SQL_FILE"
else
  mysql -h"$DB_HOST" -u"$DB_USER" -p "$DB_NAME" <"$SQL_FILE"
fi
echo "Done."
