<?php

namespace App\Providers;

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Services\Chat\GroqChatProvider;
use App\Services\Chat\OpenRouterChatProvider;
use App\Services\Embedding\GeminiEmbeddingProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class DocChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmbeddingProvider::class, function (): EmbeddingProvider {
            $driver = (string) config('docchat.embedding.driver');

            return match ($driver) {
                'gemini' => $this->app->make(GeminiEmbeddingProvider::class),
                default => throw new InvalidArgumentException(
                    'Unsupported embedding driver ['.$driver.'].',
                ),
            };
        });

        $this->app->bind(ChatProvider::class, function (): ChatProvider {
            $driver = (string) config('docchat.chat.driver');

            return match ($driver) {
                'groq' => $this->app->make(GroqChatProvider::class),
                'openrouter' => $this->app->make(OpenRouterChatProvider::class),
                default => throw new InvalidArgumentException(
                    'Unsupported chat driver ['.$driver.'].',
                ),
            };
        });
    }
}
