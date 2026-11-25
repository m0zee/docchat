# Phase 8 handoff: Telegram bot

Branch `phase-8`, stacked on `phase-7`.

## What was built

- **Channel links** (`channel_links`, `channel_link_codes`): a chat (channel + chat id) belongs to one workspace. Owners create one-time codes (24 letters and digits, stored as SHA-256, 15 minutes, single use). Linking a chat again moves it to the new workspace.
- **Chat apps page** (Workspace > Chat apps, owners only): creates a code and the `https://t.me/<bot>?start=<code>` link, lists linked chats, unlinks. Shows what is missing when `TELEGRAM_BOT_USERNAME` is not set.
- **Service keys** (`dc_svc_`, `php artisan docchat:service-key`): no workspace. With `channels:link` they call `POST /api/v1/channels/{telegram|whatsapp|discord}/link|resolve|unlink`. On every other route they send `X-DocChat-Link: <channel>:<chat id>` and act in that chat's workspace (`ResolveApiWorkspace` middleware: 400 `link_required`, 404 `chat_not_linked`).
- **`POST /api/v1/conversations/end`** for `/new`: the next question with the same id starts a fresh conversation; the old one stays in history.
- **Channel webhook:** documents with source telegram or whatsapp also go, signed, to `DOCCHAT_CHANNEL_WEBHOOK_URL` (`{channel}` is replaced), so the bot's workflow can answer in the chat. The secret is read from config at send time and never stored in the queue.
- **telegram_html formatter** already existed from Phase 6, with tests for escaping, bold, sources and tag-safe splitting.
- **n8n/telegram-bot.json** and its README section (BotFather, privacy mode, setup, how it calls DocChat).

## Checked here

- 304 Pest tests (new: ChannelLinkTest), Pint and PHPStan.
- End to end in a real n8n 2.49.5 container against a running DocChat (`php artisan serve` as `app:8080`), with the Telegram trigger and send nodes swapped for test stubs:
  - `/start` unlinked, `/help`, a question before linking, `/start <code>`, `/start` linked, `/new`, a reused code, `/unlink`: each gave the expected reply. HTML escaping worked (workspace "Harbor & Co").
  - A Markdown file was uploaded with source telegram and the chat id in meta. An .exe got "Only PDF, TXT and Markdown files are allowed."
  - In a group: plain text and `/help@OtherBot` were ignored, and `/help@DocChatTestBot` was answered.
  - A signed `document.ready` from DocChat reached the second trigger and produced "faq.md is ready (1 page)".
  - A question reached DocChat; with no Gemini key in that test setup it returned 502 and the bot sent its error reply. A real answer needs the real keys.

## Not checked

- Real Telegram delivery: the trigger and send nodes need a bot token and an HTTPS address.

## Needs Moiz

1. Create the bot with @BotFather (free). Put the token only in an n8n Telegram credential.
2. Set `TELEGRAM_BOT_USERNAME` and `DOCCHAT_CHANNEL_WEBHOOK_SECRET` in `.env`, and create the service key on the server.
