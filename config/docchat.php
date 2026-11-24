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
        ],
    ],

    'rag' => [
        'top_k' => (int) env('DOCCHAT_RAG_TOP_K', 5),
        'similarity_threshold' => (float) env('DOCCHAT_RAG_SIMILARITY_THRESHOLD', 0.5),
        'history_messages' => (int) env('DOCCHAT_RAG_HISTORY_MESSAGES', 4),
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
