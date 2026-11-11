#!/usr/bin/env bash
# tantra: Google Compute Engine startup script for the Tantra Content Engine VM.
#
# Paste into: Create instance > Advanced > Automation > Startup script.
# Google runs it as root on every boot, so every step is safe to repeat.
# It holds no secrets: /opt/tantra/setup.sh asks for the tunnel token and
# generates everything else on the VM.
set -euo pipefail

# Docker and Compose, once.
if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq docker.io docker-compose-v2 curl openssl
fi
systemctl enable --now docker

# 2 GB of swap as a cushion for the queue workers, once.
if [ ! -f /swapfile ]; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi
swapon -a || true

timedatectl set-timezone UTC

# The one-time setup script, refreshed on each boot.
mkdir -p /opt/tantra
curl -fsSL https://raw.githubusercontent.com/adisuja/tantra-content-engine/main/deploy/tantra/setup.sh -o /opt/tantra/setup.sh
chmod 700 /opt/tantra/setup.sh

# Once setup.sh has written .env, bring the stack back up after a reboot.
if [ -f /opt/tantra/.env ] && [ -f /opt/tantra/compose.yaml ]; then
    (cd /opt/tantra && docker compose up -d)
fi

touch /opt/tantra/.startup-done
