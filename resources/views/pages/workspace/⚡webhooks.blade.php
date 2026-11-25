<?php

use App\Jobs\SendWebhookJob;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Webhooks')] class extends Component {
    public string $url = '';

    /**
     * @var list<string>
     */
    public array $events = ['document.ready', 'document.failed'];

    /**
     * Webhook ids whose secret is shown.
     *
     * @var list<int>
     */
    public array $revealed = [];

    public function mount(): void
    {
        Gate::authorize('manage', $this->workspace);
    }

    public function create(): void
    {
        Gate::authorize('manage', $this->workspace);

        $this->validate([
            'url' => ['required', 'url:http,https', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(Webhook::EVENTS))],
        ]);

        $webhook = Webhook::query()->create([
            'workspace_id' => $this->workspace->id,
            'url' => $this->url,
            'events' => array_values($this->events),
            'secret' => Webhook::newSecret(),
        ]);

        $this->revealed[] = $webhook->id;
        $this->reset('url');
        unset($this->webhooks);
    }

    public function toggle(int $id): void
    {
        $webhook = $this->find($id);
        $webhook?->update(['active' => ! $webhook->active]);
        unset($this->webhooks);
    }

    public function delete(int $id): void
    {
        $this->find($id)?->delete();
        unset($this->webhooks);
    }

    public function reveal(int $id): void
    {
        if ($this->find($id) !== null && ! in_array($id, $this->revealed, true)) {
            $this->revealed[] = $id;
        }
    }

    public function sendTest(int $id): void
    {
        $webhook = $this->find($id);

        if ($webhook === null) {
            return;
        }

        SendWebhookJob::dispatch($webhook, 'ping', ['message' => 'Test from DocChat']);
        Flux::toast(text: __('Test event queued.'));
    }

    #[Computed]
    public function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->require();
    }

    /**
     * @return Collection<int, Webhook>
     */
    #[Computed]
    public function webhooks(): Collection
    {
        return Webhook::query()->where('workspace_id', $this->workspace->id)->latest()->get();
    }

    private function find(int $id): ?Webhook
    {
        Gate::authorize('manage', $this->workspace);

        return Webhook::query()->where('workspace_id', $this->workspace->id)->find($id);
    }
}; ?>

<x-pages::workspace.layout :heading="__('Webhooks')" :subheading="__('DocChat POSTs signed JSON to these URLs, for example an n8n Webhook node.')">
    <div class="space-y-8">
        <form wire:submit="create" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:input wire:model="url" type="url" :label="__('URL')" placeholder="https://n8n.example.com/webhook/docchat" required />

            <flux:checkbox.group wire:model="events" :label="__('Events')">
                @foreach (\App\Models\Webhook::EVENTS as $event => $label)
                    <flux:checkbox :value="$event" :label="$event" :description="__($label)" />
                @endforeach
            </flux:checkbox.group>

            <flux:button type="submit" variant="primary" icon="plus">{{ __('Add webhook') }}</flux:button>
        </form>

        <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->webhooks as $webhook)
                <div class="space-y-2 p-3" wire:key="wh-{{ $webhook->id }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="min-w-0 flex-1 truncate font-mono text-sm">{{ $webhook->url }}</div>
                        <flux:badge size="sm" :color="$webhook->active ? 'green' : 'zinc'">{{ $webhook->active ? __('Active') : __('Paused') }}</flux:badge>
                        @if ($webhook->last_status)
                            <flux:badge size="sm" :color="$webhook->last_status < 300 ? 'green' : 'red'">HTTP {{ $webhook->last_status }}</flux:badge>
                        @elseif ($webhook->last_error)
                            <flux:badge size="sm" color="red">{{ __('Error') }}</flux:badge>
                        @endif
                    </div>
                    <div class="text-xs text-zinc-500">{{ implode(', ', $webhook->events) }}</div>
                    @if ($webhook->last_error)
                        <div class="text-xs text-red-600">{{ $webhook->last_error }}</div>
                    @endif
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="text-zinc-500">{{ __('Signing secret:') }}</span>
                        @if (in_array($webhook->id, $revealed, true))
                            <code class="break-all" data-test="webhook-secret">{{ $webhook->secret }}</code>
                        @else
                            <flux:button size="xs" variant="ghost" wire:click="reveal({{ $webhook->id }})">{{ __('Show') }}</flux:button>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" wire:click="sendTest({{ $webhook->id }})">{{ __('Send test') }}</flux:button>
                        <flux:button size="sm" wire:click="toggle({{ $webhook->id }})">{{ $webhook->active ? __('Pause') : __('Resume') }}</flux:button>
                        <flux:button size="sm" variant="danger" wire:click="delete({{ $webhook->id }})" wire:confirm="{{ __('Delete this webhook?') }}">{{ __('Delete') }}</flux:button>
                    </div>
                </div>
            @empty
                <div class="p-4 text-sm text-zinc-500">{{ __('No webhooks yet.') }}</div>
            @endforelse
        </div>

        <div class="space-y-2 text-sm">
            <flux:heading>{{ __('Verifying a delivery') }}</flux:heading>
            <flux:text>{{ __('Compute HMAC-SHA256 of the raw request body with the signing secret and compare it to the X-DocChat-Signature header (sha256=...). Then reject the request if X-DocChat-Timestamp is more than 5 minutes away from your clock, so an old delivery cannot be replayed.') }}</flux:text>
        </div>
    </div>
</x-pages::workspace.layout>
