# DocChat: additional phases (6 to 10)

> Copied from Moiz's Google Doc "DOCCHAT_PHASES_6_10.md" on 2026-11-24 as Claude's working copy. The Google Doc is the original and was not changed. Only formatting was cleaned up.

## Context

DocChat is the Laravel 13 RAG app built in Phases 0 to 5 from DOCCHAT_PROMPT.md: upload PDF/TXT/MD, queued ingestion (parse, chunk, embed with Gemini, store in pgvector), streaming chat with page citations, evals command, Pest tests, Docker, README.

These phases turn it into a multi-channel product: a public API, n8n automations (Google Drive, Telegram, WhatsApp), Discord, and an embeddable website widget. Same working rules as before: plan first and wait for my OK, one phase at a time, run tests, show changes, commit after each phase, ask before adding any package not listed, check current docs for any API you are unsure about, never commit secrets.

Before starting Phase 6, read the existing codebase and tell me what needs refactoring for workspaces (tenancy). If Phases 0 to 5 are not finished yet, tell me and stop.

## Target architecture

- Laravel owns all AI logic, data, auth, tenancy, billing-ready usage tracking.
- n8n owns triggers and third-party integrations (Google Drive, Telegram, WhatsApp, Gmail, schedules).
- Every channel (web, API, widget, Telegram, WhatsApp, Discord) goes through one AnswerService, so retrieval, prompt and citations behave the same everywhere.
- Add a Mermaid diagram of this to the README.

---

## Phase 6: Workspaces, API keys and the public API

### Tenancy

- New tables: `workspaces` (id, name, slug, settings json, timestamps) and `workspace_user` (workspace_id, user_id, role: owner, member).
- Add `workspace_id` to documents, chunks, conversations, messages (migrate existing data into a default workspace per user).
- A global scope or a `BelongsToWorkspace` trait so every query is scoped. The current workspace comes from the session (web) or the API key (API).
- Workspace switcher in the dashboard. Policies updated for workspace roles.

### Documents: external sources

- Add `source` (upload, google_drive, telegram, whatsapp, api) and `external_id` (nullable) to documents, unique on (workspace_id, source, external_id).
- Uploading again with the same external_id replaces the file and re-ingests: delete old chunks, re-embed, keep the same document id.
- Store a `content_hash`; skip re-embedding if the content did not change.

### Conversations: channels

- Add `channel` (web, api, widget, telegram, whatsapp, discord) and `external_id` (nullable) to conversations, unique on (workspace_id, channel, external_id).

### API keys and usage

- `api_keys` table: workspace_id, name, key prefix (shown), hashed secret, abilities json (ask, documents:write, documents:read), allowed_origins json, rate_limit_per_minute, last_used_at, revoked_at.
- Keys shown once at creation, format `dc_live_<random>`. Settings page to create, list and revoke.
- `usage_events` table: workspace_id, api_key_id nullable, channel, type (question, ingest), prompt_tokens, completion_tokens, embedding_tokens, latency_ms, created_at. Monthly usage page with totals per channel.
- Per-key rate limiting with Laravel RateLimiter, 429 with Retry-After.

### Endpoints (versioned, JSON, consistent error format)

- `POST /api/v1/documents` multipart: file, optional title, source, external_id. Returns document with status.
- `GET /api/v1/documents`, `GET /api/v1/documents/{id}`, `DELETE /api/v1/documents/{id}`
- `POST /api/v1/ask`: question, optional document_ids, optional channel, optional external_conversation_id. Returns answer, citations (document_id, title, page, snippet), conversation_id, usage.
- `GET /api/v1/ask/stream`: same input as query params or POST body, Server-Sent Events: token events, then a final done event with citations and usage.
- Optional `format` field on ask: plain, markdown, telegram_html, whatsapp. The response then includes a ready-to-send `formatted` array, already split into channel-safe message parts.

### Outgoing webhooks

- `webhooks` table per workspace: url, events json (document.ready, document.failed), secret, active.
- Send with a queued job, retries with backoff, header `X-DocChat-Signature: sha256=<hmac of raw body>` and `X-DocChat-Timestamp`. Reject replays older than 5 minutes on the receiver side (document this).
- Payload includes document id, title, status, source, external_id, and any meta passed at upload (so n8n can reply to the right Telegram chat).

### Docs

- OpenAPI docs with Scramble (dedoc/scramble) at /docs/api, with request and response examples.

### Tests

Tenant isolation (workspace A cannot read B through web or API), key auth and abilities, revoked keys, rate limits, re-ingest by external_id, content_hash skip, SSE stream shape, webhook signing and retries.

---

## Phase 7: n8n setup and Google Drive auto-ingest

### Infrastructure

- Add the n8nio/n8n service to docker-compose.yml and docker-compose.prod.yml, with a persistent volume, its own Postgres database (same server, separate DB), timezone Asia/Karachi, and WEBHOOK_URL set to the public URL.
- Caddy: `n8n.<domain>` reverse proxy with HTTPS. Document how to use a cloudflared tunnel for local testing of webhooks.
- `/n8n` folder in the repo for exported workflow JSON, with a README explaining how to import them and which credentials each needs. No credentials in exported files.

### Workflow: n8n/drive-ingest.json

1. Google Drive Trigger on a chosen folder: file created and file updated.
2. If the file is a native Google Doc, Sheet or Slides, download with conversion to PDF. Otherwise download as-is. Skip unsupported types.
3. HTTP Request to POST /api/v1/documents with source google_drive, external_id = Drive file id, title = file name.
4. Webhook node receiving document.ready / document.failed, verifying the HMAC signature in a Code node, then sending an email or Slack message.

