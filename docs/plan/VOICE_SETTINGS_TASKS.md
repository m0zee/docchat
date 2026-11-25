# Voice settings: tasks

Moiz asked on 2026-11-25 for the calling agent to sound natural ("it should not feel like
an AI call"), with multiple voices, tones, accents and languages. He then approved the
extra list: gender and age, speed and pitch, persona (name, greeting, personality),
background sound, fillers and pauses, interruptions, a natural reply delay, auto language
detection, hold music and voicemail detection. Everything stays free, and the calls still
say they are a virtual assistant (the law in many places requires it). Lands on the
calling agent PR (#14), which also merges PR #13 for its site pages.

## What the free options support (checked 2026-11-25)

| Setting | Website calls (browser) | Phone line (Twilio trial) |
| --- | --- | --- |
| Voices | The best voice on the visitor's device ("Natural", "Online", "Neural", "Google" voices first), or the self-hosted Kokoro voice (Apache-2.0, free, runs on the VM) | Twilio basic voice (free), Amazon Polly and Google voices (use trial credit, paid after it), or Kokoro played with `<Play>` |
| Language and accent | Any language the device or Kokoro has | Basic voice: English, Spanish, French, German. Polly or Google for the rest |
| Gender, age | Picks a matching voice; age nudges pitch and speed | Picks a matching voice |
| Speed, pitch | `rate` and `pitch` on speechSynthesis, `speed` on Kokoro | SSML `<prosody rate>` on Polly and Google voices only |
| Interruptions | Mic stays on while the assistant talks; talking over it stops the voice | `<Gather bargeIn>` |
| Reply delay, fillers, hold music | In the browser (fillers, soft synthesized hold music) | Answer runs in a job; the line says a filler and plays a short generated hold sound until it is ready |
| Background sound | Soft synthesized room noise, mixed in the browser | Not possible on a trial (no `<Stream>`) |
| Auto language detection | Whisper detects the language; the reply and the voice follow it | No: Twilio's speech recognition needs the language up front |
| Voicemail detection | Not needed | Twilio answering machine detection (uses trial credit). Off by default |

## Tasks

- [x] V1. Voice profile per workspace (`settings.calls.voice`): assistant name, personality,
      language and accent, auto language, gender, age, speed, pitch, web voice engine and
      voice, phone voice, interruptions, pace, fillers, hold music, background sound,
      voicemail. Validation and a catalog of languages and voices.
- [x] V2. Natural, still-disclosed wording: "Hi, this is Ava, Harbor's virtual assistant",
      phrases in 7 languages (English, Spanish, French, German, Hindi, Urdu, Arabic), varied
      per turn, and a custom greeting gets a disclosure added if it has none.
- [x] V3. Spoken answer style: AnswerService takes a style note (short spoken sentences,
      the personality, the caller's language). Voice-styled answers don't enter the cache.
- [x] V4. Auto language detection: Whisper's detected language is saved on the call; fixed
      phrases and the browser voice follow it.
- [x] V5. Kokoro: optional `kokoro` service in Docker Compose (profile `voice`),
      `KokoroTextToSpeech`, `POST /api/v1/call/speech` limited to what the assistant said
      on that call, an audio cache, and a preview on Workspace > Calls. Bots' voice replies
      can use it too.
- [x] V6. Browser client: best-voice picking, rate and pitch, Kokoro playback with
      sentence pipelining, interruptions, pace, fillers, hold music, background sound.
- [x] V7. Phone line: voice, language, prosody rate and bargeIn from the profile; answers in
      a job with a filler and hold sound while waiting; Kokoro via `<Play>`; voicemail
      detection (hang up or leave a message).
- [x] V8. Workspace > Calls: "Voice and personality" card with previews (browser voice,
      Kokoro voice) and clear "free" and "uses trial credit" labels.
- [x] V9. Tests (Pest, browser checks), Pint, PHPStan.
- [x] V10. README, `docs/calls.md`, the user guide at `/docs`, the landing page feature list
      and the pricing page.
