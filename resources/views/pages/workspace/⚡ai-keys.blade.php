<?php

use App\Enums\AiProvider;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Services\Ai\AiProviders;
use App\Services\Ai\KeyChecker;
use App\Services\Cache\AnswerCache;
use App\Support\CurrentWorkspace;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('AI keys')] class extends Component {
    /**
     * New key typed per provider. Never filled from the database.
     *
     * @var array<string, string>
     */
    public array $keys = ['groq' => '', 'gemini' => ''];

    /**
     * Optional model per provider.
     *
     * @var array<string, string>
     */
    public array $models = ['groq' => '', 'gemini' => ''];

    public string $chatProvider = 'platform';

    public bool $ownEmbeddings = true;

    public function mount(): void
    {
        Gate::authorize('manage', $this->workspace);

        foreach ($this->savedKeys as $provider => $key) {
            $this->models[$provider] = (string) $key->model;
        }

        $this->chatProvider = $this->providers()->chatChoice($this->workspace)?->value ?? 'platform';
        $this->ownEmbeddings = $this->workspace->setting(AiProviders::EMBEDDING_SETTING, true) !== false;
    }

    public function saveKey(string $provider, KeyChecker $checker): void
    {
        Gate::authorize('manage', $this->workspace);

        $type = AiProvider::tryFrom($provider);
        abort_if($type === null, 404);

        $existing = $this->savedKeys->get($provider);

        $this->validate([
            'keys.'.$provider => [$existing === null ? 'required' : 'nullable', 'string', 'min:20', 'max:300', 'regex:/^\S+$/'],
            'models.'.$provider => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
        ], [
            'keys.'.$provider.'.regex' => __('The key cannot contain spaces.'),
        ], [
            'keys.'.$provider => __('key'),
            'models.'.$provider => __('model'),
        ]);

        $key = trim($this->keys[$provider] ?? '');
        $model = trim($this->models[$provider] ?? '') ?: null;
        $verified = $existing?->verified_at;

        if ($key !== '') {
            $result = $checker->check($type, $key);

            if ($result === KeyChecker::REJECTED) {
                $this->addError('keys.'.$provider, __(':provider did not accept this key. Copy it again from :url', [
                    'provider' => $type->label(),
                    'url' => $type->keyUrl(),
                ]));

                return;
            }

            $verified = $result === KeyChecker::OK ? now() : null;
        }

        $attributes = ['model' => $model];

        if ($key !== '') {
            $attributes += [
                'api_key' => $key,
                'key_hint' => WorkspaceAiKey::hintFor($key),
                'verified_at' => $verified,
                'last_error' => null,
                'last_error_at' => null,
            ];
        }

        WorkspaceAiKey::query()->updateOrCreate(
            ['workspace_id' => $this->workspace->id, 'provider' => $type->value],
            $attributes,
        );

        $this->keys[$provider] = '';
        unset($this->savedKeys);

        Flux::toast(
            variant: $key !== '' && $verified === null ? 'warning' : 'success',
            text: $key !== '' && $verified === null
                ? __('Key saved, but :provider could not be reached to check it. It is checked again on the first question.', ['provider' => $type->label()])
                : __(':provider key saved.', ['provider' => $type->label()]),
        );
    }

    public function removeKey(string $provider): void
    {
        Gate::authorize('manage', $this->workspace);

        $type = AiProvider::tryFrom($provider);
        abort_if($type === null, 404);

        WorkspaceAiKey::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('provider', $type->value)
            ->delete();

        $this->keys[$provider] = '';
        $this->models[$provider] = '';

        if ($this->chatProvider === $provider) {
            $this->chatProvider = 'platform';
            $this->storeSettings();
        }

        unset($this->savedKeys);

        Flux::toast(text: __(':provider key removed. Answers use the app\'s keys again.', ['provider' => $type->label()]));
    }

    public function saveChoice(): void
    {
        Gate::authorize('manage', $this->workspace);

        $this->validate([
            'chatProvider' => ['required', Rule::in(['platform', ...array_column(AiProvider::cases(), 'value')])],
        ]);

        $type = AiProvider::tryFrom($this->chatProvider);

        if ($type !== null && ! $this->savedKeys->has($type->value)) {
            $this->addError('chatProvider', __('Save a :provider key first.', ['provider' => $type->label()]));

            return;
        }

        if ($type !== null && $this->modelFor($type) === '') {
            $this->addError('chatProvider', __('Enter a :provider model next to the key first.', ['provider' => $type->label()]));

            return;
        }

        $this->storeSettings();

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    private function storeSettings(): void
    {
        $previous = $this->workspace->setting(AiProviders::CHAT_SETTING, 'platform');

        $this->workspace->update([
            'settings' => array_merge($this->workspace->settings ?? [], [
                AiProviders::CHAT_SETTING => $this->chatProvider,
                AiProviders::EMBEDDING_SETTING => $this->ownEmbeddings,
            ]),
        ]);

        // Answers cached from another model would hide the switch.
        if ($previous !== $this->chatProvider) {
            AnswerCache::invalidate($this->workspace->id);
        }
    }

    /**
     * The model answers use with this provider's key: the one typed for the
     * key, else the app's configured model.
     */
    public function modelFor(AiProvider $type): string
    {
        $model = trim((string) $this->savedKeys->get($type->value)?->model);

        return $model !== '' ? $model : trim((string) config('docchat.chat.drivers.'.$type->value.'.model'));
    }

    /**
     * @return Collection<string, WorkspaceAiKey>
     */
    #[Computed]
    public function savedKeys(): Collection
    {
        return WorkspaceAiKey::query()
            ->where('workspace_id', $this->workspace->id)
            ->get()
            ->keyBy(fn (WorkspaceAiKey $key): string => $key->provider->value);
    }

    #[Computed]
    public function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->require();
    }

    private function providers(): AiProviders
    {
        return app(AiProviders::class);
    }
}; ?>

