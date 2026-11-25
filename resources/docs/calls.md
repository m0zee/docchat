DocChat can answer by voice. Visitors call from your website, people ring your phone number, and you can call contacts who asked for it. The assistant answers from your documents only, like in chat, and every call keeps a transcript.

Set it up under **Workspace > Calls** (owners only). Press **Start a test call** to talk to it in your browser first.

## Ways to call

| | How | Cost |
|---|---|---|
| Website calls | Visitors press **Call** in the [website widget](/docs/widget) and talk in the browser | Free |
| Call links | You create a call for a contact and send them the link | Free |
| Phone line | People ring your Twilio number, or DocChat rings a contact's phone | Free on a Twilio trial, with its limits |

The browser needs microphone access, which browsers only allow on HTTPS pages. Visitors who don't allow it can type instead.

## Make it sound natural

Under **Voice and personality** you choose:

- **Name and personality.** The assistant introduces itself by name ("Hi, this is Ava, Harbor Library's virtual assistant") and talks in a friendly, professional, calm or cheerful way.
- **Language and accent.** English (US, UK, Australia, India), Spanish, French, German, Hindi, Urdu or Arabic. With **Answer in the caller's language** on, website calls notice the language the caller speaks and switch to it.
- **Voice.** Female or male, young, adult or mature, and the speed and pitch. Press **Preview** to hear it.
- **Pace.** How long a pause ends the caller's turn, and how quickly the assistant answers.
- **Interruptions.** Callers can talk over the assistant, and it stops to listen.
- **Fillers and hold music.** "Hmm, let me check." when an answer takes a moment, and soft music while a longer one is written.
- **Background sound.** A very quiet office or cafe sound on website calls.

The assistant always says it is a virtual assistant when the call starts. Callers have a right to know they are talking to an AI, so if your own greeting doesn't say it, DocChat adds it.

## Voices and what they cost

| Voice | Used for | Cost |
|---|---|---|
| The visitor's browser voice | Website calls | Free. Edge and Chrome have very natural voices |
| Self-hosted natural voice (Kokoro) | Website calls, phone calls, voice replies in bots | Free, if the server runs it (see [Self-hosting](/docs/self-hosting)) |
| Voice clone | Website calls, phone calls | Free, if the server runs it (see below) |
| Twilio basic voice | Phone calls | Free |
| Amazon and Google voices | Phone calls | Use the Twilio trial credit, then a small charge per call |

## Voice clones

The assistant can speak with a real person's voice, such as yours or a colleague's, but only if that person agrees in their own voice:

1. Under **Workspace > Calls > Voice clones**, enter a name for the voice, the speaker's full name and, if it isn't you, their email. Press **Ask for consent**.
2. The speaker opens the link, ticks the box and records themselves reading a short consent statement with two code words, then a short passage. It takes about half a minute. Recordings can't be uploaded, only made on that page.
3. DocChat checks that the statement was said. The voice then shows **Ready**: press **Preview**, then **Use on calls**.

The speaker can withdraw on the same link at any time, which deletes the recording. Calls in a cloned voice still say they are a virtual assistant. Voice clones need the free voice clone service on the server (see [Self-hosting](/docs/self-hosting)), and speak one language per server: English, Spanish, French or German.

## Calling people

Only call people who agreed to it. Add them under **Contacts**, tick **This person agreed to be called** and note how they agreed. Anyone who says "stop calling" on a call, in any of the supported languages, is never called again. Phone calls only start inside the calling hours the server sets.

For phone calls you make, **voicemail detection** can hang up on answering machines or leave a short message. It uses Twilio credit, so it is off by default.

## Voice notes

The Telegram and WhatsApp bots understand voice messages too: they write down what was said and answer it. If the server turns on voice replies, the bots answer with audio as well.
