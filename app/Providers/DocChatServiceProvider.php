<?php

namespace App\Providers;

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Contracts\SpeechToText;
use App\Contracts\TextToSpeech;
use App\Services\Chat\GroqChatProvider;
use App\Services\Chat\OpenRouterChatProvider;
use App\Services\Embedding\CachingEmbeddingProvider;
use App\Services\Embedding\GeminiEmbeddingProvider;
use App\Services\Voice\GroqSpeechToText;
use App\Services\Voice\GroqTextToSpeech;
use App\Support\CurrentWorkspace;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class DocChatServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // OpenAPI docs: document the bearer key on every route.
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::http('bearer', 'API key'));
        });

        // Browser calls: per call token, so one visitor cannot flood the agent.
        RateLimiter::for('calls', fn (Request $request) => Limit::perMinute(max(1, (int) config('docchat.calls.rate_limit_per_minute')))
            ->by('call:'.hash('sha256', (string) $request->bearerToken()).':'.$request->ip()));
    }

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

        $this->app->bind(SpeechToText::class, function (): SpeechToText {
            $driver = (string) config('docchat.voice.stt.driver');

            return match ($driver) {
                'groq' => $this->app->make(GroqSpeechToText::class),
                default => throw new InvalidArgumentException('Unsupported speech to text driver ['.$driver.'].'),
            };
        });

        $this->app->bind(TextToSpeech::class, GroqTextToSpeech::class);

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
