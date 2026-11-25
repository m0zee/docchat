<?php

namespace App\Services\Chat;

use App\Contracts\ChatProvider;
use App\Exceptions\ChatProviderException;
use App\Exceptions\ChatRateLimitedException;
use App\Exceptions\InvalidProviderKeyException;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/**
 * Shared driver for providers that speak the OpenAI chat completions API
 * with server-sent events (Groq, OpenRouter).
 */
abstract class OpenAiCompatibleChatProvider implements ChatProvider
{
    /**
     * @param  string|null  $apiKey  a workspace's own key; null uses the app's key from config
     * @param  string|null  $model  a model for that key; null uses the configured model
     */
    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $model = null,
    ) {}

    /**
     * True when this provider runs on a workspace's own key.
     */
    public function usesOwnKey(): bool
    {
        return $this->apiKey !== null;
    }

    /**
     * Name of the request field that limits the answer length.
     */
    protected function maxTokensField(): string
    {
        return 'max_completion_tokens';
    }

    /**
     * Human name of the provider for error messages.
     */
    protected function label(): string
    {
        return ucfirst($this->driverName());
    }

    /**
     * Config key under docchat.chat.drivers.
     */
    abstract protected function driverName(): string;

    /**
     * Name of the env variable holding the key, used in error messages.
     */
    abstract protected function apiKeyEnv(): string;

    /**
     * Name of the env variable holding the model, used in error messages.
     */
    protected function modelEnv(): string
    {
        return 'DOCCHAT_'.strtoupper($this->driverName()).'_MODEL';
    }

    /**
     * Provider-specific request fields merged into the payload.
     *
     * @return array<string, mixed>
     */
    protected function extraPayload(): array
    {
        return [];
    }

    /**
     * Provider-specific headers.
     *
     * @return array<string, string>
     */
    protected function extraHeaders(): array
    {
        return [];
    }

    /**
     * Read usage from a parsed stream event, if it carries any.
     *
     * @param  array<string, mixed>  $event
     * @return array{0: int, 1: int}|null
     */
    protected function usageFrom(array $event): ?array
    {
        $usage = $event['usage'] ?? null;

        if (! is_array($usage) || ! isset($usage['prompt_tokens'], $usage['completion_tokens'])) {
            return null;
        }

        return [(int) $usage['prompt_tokens'], (int) $usage['completion_tokens']];
    }

    public function stream(array $messages, array $options = []): Generator
    {
        $config = (array) config('docchat.chat.drivers.'.$this->driverName(), []);
        $apiKey = trim((string) ($this->apiKey ?? $config['api_key'] ?? ''));
        $model = trim((string) (($this->model ?? '') !== '' ? $this->model : ($config['model'] ?? '')));

        if ($apiKey === '') {
            throw new ChatProviderException('Set '.$this->apiKeyEnv().' in .env.');
        }

        if ($model === '') {
            throw new ChatProviderException($this->usesOwnKey()
                ? 'Choose a '.$this->label().' model for your workspace key in Workspace > AI keys.'
                : 'Set '.$this->modelEnv().' in .env.');
        }

        $url = rtrim((string) ($config['base_url'] ?? ''), '/').'/chat/completions';

        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            $this->maxTokensField() => (int) ($options['max_output_tokens'] ?? config('docchat.chat.max_output_tokens')),
        ], $this->extraPayload());

        try {
            $response = Http::withToken($apiKey)
                ->withHeaders(array_merge(['Accept' => 'text/event-stream'], $this->extraHeaders()))
                ->connectTimeout(10)
                ->timeout((int) config('docchat.chat.timeout_seconds', 120))
                ->withOptions(['stream' => true])
                ->post($url, $payload);
        } catch (ConnectionException) {
            throw new ChatProviderException('Could not reach the '.$this->driverName().' API.');
        }

        if ($this->usesOwnKey() && in_array($response->status(), [400, 401, 403], true)) {
            throw new InvalidProviderKeyException(
                $this->label().' did not accept your workspace key (status '.$response->status().'). Check it in Workspace > AI keys.',
            );
        }

        if ($response->status() === 429) {
            throw new ChatRateLimitedException('The '.$this->driverName().' API is rate limited.');
        }

        if (! $response->successful()) {
            throw new ChatProviderException(
                'The '.$this->driverName().' API returned status '.$response->status().'.',
            );
        }

        $content = '';
        $usage = null;
        $finishReason = null;
        $responseModel = $model;

        foreach ($this->events($response->toPsrResponse()->getBody()) as $event) {
            if (isset($event['error'])) {
                $code = is_array($event['error']) ? ($event['error']['code'] ?? null) : null;

                throw (int) $code === 429
                    ? new ChatRateLimitedException('The '.$this->driverName().' API is rate limited.')
                    : new ChatProviderException('The '.$this->driverName().' API stopped with an error.');
            }

            if (is_string($event['model'] ?? null)) {
                $responseModel = $event['model'];
            }

            $usage = $this->usageFrom($event) ?? $usage;

            $choice = $event['choices'][0] ?? null;

            if (! is_array($choice)) {
                continue;
            }

            if (is_string($choice['finish_reason'] ?? null)) {
                $finishReason = $choice['finish_reason'];
            }

            // Only the answer text: reasoning fields are never shown or stored.
            $delta = $choice['delta']['content'] ?? null;

            if (is_string($delta) && $delta !== '') {
                $content .= $delta;

                yield $delta;
            }
        }

        if ($usage === null) {
            $charsPerToken = max(1, (int) config('docchat.ingestion.chars_per_token', 4));
            $promptChars = array_sum(array_map(
                fn (array $message): int => mb_strlen((string) $message['content']),
                $messages,
            ));

            return new ChatResult(
                content: $content,
                promptTokens: max(1, (int) ceil($promptChars / $charsPerToken)),
                completionTokens: max(1, (int) ceil(mb_strlen($content) / $charsPerToken)),
                usageEstimated: true,
                model: $responseModel,
                finishReason: $finishReason,
            );
        }

        return new ChatResult(
            content: $content,
            promptTokens: $usage[0],
            completionTokens: $usage[1],
            usageEstimated: false,
            model: $responseModel,
            finishReason: $finishReason,
        );
    }

    /**
     * Parse an SSE body into JSON events. Handles comments, blank lines,
     * [DONE] and lines split across reads.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function events(StreamInterface $body): Generator
    {
        $buffer = '';

        while (true) {
            $eof = $body->eof();
            $chunk = $eof ? '' : $body->read(8192);
            $buffer .= $chunk;

            while (($position = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $position), "\r");
                $buffer = substr($buffer, $position + 1);

                $event = $this->parseLine($line);

                if ($event === false) {
                    return;
                }

                if ($event !== null) {
                    yield $event;
                }
            }

            if ($eof || ($chunk === '' && $body->eof())) {
                break;
            }
        }

        if (trim($buffer) !== '') {
            $event = $this->parseLine(rtrim($buffer, "\r"));

            if (is_array($event)) {
                yield $event;
            }
        }
    }

    /**
     * @return array<string, mixed>|false|null false at [DONE], null to skip
     */
    private function parseLine(string $line): array|false|null
    {
        if ($line === '' || str_starts_with($line, ':') || ! str_starts_with($line, 'data:')) {
            return null;
        }

        $data = trim(substr($line, 5));

        if ($data === '[DONE]') {
            return false;
        }

        $decoded = json_decode($data, true);

        return is_array($decoded) ? $decoded : null;
    }
}
