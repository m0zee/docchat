<?php

namespace App\Providers;

use App\Contracts\EmbeddingProvider;
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
    }
}