### Workflow: n8n/drive-sync-deletes.json

- Nightly schedule: list file ids in the Drive folder, call GET /api/v1/documents?source=google_drive, delete any document whose external_id is no longer in Drive.

### Workflow: n8n/nightly-eval.json

- Add POST /api/v1/evals/run (owner-only ability evals:run) that runs the eval set in a queued job and posts results to a webhook.
- Schedule trigger, start eval, wait for webhook, email a summary (hit rate, keyword match, refusal accuracy, latency).

---

## Phase 8: Telegram bot

### Laravel

- `channel_links` table: workspace_id, channel, external_id (chat id), linked_by_user_id, created_at. Unique on (channel, external_id).
- Dashboard: "Connect Telegram" generates a one-time code (expires in 15 minutes) and a deep link `https://t.me/<bot_username>?start=<code>`.
- `POST /api/v1/channels/{channel}/link`: code + external_id, returns workspace name. Protected by an n8n service key with ability channels:link.
- `POST /api/v1/channels/{channel}/resolve`: external_id, returns the workspace API context, so one bot can serve many customer workspaces. Unlinked chats get a "please connect your workspace" message.

### Workflow: n8n/telegram-bot.json

1. Telegram Trigger on messages.
2. Switch:
   - `/start <code>`: call link endpoint, reply with success or error.
   - `/start` without code or `/help`: welcome message with instructions.
   - `/new`: start a fresh conversation (new external_conversation_id suffix).
   - Document message (PDF, TXT, MD): download via Telegram, upload with source telegram, meta containing chat_id, reply "Processing...". The document.ready webhook path replies "Ready, ask me anything about <title>". (The original text here was garbled in the Google Doc; the document title is the likely intent.)
   - Text: send typing chat action, call /api/v1/ask with channel telegram, external_conversation_id `telegram:<chat_id>`, format telegram_html, send each part with HTML parse mode.
3. Error branch: friendly message on API errors or rate limits.

- Group support: bot replies only when mentioned or replied to. Document the BotFather privacy mode setting.

### Tests

Link code expiry and single use, resolve for linked and unlinked chats, conversation reuse by external id, telegram_html escaping and splitting under 4000 characters.

---

## Phase 9: Embeddable website widget

- `GET /widget.js`: a small vanilla JS file (no framework, under 30 KB) that renders a floating chat bubble and panel, using Shadow DOM so the host site's CSS cannot break it.
- Embed snippet shown in the dashboard: `<script src="https://<domain>/widget.js" data-key="dc_pub_..." data-title="Ask us" data-color="#4f46e5" async></script>`
- Public widget keys (`dc_pub_`) with only the ask ability, restricted to allowed_origins, with their own lower rate limit. Validate the Origin header server-side and set CORS per key.
- Streams answers via the SSE endpoint, shows citations as small source chips, remembers the conversation in localStorage (wrapped in try/catch), has a "new chat" button, works on mobile.
- Widget settings page: title, welcome message, brand color, allowed domains, live preview.
- Optional: handoff field "If the answer isn't here, email us at ..." shown on refusals.
- Tests for origin enforcement, public key abilities, CORS headers.

---

## Phase 10: WhatsApp and Discord

### WhatsApp: n8n/whatsapp-bot.json

- Use Meta's WhatsApp Business Cloud API through the n8n WhatsApp Business Cloud nodes (trigger and send). Check current n8n node docs and Meta docs before building.
- Linking: same channel_links flow, where the user sends `link <code>` as their first message.
- Text messages: ask with channel whatsapp, format whatsapp (plain text with *bold*, sources as a short list, split into parts under 4000 characters).
- Document messages: download media, upload with source whatsapp, notify on ready.
- Only reply within the 24-hour customer service window. Any outbound message outside it must use an approved template; document this in the README and do not send outbound messages by default.
- README section: Meta Business setup, test number, webhook verification, and a note to check Meta's current pricing.

### Discord

- Slash command `/ask question:<text>` and `/link code:<text>` using Discord Interactions (HTTP endpoint in Laravel, not a gateway bot): `POST /discord/interactions`.
- Verify the Ed25519 signature on every request (X-Signature-Ed25519, X-Signature-Timestamp) using sodium. Answer PING with PONG.
- Respond with a deferred response, then process in a queued job and edit the original message through the follow-up webhook with the answer and sources (Discord limit 2000 characters per message, split as needed).
- Map guild or channel id to a workspace through channel_links.
- Artisan command `docchat:discord-register-commands` to register slash commands.
- Tests: signature verification (valid, invalid, replay), PING, deferred flow with faked HTTP, splitting under 2000 characters.

---

## Final: README and portfolio polish

- README sections: Features, Channels (web, API, widget, Telegram, WhatsApp, Discord, Google Drive), Architecture diagram, API quick start (curl examples), Automations (each n8n workflow with a screenshot placeholder), Security (tenancy, key hashing, HMAC, signature checks, rate limits), Evals table, Roadmap (hybrid search, reranking, billing with Stripe on usage_events).
- `docs/demo-script.md`: a short script for recording 30 to 60 second demo videos of the web chat, Telegram bot, widget on a sample site, and Drive auto-ingest.
- A sample static page `public/demo-site.html` that embeds the widget, for demos.
