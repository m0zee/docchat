DocChat is open source under the MIT license. You can run every part of it on your own server for free.

## What you need

- Docker with Docker Compose.
- A free Gemini key from [Google AI Studio](https://aistudio.google.com/apikey) for search, and a free Groq key from [console.groq.com](https://console.groq.com/keys) for answers.

## Run it on your computer

```bash
git clone https://github.com/m0zee/docchat.git && cd docchat
cp .env.example .env                       # add GEMINI_API_KEY and GROQ_API_KEY
docker compose up -d --build
docker compose exec app sh -c "composer install && php artisan key:generate && php artisan migrate"
docker compose exec app php artisan db:seed --class=SampleDocumentSeeder
```

Open http://localhost:8000 and log in as `demo@docchat.test` with the password `password`.

## Put it online

`deploy.md` in the repository walks through a free Oracle Cloud Always Free server with `docker-compose.prod.yml`: the app, a queue worker, the scheduler, n8n, Postgres with pgvector, and Caddy for automatic HTTPS.

## Settings worth knowing

All settings live in `.env`, explained in `.env.example`. A few you may want:

| Setting | What it does |
|---|---|
| `DOCCHAT_CHAT_DRIVER` | `groq` (default), `openrouter` or `gemini` |
| `DOCCHAT_GEMINI_CHAT_MODEL` | Gemini model for answers on Gemini keys |
| `DOCCHAT_OWN_KEYS` | Let workspaces add their own AI keys (default on) |
| `DOCCHAT_DEVICE_AI` | Allow answers written in the browser (default on) |
| `DOCCHAT_RAG_SIMILARITY_THRESHOLD` | How close a passage must be to count (0 to 1) |
| `DOCCHAT_KOKORO_URL` | The self-hosted natural voice for calls, for example `http://kokoro:8880` |
| `DOCCHAT_CLONE_URL`, `DOCCHAT_CLONE_LANGUAGE`, `HF_TOKEN` | Voice clones, for example `http://voice-clone:8000` and `en` |
| `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_PHONE_NUMBER` | The phone line, on a free Twilio trial |
| `DOCCHAT_CALLING_HOURS` | When outbound phone calls may start (default `09:00-20:00`) |

## A natural voice for calls

Calls work without it, using each visitor's browser voice. For one natural voice everywhere, including the phone line, run the open Kokoro voice model next to DocChat. It is free and needs about 2 GB of memory:

```bash
docker compose --profile voice up -d kokoro
```

Then set `DOCCHAT_KOKORO_URL=http://kokoro:8880` in `.env` and pick **Self-hosted natural voice** under **Workspace > Calls**. The full setup, including the phone line, is in `docs/calls.md` in the repository.

For voice clones, run the open Kyutai Pocket TTS model (free, on the CPU, about 2 GB of memory). Its voice cloning files need a free Hugging Face account: accept the model's terms, make a read token and put it in `.env` as `HF_TOKEN`. Then:

```
docker compose --profile clone up -d voice-clone
```

and set `DOCCHAT_CLONE_URL=http://voice-clone:8000` and `DOCCHAT_CLONE_LANGUAGE` (`en`, `es`, `fr` or `de`).

Keep `APP_KEY` safe and unchanged: it encrypts workspaces' own AI keys. If you must change it, put the old one in `APP_PREVIOUS_KEYS`.
