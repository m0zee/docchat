# Handoff: Phase 6 (workspaces, API keys, public API)

Branch `phase-6`, draft PR #8 into `main`. One commit per task; CI green.

## What's in it

- **Workspaces**: `workspaces`, `workspace_user` (owner/member), `users.current_workspace_id`. All existing data was moved into a personal workspace per user by a migration. `CurrentWorkspace` + `BelongsToWorkspace` global scope; the retriever, jobs and API filter explicitly as well.
- **Members**: add an existing user by email, or send an invite link (7 days, single use, hashed, bound to the email). Invite mail is queued; default mailer is `log` (free SMTP options in deploy.md).
- **Sources and channels**: `documents.source/external_id/meta/content_hash` (re-upload replaces, unchanged content skips embedding), `conversations.channel/external_id`.
- **API**: `GET /api/v1/workspace`, `POST/GET /api/v1/documents`, `GET/DELETE /api/v1/documents/{id}`, `POST /api/v1/ask`, `GET|POST /api/v1/ask/stream` (SSE). Keys `dc_live_` (hashed, abilities, per-key limit). One JSON error shape. Docs at `/docs/api` (Scramble).
- **Formats**: plain, markdown, telegram_html, whatsapp, discord; split under 4000 (Discord 1900).
- **Webhooks**: document.ready/failed (eval.completed reserved for Phase 7), HMAC-SHA256 signature + timestamp, 5 tries with backoff.
- **Usage**: `usage_events` per question and ingestion; monthly page per channel.
- **Cache (Moiz's request)**: query-embedding cache, answer cache (exact + semantic ≥ 0.95, first questions only, per workspace and scope, invalidated by `workspaces.corpus_version`). `scheduler` service added to `docker-compose.prod.yml` for daily pruning.

## Decisions to confirm with Moiz

- Web chats are private to their author; documents are shared in a workspace.
- No Sanctum; custom keys.
- Docs at `/docs/api` are public (can be turned off).

## Not testable in the cloud

Real Gemini/Groq calls (keys on Moiz's PC), real email sending.
