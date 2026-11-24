<?php

use App\Enums\ApiAbility;
use App\Enums\ApiKeyType;
use App\Models\ApiKey;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('API keys')] class extends Component {
    public string $name = '';

    /**
     * @var list<string>
     */
    public array $abilities = ['ask', 'documents:read', 'documents:write'];

    public ?int $rateLimit = null;

    /**
     * The new key in plain text. Shown once, never stored.
     */
    public ?string $plainKey = null;

    public function mount(): void
    {
        Gate::authorize('manage', $this->workspace);
    }

    public function create(): void
    {
        Gate::authorize('manage', $this->workspace);

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(array_map(fn (ApiAbility $a): string => $a->value, ApiAbility::forLiveKeys()))],
            'rateLimit' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);

        [, $this->plainKey] = ApiKey::issue(
            $this->workspace,
            $this->name,
            array_map(fn (string $a): ApiAbility => ApiAbility::from($a), $this->abilities),
            ApiKeyType::Live,
            Auth::user(),
            rateLimitPerMinute: $this->rateLimit,
        );

        $this->reset('name', 'rateLimit');
        unset($this->keys);
    }

    public function revoke(int $keyId): void
    {
        Gate::authorize('manage', $this->workspace);

        $key = ApiKey::query()->where('workspace_id', $this->workspace->id)->active()->find($keyId);
        $key?->revoke();
        unset($this->keys);

        if ($key !== null) {
            Flux::toast(variant: 'success', text: __('Key ":name" revoked.', ['name' => $key->name]));
        }
    }

    #[Computed]
    public function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->require();
    }

    /**
     * @return Collection<int, ApiKey>
     */
    #[Computed]
    public function keys(): Collection
    {
        return ApiKey::query()->where('workspace_id', $this->workspace->id)
            ->where('type', ApiKeyType::Live->value)
            ->orderByRaw('revoked_at is not null')
            ->latest()
            ->get();
    }
}; ?>

<x-pages::workspace.layout :heading="__('API keys')" :subheading="__('Secret keys for servers and n8n. Keep them out of browsers and public code.')">
    <div class="space-y-8">
        @if ($plainKey)
            <flux:callout variant="success" icon="key" data-test="new-api-key">
                <flux:callout.heading>{{ __('Copy your new key now. It will not be shown again.') }}</flux:callout.heading>
                <flux:callout.text><span class="break-all font-mono text-xs">{{ $plainKey }}</span></flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="sm" x-on:click="navigator.clipboard.writeText(@js($plainKey))">{{ __('Copy') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif

        <form wire:submit="create" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:input wire:model="name" :label="__('Name')" placeholder="n8n production" required maxlength="80" />

            <flux:checkbox.group wire:model="abilities" :label="__('Abilities')">
                @foreach (\App\Enums\ApiAbility::forLiveKeys() as $ability)
                    <flux:checkbox :value="$ability->value" :label="__($ability->label())" :description="$ability->value" />
                @endforeach
            </flux:checkbox.group>

            <flux:input wire:model="rateLimit" type="number" min="1" max="600" :label="__('Requests per minute')" :placeholder="__('Default: :n', ['n' => config('docchat.api.rate_limit_per_minute')])" />

            <flux:button type="submit" variant="primary" icon="plus">{{ __('Create key') }}</flux:button>
        </form>

        <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->keys as $key)
                <div class="flex flex-wrap items-center gap-3 p-3" wire:key="key-{{ $key->id }}">
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">
                            {{ $key->name }}
                            @if ($key->isRevoked())
                                <flux:badge size="sm" color="red" class="ms-1">{{ __('Revoked') }}</flux:badge>
                            @endif
                        </div>
                        <div class="font-mono text-xs text-zinc-500">{{ $key->prefix }}…</div>
                        <div class="mt-1 text-xs text-zinc-500">
                            {{ implode(', ', $key->abilities) }} ·
                            {{ __(':n/min', ['n' => $key->rateLimit()]) }} ·
                            {{ $key->last_used_at ? __('used :time', ['time' => $key->last_used_at->diffForHumans()]) : __('never used') }}
                        </div>
                    </div>
                    @unless ($key->isRevoked())
                        <flux:button size="sm" variant="danger" wire:click="revoke({{ $key->id }})" wire:confirm="{{ __('Revoke :name? Apps using it stop working at once.', ['name' => $key->name]) }}">{{ __('Revoke') }}</flux:button>
                    @endunless
                </div>
            @empty
                <div class="p-4 text-sm text-zinc-500">{{ __('No API keys yet.') }}</div>
            @endforelse
        </div>
    </div>
</x-pages::workspace.layout>
