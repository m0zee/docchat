<?php

use App\Enums\DocumentStatus;
use App\Enums\MessageRole;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Services\Rag\AnswerService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Chat')] class extends Component {
    #[Locked]
    public ?int $conversationId = null;

    public string $question = '';

    /**
     * Question sent but not yet answered; shown while the answer streams.
     */
    #[Locked]
    public ?string $pending = null;

    /**
     * Documents picked in the scope picker. Empty means all documents.
     *
     * @var list<int|string>
     */
    public array $documentIds = [];

    /**
     * Full chunk text for citations the user expanded, keyed by chunk id.
     *
     * @var array<int, string>
     */
    public array $expanded = [];

    public function mount(?Conversation $conversation = null): void
    {
        if ($conversation !== null) {
            Gate::authorize('view', $conversation);

            $this->conversationId = $conversation->id;
            $this->documentIds = $conversation->documents()->pluck('documents.id')->map(fn ($id): int => (int) $id)->all();
        }
    }

    public function send(): void
    {
        $this->validate(['question' => ['required', 'string', 'max:2000']]);

        if ($this->pending !== null) {
            return;
        }

        $conversation = $this->conversation ?? $this->startConversation();

        $this->pending = trim($this->question);
        $this->question = '';

        // Answer in a second request so the question shows at once and
        // the answer can stream into the page.
        $this->js('$wire.ask()');
    }

    public function ask(AnswerService $answers): void
    {
        $conversation = $this->conversation;

        if ($conversation === null || $this->pending === null) {
            return;
        }

        Gate::authorize('update', $conversation);

        $question = $this->pending;

        $answers->answer($conversation, $question, function (string $delta): void {
            // Streamed text is inserted as HTML, so escape it.
            $this->stream(e($delta), name: 'answer');
        });

        $this->pending = null;
        unset($this->messages, $this->conversations);
    }

    public function updatedDocumentIds(): void
    {
        $this->documentIds = $this->allowedDocumentIds($this->documentIds);

        if ($this->conversation !== null) {
            Gate::authorize('update', $this->conversation);
            $this->conversation->documents()->sync($this->documentIds);
        }
    }

    public function expand(int $chunkId): void
    {
        if (isset($this->expanded[$chunkId])) {
            unset($this->expanded[$chunkId]);

            return;
        }

        $chunk = Chunk::query()->ownedBy($this->user())->find($chunkId);

        if ($chunk !== null) {
            $this->expanded[$chunkId] = $chunk->content;
        }
    }

    #[Computed]
    public function conversation(): ?Conversation
    {
        if ($this->conversationId === null) {
            return null;
        }

        return $this->user()->conversations()->find($this->conversationId);
    }

    /**
     * @return Collection<int, Message>
     */
    #[Computed]
    public function messages(): Collection
    {
        return $this->conversation?->messages()->get() ?? new Collection();
    }

    /**
     * @return Collection<int, Conversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        return $this->user()->conversations()->latest('updated_at')->latest('id')->limit(50)->get();
    }

    /**
     * @return Collection<int, Document>
     */
    #[Computed]
    public function readyDocuments(): Collection
    {
        return $this->user()->documents()
            ->where('status', DocumentStatus::Ready)
            ->orderBy('title')
            ->get(['id', 'title']);
    }

    private function startConversation(): Conversation
    {
        $conversation = $this->user()->conversations()->create();
        $conversation->documents()->sync($this->allowedDocumentIds($this->documentIds));

        $this->conversationId = $conversation->id;
        unset($this->conversation, $this->conversations);

        $this->js('history.replaceState(history.state, "", '.json_encode(route('chat.show', $conversation)).')');

        return $conversation;
    }

    /**
     * Keep only ids of the user's own ready documents; never trust the browser.
     *
     * @param  array<int|string>  $ids
     * @return list<int>
     */
    private function allowedDocumentIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        return $this->user()->documents()
            ->where('status', DocumentStatus::Ready)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<div class="flex h-[calc(100vh-6rem)] w-full gap-6">
    <aside class="hidden w-64 shrink-0 flex-col md:flex">
        <flux:button :href="route('chat.index')" icon="plus" variant="primary" class="w-full" wire:navigate>
            {{ __('New chat') }}
        </flux:button>

        <nav class="mt-4 flex-1 space-y-1 overflow-y-auto">
            @forelse ($this->conversations as $item)
                <a
                    href="{{ route('chat.show', $item) }}"
                    wire:navigate
                    wire:key="conversation-{{ $item->id }}"
                    @class([
                        'block truncate rounded-lg px-3 py-2 text-sm',
                        'bg-zinc-200 font-medium dark:bg-zinc-700' => $item->id === $conversationId,
                        'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => $item->id !== $conversationId,
                    ])
                >
                    {{ $item->title ?? __('New conversation') }}
                </a>
            @empty
                <flux:text class="px-3 text-sm">{{ __('No conversations yet.') }}</flux:text>
            @endforelse
        </nav>
    </aside>

    <section class="flex min-w-0 flex-1 flex-col">
        @if ($this->readyDocuments->isEmpty())
            <div class="m-auto max-w-md rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
                <flux:icon name="document-plus" class="mx-auto size-8 text-zinc-400" />
                <flux:heading class="mt-3">{{ __('Upload a document first') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Chat answers come only from your documents, so you need at least one ready document.') }}</flux:text>
                <flux:button :href="route('documents.index')" class="mt-4" wire:navigate>{{ __('Go to Documents') }}</flux:button>
            </div>
        @else
            <div class="flex flex-wrap items-center gap-3 border-b border-zinc-200 pb-3 dark:border-zinc-700">
                <flux:heading size="lg" class="truncate">{{ $this->conversation?->title ?? __('New chat') }}</flux:heading>
                <flux:spacer />
                <flux:dropdown position="bottom" align="end">
                    <flux:button size="sm" icon="funnel" icon:trailing="chevron-down" data-scope-button>
                        @if ($documentIds === [])
                            {{ __('All my documents') }}
                        @else
                            {{ trans_choice(':count document|:count documents', count($documentIds)) }}
                        @endif
                    </flux:button>

                    <flux:menu class="max-h-80 overflow-y-auto">
                        <flux:menu.heading>{{ __('Search in (none ticked = all)') }}</flux:menu.heading>
                        <flux:menu.checkbox.group wire:model.live="documentIds">
                            @foreach ($this->readyDocuments as $document)
                                <flux:menu.checkbox :value="$document->id" wire:key="scope-{{ $document->id }}">{{ $document->title }}</flux:menu.checkbox>
                            @endforeach
                        </flux:menu.checkbox.group>
                    </flux:menu>
                </flux:dropdown>
            </div>

            <div
                class="flex-1 space-y-6 overflow-y-auto py-6"
                x-data
                x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => $el.scrollTop = $el.scrollHeight).observe($el, { childList: true, subtree: true, characterData: true })"
            >
                @if ($this->messages->isEmpty() && $pending === null)
                    <div class="mx-auto max-w-md text-center">
                        <flux:icon name="chat-bubble-left-right" class="mx-auto size-8 text-zinc-400" />
                        <flux:heading class="mt-3">{{ __('Ask a question about your documents') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Answers cite the document and page they come from. If the answer is not in your documents, DocChat says so.') }}</flux:text>
                    </div>
                @endif

                @foreach ($this->messages as $message)
                    @if ($message->role === MessageRole::User)
                        <div class="flex justify-end" wire:key="message-{{ $message->id }}">
                            <div class="max-w-[80%] whitespace-pre-line rounded-2xl bg-zinc-800 px-4 py-2 text-white dark:bg-zinc-200 dark:text-zinc-900">{{ $message->content }}</div>
                        </div>
                    @else
                        <div class="flex" wire:key="message-{{ $message->id }}">
                            <div class="max-w-[85%] space-y-3">
                                @if ($message->content !== '')
                                    <div class="whitespace-pre-line rounded-2xl bg-zinc-100 px-4 py-3 text-zinc-900 dark:bg-zinc-700 dark:text-zinc-100">{{ $message->content }}</div>
                                @endif

                                @if ($message->error)
                                    <flux:callout variant="warning" icon="exclamation-triangle" :text="$message->error" />
                                @endif

                                @if (! empty($message->citations))
                                    <ul class="space-y-2">
                                        @foreach ($message->citations as $citation)
                                            <li wire:key="citation-{{ $message->id }}-{{ $citation['n'] }}" class="rounded-lg border border-zinc-200 text-sm dark:border-zinc-700">
                                                <button
                                                    type="button"
                                                    wire:click="expand({{ (int) $citation['chunk_id'] }})"
                                                    class="w-full px-3 py-2 text-start hover:bg-zinc-50 dark:hover:bg-zinc-800"
                                                >
                                                    <span class="font-medium">[{{ $citation['n'] }}] {{ $citation['title'] }}, {{ __('page') }} {{ $citation['page'] }}</span>
                                                    <span class="mt-1 block text-zinc-500 dark:text-zinc-400">{{ $citation['snippet'] }}</span>
                                                </button>

                                                @isset($expanded[(int) $citation['chunk_id']])
                                                    <div class="whitespace-pre-line border-t border-zinc-200 px-3 py-2 text-zinc-700 dark:border-zinc-700 dark:text-zinc-300">{{ $expanded[(int) $citation['chunk_id']] }}</div>
                                                @endisset
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                <flux:text class="text-xs" data-usage>
                                    @if ($message->prompt_tokens !== null)
                                        {{ $message->usage_estimated ? '~' : '' }}{{ number_format($message->prompt_tokens + (int) $message->completion_tokens) }} {{ __('tokens') }} ·
                                    @endif
                                    {{ number_format(($message->latency_ms ?? 0) / 1000, 1) }} s
                                </flux:text>
                            </div>
                        </div>
                    @endif
                @endforeach

                @if ($pending !== null)
                    <div class="flex justify-end" wire:key="pending-question">
                        <div class="max-w-[80%] whitespace-pre-line rounded-2xl bg-zinc-800 px-4 py-2 text-white dark:bg-zinc-200 dark:text-zinc-900">{{ $pending }}</div>
                    </div>
                    <div class="flex" wire:key="pending-answer">
                        <div class="max-w-[85%] whitespace-pre-line rounded-2xl bg-zinc-100 px-4 py-3 text-zinc-900 dark:bg-zinc-700 dark:text-zinc-100">
                            <span wire:stream="answer"><span class="animate-pulse text-zinc-500">{{ __('Thinking…') }}</span></span>
                        </div>
                    </div>
                @endif
            </div>

            <form wire:submit="send" class="border-t border-zinc-200 pt-3 dark:border-zinc-700">
                <div class="flex items-end gap-2">
                    <flux:textarea
                        wire:model="question"
                        rows="2"
                        resize="none"
                        :placeholder="__('Ask about your documents…')"
                        :disabled="$pending !== null"
                        x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send() }"
                    />
                    <flux:button type="submit" variant="primary" icon="paper-airplane" :disabled="$pending !== null" wire:loading.attr="disabled" wire:target="send,ask">
                        {{ __('Send') }}
                    </flux:button>
                </div>
                @error('question')
                    <flux:text class="mt-2 text-sm !text-red-600 dark:!text-red-400">{{ $message }}</flux:text>
                @enderror
            </form>
        @endif
    </section>
</div>
