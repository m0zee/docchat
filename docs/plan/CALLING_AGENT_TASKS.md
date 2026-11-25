# Calling agent: tasks

Moiz asked on 2026-11-25 for a calling agent for inbound and outbound calls, and chose
"all of it": website calls, a Twilio trial phone line, and voice notes in the Telegram
and WhatsApp bots. Everything stays on free tiers. One PR.

## Free tiers this is built on (checked 2026-11-25)

| Piece | Free tier | Limits that matter |
| --- | --- | --- |
| Speech to text | Groq Whisper (`whisper-large-v3-turbo`) | 20 a minute, 2,000 a day, 2 h of audio an hour, 8 h a day, 25 MB a file |
| Voice in the browser | `speechSynthesis` (built into browsers) | Voice quality depends on the browser |
| Phone line | Twilio trial, no card | 75 minutes, 30 days, a shared trial number, only your up to 5 verified numbers, only inside your sign-up country, 10 minutes a call. Record, Stream and Dial are blocked, so the line uses `<Gather input="speech">` and `<Say>` |
| Voice replies in bots | Groq Orpheus TTS (`canopylabs/orpheus-v1-english`) | 100 a day, 200 characters a request, WAV only (needs ffmpeg for MP3). Off by default |

Other phone providers were checked and are not free: Vapi's free numbers are US only,
LiveKit and Telnyx charge for numbers.

## Rules

- A guest or customer always hears that they are talking to an automated assistant.
- Outbound calls (phone or link) only go to contacts marked as having agreed to be
  called, never to opted-out contacts, and phone calls only inside the calling hours.
  Saying "stop calling me" on a call opts the contact out.
- Answers still come only from the workspace's documents (AnswerService), and every
  call keeps a transcript (a conversation) and a log row.
- No new Composer or npm packages. Twilio is called with Laravel's HTTP client.
- Every outside API is faked in tests.

## Tasks

- [x] C1. Data: `call_contacts` (name, phone, email, consent, opt-out) and `calls`
      (direction, transport, status, purpose, opening line, hashed call token, Twilio
      sid, times, turns). New conversation channels `voice` and `phone`.
- [x] C2. Speech services: `SpeechToText` (Groq Whisper, fake in tests) and
      `TextToSpeech` (Groq Orpheus to WAV, ffmpeg to MP3, off by default).
- [x] C3. `CallAgent`: opening line (AI disclosure, outbound purpose), turns through
      AnswerService, spoken text (no citation marks or Markdown), goodbye and opt-out
      phrases, silence reprompts, turn and time limits.
- [x] C4. Website call API: `POST /api/v1/calls` (widget public key or live key) starts
      an inbound web call; `POST /api/v1/call/answer|turns|end` with the call token
      (`dc_call_...`) answers, sends speech (audio or text) and hangs up.
- [x] C5. Browser call client `/voice-call.js`: mic with silence detection,
      MediaRecorder upload, speechSynthesis playback, type-instead box, mute and hang up.
      Used by the widget (phone button), the call link page and the dashboard test call.
- [x] C6. Outbound call links: `/call/{token}` public page; the owner creates the call
      on Workspace > Calls and copies the link or emails it (log mailer by default).
- [x] C7. Twilio line: signed webhooks for inbound, answer (outbound), speech results
      and status; TwiML with Say and Gather; `PlaceTwilioCallJob` for outbound calls;
      the number is mapped to a workspace on Workspace > Calls.
- [x] C8. Workspace > Calls page: greeting, phone number, contacts with consent,
      new outbound call, test call in the browser, call log with transcripts.
- [x] C9. Voice notes: `POST /api/v1/voice/ask` (transcribe and answer) and
      `POST /api/v1/voice/speak` (MP3 reply); Telegram and WhatsApp workflows handle
      voice messages, and send a voice reply when `DOCCHAT_VOICE_REPLIES=true`.
- [x] C10. Tests (Pest, browser check of the call client), Pint, PHPStan, README and
      setup docs (`docs/calls.md`).
