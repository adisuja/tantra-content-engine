#!/usr/bin/env bash
# tantra: nightly database backup to R2 (run by cron as root, installed by tantra-ops).
# Dumps Postgres, gzips it, keeps 3 days on the server and copies it to
# <bucket>/backups/ on R2 (an R2 lifecycle rule deletes copies after 14 days).
# The R2 keys are read from /opt/tantra/.env and handed to rclone through a
# root-only temporary env file, so they never appear in a command line.
set -euo pipefail
umask 077
cd /opt/tantra

env_get() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

DB_USER="$(env_get DB_USERNAME)"
DB_NAME="$(env_get DB_DATABASE)"
BUCKET="$(env_get R2_BUCKET)"
STAMP="$(date -u +%F)"
FILE="backups/$STAMP.sql.gz"

mkdir -p backups
chmod 700 backups

docker compose exec -T pgsql pg_dump -U "$DB_USER" -d "$DB_NAME" --no-owner --no-privileges | gzip -9 > "$FILE.tmp"
gzip -t "$FILE.tmp"
mv "$FILE.tmp" "$FILE"

RCLONE_ENV="$(mktemp)"
trap 'rm -f "$RCLONE_ENV"' EXIT
{
    echo "RCLONE_CONFIG_R2_TYPE=s3"
    echo "RCLONE_CONFIG_R2_PROVIDER=Cloudflare"
    echo "RCLONE_CONFIG_R2_ACCESS_KEY_ID=$(env_get R2_ACCESS_KEY_ID)"
    echo "RCLONE_CONFIG_R2_SECRET_ACCESS_KEY=$(env_get R2_SECRET_ACCESS_KEY)"
    echo "RCLONE_CONFIG_R2_ENDPOINT=$(env_get R2_ENDPOINT)"
} > "$RCLONE_ENV"

docker run --rm --env-file "$RCLONE_ENV" -v /opt/tantra/backups:/backups:ro \
    rclone/rclone:1.68 copyto "/backups/$STAMP.sql.gz" "r2:$BUCKET/backups/$STAMP.sql.gz" --s3-no-check-bucket

find backups -name '*.sql.gz' -mtime +3 -delete
date -u +%FT%TZ > backups/last-success
echo "backup ok: $FILE ($(du -h "$FILE" | cut -f1)), copied to R2"
