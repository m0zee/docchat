# Phase 7 handoff: n8n and Google Drive

Branch `phase-7`, stacked on `phase-6`.

## What was built

- **n8n 2.49.5** in both compose files, with its own Postgres database and user, created by `docker/postgres/init-n8n.sh` on a fresh volume (deploy.md has the one-off command for an existing volume). Production serves it at `https://n8n.<DOCCHAT_DOMAIN>` (or `N8N_DOMAIN`) through Caddy. The scheduler service runs `schedule:work`.
- **Eval API:** `POST /api/v1/evals/run` (ability `evals:run`) and `GET /api/v1/evals/{id}`. Cases come from `cases` in the body or an `eval_set` file in `evals/` (default `sample`, at most 50 cases). `RunEvalJob` runs one case per job and re-queues itself after `delay_seconds` when the case called the model, so no job comes near the 300 s worker timeout. At the end it posts the results to `callback_url`, signed with a per-run `callback_secret` returned in the 202 response, and fires `eval.completed` to workspace webhooks (summary only).
- **Workflows** in `n8n/`: `drive-ingest.json`, `drive-sync-deletes.json`, `nightly-eval.json`, with a README covering settings, credentials, import, the HMAC check and a free Cloudflare quick tunnel.
- **Safety:** `tests/Unit/N8nWorkflowsTest.php` fails if an export has credential references, pinned data, or anything that looks like a key, secret or token. A new CI job imports every workflow into the pinned n8n image.

## Checked here

- All three workflows import into n8n 2.49.5 (CLI import, fresh SQLite and Postgres).
- Every node type and version exists in that n8n release.
- The signature check ran for real in n8n: a correctly signed `document.ready` got 200, a wrong secret and a 10-minute-old timestamp got 401.
- The nightly eval Wait node resumed from a signed callback (n8n 2.x resume URLs carry a `?signature=` token, which the workflow keeps) and built the summary email text.
- 290 Pest tests, Pint and PHPStan pass.

## Not checked (needs real accounts)

- The Google Drive trigger, download and search nodes and the Gmail nodes need Google OAuth credentials, so they have not run against Google.
- The HTTP Request nodes have not called a running DocChat from n8n here; the API they call is covered by Pest tests.

## Notes

- n8n warns that Postgres 16 is "outside the supported range and receives compatibility support only" and suggests 17. It works; moving the shared database to Postgres 17 with pgvector is a later, separate change.
- Workflows read `DOCCHAT_API_URL`, `DOCCHAT_CALLBACK_BASE_URL`, `DOCCHAT_WEBHOOK_SECRET` and `DOCCHAT_NOTIFY_EMAIL` from the environment, which needs `N8N_BLOCK_ENV_ACCESS_IN_NODE=false`. That lets any workflow read n8n's environment; fine for a single-owner test server, and written down in the README.
- An empty Drive folder stops the sync workflow without deleting anything, and it refuses to delete more than 25 documents in one run.

## Needs Moiz

1. A Google Cloud project with an OAuth client (free) for the Drive and Gmail credentials in n8n.
2. A DocChat API key with `documents:read`, `documents:write` and `evals:run`, pasted into an n8n Header Auth credential.
3. A DocChat webhook pointing at `http://n8n:5678/webhook/docchat-events`, with its secret in `DOCCHAT_WEBHOOK_SECRET`.
