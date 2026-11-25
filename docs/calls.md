# Calling agent

DocChat can talk. The same agent that answers in chat answers by voice, from the
workspace's documents only, with the same "I couldn't find that" rule.

| | Inbound | Outbound | Cost |
|---|---|---|---|
| **Browser** | Website visitors press **Call** in the widget | A call link you send to a contact (`/call/<token>`) | Free (browser voices, or the self-hosted Kokoro voice) |
| **Phone (Twilio)** | People ring the workspace's Twilio number | DocChat rings a contact's phone | Free on the Twilio trial, with its limits. Amazon and Google voices and voicemail detection use the trial credit |
| **Voice notes** | Voice messages to the Telegram and WhatsApp bots | | Free (WhatsApp: see the pricing note in `n8n/README.md`) |

Everything is set up under **Workspace > Calls** (owners only): the greeting, the
voice and personality, the phone number, contacts, outbound calls, a test call, and the
call log with transcripts.

## How a browser call works

1. The widget (or the call link page) asks for the microphone and gets a one-call token
   (`dc_call_...`) from `POST /api/v1/calls` (widget key) or `POST /api/v1/call/answer`
   (call link).
2. The agent's greeting is read aloud: with the most natural voice the visitor's browser
   has for the call's language and the chosen gender, or with the self-hosted Kokoro voice.
3. The microphone records until the caller pauses (the **Pace** setting: 0.7 to 1.4 seconds
   of quiet), and the recording goes to `POST /api/v1/call/turns`. DocChat turns it into
   text with Groq's free Whisper (which also detects the language), answers through
   `AnswerService` with a spoken style (short sentences, the personality, the caller's
   language), and sends back a reply without citation marks or Markdown, ready to read
   aloud. The transcript keeps the written answer with its sources.
4. While the answer is written, the caller hears a short filler ("Hmm, let me check.") and
   then soft hold music, if those are on. The mic keeps listening while the agent speaks,
   so the caller can interrupt it, like on a real call. Callers can mute, type instead,
   or hang up.

The browser client is one plain script, `/voice-call.js` (about 30 KB, no libraries). The
widget loads it only when someone presses **Call**. Hold music and the background sound are
made with Web Audio in the browser, so there are no audio files to load or license.

## Voice and personality

