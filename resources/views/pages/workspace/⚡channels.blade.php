<?php

use App\Enums\ConversationChannel;
use App\Models\ChannelLink;
use App\Models\ChannelLinkCode;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Chat apps')] class extends Component {
    /** The code just created, shown once. */
    public ?string $code = null;

    public ?string $codeChannel = null;

    public ?string $codeExpiresAt = null;

    public function mount(): void
    {
        Gate::authorize('manage', $this->workspace);
    }

    public function createCode(string $channel): void
    {
        Gate::authorize('manage', $this->workspace);

        $channel = ConversationChannel::tryFrom($channel);

        if ($channel === null || ! in_array($channel, $this->channels(), true)) {
            return;
        }

        [$record, $plain] = ChannelLinkCode::issue($this->workspace, $channel, auth()->user());

        $this->code = $plain;
        $this->codeChannel = $channel->value;
        $this->codeExpiresAt = $record->expires_at->toIso8601String();
    }

    public function unlink(int $id): void
    {
        Gate::authorize('manage', $this->workspace);

        ChannelLink::query()->inWorkspace($this->workspace)->whereKey($id)->delete();
        unset($this->links);
    }

    /**
     * Chat apps this server has a bot for.
     *
     * @return list<ConversationChannel>
     */
    public function channels(): array
    {
        return array_values(array_filter([
            filled(config('docchat.channels.telegram.bot_username')) ? ConversationChannel::Telegram : null,
        ]));
    }

    public function telegramUrl(): ?string
    {
        $bot = ltrim((string) config('docchat.channels.telegram.bot_username'), '@');

        return $this->code === null || $bot === '' ? null : 'https://t.me/'.$bot.'?start='.$this->code;
    }

    #[Computed]
    public function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->require();
    }

    /**
     * @return Collection<int, ChannelLink>
     */
    #[Computed]
    public function links(): Collection
    {
        return ChannelLink::query()->inWorkspace($this->workspace)->with('linkedBy')->latest()->get();
    }
}; ?>

<x-pages::workspace.layout :heading="__('Chat apps')" :subheading="__('Let people ask this workspace\'s documents from a chat app. Each linked chat uses this workspace\'s documents.')">
    <div class="space-y-8">
        @forelse ($this->channels() as $channel)
            <div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="channel-{{ $channel->value }}">
                <flux:heading>{{ __('Connect :app', ['app' => $channel->label()]) }}</flux:heading>

                @if ($code !== null && $codeChannel === $channel->value)
                    @if ($channel === \App\Enums\ConversationChannel::Telegram)
                        <flux:text>{{ __('Open this link on the phone or computer with the Telegram chat, and press Start. To link a group, add the bot to the group, then send /start followed by the code there.') }}</flux:text>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:button :href="$this->telegramUrl()" target="_blank" variant="primary" icon="arrow-top-right-on-square">{{ __('Open Telegram') }}</flux:button>
                            <code class="break-all text-sm" data-test="link-code">/start {{ $code }}</code>
                        </div>
                    @endif
                    <flux:text class="text-xs">{{ __('The code works once and expires at :time.', ['time' => \Illuminate\Support\Carbon::parse($codeExpiresAt)->format('H:i')]) }}</flux:text>
                @else
                    <flux:button wire:click="createCode('{{ $channel->value }}')" icon="link">{{ __('Create a link code') }}</flux:button>
                @endif
            </div>
        @empty
            <flux:callout icon="information-circle" :text="__('No chat bot is set up on this server yet. The server admin sets TELEGRAM_BOT_USERNAME after creating the bot (see n8n/README.md).')" />
        @endforelse

        <div class="space-y-2">
            <flux:heading>{{ __('Linked chats') }}</flux:heading>
            <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($this->links as $link)
                    <div class="flex flex-wrap items-center gap-2 p-3" wire:key="link-{{ $link->id }}">
                        <flux:badge size="sm">{{ $link->channel->label() }}</flux:badge>
                        <div class="min-w-0 flex-1 truncate text-sm">{{ $link->label ?? $link->external_id }}</div>
                        <div class="text-xs text-zinc-500">{{ $link->created_at?->diffForHumans() }}@if ($link->linkedBy) · {{ $link->linkedBy->name }}@endif</div>
                        <flux:button size="sm" variant="danger" wire:click="unlink({{ $link->id }})" wire:confirm="{{ __('Unlink this chat? It will stop answering from this workspace.') }}">{{ __('Unlink') }}</flux:button>
                    </div>
                @empty
                    <div class="p-4 text-sm text-zinc-500">{{ __('No chats linked yet.') }}</div>
                @endforelse
            </div>
        </div>
    </div>
</x-pages::workspace.layout>
