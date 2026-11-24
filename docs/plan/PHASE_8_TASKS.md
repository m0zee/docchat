# Phase 8 tasks: Telegram bot

Branch `phase-8`, PR stacked on `phase-7`.

- [x] **P8-1 Channel links.** `channel_links` (workspace_id, channel, external_id, linked_by_user_id; unique channel + external_id) and `channel_link_codes` (hashed one-time code, expires in 15 minutes, used_at). Tests for expiry and single use.
- [x] **P8-2 Connect Telegram.** Dashboard/settings card that generates the code and the `https://t.me/<bot>?start=<code>` link, lists linked chats, and unlinks. `TELEGRAM_BOT_USERNAME` in config.
- [x] **P8-3 Link and resolve API.** `POST /api/v1/channels/{channel}/link` and `/resolve`, service keys with ability `channels:link` (a key not tied to one workspace). Resolve returns the workspace context so one bot serves many workspaces; ask and documents calls from a service key name the workspace through the resolved link. Tests for linked and unlinked chats.
- [x] **P8-4 telegram_html formatter.** Escaping, citations, splitting under 4000 characters without breaking tags. Tests.
- [x] **P8-5 telegram-bot.json.** Trigger, Switch for /start with and without code, /help, /new, documents, text (typing action, ask, send parts with HTML), error branch, group mention rule. The document.ready webhook replies in the right chat from meta.
- [x] **P8-6 Docs and handoff.** BotFather steps, privacy mode for groups, conversation reuse test, README channel section.
