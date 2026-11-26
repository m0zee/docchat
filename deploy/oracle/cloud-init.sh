#!/bin/bash
# First-boot setup for DocChat on an Oracle Cloud Always Free Ubuntu VM.
# Passed as the instance's user data by create-server.sh (it runs once, as root).
# Does deploy.md steps 2b to 5 without SSH: opens the VM firewall, installs
# Docker, clones DocChat, writes a .env with fresh random secrets and the free
# <ip>.sslip.io hostname, then builds and starts the stack.
# Progress: sudo tail -f /var/log/docchat-setup.log   Done marker: /var/log/docchat-setup.done
# The AI keys (GEMINI_API_KEY, GROQ_API_KEY) are left empty; add them to
# /home/ubuntu/docchat/.env later and run: docker compose -f docker-compose.prod.yml up -d
set -euo pipefail
exec > >(tee -a /var/log/docchat-setup.log) 2>&1
echo "== DocChat setup started $(date -u)"

REPO_URL="${DOCCHAT_REPO_URL:-https://github.com/m0zee/docchat.git}"
REPO_REF="${DOCCHAT_REPO_REF:-main}"
APP_DIR=/home/ubuntu/docchat

# 1. VM firewall: Oracle's Ubuntu image rejects everything but SSH.
for rule in "tcp 80" "tcp 443" "udp 443"; do
  set -- $rule
  iptables -C INPUT -m state --state NEW -p "$1" --dport "$2" -j ACCEPT 2>/dev/null \
    || iptables -I INPUT 5 -m state --state NEW -p "$1" --dport "$2" -j ACCEPT
done
netfilter-persistent save || true

# 2. Docker (official repository; arm64 builds).
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl git openssl
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  > /etc/apt/sources.list.d/docker.list
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
usermod -aG docker ubuntu

# 3. Code.
if [ ! -d "$APP_DIR/.git" ]; then
  git clone --branch "$REPO_REF" "$REPO_URL" "$APP_DIR"
fi
cd "$APP_DIR"

# 4. .env with random secrets (only if there isn't one yet).
if [ ! -f .env ]; then
  PUBLIC_IP="$(curl -fsS --max-time 10 https://api.ipify.org || curl -fsS --max-time 10 https://ifconfig.me)"
  DOMAIN="${PUBLIC_IP}.sslip.io"
  cp .env.production.example .env
  set_env() { sed -i "s|^$1=.*|$1=$2|" .env; }
  set_env APP_KEY "base64:$(openssl rand -base64 32)"
  set_env APP_URL "https://${DOMAIN}"
  set_env DOCCHAT_DOMAIN "${DOMAIN}"
  set_env DB_PASSWORD "$(openssl rand -hex 24)"
  set_env N8N_DB_PASSWORD "$(openssl rand -hex 24)"
  set_env N8N_ENCRYPTION_KEY "$(openssl rand -hex 24)"
  chmod 600 .env
  echo "== Domain: https://${DOMAIN}"
fi
chown -R ubuntu:ubuntu "$APP_DIR"

# 5. Build and start (the first build takes a while on 2 Arm cores).
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml ps

date -u > /var/log/docchat-setup.done
echo "== DocChat setup finished $(date -u)"