<x-pages::workspace.layout :heading="__('AI keys')" :subheading="__('Bring your own free Groq or Gemini key, so your answers use your own free quota instead of the app\'s.')">
    @if (! app(\App\Services\Ai\AiProviders::class)->enabled())
        <flux:callout variant="warning" icon="exclamation-triangle" :text="__('Own keys are turned off on this server (DOCCHAT_OWN_KEYS=false).')" class="mb-6" />
    @endif

    <flux:text class="mb-6">
        {{ __('Keys are encrypted before they are stored and are never shown again; only the last four characters are. Both providers have free tiers that need no card.') }}
    </flux:text>

    <div class="space-y-6">
        @foreach (\App\Enums\AiProvider::cases() as $type)
            @php($saved = $this->savedKeys->get($type->value))
            <form wire:submit="saveKey('{{ $type->value }}')" wire:key="key-{{ $type->value }}" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="ai-key-{{ $type->value }}">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading>{{ $type->label() }}</flux:heading>
                    @if ($saved)
                        <flux:badge size="sm" color="{{ $saved->last_error ? 'red' : ($saved->verified_at ? 'green' : 'zinc') }}" data-test="ai-key-status">
                            {{ $saved->last_error ? __('Rejected') : ($saved->verified_at ? __('Checked') : __('Saved, not checked')) }}
                        </flux:badge>
                        <flux:text class="text-sm">{{ __('Key :hint', ['hint' => $saved->key_hint]) }}</flux:text>
                        @if ($saved->last_used_at)
                            <flux:text class="text-sm">· {{ __('last used :time', ['time' => $saved->last_used_at->diffForHumans()]) }}</flux:text>
                        @endif
                    @endif
                    <flux:spacer />
                    <flux:link :href="$type->keyUrl()" target="_blank" rel="noopener" class="text-sm">{{ __('Get a free key') }}</flux:link>
                </div>

                @if ($saved?->last_error)
                    <flux:callout variant="danger" icon="exclamation-triangle" :text="$saved->last_error" />
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input
                        type="password"
                        wire:model="keys.{{ $type->value }}"
                        :label="$saved ? __('Replace key') : __('API key')"
                        :placeholder="$saved ? __('Leave empty to keep the saved key') : ''"
                        autocomplete="off"
                        spellcheck="false"
                    />
                    <flux:input
                        wire:model="models.{{ $type->value }}"
                        :label="__('Model (optional)')"
                        :placeholder="config('docchat.chat.drivers.'.$type->value.'.model') ?: __('A model id from the provider')"
                        autocomplete="off"
                    />
                </div>

                @if ($type === \App\Enums\AiProvider::Gemini)
                    <flux:text class="text-sm">{{ __('A Gemini key also embeds your documents and questions for search, unless you turn that off below.') }}</flux:text>
                @else
                    <flux:text class="text-sm">{{ __('Groq is used for answers only. Document search keeps using the app\'s Gemini key, or your Gemini key if you add one.') }}</flux:text>
                @endif

                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary" size="sm">{{ $saved ? __('Save') : __('Check and save') }}</flux:button>
                    @if ($saved)
                        <flux:button type="button" size="sm" variant="danger" wire:click="removeKey('{{ $type->value }}')" wire:confirm="{{ __('Remove your :provider key?', ['provider' => $type->label()]) }}">{{ __('Remove') }}</flux:button>
                    @endif
                </div>
            </form>
        @endforeach
    </div>

    <flux:separator class="my-8" />

    <form wire:submit="saveChoice" class="space-y-6">
        <flux:radio.group wire:model="chatProvider" :label="__('Answers use')">
            <flux:radio value="platform" :label="__('The app\'s keys (default)')" />
            @foreach (\App\Enums\AiProvider::cases() as $type)
                <flux:radio
                    :value="$type->value"
                    :label="__('My :provider key', ['provider' => $type->label()])"
                    :description="$this->savedKeys->has($type->value) ? __('Model: :model', ['model' => $this->modelFor($type) ?: __('not set')]) : __('Save a key above first.')"
                    :disabled="! $this->savedKeys->has($type->value)"
                />
            @endforeach
        </flux:radio.group>

        <flux:switch wire:model="ownEmbeddings" :label="__('Use my Gemini key for document search too')" :description="__('Embeds your documents and questions on your key. The embedding model stays the same, so nothing needs re-uploading when you add or remove the key.')" />

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>
</x-pages::workspace.layout>
