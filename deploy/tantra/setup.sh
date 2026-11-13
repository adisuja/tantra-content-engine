#!/usr/bin/env bash
# tantra: one-time setup of the Tantra Content Engine on the Oracle VM.
#
#   sudo bash /opt/tantra/setup.sh
#
# Writes /opt/tantra/.env with secrets generated here on the VM (app key,
# database password, Reverb secret, Passport keys), so they never leave it.
# Asks for one thing: the Cloudflare Tunnel token. Safe to run again: an
# existing .env is kept, and the stack is pulled and restarted.
set -euo pipefail

DIR=/opt/tantra
RAW=https://raw.githubusercontent.com/adisuja/tantra-content-engine/main/deploy/tantra
cd "$DIR"

echo "==> Fetching the latest compose file"
curl -fsSL "$RAW/compose.yaml" -o compose.yaml

if [ ! -f .env ]; then
    echo "==> Writing .env (secrets are generated on this VM)"
    echo
    echo "Paste the Cloudflare Tunnel token, then press Enter."
    echo "(Typing is hidden. It is the long string after --token in the Cloudflare dashboard.)"
    read -rs TUNNEL_TOKEN
    echo
    if [ ${#TUNNEL_TOKEN} -lt 100 ]; then
        echo "That does not look like a tunnel token (too short). Nothing was written." >&2
        exit 1
    fi

    APP_KEY="base64:$(openssl rand -base64 32)"
    DB_PASSWORD="$(openssl rand -hex 24)"
    REVERB_APP_SECRET="$(openssl rand -hex 24)"
    KEYDIR="$(mktemp -d)"
    openssl genrsa -out "$KEYDIR/private.pem" 4096 2>/dev/null
    openssl rsa -in "$KEYDIR/private.pem" -pubout -out "$KEYDIR/public.pem" 2>/dev/null
    PASSPORT_PRIVATE_KEY="$(awk 'BEGIN{ORS="\\n"} {print}' "$KEYDIR/private.pem")"
    PASSPORT_PUBLIC_KEY="$(awk 'BEGIN{ORS="\\n"} {print}' "$KEYDIR/public.pem")"
    shred -u "$KEYDIR/private.pem" "$KEYDIR/public.pem"
    rmdir "$KEYDIR"

    umask 077
    cat > .env <<ENV
# Tantra Content Engine production settings. Written by setup.sh on $(date -u +%F).
# Secrets in this file exist only on this VM. Keep it out of git and chat.

APP_NAME="Tantra Content Engine"
APP_ENV=production
APP_DEBUG=false
APP_KEY=${APP_KEY}
APP_URL=https://content.usetantra.com
SELF_HOSTED=true
TRYPOST_TARGET=production
TANTRA_IMAGE_TAG=latest
LOG_CHANNEL=daily
LOG_DAILY_DAYS=14

# Requests reach the app only from the cloudflared container on this compose
# network (see compose.yaml), so only that range may set X-Forwarded-* headers.
TRUSTED_PROXIES=172.30.0.0/24

DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=tantra
DB_USERNAME=tantra
DB_PASSWORD=${DB_PASSWORD}

REDIS_HOST=redis
REDIS_PORT=6379
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database
BROADCAST_CONNECTION=reverb

# The app key is public (the image build bakes it into the browser bundle) and
# must match VITE_REVERB_APP_KEY in .github/workflows/tantra-image.yml.
REVERB_APP_ID=1001
REVERB_APP_KEY=tantraksw2eiq3uobd69194mqv
REVERB_APP_SECRET=${REVERB_APP_SECRET}
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

PASSPORT_PRIVATE_KEY="${PASSPORT_PRIVATE_KEY}"
PASSPORT_PUBLIC_KEY="${PASSPORT_PUBLIC_KEY}"

# Local disk until the R2 keys are added (M0 step 2).
FILESYSTEM_DISK=public

# Mail goes to the log until Resend on notify.usetantra.com is set up.
MAIL_MAILER=log
MAIL_FROM_ADDRESS=engine@notify.usetantra.com
MAIL_FROM_NAME="Tantra Content Engine"

# No phone home, no TryPost changelog.
POSTHOG_ENABLED=false
NIGHTWATCH_ENABLED=false
CHANGELOG_ENABLED=false
TELESCOPE_ENABLED=false
GTM_ID=

# Networks: every one is listed in Add channel. A network connects once its
# developer app keys are in, either here (LINKEDIN_CLIENT_ID and the like) or
# saved by the owner in Settings > Connectors > Channels (ChannelApps).
# The *_ENABLED flags for networks are no longer read.
GOOGLE_DRIVE_ENABLED=false
GOOGLE_PHOTOS_ENABLED=false
CANVA_ENABLED=false
GOOGLE_AUTH_ENABLED=false
GITHUB_AUTH_ENABLED=false

# Filled in later in M0:
# LINKEDIN_CLIENT_ID=
# LINKEDIN_CLIENT_SECRET=
# LINKEDIN_CLIENT_REDIRECT=https://content.usetantra.com/accounts/linkedin/callback

TUNNEL_TOKEN=${TUNNEL_TOKEN}
ENV
    chmod 600 .env
else
    echo "==> Keeping the existing .env"
fi

echo "==> Pulling images"
docker compose pull
echo "==> Starting the stack"
docker compose up -d
echo
docker compose ps
echo
echo "Done. Give it about a minute, then open https://content.usetantra.com/up"
