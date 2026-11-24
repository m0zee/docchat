<?php

namespace App\Providers;

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Services\Chat\GroqChatProvider;
use App\Services\Chat\OpenRouterChatProvider;
use App\Services\Embedding\CachingEmbeddingProvider;
use App\Services\Embedding\GeminiEmbeddingProvider;
use App\Support\CurrentWorkspace;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class DocChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: reset between queue jobs and Octane requests.
        $this->app->scoped(CurrentWorkspace::class);

        $this->app->bind(EmbeddingProvider::class, function (): EmbeddingProvider {
            $driver = (string) config('docchat.embedding.driver');

            $provider = match ($driver) {
                'gemini' => $this->app->make(GeminiEmbeddingProvider::class),
                default => throw new InvalidArgumentException(
                    'Unsupported embedding driver ['.$driver.'].',
                ),
            };

            return config('docchat.cache.query_embeddings')
                ? new CachingEmbeddingProvider($provider)
                : $provider;
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
