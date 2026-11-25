# n8n workflows

Three workflows that run DocChat from [n8n](https://n8n.io), the open-source automation tool. n8n runs in the same Docker stack (`docker compose up -d` starts it at <http://localhost:5678>; on the server it is at `https://n8n.<your domain>`, see [deploy.md](../deploy.md#n8n-automations)).

| File | What it does | DocChat key abilities |
|---|---|---|
| [drive-ingest.json](drive-ingest.json) | New and changed files in a Google Drive folder are uploaded to DocChat. Google Docs, Sheets and Slides are exported as PDF. When DocChat finishes (`document.ready` / `document.failed` webhook), it checks the signature and emails you. | `documents:write` |
| [drive-sync-deletes.json](drive-sync-deletes.json) | Every night at 02:30, deletes DocChat documents whose Drive file is gone. | `documents:read`, `documents:write` |
| [nightly-eval.json](nightly-eval.json) | Every night at 02:00, runs the `sample` eval set, waits for DocChat to post the results back, and emails a summary. | `evals:run` |

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
