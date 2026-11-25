# Bring your own key and AI in the browser

Requested by Moiz on 2026-11-25. Both features keep DocChat free: no paid
service is added. Ollama is out of scope for now (too technical for most
users). Borrowing people's ChatGPT, Gemini or Claude subscriptions is not
offered because it breaks those services' terms.

## 1. Bring your own key (BYOK)

Owners open **Workspace > AI keys** and paste a free key:

- **Groq** (console.groq.com/keys): used for answers.
- **Gemini** (aistudio.google.com/apikey): used for answers if chosen, and
  for embeddings (documents and questions) unless the owner turns that off.

How it works:

- Table `workspace_ai_keys`: one row per workspace and provider. `api_key`
  uses Laravel's `encrypted` cast (APP_KEY), is `#[Hidden]`, and only a
  `…abcd` hint is shown. Rotating APP_KEY needs `APP_PREVIOUS_KEYS`.
- Saving checks the key with the provider's free list-models call
  (`KeyChecker`). A rejected key (400/401/403) is not saved. If the provider
  can't be reached, the key is saved as "not checked".
- `App\Services\Ai\AiProviders` picks the provider per workspace:
  `chat()` returns the app's `ChatProvider` from the container unless the
  workspace chose its own Groq or Gemini key (`settings.chat_provider`);
  `embeddings()` returns a `GeminiEmbeddingProvider` on the workspace key
  when it has one (and `settings.own_key_embeddings` is not false).
- Gemini answers use the OpenAI-compatible endpoint
  (`/v1beta/openai/chat/completions`) through the shared streaming driver.
  The model comes from the key's optional model field, else
  `DOCCHAT_GEMINI_CHAT_MODEL`.
- Embeddings keep the same model and size, so vectors made on the app key
  and the workspace key mix freely: adding or removing a key needs no
  re-indexing.
- A rejected key during a question or ingestion gives a clear error that
  points to Workspace > AI keys, and the page flags the key.
- `messages.ai_source` and `usage_events.ai_source` record `platform`,
  `workspace` or `device`. The usage page shows the counts.

## 2. AI in the browser

The server still embeds the question, retrieves chunks, applies the cache
and the "not found" rule. Only answer writing moves to the device.

Browser support checked on 2026-11-25:

- **Chrome built-in AI (Prompt API, `LanguageModel`)**: on web pages since
  Chrome 148 (desktop: Windows 10/11, macOS 13+, Linux, Chromebook Plus;
  not Android or iOS). Needs 22 GB free disk and a GPU with more than 4 GB
  VRAM or 16 GB RAM. Languages en, es, ja, de, fr.
  `availability()` returns available, downloadable, downloading or
  unavailable; `promptStreaming()` yields text deltas. Cross-origin iframes
  need `allow="language-model"`. Not in Firefox or Safari.
- **WebLLM** (`@mlc-ai/web-llm` 0.2.87, pinned, from jsDelivr): any browser
  with WebGPU. Default model `Llama-3.2-1B-Instruct-q4f16_1-MLC`, about
  0.9 GB, downloaded once and cached by the browser. Not added to
  package.json; it is only imported when a user picks it.

Flow (two steps):

1. `AnswerService::prepareForDevice()` saves the question, then returns a
   finished reply when no model is needed (cache hit, nothing found, search
   failed) or a `DevicePrompt`: the same messages the server model would
   get (3 chunks instead of 5, `DOCCHAT_DEVICE_TOP_K`) and a random
   40-character token, kept in the cache for 10 minutes.
2. The browser runs the model and sends the text back.
   `completeFromDevice()` pulls the token (single use, bound to the
   conversation), maps `[n]` citations to the stored chunks, and saves the
   message with `ai_source = device` and no server tokens.

Device answers are browser-supplied text, so they never enter the shared
answer cache.

Where it runs:

- **Chat page**: an "AI" picker appears only when the browser supports
  Chrome built-in AI or WebGPU. The choice is kept in localStorage.
  Livewire methods `sendOnDevice()` and `completeOnDevice()`.
- **Widget**: owner opt-in in Workspace > Widget ("Answer on visitors'
  devices when they can"). Only Chrome built-in AI, and only when the model
  is already `available`, so a visitor never gets a multi-gigabyte download.
  If the model fails, the widget asks the server instead.
- **API**: `POST /api/v1/ask/device` and `POST /api/v1/ask/device/complete`,
  same keys as `/ask`, behind the same workspace setting.

## Needs Moiz

- Try a real Groq and Gemini key on the AI keys page, and set
  `DOCCHAT_GEMINI_CHAT_MODEL` to a current Gemini model id from AI Studio.
- Try the chat page in Chrome 148+ on a capable laptop (Chrome built-in AI)
  and in a WebGPU browser (WebLLM). The cloud can't run either model.
