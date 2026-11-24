# Phase 7 tasks: n8n and Google Drive auto-ingest

Branch `phase-7`, PR stacked on `phase-6`.

- [ ] **P7-1 n8n service.** `n8nio/n8n` (pinned version) in `docker-compose.yml` and `docker-compose.prod.yml`, own volume, own Postgres database on the same server (init script creates `n8n` DB and user), `GENERIC_TIMEZONE=Asia/Karachi`, `WEBHOOK_URL`, `N8N_ENCRYPTION_KEY` from `.env`. CI validates both compose files.
- [ ] **P7-2 Caddy and tunnel.** `n8n.<domain>` site in the Caddyfile. deploy.md: DNS / sslip.io name for n8n, cloudflared quick tunnel for local webhook tests.
- [ ] **P7-3 /n8n folder.** README: import steps, credentials per workflow, the DocChat API key abilities each needs, HMAC verify snippet. A test that fails if any exported workflow contains credential secrets or IDs.
- [ ] **P7-4 drive-ingest.json.** Drive Trigger (created + updated) on a folder, native Google files exported as PDF, other types downloaded, unsupported skipped, upload with source google_drive and external_id = file id. Webhook node for document.ready / failed with HMAC check in a Code node, then email.
- [ ] **P7-5 drive-sync-deletes.json.** Nightly schedule, list Drive file ids, `GET /api/v1/documents?source=google_drive`, delete the missing ones. Add pagination (`per_page`, `cursor`) to the list endpoint if needed.
- [ ] **P7-6 Eval API.** `POST /api/v1/evals/run` (ability `evals:run`, owner keys only), queued `RunEvalJob`, results posted to a callback URL signed like other webhooks. Tests.
- [ ] **P7-7 nightly-eval.json.** Schedule, start eval, Wait node resumed by the callback, email summary.
- [ ] **P7-8 Import check and handoff.** Import every workflow into a throwaway n8n container to prove the JSON loads. Handoff file.
