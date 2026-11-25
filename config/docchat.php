<?php

return [
    'embedding' => [
        'driver' => env('DOCCHAT_EMBEDDING_DRIVER', 'gemini'),
        'model' => env('DOCCHAT_EMBEDDING_MODEL'),
        'dimensions' => (int) env('DOCCHAT_EMBEDDING_DIMENSIONS', 1536),
        'base_url' => env('DOCCHAT_EMBEDDING_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'api_key' => env('GEMINI_API_KEY'),
        'batch_size' => (int) env('DOCCHAT_EMBEDDING_BATCH_SIZE', 50),
        'max_retries' => (int) env('DOCCHAT_EMBEDDING_MAX_RETRIES', 5),
        'retry_base_ms' => (int) env('DOCCHAT_EMBEDDING_RETRY_BASE_MS', 1000),
        'task_prefixes' => [
            'query' => env('DOCCHAT_EMBEDDING_QUERY_PREFIX', 'task: search result | query: '),
            'document' => env('DOCCHAT_EMBEDDING_DOCUMENT_PREFIX', 'title: {title} | text: '),
        ],
    ],

    'chat' => [
        'driver' => env('DOCCHAT_CHAT_DRIVER', 'groq'),
        'max_output_tokens' => (int) env('DOCCHAT_CHAT_MAX_OUTPUT_TOKENS', 800),
        'timeout_seconds' => (int) env('DOCCHAT_CHAT_TIMEOUT_SECONDS', 120),
        'rate_limit_per_minute' => (int) env('DOCCHAT_CHAT_RATE_LIMIT_PER_MINUTE', 20),
        'drivers' => [
            'openrouter' => [
                'model' => env('DOCCHAT_OPENROUTER_MODEL'),
                'base_url' => env('DOCCHAT_OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
                'api_key' => env('OPENROUTER_API_KEY'),
                'referer' => env('DOCCHAT_OPENROUTER_REFERER', env('APP_URL')),
                'title' => env('DOCCHAT_OPENROUTER_TITLE', env('APP_NAME', 'DocChat')),
            ],
            'groq' => [
                'model' => env('DOCCHAT_GROQ_MODEL'),
                'base_url' => env('DOCCHAT_GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
                'api_key' => env('GROQ_API_KEY'),
                'reasoning_effort' => env('DOCCHAT_GROQ_REASONING_EFFORT', 'low'),
            ],
            // Gemini chat, OpenAI-compatible endpoint. Mainly for workspaces
            // that bring their own Gemini key; the app's GEMINI_API_KEY works too.
            'gemini' => [
                'model' => env('DOCCHAT_GEMINI_CHAT_MODEL'),
                'base_url' => env('DOCCHAT_GEMINI_CHAT_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'),
                'api_key' => env('GEMINI_API_KEY'),
                'reasoning_effort' => env('DOCCHAT_GEMINI_REASONING_EFFORT', 'low'),
            ],
        ],
    ],

    // Bring your own key: workspace owners can paste their own free Groq or
    // Gemini key in Workspace > AI keys. Keys are encrypted with APP_KEY.
    'own_keys' => [
        'enabled' => (bool) env('DOCCHAT_OWN_KEYS', true),
        // Base URLs used to check a key before it is saved.
        'check_urls' => [
            'groq' => env('DOCCHAT_GROQ_BASE_URL', 'https://api.groq.com/openai/v1').'/models',
            'gemini' => env('DOCCHAT_EMBEDDING_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta').'/models',
        ],
    ],

    // In-browser AI: the server retrieves, the asker's browser writes the
    // answer with Chrome's built-in model (Gemini Nano) or WebLLM.
    'device' => [
        'enabled' => (bool) env('DOCCHAT_DEVICE_AI', true),
        // Small on-device models have small context windows.
        'top_k' => (int) env('DOCCHAT_DEVICE_TOP_K', 3),
        'token_ttl_seconds' => (int) env('DOCCHAT_DEVICE_TOKEN_TTL', 600),
        'max_answer_chars' => (int) env('DOCCHAT_DEVICE_MAX_ANSWER_CHARS', 6000),
        // WebLLM fallback for the signed-in chat page, loaded from a CDN only
        // when someone turns it on. Pinned; see https://github.com/mlc-ai/web-llm
        'webllm_url' => env('DOCCHAT_WEBLLM_URL', 'https://cdn.jsdelivr.net/npm/@mlc-ai/web-llm@0.2.87/+esm'),
        'webllm_model' => env('DOCCHAT_WEBLLM_MODEL', 'Llama-3.2-1B-Instruct-q4f16_1-MLC'),
    ],

    'api' => [
        'rate_limit_per_minute' => (int) env('DOCCHAT_API_RATE_LIMIT_PER_MINUTE', 60),
        'public_rate_limit_per_minute' => (int) env('DOCCHAT_API_PUBLIC_RATE_LIMIT_PER_MINUTE', 10),
    ],

    // Owner-only pages in Workspace settings: route name => label.
    'workspace_nav' => [
        'workspace.members' => 'Members',
        'workspace.api-keys' => 'API keys',
        'workspace.ai-keys' => 'AI keys',
        'workspace.widget' => 'Widget',
        'workspace.channels' => 'Chat apps',
        'workspace.calls' => 'Calls',
        'workspace.webhooks' => 'Webhooks',
        'workspace.usage' => 'Usage',
    ],

    'rag' => [
        'top_k' => (int) env('DOCCHAT_RAG_TOP_K', 5),
        'similarity_threshold' => (float) env('DOCCHAT_RAG_SIMILARITY_THRESHOLD', 0.5),
        'history_messages' => (int) env('DOCCHAT_RAG_HISTORY_MESSAGES', 4),
    ],

    // Token-saving caches. Answers are cached per workspace for the first
    // question of a conversation and dropped when documents change.
    'cache' => [
        'answers' => (bool) env('DOCCHAT_ANSWER_CACHE', true),
        'semantic_threshold' => (float) env('DOCCHAT_ANSWER_CACHE_SIMILARITY', 0.95),
        'ttl_days' => (int) env('DOCCHAT_ANSWER_CACHE_TTL_DAYS', 7),
        'query_embeddings' => (bool) env('DOCCHAT_QUERY_EMBEDDING_CACHE', true),
        'embedding_ttl_days' => (int) env('DOCCHAT_QUERY_EMBEDDING_CACHE_TTL_DAYS', 30),
    ],

    // Shared chat bots (Telegram, WhatsApp, Discord) run in n8n with one
    // platform service key. Documents sent to a bot report back to the bot's
    // workflow through this signed webhook, whatever workspace they are in.
    // "{channel}" in the URL is replaced with telegram or whatsapp.
    'channels' => [
        'telegram' => [
            'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        ],
        'whatsapp' => [
            // The business number people message, digits only, for the link on the Chat apps page.
            'number' => env('WHATSAPP_NUMBER'),
        ],
        'discord' => [
            'application_id' => env('DISCORD_APPLICATION_ID'),
            'public_key' => env('DISCORD_PUBLIC_KEY'),
            'bot_token' => env('DISCORD_BOT_TOKEN'),
        ],
        'webhook_url' => env('DOCCHAT_CHANNEL_WEBHOOK_URL'),
        'webhook_secret' => env('DOCCHAT_CHANNEL_WEBHOOK_SECRET'),
    ],

    // Speech for calls and voice notes. Speech to text uses Groq's free
    // Whisper with the Groq key above. Voice replies in the chat bots use
    // Groq's Orpheus voices (100 a day free) and need ffmpeg; they are off by
    // default, and calls in the browser and on the phone never need them.
    'voice' => [
        'stt' => [
            'driver' => env('DOCCHAT_STT_DRIVER', 'groq'),
            'model' => env('DOCCHAT_STT_MODEL', 'whisper-large-v3-turbo'),
            'base_url' => env('DOCCHAT_STT_BASE_URL', env('DOCCHAT_GROQ_BASE_URL', 'https://api.groq.com/openai/v1')),
            'api_key' => env('GROQ_API_KEY'),
            'language' => env('DOCCHAT_STT_LANGUAGE'),
            'max_upload_kb' => (int) env('DOCCHAT_STT_MAX_UPLOAD_KB', 10240),
            'daily_limit_per_workspace' => (int) env('DOCCHAT_STT_DAILY_LIMIT', 300),
        ],
        'replies' => (bool) env('DOCCHAT_VOICE_REPLIES', false),
        'tts' => [
            // Voice replies in the bots: groq (Orpheus) or kokoro (self-hosted).
            'driver' => env('DOCCHAT_TTS_DRIVER', 'groq'),
            'model' => env('DOCCHAT_TTS_MODEL', 'canopylabs/orpheus-v1-english'),
            'voice' => env('DOCCHAT_TTS_VOICE', 'hannah'),
            'base_url' => env('DOCCHAT_TTS_BASE_URL', env('DOCCHAT_GROQ_BASE_URL', 'https://api.groq.com/openai/v1')),
            'api_key' => env('GROQ_API_KEY'),
            'max_chars' => (int) env('DOCCHAT_TTS_MAX_CHARS', 600),
            'ffmpeg' => env('DOCCHAT_FFMPEG', 'ffmpeg'),
        ],
        // Kokoro: an open, natural-sounding voice you host yourself (the
        // "kokoro" service in docker-compose.yml, profile "voice"). Free and
        // without daily limits. Empty turns it off, and calls use the
        // browser's own voices and Twilio's voices instead.
        'kokoro' => [
            'url' => env('DOCCHAT_KOKORO_URL'),
            'voice' => env('DOCCHAT_KOKORO_VOICE', 'af_heart'),
            'timeout' => (int) env('DOCCHAT_KOKORO_TIMEOUT', 30),
            'max_chars' => (int) env('DOCCHAT_KOKORO_MAX_CHARS', 600),
        ],
    ],

    // The calling agent (docs/calls.md).
    'calls' => [
        'max_minutes' => (int) env('DOCCHAT_CALL_MAX_MINUTES', 10),
        'max_turns' => (int) env('DOCCHAT_CALL_MAX_TURNS', 30),
        // Answers are read aloud, so they are cut to this many characters.
        'max_spoken_chars' => (int) env('DOCCHAT_CALL_MAX_SPOKEN_CHARS', 700),
        // Outbound phone calls only start inside these hours (24 h clock).
        'calling_hours' => env('DOCCHAT_CALLING_HOURS', '09:00-20:00'),
        'timezone' => env('DOCCHAT_CALLING_TIMEZONE', env('APP_TIMEZONE', 'UTC')),
        'link_valid_days' => (int) env('DOCCHAT_CALL_LINK_DAYS', 7),
        'rate_limit_per_minute' => (int) env('DOCCHAT_CALL_RATE_LIMIT_PER_MINUTE', 30),
        // Phone answers run in a job; the line plays a filler and a hold
        // sound while it waits, for at most this many seconds.
        'phone_answer_wait_seconds' => (int) env('DOCCHAT_PHONE_ANSWER_WAIT', 25),
        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            // The Twilio number outbound calls come from, in E.164.
            'from_number' => env('TWILIO_PHONE_NUMBER'),
            'base_url' => env('TWILIO_API_BASE_URL', 'https://api.twilio.com/2010-04-01'),
            // The public https address Twilio calls, if APP_URL is not it.
            'webhook_base_url' => env('TWILIO_WEBHOOK_BASE_URL'),
        ],
    ],

    'evals' => [
        'delay_seconds' => (float) env('DOCCHAT_EVAL_DELAY_SECONDS', 8),
    ],

    'ingestion' => [
        'chunk_tokens' => (int) env('DOCCHAT_CHUNK_TOKENS', 800),
        'chunk_overlap' => (float) env('DOCCHAT_CHUNK_OVERLAP', 0.15),
        'chars_per_token' => (int) env('DOCCHAT_CHARS_PER_TOKEN', 4),
        'max_upload_kb' => (int) env('DOCCHAT_MAX_UPLOAD_KB', 10240),
    ],
];