**Workspace > Calls > Voice and personality** sets how the agent sounds. It always says it
is a virtual assistant at the start ("Hi, this is Ava, Harbor Library's virtual
assistant"), because callers must know they are talking to an AI; after that it talks like
a person would. A custom greeting that doesn't say so gets "I'm a virtual assistant." added.

| Setting | Browser calls | Phone calls (Twilio trial) |
|---|---|---|
| Assistant name, personality (friendly, professional, calm, cheerful) | Yes | Yes |
| Language and accent: English (US, UK, Australia, India), Spanish (Spain, Mexico), French, German, Hindi, Urdu, Arabic | Yes, with the visitor's browser voices or Kokoro | Yes. The free basic voice speaks English (US, UK), Spanish, French and German; other languages need an Amazon or Google voice |
| Answer in the caller's language | Yes: Whisper detects it, and the replies, fixed lines and voice follow | No: Twilio's speech recognition needs the language up front |
| Gender, age | Picks a matching voice; age nudges pitch and speed | Picks a matching voice |
| Speed, pitch | Yes | Speed on Amazon and Google voices (SSML `prosody`) |
| Interruptions | Yes, the mic listens while the agent speaks | Yes (`<Gather bargeIn>`) |
| Pace, fillers, hold music | Yes | Yes: the answer is written in a job while the line says a filler and plays a short hold sound |
| Background sound (quiet office, soft cafe) | Yes | No (needs `<Stream>`, blocked on trials) |
| Voicemail detection (calls you make) | Not needed | Yes, hang up or leave a short message. Uses Twilio credit |

The fixed lines (greeting, "sorry, I didn't catch that", goodbye, opt-out) exist in all
seven languages, with a few variants so the agent doesn't repeat itself. Goodbye and "stop
calling" are understood in those languages too.

### Which voices cost money

| Voice | Where | Cost |
|---|---|---|
| Browser voices | Browser calls | Free. Edge and Chrome have very natural "Online (Natural)" and Google voices; quality depends on the visitor's device |
| Kokoro (self-hosted) | Browser calls, phone calls (`<Play>`), bot voice replies | Free: an open model (Apache-2.0) on your own server, no limits. About 2 GB of RAM; replies start after about a second on the Oracle free VM |
| Voice clone (Kyutai Pocket TTS, self-hosted) | Browser calls, phone calls (`<Play>`) | Free: open model (code MIT, weights CC-BY-4.0) on your own CPU. Needs a free Hugging Face account and about 2 GB of RAM |
| Twilio basic voice | Phone calls | Free |
| Amazon Polly and Google voices on Twilio | Phone calls | Use the trial credit; after the trial Twilio charges per 100 characters spoken. Generative voices (Google Chirp 3 HD, Amazon Generative) cost the most |
| Twilio voicemail detection | Outbound phone calls | Uses trial credit; a small charge per call after it. Off by default |

### The self-hosted Kokoro voice

1. Start it with the rest of the stack:

   ```
   docker compose --profile voice up -d kokoro
   ```

   The image is `ghcr.io/remsky/kokoro-fastapi-cpu:v0.10.0` (amd64 and arm64).
2. In `.env`: `DOCCHAT_KOKORO_URL=http://kokoro:8880`.
3. On **Workspace > Calls**, choose **Self-hosted natural voice**, pick a voice and press
   **Preview**.

Kokoro has voices for American and British English, Spanish, French and Hindi (20 of them are
listed, female and male). For German, Urdu and Arabic the browser voice is used instead.
Clips are cached on the server's disk (`storage/app/private/voice-cache`), so repeated lines
are instant; `docchat:prune-voice-cache` (daily) deletes clips older than a week. The
browser can only have the call's own lines spoken (`POST /api/v1/call/speech` checks them),
so a call token can't be used as a free text-to-speech service.

### Voice clones (with the speaker's consent)

A workspace can give its assistant a real person's voice, such as the owner's or a team
member's. It is built so a voice can only be copied with that person's clear, recorded
consent:

1. On **Workspace > Calls > Voice clones**, an owner enters the voice's name, the speaker's
   full name, an optional email and the language, and presses **Ask for consent**. DocChat
   makes a consent link (`/voice-consent/<token>`, shown once, valid for 7 days) and can
   email it to the speaker. Owners open it themselves to record their own voice.
2. The page shows a statement with the speaker's name, the workspace and two random code
   words, for example: "I, Sara Khan, agree that Harbor Library may create an AI copy of my
   voice for its virtual assistant. My code words are silver rocket." Then a short passage.
3. The speaker ticks "this is my own voice and I agree" and reads both aloud in one take
   (8 to 45 seconds), recorded in the browser as a 24 kHz WAV. There is no file upload, so
   nobody can clone a voice from someone else's recording.
4. Whisper transcribes it. It must contain both code words, the word for "agree" and the
   speaker's name (one wrong letter is allowed). Five failed tries end the link. On success
   the recording is kept on the private disk with when, from which IP and browser the
   speaker agreed, and the voice is **Ready**.
5. The same link lets the speaker **withdraw** at any time. That deletes the recording and
   every clip made from it, and calls go back to the normal voice. Deleting a clone on the
   Calls page does the same.

Calls in a cloned voice still open with "this is Ava, Harbor's virtual assistant". A
workspace can have 3 clones (`DOCCHAT_CLONE_PER_WORKSPACE`).

Set it up:

1. Create a free Hugging Face account, open the Kyutai Pocket TTS model page, accept its
   terms (they also forbid cloning without consent) and make a read token.
2. In `.env`: `HF_TOKEN=...`, `DOCCHAT_CLONE_URL=http://voice-clone:8000` and
   `DOCCHAT_CLONE_LANGUAGE` (`en`, `es`, `fr` or `de`; one language per server).
3. `docker compose --profile clone up -d voice-clone` builds `docker/voice-clone/Dockerfile`
   (`pip install pocket-tts==4.0.1` with CPU-only PyTorch) and runs `pocket-tts serve`.
4. On **Workspace > Calls**, press **Use on calls** next to a ready voice, or choose **Voice
   clone** as the website or phone voice.

DocChat calls `POST /tts` with the text and the consent recording as `voice_wav`, and caches
the WAV per clone (`voice-cache/clone-<id>`). A clone is used only when the call is in the
clone server's language; other calls use the normal voice. If the server is down, calls fall
back to the normal voice too.

## Rules the agent follows

- It always says it is a virtual assistant: the greeting does (it is added to a custom
  greeting that doesn't), outbound calls start with "this is Ava, a virtual assistant
  calling for ...", and so do voicemail messages.
- Outbound calls go only to contacts marked as having agreed to be called, with a note on how
  and when they agreed. Saying "stop calling", "don't call me" or "remove me" on a call, or
  pressing **Don't call me again** on a call link, opts the contact out for good.
- Phone calls only start inside `DOCCHAT_CALLING_HOURS` (default `09:00-20:00`) in
  `DOCCHAT_CALLING_TIMEZONE`.
- A call ends after two silences in a row, when the caller says goodbye, after
  `DOCCHAT_CALL_MAX_TURNS` answers (30), or after `DOCCHAT_CALL_MAX_MINUTES` (10).
- Each workspace can use speech recognition `DOCCHAT_STT_DAILY_LIMIT` times a day (300), so
  one busy workspace cannot use up the free Groq quota (2,000 a day) for everyone.

## Setup

### Browser calls and voice notes

Nothing extra: they use `GROQ_API_KEY`. Turn widget calls on or off under
**Workspace > Calls**. Browsers only allow the microphone on HTTPS pages (and on
`localhost`), so on a real site the widget must be on HTTPS.

### Phone line on a free Twilio trial

The trial needs no card. What it allows (checked on 25 November 2026):

- 75 call minutes over 30 days, calls up to 10 minutes.
- A shared trial number. Calls only to and from the up to 5 numbers you verify, and only
  inside the country you signed up from.
- Some TwiML is blocked (Record, Stream, Dial), which is why DocChat uses
  `<Gather input="speech">` and `<Say>` instead of streaming audio.

Steps:

1. Sign up at [twilio.com](https://www.twilio.com/try-twilio), verify your own phone, and note
   the **Account SID**, **Auth Token** and the trial number.
2. In `.env` on the server (never in git):

   ```
   TWILIO_ACCOUNT_SID=AC...
   TWILIO_AUTH_TOKEN=...
   TWILIO_PHONE_NUMBER=+1...
   ```

3. In DocChat, **Workspace > Calls > Phone number**: enter the same number and save. That
   workspace now answers calls to it.
4. In the Twilio console, open the number and set **A call comes in** to Webhook,
   `https://<your domain>/twilio/voice/inbound`, HTTP POST. Twilio needs a public HTTPS
   address: use the deployed server, or a tunnel with `TWILIO_WEBHOOK_BASE_URL` set to the
   tunnel's address.
5. Call the number from your verified phone. For an outbound test, add yourself as a contact
   with consent and choose **Phone call** under **Call someone**.

Every Twilio request is checked against `X-Twilio-Signature` with your auth token, using
`TWILIO_WEBHOOK_BASE_URL` or `APP_URL` as the address, so set the one Twilio actually calls.
Outbound calls are placed by the queue worker (`PlaceTwilioCallJob`).

The phone voice and the language Twilio listens for come from the workspace's voice settings
(the free basic voice by default). Answers are written by the queue worker
(`AnswerCallTurnJob`), so the worker must run for phone calls; while it works, Twilio plays
the filler and the hold sound (`/twilio/voice/hold.wav`, made by DocChat) and checks back
every few seconds, for up to `DOCCHAT_PHONE_ANSWER_WAIT` seconds (25).

Trial calls start with Twilio's own trial message, and the person you call has to press a
key before DocChat speaks. That also makes voicemail detection unreliable on a trial.

### Voice notes in Telegram and WhatsApp

The workflows in `n8n/` already handle voice messages: they download the voice note and send
it to `POST /api/v1/voice/ask`, then reply with what was heard and the answer.

Audio answers are off by default. To turn them on:

1. Set `DOCCHAT_VOICE_REPLIES=true`.
2. Pick the voice:
   - **Kokoro** (recommended, no daily limit): `DOCCHAT_TTS_DRIVER=kokoro`, with Kokoro set
     up as above and `DOCCHAT_KOKORO_VOICE` (default `af_heart`). It returns MP3 directly.
   - **Groq Orpheus** (default driver): `DOCCHAT_TTS_VOICE` (default `hannah`). Groq's free
     plan lists 100 a day, 200 characters a request, so DocChat splits answers and caps them
     at 600 characters. Install ffmpeg on the server (`apt install ffmpeg`), because Orpheus
     returns WAV and Telegram and WhatsApp need MP3. The Docker image does not include it.

## API

| Route | Auth | What it does |
|---|---|---|
| `POST /api/v1/calls` | widget `dc_pub_` key (from listed sites) or `dc_live_` key | Starts a browser call. Returns the call token, the greeting and the `voice` settings. |
| `POST /api/v1/call/answer` | `Bearer dc_call_...` | Answers a call link or test call. Returns the opening line and `voice`. |
| `POST /api/v1/call/turns` | `Bearer dc_call_...` | Multipart `audio` or `text`. Returns `heard`, `reply`, `citations`, `ended`, and `lang`, `fillers` and `engine` (they follow the caller's language). |
| `POST /api/v1/call/speech` | `Bearer dc_call_...` | `text` (one of the call's own lines) in, Kokoro MP3 (or voice clone WAV) out. Also works for two minutes after the call ends, for the goodbye. |
| `POST /api/v1/call/end` | `Bearer dc_call_...` | Hangs up. |
| `POST /api/v1/voice/ask` | `dc_live_` or service key | Voice note in, answer out (same fields as `/ask`, plus `transcript` and `spoken`). |
| `POST /api/v1/voice/speak` | `dc_live_` or service key | Text in, MP3 out, when voice replies are on. |
| `POST /twilio/voice/*` | Twilio signature | Twilio's webhooks: `inbound`, `calls/{id}/answer` (with `AnsweredBy` for voicemail), `calls/{id}/gather`, `calls/{id}/reply/{turn}` (waiting for an answer), `calls/{id}/status`. |
| `GET /twilio/voice/hold.wav`, `GET /twilio/voice/audio/{clip}?sig=...` | none, or DocChat's own signature | The hold sound, and Kokoro and voice clone clips for `<Play>`. |
| `GET /voice-consent/{token}`, `POST .../recording`, `POST .../withdraw` | the consent link's token | The speaker's consent page: record the statement (WAV), or withdraw. |
