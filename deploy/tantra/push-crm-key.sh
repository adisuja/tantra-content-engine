#!/usr/bin/env bash
# tantra: puts the Tantra CRM API key (tracked short links and the traffic report)
# into the server's /opt/tantra/.env as TANTRA_CRM_API_KEY, then restarts the app.
#
#   bash deploy/tantra/push-crm-key.sh
#
# Run from a Mac that has the key in ~/.config/tantra/keys. The key goes over
# SSH on stdin: it is never printed, never in a command line, never in git.
# Override with CRM_KEY_FILE, TANTRA_HOST, TANTRA_SSH_KEY.
set -euo pipefail

KEY_FILE="${CRM_KEY_FILE:-$HOME/.config/tantra/keys/crm-team-owner.key}"
HOST="${TANTRA_HOST:-ubuntu@3.7.58.100}"
SSH_KEY="${TANTRA_SSH_KEY:-$HOME/.ssh/tantra-engine-aws.pem}"

if [ ! -s "$KEY_FILE" ]; then
    echo "No CRM key at $KEY_FILE" >&2
    exit 1
fi

tr -d '\r\n' < "$KEY_FILE" | ssh -i "$SSH_KEY" "$HOST" 'set -euo pipefail
    KEY="$(cat)"
    cd /opt/tantra
    sudo cp .env ".env.bak.$(date -u +%Y%m%d%H%M%S)"
    sudo sed -i "/^TANTRA_CRM_API_KEY=/d" .env
    printf "TANTRA_CRM_API_KEY=%s\n" "$KEY" | sudo tee -a .env > /dev/null
    sudo docker compose up -d --force-recreate app > /dev/null 2>&1
    echo "TANTRA_CRM_API_KEY set ($(printf %s "$KEY" | wc -c | tr -d " ") characters); app restarted."'
