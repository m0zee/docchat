<?php

namespace App\Services\Rag;

/**
 * What a browser needs to write an answer on the device: the prompt the
 * server would have sent to its own model, and a one-time token to hand
 * the answer back with.
 */
final readonly class DevicePrompt
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function __construct(
        public string $token,
        public array $messages,
        public int $maxOutputTokens,
        public int $expiresIn,
    ) {}

    /**
     * @return array{token: string, messages: list<array{role: string, content: string}>, max_output_tokens: int, expires_in: int}
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'messages' => $this->messages,
            'max_output_tokens' => $this->maxOutputTokens,
            'expires_in' => $this->expiresIn,
        ];
    }
}
