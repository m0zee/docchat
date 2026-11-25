@php
    $user = auth()->user();
    $workspace = app(\App\Support\CurrentWorkspace::class)->require();
    $myConversations = \App\Models\Conversation::query()->inWorkspace($workspace)->where('channel', 'web')->where('user_id', $user->id);
    $stats = [
        ['label' => __('Ready documents'), 'value' => \App\Models\Document::query()->inWorkspace($workspace)->where('status', \App\Enums\DocumentStatus::Ready)->count(), 'icon' => 'document-text'],
        ['label' => __('Chunks indexed'), 'value' => \App\Models\Chunk::query()->inWorkspace($workspace)->count(), 'icon' => 'squares-2x2'],
        ['label' => __('Your conversations'), 'value' => (clone $myConversations)->count(), 'icon' => 'chat-bubble-left-right'],
    ];
    $recent = (clone $myConversations)->latest('updated_at')->limit(5)->get();
@endphp

<x-layouts::app :title="__('Dashboard')">
    <div class="flex w-full max-w-5xl flex-col gap-8">
        <div>
            <flux:heading size="xl">{{ __('Welcome, :name', ['name' => $user->name]) }}</flux:heading>
            <flux:subheading>{{ __(':workspace · Upload documents, then ask questions about them in Chat.', ['workspace' => $workspace->name]) }}</flux:subheading>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($stats as $stat)
                <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" data-stat>
                    <div class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                        <flux:icon :name="$stat['icon']" variant="mini" />
                        {{ $stat['label'] }}
                    </div>
                    <div class="mt-2 text-3xl font-semibold">{{ number_format($stat['value']) }}</div>
                </div>
            @endforeach
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <a href="{{ route('documents.index') }}" wire:navigate class="rounded-xl border border-zinc-200 p-5 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">
                <flux:heading>{{ __('Documents') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Upload PDF, TXT or Markdown files and watch them get indexed.') }}</flux:text>
            </a>
            <a href="{{ route('chat.index') }}" wire:navigate class="rounded-xl border border-zinc-200 p-5 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">
                <flux:heading>{{ __('Chat') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Ask a question and get an answer that cites the page it came from.') }}</flux:text>
            </a>
        </div>

        @if ($recent->isNotEmpty())
            <div>
                <flux:heading size="lg">{{ __('Recent conversations') }}</flux:heading>
                <ul class="mt-3 divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($recent as $conversation)
                        <li>
                            <a href="{{ route('chat.show', $conversation) }}" wire:navigate class="flex justify-between gap-4 px-4 py-3 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <span class="truncate">{{ $conversation->title ?? __('New conversation') }}</span>
                                <span class="shrink-0 text-zinc-500">{{ $conversation->updated_at?->diffForHumans() }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-layouts::app>
