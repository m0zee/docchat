# Calling agent

DocChat can talk. The same agent that answers in chat answers by voice, from the
workspace's documents only, with the same "I couldn't find that" rule.

| | Inbound | Outbound | Cost |
|---|---|---|---|
| **Browser** | Website visitors press **Call** in the widget | A call link you send to a contact (`/call/<token>`) | Free |
| **Phone (Twilio)** | People ring the workspace's Twilio number | DocChat rings a contact's phone | Free on the Twilio trial, with its limits |
| **Voice notes** | Voice messages to the Telegram and WhatsApp bots | | Free (WhatsApp: see the pricing note in `n8n/README.md`) |

Everything is set up under **Workspace > Calls** (owners only): the greeting, the
phone number, contacts, outbound calls, a test call, and the call log with transcripts.

## How a browser call works

1. The widget (or the call link page) asks for the microphone and gets a one-call token
   (`dc_call_...`) from `POST /api/v1/calls` (widget key) or `POST /api/v1/call/answer`
   (call link).
2. The agent's greeting is read aloud with the browser's own `speechSynthesis`.
3. The microphone records until the caller pauses (about 1.2 seconds of quiet), and the
   recording goes to `POST /api/v1/call/turns`. DocChat turns it into text with Groq's free
   Whisper, answers through `AnswerService`, and sends back a reply without citation marks
   or Markdown, ready to read aloud. The transcript keeps the written answer with its sources.
4. The mic is off while the agent speaks. Callers can mute, type instead, or hang up.

The browser client is one plain script, `/voice-call.js` (about 17 KB, no libraries). The
widget loads it only when someone presses **Call**.

## Rules the agent follows

- It always says it is an automated assistant: the default greeting does, and outbound
  calls start with "this is an automated assistant calling for ...".
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

Twilio's default voice is used. `TWILIO_VOICE` can pick another (for example a Polly voice),
but those may cost extra after the trial. `TWILIO_SPEECH_LANGUAGE` sets the language Twilio
listens for (default `en-US`).

### Voice notes in Telegram and WhatsApp

The workflows in `n8n/` already handle voice messages: they download the voice note and send
it to `POST /api/v1/voice/ask`, then reply with what was heard and the answer.

Audio answers are off by default. To turn them on:

1. Set `DOCCHAT_VOICE_REPLIES=true`. They use Groq's Orpheus voices (`DOCCHAT_TTS_VOICE`,
   default `hannah`). Groq's free plan lists 100 a day, 200 characters a request, so DocChat
   splits answers and caps them at 600 characters.
2. Install ffmpeg on the server (`apt install ffmpeg`), because Orpheus returns WAV and
   Telegram and WhatsApp need MP3. The Docker image does not include it yet.

## API

| Route | Auth | What it does |
|---|---|---|
| `POST /api/v1/calls` | widget `dc_pub_` key (from listed sites) or `dc_live_` key | Starts a browser call. Returns the call token and the greeting. |
| `POST /api/v1/call/answer` | `Bearer dc_call_...` | Answers a call link or test call. Returns the opening line. |
| `POST /api/v1/call/turns` | `Bearer dc_call_...` | Multipart `audio` or `text`. Returns `heard`, `reply`, `citations`, `ended`. |
| `POST /api/v1/call/end` | `Bearer dc_call_...` | Hangs up. |
| `POST /api/v1/voice/ask` | `dc_live_` or service key | Voice note in, answer out (same fields as `/ask`, plus `transcript` and `spoken`). |
| `POST /api/v1/voice/speak` | `dc_live_` or service key | Text in, MP3 out, when voice replies are on. |
| `POST /twilio/voice/*` | Twilio signature | Twilio's webhooks: `inbound`, `calls/{id}/answer`, `calls/{id}/gather`, `calls/{id}/status`. |
