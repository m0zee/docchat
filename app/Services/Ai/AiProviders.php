<?php

namespace App\Services\Ai;

use App\Contracts\ChatProvider;
use App\Contracts\EmbeddingProvider;
use App\Enums\AiProvider;
use App\Enums\AiSource;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Services\Chat\GeminiChatProvider;
use App\Services\Chat\GroqChatProvider;
use App\Services\Embedding\CachingEmbeddingProvider;
use App\Services\Embedding\GeminiEmbeddingProvider;
use Illuminate\Support\Str;
use Throwable;

/**
 * Picks the AI a workspace runs on. With no own key (the default) this is
 * the app's providers from the container; a workspace owner can save their
 * own free Groq or Gemini key and choose it for answers, and a Gemini key
 * also embeds their documents and questions.
 */
class AiProviders
{
    /**
     * Settings key: which provider answers use ("platform", "groq" or "gemini").
     */
    public const CHAT_SETTING = 'chat_provider';

    /**
     * Settings key: false keeps embeddings on the app's key even with an own Gemini key.
     */
    public const EMBEDDING_SETTING = 'own_key_embeddings';

    public function enabled(): bool
    {
        return (bool) config('docchat.own_keys.enabled', true);
    }

    public function key(Workspace $workspace, AiProvider $provider): ?WorkspaceAiKey
    {
        if (! $this->enabled()) {
            return null;
        }

        return WorkspaceAiKey::query()
            ->where('workspace_id', $workspace->id)
            ->where('provider', $provider->value)
            ->first();
    }

    /**
     * The provider chosen for answers, or null for the app's own.
     */
    public function chatChoice(Workspace $workspace): ?AiProvider
    {
        return AiProvider::tryFrom((string) $workspace->setting(self::CHAT_SETTING, 'platform'));
    }

    public function chat(Workspace $workspace): ResolvedChat
    {
        $choice = $this->chatChoice($workspace) ?? $this->fallbackChoice($workspace);
        $key = $choice === null ? null : $this->key($workspace, $choice);

        if ($choice === null || $key === null) {
            return new ResolvedChat(app(ChatProvider::class), AiSource::Platform);
        }

        $model = ($key->model ?? '') !== '' ? $key->model : null;

        $provider = match ($choice) {
            AiProvider::Groq => new GroqChatProvider($key->api_key, $model),
            AiProvider::Gemini => new GeminiChatProvider($key->api_key, $model),
        };

        return new ResolvedChat($provider, AiSource::Workspace, $key);
    }

    /**
     * A workspace left on "the app's keys" when the app has no chat key of its
     * own (a self-hosted install with only workspace keys) would always fail
     * with "Set GROQ_API_KEY in .env.", so it uses its own saved key instead:
     * the app's driver first, then Groq, then Gemini.
     */
    private function fallbackChoice(Workspace $workspace): ?AiProvider
    {
        $driver = (string) config('docchat.chat.driver');

        if (trim((string) config('docchat.chat.drivers.'.$driver.'.api_key')) !== '') {
            return null;
        }

        $order = array_unique(array_filter([AiProvider::tryFrom($driver), AiProvider::Groq, AiProvider::Gemini]), SORT_REGULAR);

        foreach ($order as $provider) {
            if ($this->key($workspace, $provider) !== null) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Embeddings for this workspace: its own Gemini key when it has one,
     * otherwise $fallback (or the app's provider from the container).
     * Own-key vectors use the same model and size, so they mix freely with
     * vectors made on the app's key.
     */
    public function embeddings(Workspace $workspace, ?EmbeddingProvider $fallback = null): EmbeddingProvider
    {
        $key = $this->embeddingKey($workspace);

        if ($key === null) {
            return $fallback ?? app(EmbeddingProvider::class);
        }

        $provider = new GeminiEmbeddingProvider($key->api_key);

        return config('docchat.cache.query_embeddings')
            ? new CachingEmbeddingProvider($provider)
            : $provider;
    }

    public function embeddingKey(Workspace $workspace): ?WorkspaceAiKey
    {
        if (config('docchat.embedding.driver') !== 'gemini'
            || $workspace->setting(self::EMBEDDING_SETTING, true) === false) {
            return null;
        }

        return $this->key($workspace, AiProvider::Gemini);
    }

    public function markUsed(?WorkspaceAiKey $key): void
    {
        if ($key === null) {
            return;
        }

        $this->quietly(fn () => $key->forceFill([
            'last_used_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
        ])->saveQuietly());
    }

    public function markRejected(?WorkspaceAiKey $key, string $message): void
    {
        if ($key === null) {
            return;
        }

        $this->quietly(fn () => $key->forceFill([
            'last_error' => Str::limit($message, 250),
            'last_error_at' => now(),
        ])->saveQuietly());
    }

    /**
     * Bookkeeping must never break an answer or an ingestion.
     */
    private function quietly(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
