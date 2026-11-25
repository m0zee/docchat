# Phase 10 tasks: WhatsApp, Discord and final polish

Branch `phase-10`, PR stacked on `phase-9`.

- [x] **P10-1 whatsapp formatter.** Plain text with *bold*, sources as a short list, split under 4000 characters. Tests.
- [x] **P10-2 whatsapp-bot.json.** WhatsApp Business Cloud trigger and send nodes (checked against current n8n and Meta docs), `link <code>` first message, text ask, document upload with notify on ready, replies only inside the 24-hour window, no outbound templates by default.
- [x] **P10-3 WhatsApp docs.** README section: Meta Business setup, test number, webhook verification, 24-hour window and templates, check Meta's current pricing.
- [x] **P10-4 Discord interactions endpoint.** `POST /discord/interactions`, Ed25519 verification with sodium, replay window on the timestamp, PING to PONG. Tests: valid, invalid, replay, PING.
- [x] **P10-5 Discord commands.** `/ask` deferred response, queued job that edits the original message through the follow-up webhook, split under 2000 characters; `/link` through channel_links (guild or channel id). `docchat:discord-register-commands`. Tests with faked HTTP.
- [x] **P10-6 Final README.** Features, Channels, Architecture diagram, API quick start with curl, Automations with screenshot placeholders, Security, Evals table, Roadmap (hybrid search, reranking, Stripe billing on usage_events).
- [x] **P10-7 Demo assets.** `docs/demo-script.md` (30 to 60 second videos: web chat, Telegram, widget, Drive ingest) and `public/demo-site.html` embedding the widget.
