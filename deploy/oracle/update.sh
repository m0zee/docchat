#!/bin/sh
# Updates the running server to a commit on main and restarts what changed.
# Called by .github/workflows/deploy.yml over SSH; also fine to run by hand:
#   cd ~/docchat && sh deploy/oracle/update.sh            (latest main)
#   cd ~/docchat && sh deploy/oracle/update.sh <commit>   (a specific commit)
# .env and the Docker volumes (database, uploads, certificates) are untouched.
set -eu
cd "$(dirname "$0")/../.."

TARGET="${1:-origin/main}"
git fetch --quiet origin main
git checkout --quiet main
git reset --quiet --hard "$TARGET"
echo "Deploying $(git log -1 --format='%h %s')"

COMPOSE="docker compose -f docker-compose.prod.yml"
$COMPOSE build
# The app container runs migrations and warms caches on start.
$COMPOSE up -d --remove-orphans
# Workers keep old code in memory until restarted.
$COMPOSE restart worker scheduler
docker image prune -f >/dev/null
$COMPOSE ps
