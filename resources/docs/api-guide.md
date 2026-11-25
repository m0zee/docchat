Use the API to add documents and ask questions from your own systems. The full reference, with every field and response, is at [/docs/api](/docs/api).

## Keys

Owners create keys in **Workspace > API keys**. A key is shown once, so copy it then. Choose what it may do:

| Ability | Allows |
|---|---|
| `ask` | Asking and streaming answers |
| `documents:read` | Listing documents |
| `documents:write` | Uploading and deleting documents |
| `evals:run` | Running eval sets |

Send the key on every request:

```bash
curl -H "Authorization: Bearer dc_live_..." https://your-docchat-domain/api/v1/workspace
```

Each key has a rate limit per minute. Going over returns `429` with a `Retry-After` header.

## Upload a document

```bash
curl -H "Authorization: Bearer $KEY" -F file=@handbook.pdf -F external_id=handbook \
  https://your-docchat-domain/api/v1/documents
```

Sending the same `external_id` again replaces the document.

## Ask

```bash
curl -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"question": "How long can I keep a DVD?"}' \
  https://your-docchat-domain/api/v1/ask
```

The reply holds `answer`, `citations`, `refused` (true when the documents had no answer) and `usage`. Send the same `external_conversation_id` to continue a conversation with follow-ups. Add `"format": "telegram_html"`, `"whatsapp"` or `"discord"` to get message parts ready to send.

## Stream

`/api/v1/ask/stream` takes the same input and answers with Server-Sent Events: `token` events with pieces of text, then one `done` with the same data as `/ask`, or one `error`.

## Answers written on the device

`POST /api/v1/ask/device` and `POST /api/v1/ask/device/complete` let a browser client write the answer with its own model while DocChat does the search. See [AI in the browser](/docs/device-ai).

## Webhooks

In **Workspace > Webhooks**, add a URL to get `document.ready` and `document.failed` events. Each request is signed: check `X-DocChat-Signature` (`sha256=` HMAC of the raw body with your webhook's secret) and reject anything whose `X-DocChat-Timestamp` is older than 5 minutes.

## Errors

Errors always look like `{"error": {"code": "...", "message": "..."}}`.
