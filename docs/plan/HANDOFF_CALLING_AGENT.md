# Handoff: calling agent

Branch `claude/project-thread-7dv1ii`, based on `main`. Tasks: `CALLING_AGENT_TASKS.md`. Setup: `docs/calls.md`.

## What was built

- **Data:** `call_contacts` (consent note and time, opt-out) and `calls` (direction, browser or phone, status, purpose, opening line, hashed `dc_call_` token, Twilio sid, turns, duration, end reason). New conversation channels `voice` and `phone`; the transcript of a call is its conversation.
- **CallAgent:** greeting with an AI disclosure (inbound uses the workspace greeting; outbound names the contact, the purpose and how to opt out), answers through `AnswerService` turned into spoken text, goodbye and opt-out phrases, two silences end the call, turn and time limits.
- **Browser calls:** `/voice-call.js` (mic with silence detection, MediaRecorder upload, speechSynthesis, mute, type instead, hang up). Widget **Call** button, call link page `/call/{token}` with "Don't call me again", and a test call on **Workspace > Calls**.
- **Speech:** Groq Whisper (`whisper-large-v3-turbo`) with a per-workspace daily cap; Groq Orpheus + ffmpeg for MP3 voice replies, off by default.
- **Twilio line:** signed webhooks (`inbound`, `answer`, `gather`, `status`) with `<Gather input="speech">` and `<Say>`, `PlaceTwilioCallJob` over Twilio's REST API with Laravel's HTTP client (no SDK), consent, opt-out and calling-hours checks at dial time.
- **Workspace > Calls:** settings (widget calls, greeting, handoff line, Twilio number), contacts with consent, call someone (link by email or phone), call log with transcripts, cancel waiting calls.
- **Voice notes:** `POST /api/v1/voice/ask` and `/voice/speak`; Telegram and WhatsApp workflows route voice messages there and send an audio reply when it is on.

## Checked here

- 384 Pest tests, Pint and PHPStan. New: `CallsApiTest`, `TwilioCallTest`, `VoiceNotesTest`, `CallsPageTest`, `SpokenTextTest`.
- `tests/browser/voice-call.cjs` (4 checks in Chromium with a fake microphone): a widget call that records, uploads and reads the answer; typing and the agent hanging up; no Call button when calls are off; no microphone. Also a new CI step.
- Both n8n workflows import into n8n 2.49.5; the voice routes and replies of their Code nodes were run in Node; the Telegram and WhatsApp node parameters were checked against that n8n version's source.
- Screenshots of the Calls page and the call link page from a running app.

## Not checked

- Real speech recognition and answers (Groq is blocked from this cloud and there are no keys here), real Twilio calls (need an account and a public HTTPS address), and real Telegram or WhatsApp voice messages.

## Needs Moiz

1. Try a test call on **Workspace > Calls** with `GROQ_API_KEY` set (Chrome or Edge; `localhost` or HTTPS).
2. For the phone line: a Twilio trial account, the three `TWILIO_*` values in `.env`, the number on **Workspace > Calls**, and the webhook URL in the Twilio console (after the deploy, or through a tunnel).
3. Optional: `DOCCHAT_VOICE_REPLIES=true` plus ffmpeg on the server for audio answers in the bots.
