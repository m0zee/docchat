# Phase 10 handoff: WhatsApp, Discord and final polish

Branch `phase-10`, stacked on `phase-9`.

## What was built

- **WhatsApp formatter** (`format: whatsapp`): plain text with `*bold*`, sources as a short list, split under 4000 characters.
- **`n8n/whatsapp-bot.json`** (WhatsApp Business Cloud trigger and send nodes):
  - The first message `link CODE` links the number (code from Workspace > Chat apps).
  - Text messages are asked; `new` and `unlink` work like the Telegram commands.
  - Documents are uploaded with the sender in `meta`, and a signed `document.ready` webhook tells the sender when they are ready.
  - It only replies to incoming messages, so every reply is inside the 24-hour window. It sends no templates.
- **Discord** (no n8n, Laravel only):
  - `POST /discord/interactions` checks the Ed25519 signature with sodium and rejects timestamps older than 5 minutes. PING gets PONG.
  - `/ask` answers with a deferred reply. `DiscordAnswerJob` then edits the original message and sends follow-ups, split under 2000 characters, with mentions disabled.
  - `/link code:` links a server (or a DM) through `channel_links` and needs Manage Server in a server. `/new` and `/unlink` are also there.
  - `php artisan docchat:discord-register-commands [--guild=]` registers the commands.
  - Setup: `docs/discord.md`.
- **Workspace > Chat apps** shows WhatsApp (a wa.me link with the code) and Discord (an invite link and the `/link` command).
- **Final README:** features, a channels table, the architecture diagram, an API quick start with curl, automations with screenshot placeholders, security, the eval table (still pending a real run), and the roadmap.
- **Demo assets:** `docs/demo-script.md` covers four recordings: web chat, Telegram, widget and Drive ingest. `public/demo-site.html` is a fictional library site that embeds the widget.

## Checked here

- Pest, Pint and PHPStan pass. New: tests/Feature/DiscordTest.php (valid, invalid and replayed signatures, PING, deferred `/ask`, the job's edit and follow-ups, `/link`, permissions) and the WhatsApp formatter tests.
- The WhatsApp workflow ran in a real n8n 2.49.5 container against a live DocChat: linking, asking (the error reply, since there was no model key), uploading, and the signed ready message.

## WhatsApp costs (check before going live)

Meta's main pricing page still says replies to a customer inside the 24-hour window are free. Meta's page for AI providers says service messages are charged per message from 1 October 2026. Use Meta's free test number for the demo, and check the current pricing before using a real number. Since 15 January 2026 Meta's terms only allow business-specific bots like DocChat, not general-purpose AI assistants.

## Needs Moiz

- **WhatsApp:** a Meta developer app with the WhatsApp product, the test number, and the n8n credentials (see n8n/README.md).
- **Discord:** an application in the Discord developer portal. Put `DISCORD_APPLICATION_ID`, `DISCORD_PUBLIC_KEY` and `DISCORD_BOT_TOKEN` in `.env`, run the register command, and set the Interactions Endpoint URL to `https://<domain>/discord/interactions`. Discord needs a public HTTPS address, so this can be tested only after the deploy, or through a tunnel.
- **Demo videos:** follow docs/demo-script.md once real keys are in `.env`.
