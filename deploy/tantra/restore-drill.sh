#!/usr/bin/env bash
# tantra: restore drill. Restores the newest local backup into a throwaway
# database in the same Postgres container, compares row counts with the live
# database, then drops the throwaway copy. Never writes to the live database.
set -euo pipefail
cd /opt/tantra

env_get() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

DB_USER="$(env_get DB_USERNAME)"
DB_NAME="$(env_get DB_DATABASE)"
DRILL=tantra_restore_drill
FILE="$(ls -1t backups/*.sql.gz | head -1)"

psql() { docker compose exec -T pgsql psql -v ON_ERROR_STOP=1 -U "$DB_USER" "$@"; }

echo "restoring $FILE into $DRILL"
psql -d postgres -qc "DROP DATABASE IF EXISTS $DRILL"
psql -d postgres -qc "CREATE DATABASE $DRILL"
start=$(date +%s)
gunzip -c "$FILE" | psql -d "$DRILL" -q > /dev/null
echo "restore took $(( $(date +%s) - start ))s"

fail=0
for t in migrations accounts users workspaces social_accounts posts medias; do
    live=$(psql -d "$DB_NAME" -tAc "SELECT count(*) FROM $t" 2>/dev/null || echo n/a)
    copy=$(psql -d "$DRILL" -tAc "SELECT count(*) FROM $t" 2>/dev/null || echo n/a)
    if [ "$live" = "$copy" ]; then mark=same; else mark=DIFFERENT; fail=1; fi
    printf '%-16s live=%-6s restored=%-6s %s\n' "$t" "$live" "$copy" "$mark"
done

psql -d postgres -qc "DROP DATABASE $DRILL"

if [ "$fail" = 0 ]; then
    echo "restore drill PASSED"
else
    echo "restore drill: counts differ (writes during the drill can cause this)"
    exit 1
fi
