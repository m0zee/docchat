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

Keep `APP_KEY` safe and unchanged: it encrypts workspaces' own AI keys. If you must change it, put the old one in `APP_PREVIOUS_KEYS`.
