# n8n workflows

Three workflows that run DocChat from [n8n](https://n8n.io), the open-source automation tool. n8n runs in the same Docker stack (`docker compose up -d` starts it at <http://localhost:5678>; on the server it is at `https://n8n.<your domain>`, see [deploy.md](../deploy.md#n8n-automations)).

| File | What it does | DocChat key abilities |
|---|---|---|
| [drive-ingest.json](drive-ingest.json) | New and changed files in a Google Drive folder are uploaded to DocChat. Google Docs, Sheets and Slides are exported as PDF. When DocChat finishes (`document.ready` / `document.failed` webhook), it checks the signature and emails you. | `documents:write` |
| [drive-sync-deletes.json](drive-sync-deletes.json) | Every night at 02:30, deletes DocChat documents whose Drive file is gone. | `documents:read`, `documents:write` |
| [nightly-eval.json](nightly-eval.json) | Every night at 02:00, runs the `sample` eval set, waits for DocChat to post the results back, and emails a summary. | `evals:run` |
| [telegram-bot.json](telegram-bot.json) | One Telegram bot for every workspace. See [Telegram bot](#telegram-bot). | a **service key** (`dc_svc_...`) |
| [whatsapp-bot.json](whatsapp-bot.json) | One WhatsApp Business number for every workspace. See [WhatsApp bot](#whatsapp-bot). | the same service key |

Discord does not use n8n: DocChat answers Discord slash commands itself. See [docs/discord.md](../docs/discord.md).

The exports hold no credentials. You create them once in n8n and pick them in each node after importing.

## 1. Settings in `.env`

n8n reads these from the environment (they are passed through in both compose files):

| Variable | Default | What it is |
|---|---|---|
| `DOCCHAT_API_URL` | `http://app:8080` | DocChat as n8n sees it. Inside Docker this is the `app` service. If DocChat runs in Laragon on Windows, use `http://host.docker.internal:8000` (or your Laragon port). |
| `DOCCHAT_CALLBACK_BASE_URL` | `http://n8n:5678` | n8n as DocChat sees it, for the eval callback. With DocChat in Laragon, use `http://localhost:5678`. |
| `DOCCHAT_WEBHOOK_SECRET` | empty | The secret of the DocChat webhook that points at n8n (step 3). |
| `DOCCHAT_NOTIFY_EMAIL` | empty | Where the workflows send email. |

After changing `.env`, run `docker compose up -d n8n` to restart n8n with the new values.

The compose files also set `NODE_FUNCTION_ALLOW_BUILTIN=crypto` (the Code nodes use node's `crypto` to check signatures) and `N8N_BLOCK_ENV_ACCESS_IN_NODE=false` (so workflows can read the variables above). That second setting lets any workflow read n8n's environment, so only give the n8n owner account to people you trust with the server.

## 2. Credentials (create once in n8n, under Credentials)

| Credential | Type in n8n | Used by |
|---|---|---|
| **DocChat API key** | Header Auth. Name: `Authorization`. Value: `Bearer dc_live_...` | every HTTP Request node |
| **Google Drive** | Google Drive OAuth2 API | the two Drive trigger nodes and the Drive nodes |
| **Gmail** | Gmail OAuth2 | the email nodes |

Create the DocChat key in DocChat under **Workspace > API keys**, with the abilities from the table at the top. One key with `documents:read`, `documents:write` and `evals:run` covers all three workflows. The key is shown once; paste it straight into n8n.

Google Drive and Gmail use one free Google Cloud project with an OAuth client: follow n8n's guide [Google OAuth2 single service](https://docs.n8n.io/integrations/builtin/credentials/google/oauth-single-service/). The redirect URL n8n shows must be added to the OAuth client. Leave the app in "Testing" mode and add your own Google account as a test user; that is free and fine for a test server.

## 3. Import

1. In n8n: **Workflows > Add workflow > ... > Import from File**, and pick a JSON file. Or from the command line:
   ```bash
   docker compose cp n8n/drive-ingest.json n8n:/tmp/ && docker compose exec n8n n8n import:workflow --input=/tmp/drive-ingest.json
   ```
2. Open each node with a red warning and pick the credential from step 2.
3. In the two **Drive trigger** nodes and in **Files in the folder** (sync workflow), pick the same Drive folder.
4. For the ingest workflow, add a webhook in DocChat under **Workspace > Webhooks**: URL `http://n8n:5678/webhook/docchat-events` (with DocChat in Laragon: `http://localhost:5678/webhook/docchat-events`), events `document.ready` and `document.failed`. Copy its secret into `DOCCHAT_WEBHOOK_SECRET` and restart n8n.
5. Publish (activate) each workflow.

To try a workflow before publishing it, open it and click **Execute workflow**.

## Telegram bot

One bot serves every workspace. A workspace owner links a chat with a one-time code, and from then on the bot answers that chat from that workspace's documents.

### Create the bot (free)

1. In Telegram, open [@BotFather](https://t.me/BotFather) and send `/newbot`. Pick a name and a username ending in `bot`. BotFather replies with a token like `123456789:AA...`. Keep it secret: it goes only into n8n.
2. Optional: send `/setcommands` to BotFather, pick the bot, and paste:
   ```
   start - Link this chat with a code from DocChat
   new - Start a new conversation
   unlink - Unlink this chat
   help - How to use the bot
   ```
3. Groups: leave **privacy mode** on (the default, `/setprivacy` > Enable). The bot then only receives commands, messages that @mention it, and replies to it, which is all it answers anyway. Turning privacy mode off would send every group message to your server.

### Set it up

1. On the server, create a service key once. It belongs to no workspace and is shown once:
   ```bash
   docker compose -f docker-compose.prod.yml exec app php artisan docchat:service-key "telegram bot"
   ```
   (`--list` shows keys, `--revoke=<id>` revokes one.) Put it in a new n8n Header Auth credential, **DocChat service key**: name `Authorization`, value `Bearer dc_svc_...`.
2. In n8n, create a **Telegram API** credential with the BotFather token.
3. In `.env`, set `TELEGRAM_BOT_USERNAME` (without the @), keep `DOCCHAT_CHANNEL_WEBHOOK_URL=http://n8n:5678/webhook/{channel}-docchat`, and set `DOCCHAT_CHANNEL_WEBHOOK_SECRET` to a random value (`openssl rand -hex 24`). DocChat and n8n both read it. Restart: `docker compose -f docker-compose.prod.yml up -d`.
4. Import `telegram-bot.json`, pick the Telegram credential in the trigger and the Telegram nodes (Typing, Reply, Get voice note, Send voice reply), the service key in every HTTP Request node, and publish it. Telegram only delivers to HTTPS addresses, so the trigger works on the server (`https://n8n.<domain>`) or locally through the [tunnel](#testing-webhooks-from-the-internet-free-tunnel).

### Use it

- In DocChat, an owner opens **Workspace > Chat apps** and clicks **Create a link code**. The **Open Telegram** link opens the bot with `/start <code>`; pressing Start links the chat. For a group, add the bot to the group and send `/start <code>` there. Codes work once, for 15 minutes.
- Ask questions in the chat. Answers come back as Telegram HTML with a Sources list, split under Telegram's 4096-character limit. Each chat keeps its own conversation, so follow-up questions work; `/new` starts over.
- Send a PDF, TXT or Markdown file in a private chat and it is added to the workspace; the bot says when it is ready. Files sent in groups are ignored, so group members can ask but not add documents.
- `/unlink` (or **Unlink** on the Chat apps page) disconnects the chat.
- Send a **voice message** and the bot answers it: first what it heard, then the answer with sources. In groups it only takes voice messages that reply to the bot. With `DOCCHAT_VOICE_REPLIES=true` on the server it also sends the answer as audio (see [docs/calls.md](../docs/calls.md#voice-notes-in-telegram-and-whatsapp)).

### How it talks to DocChat

| Bot action | DocChat call |
|---|---|
| `/start <code>` | `POST /api/v1/channels/telegram/link` with the code and chat id |
| `/start` | `POST /api/v1/channels/telegram/resolve` |
| question | `POST /api/v1/ask` with `X-DocChat-Link: telegram:<chat id>`, `channel=telegram`, `format=telegram_html` |
| `/new` | `POST /api/v1/conversations/end` |
| file | `POST /api/v1/documents` with `source=telegram` and the chat id in `meta` |
| voice message | **Get voice note** downloads it, then `POST /api/v1/voice/ask` (multipart `audio`); when `voice_replies` is true, `POST /api/v1/voice/speak` returns an MP3 for **Send voice reply** |
| file ready | DocChat POSTs `document.ready` / `document.failed` to `.../webhook/telegram-docchat`, signed with `DOCCHAT_CHANNEL_WEBHOOK_SECRET` |

The service key can act in any workspace whose chat is linked, so treat it like a server password. It cannot run evals or reach a chat that nobody linked.

## WhatsApp bot

Works like the Telegram bot, on Meta's **WhatsApp Business Cloud API**: people send `link <code>` once, then ask in plain messages. `new`, `unlink` and `help` work too, and files they send (PDF, TXT, Markdown) are added and reported when ready.

### Costs: check before you switch it on

WhatsApp is the one channel here that may not be free. Meta's pricing changed during 2026. Check [Meta's pricing page](https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing) before using a real number:

- Messages people send to you are free.
- Replies are only allowed inside the **24-hour customer service window** that opens when someone writes to you. This workflow only ever replies, and it never sends template messages, so it never starts a conversation.
- Until 30 September 2026, those replies were free. Meta's AI providers page says Meta "resumed charging" for service messages from **1 October 2026**, per message, at the utility rate of the recipient's country (for example about US$0.0014 in India and US$0.0034 in the US/Canada, as reported by [Courier](https://www.courier.com/blog/whatsapp-pricing-changes-october-2026)). Meta's main pricing page still says non-template messages are free, so confirm in your WhatsApp Manager billing before going live.
- Since 15 January 2026, Meta's terms allow general-purpose AI assistants only where the law requires it ([AI providers](https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing/ai-providers)). DocChat answers only from a business's own documents, so it is a business bot, not a general-purpose assistant. Keep it that way.

For a free test, use the **test number** Meta gives every new app and message it only from the up to 5 phone numbers you add as test recipients.

### Set it up

1. Create a Meta developer account and an app of type **Business** at [developers.facebook.com](https://developers.facebook.com/apps), and add the **WhatsApp** product. Meta gives you a test phone number, its **Phone number ID**, and a temporary access token. For a token that does not expire, create a System User in Business Settings and give it the `whatsapp_business_messaging` permission.
2. In n8n, create two credentials:
   - **WhatsApp API** with the access token and the WhatsApp Business Account ID, for the send and media nodes.
   - **WhatsApp OAuth API** (the trigger's credential) with the app's App ID and App Secret.
3. Import `whatsapp-bot.json`. Pick the WhatsApp credentials in the trigger, **Get file URL**, **Download file**, **Get voice note URL**, **Download voice note** (predefined credential type WhatsApp API), **Reply** and **Send voice reply**, and the DocChat service key in the HTTP Request nodes. Publish it.
4. Meta must reach the trigger over HTTPS, so do this on the server, or locally with the [tunnel](#testing-webhooks-from-the-internet-free-tunnel). When the workflow is published, n8n registers the webhook with Meta and answers Meta's verification request (`hub.challenge`) itself. If you set it up by hand under WhatsApp > Configuration instead, use the trigger's production URL and subscribe to the `messages` field.
5. In `.env`, set `WHATSAPP_NUMBER` (digits with country code, for the link on the Chat apps page) and the same `DOCCHAT_CHANNEL_WEBHOOK_URL` / `DOCCHAT_CHANNEL_WEBHOOK_SECRET` as for Telegram. `{channel}` becomes `whatsapp`, so "file ready" messages reach `.../webhook/whatsapp-docchat`.

On the Chat apps page, an owner creates a code. The **Open WhatsApp** link opens a chat with the number and `link <code>` already typed.

Voice messages work like in Telegram: the bot replies with what it heard and the answer, plus an audio answer when `DOCCHAT_VOICE_REPLIES=true`. Each audio reply is one more message, so it counts like any other reply in the pricing above.

## How the signature check works

DocChat signs every webhook and eval callback:

- `X-DocChat-Signature: sha256=<hex HMAC-SHA256 of the raw request body, keyed with the secret>`
- `X-DocChat-Timestamp: <unix seconds>`, also in the body as `timestamp`

The receiver computes the HMAC over the exact bytes it received (the Webhook and Wait nodes have **Raw Body** turned on for this), compares in constant time, and rejects a timestamp more than 5 minutes from its clock. The **Check signature** Code node does this:

```js
const crypto = require('crypto');
const raw = (await this.helpers.getBinaryDataBuffer(0, 'data')).toString('utf8');
const headers = $input.first().json.headers;
const expected = 'sha256=' + crypto.createHmac('sha256', $env.DOCCHAT_WEBHOOK_SECRET).update(raw).digest('hex');
const signature = String(headers['x-docchat-signature'] || '');
const timestamp = Number(headers['x-docchat-timestamp']);
const valid = signature.length === expected.length
  && crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected))
  && Math.abs(Date.now() / 1000 - timestamp) <= 300
  && JSON.parse(raw).timestamp === timestamp;
```

A request that fails gets HTTP 401. DocChat shows the last status on the Webhooks page and retries 5 times.

The eval callback uses a secret made for that one run: `POST /api/v1/evals/run` returns `callback_secret`, and the workflow reads it from the **Start eval** node after the Wait node resumes. Nothing has to be configured for it.

## Testing webhooks from the internet (free tunnel)

Locally, DocChat reaches n8n inside Docker, so no tunnel is needed. A tunnel only helps when a service on the internet has to call your laptop, for example when trying the Telegram or WhatsApp workflows of later phases before deploying. Cloudflare's quick tunnel is free and needs no account:

```bash
docker run --rm --network container:$(docker compose ps -q n8n) cloudflare/cloudflared:latest tunnel --no-autoupdate --url http://localhost:5678
```

It prints a `https://<random>.trycloudflare.com` address. Put it in `.env` as `N8N_WEBHOOK_URL=https://<random>.trycloudflare.com/` and run `docker compose up -d n8n`, so n8n shows that address in its webhook URLs. The address changes every time the tunnel starts. The `--network container:` part runs the tunnel inside n8n's network, so it works whatever your project folder is called (the command works in bash and PowerShell).

## Editing the workflows

After changing a workflow in n8n, export it (**... > Download**) over the file here. Exports keep credential ids out only if you remove them: the test `tests/Unit/N8nWorkflowsTest.php` fails when an export contains a credential reference, an API key, a webhook secret, a Google or Telegram token, or pinned test data. Remove the `credentials` blocks and `pinData` before committing.
