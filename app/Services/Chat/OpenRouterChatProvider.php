<?php

namespace App\Services\Chat;

/**
 * OpenRouter chat completions (https://openrouter.ai/docs/api_reference/streaming).
 * Usage arrives in the last chunk without any extra parameter.
 */
class OpenRouterChatProvider extends OpenAiCompatibleChatProvider
{
    protected function driverName(): string
    {
        return 'openrouter';
    }

    protected function apiKeyEnv(): string
    {
        return 'OPENROUTER_API_KEY';
    }

    protected function extraPayload(): array
    {
        return [
            'reasoning' => ['exclude' => true],
        ];
    }

    protected function extraHeaders(): array
    {
        $headers = [];
        $referer = (string) config('docchat.chat.drivers.openrouter.referer', '');
        $title = (string) config('docchat.chat.drivers.openrouter.title', '');

        if ($referer !== '') {
            $headers['HTTP-Referer'] = $referer;
        }

        if ($title !== '') {
            $headers['X-Title'] = $title;
        }

        return $headers;
    }
}
