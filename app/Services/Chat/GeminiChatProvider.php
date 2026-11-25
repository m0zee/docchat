<?php

namespace App\Services\Chat;

/**
 * Gemini through its OpenAI-compatible endpoint
 * (https://ai.google.dev/gemini-api/docs/openai). Same streaming shape as
 * Groq, so it shares the driver. Used mainly with a workspace's own key.
 */
class GeminiChatProvider extends OpenAiCompatibleChatProvider
{
    protected function driverName(): string
    {
        return 'gemini';
    }

    protected function label(): string
    {
        return 'Gemini';
    }

    protected function apiKeyEnv(): string
    {
        return 'GEMINI_API_KEY';
    }

    protected function modelEnv(): string
    {
        return 'DOCCHAT_GEMINI_CHAT_MODEL';
    }

    protected function maxTokensField(): string
    {
        return 'max_tokens';
    }

    protected function extraPayload(): array
    {
        $payload = [
            'stream_options' => ['include_usage' => true],
        ];

        // Thinking spends the free-tier budget; low keeps it for the answer.
        $effort = (string) config('docchat.chat.drivers.gemini.reasoning_effort', '');

        if ($effort !== '') {
            $payload['reasoning_effort'] = $effort;
        }

        return $payload;
    }
}
