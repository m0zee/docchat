# Voice clone: tasks

Moiz asked on 2026-11-25 for a voice clone feature after the voice settings, free and
self-hosted, with consent. A workspace may only clone a voice the speaker agreed to, and
calls still say they are a virtual assistant. Moiz chose to ship it on the calling agent PR
(#14).

## Engine (checked 2026-11-25)

| Option | License | CPU | Languages | Notes |
| --- | --- | --- | --- | --- |
| **Kyutai Pocket TTS** (chosen) | Code MIT, weights CC-BY-4.0 | 100M params, about 2.3x real time on 4 x86 vCPUs, first audio in about 200 ms | English, French, German, Spanish, Italian, Portuguese, Dutch (one per server) | `pocket-tts serve`: `POST /tts` (form `text`, file `voice_wav`), streams WAV. The voice-cloning weights are gated on Hugging Face: accept the terms with a free account and set `HF_TOKEN`. Its terms forbid cloning without explicit consent |
| Chatterbox (Resemble AI) | MIT | 0.5B, slow on CPU, 4 to 8 GB RAM | 22 languages incl. Arabic and Hindi | Watermarked output. The HTTP wrapper is AGPL and has no published image |
| XTTS-v2, F5-TTS | Non-commercial model licenses | | | Not used |

## Consent rule

The clone is made only from audio recorded on DocChat's consent page, in one take that
starts with a consent statement read aloud: "I, <name>, agree that <workspace> may create
an AI copy of my voice for its virtual assistant. My code words are <two random words>."
Speech recognition must hear the code words, "agree" and the name. Uploading a file is not
possible, so a voice can't be cloned from someone else's recording. The speaker can
withdraw consent from the same link at any time, which deletes the recording and every clip.

## Tasks

- [x] VC1. `voice_clones` table and model: name, speaker name and email, status (waiting
      for consent, ready, failed, withdrawn), the consent statement and code words, the
      recording (private disk), the transcript, who agreed and when (IP, browser), language.
- [x] VC2. Consent statements in English, Spanish, French and German, with random code
      words, and verification of the transcript.
- [x] VC3. Consent page `/voice-consent/{token}`: statement, a short passage to read,
      recording in the browser as WAV, upload, result; and "withdraw consent". Owners can
      record their own voice from the Calls page, or send the link to the speaker.
- [x] VC4. `PocketTtsVoice`: speech from the clone through `POST /tts`, cached clips.
- [x] VC5. Use a clone on browser calls and phone calls (`<Play>`) when the call's
      language matches the clone server's; otherwise the normal voice is used.
- [x] VC6. Workspace > Calls: voice clones card (create, send link, status, preview, use,
      delete). Limits: 3 clones a workspace, owners only.
- [x] VC7. Optional `voice-clone` service in Docker Compose (profile `clone`) built from
      `docker/voice-clone/Dockerfile`.
- [x] VC8. Tests (Pest, browser check of the WAV recorder), Pint, PHPStan.
- [x] VC9. README, `docs/calls.md`, the `/docs` user guide, landing page and pricing page.
