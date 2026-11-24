# Demo script

Four short screen recordings, 30 to 60 seconds each, for the README, GitHub and an Upwork portfolio. Each one shows one thing working end to end. Record at 1280x800, hide bookmarks and notifications, and use the seeded demo workspace with the Harbor Point library handbook.

Before recording:

- `docker compose up -d`, then log in as `demo@docchat.test` / `password` and wait until the handbook shows **Ready**.
- Real keys in `.env` (`GEMINI_API_KEY`, `GROQ_API_KEY`), so answers are real.
- Ask each question once before recording. The second time is served from the answer cache, which is faster; say so in the voice-over or ask a fresh question if you want to show streaming.

## 1. Web chat (45 s)

1. Start on the Documents page with the handbook listed as Ready (3 s).
2. Upload a second small PDF and let the status move from Processing to Ready (10 s, cut the wait).
3. Open Chat and ask "How long can I keep a DVD?". Let the answer stream (10 s).
4. Click the citation and show the page and passage it came from (8 s).
5. Ask "Who won the 1998 World Cup?" and show the refusal: the answer is not in your documents (8 s).
6. End on the Usage page with the chat and cache counts (5 s).

Voice-over line: "Answers come only from your files, with the page behind each fact, and it says so when the answer isn't there."

## 2. Telegram (40 s)

Setup: the Telegram workflow active in n8n, the bot created with BotFather (see `n8n/README.md`).

1. In DocChat open **Workspace > Chat apps**, create a Telegram link code and click the link (5 s).
2. Telegram opens; press Start. The bot replies "Linked to Demo workspace" (5 s).
3. Ask "What are the library's opening hours on Saturday?" and show the typing indicator, then the answer with sources (12 s).
4. Send a PDF to the bot. It replies "Got it", then "is ready (N pages)" (10 s, cut the wait).
5. Ask a question about the new file (8 s).

## 3. Website widget (35 s)

Setup: open **Workspace > Website widget**, save once, and copy the public key into `public/demo-site.html`. The page is served by DocChat itself, so its origin (`APP_URL`) is already allowed.

1. Open http://localhost:8000/demo-site.html, a plain library website (3 s).
2. Click the chat bubble; show the welcome message (4 s).
3. Ask "Is there a fee for a lost library card?" and let the answer stream with sources (12 s).
4. Reload the page and show that the conversation is still there (5 s).
5. Back in DocChat, change the widget colour and title, save, and reload the demo site (10 s).

Voice-over line: "Guests on a client's site chat without an account; the key only works on the origins the client allows."

## 4. Google Drive ingest (40 s)

Setup: the Drive ingest workflow active in n8n with Google credentials, a watched folder (see `n8n/README.md`).

1. Show the n8n workflow canvas for a moment (4 s).
2. Drop a PDF into the watched Drive folder in the browser (5 s).
3. Switch to n8n's Executions list and show the run succeed (8 s, cut the poll wait).
4. In DocChat the document appears with source **Google Drive** and turns Ready (8 s).
5. Ask a question about it in the chat (10 s).

## Optional extras

- **Discord:** `/link code:...` in a server, then `/ask question:...` and show the deferred "thinking" message being replaced by the answer.
- **API:** a terminal with `curl` to `POST /api/v1/ask` and the JSON answer with citations.
- **Nightly eval:** the email with the scores.

Save the recordings as `docs/demo-*.gif` or upload MP4s to the GitHub release, and replace the placeholders in the README.
