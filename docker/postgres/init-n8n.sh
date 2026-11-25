#!/bin/sh
# Creates n8n's own database and user on the same Postgres server.
# Runs once, when the pgdata volume is first created. For an existing
# volume, run the same SQL by hand (see n8n/README.md).
set -eu

if [ -z "${N8N_DB_PASSWORD:-}" ]; then
    echo "N8N_DB_PASSWORD is not set; skipping the n8n database." >&2
    exit 0
fi

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -v n8n_password="$N8N_DB_PASSWORD" <<'SQL'
CREATE USER n8n WITH PASSWORD :'n8n_password';
CREATE DATABASE n8n OWNER n8n;
REVOKE ALL ON DATABASE n8n FROM PUBLIC;
SQL
