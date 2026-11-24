<?php

namespace App\Services\Chat;

/**
 * Groq chat completions (https://console.groq.com/docs/api-reference).
 * gpt-oss models return reasoning in a separate field; include_reasoning
 * false drops it and low effort keeps the free-tier token budget for the answer.
 */
class GroqChatProvider extends OpenAiCompatibleChatProvider
{
    protected function driverName(): string
    {
        return 'groq';
    }

    protected function apiKeyEnv(): string
    {
        return 'GROQ_API_KEY';
    }

    protected function extraPayload(): array
    {
        $payload = [
            'stream_options' => ['include_usage' => true],
        ];

        $effort = (string) config('docchat.chat.drivers.groq.reasoning_effort', '');

        if ($effort !== '') {
            $payload['reasoning_effort'] = $effort;
            $payload['include_reasoning'] = false;
        }

        return $payload;
    }

    protected function usageFrom(array $event): ?array
    {
        $groq = $event['x_groq'] ?? null;

        if (is_array($groq) && is_array($groq['usage'] ?? null)) {
            return parent::usageFrom(['usage' => $groq['usage']]);
        }

        return parent::usageFrom($event);
    }
}
