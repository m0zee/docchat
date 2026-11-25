<?php

use App\Enums\DocumentStatus;
use App\Enums\ConversationChannel;
use App\Enums\MessageRole;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Rag\AnswerService;
use App\Services\Rag\DevicePrompt;
use App\Support\ChatRateLimit;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
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

    public function send(ChatRateLimit $limit): void
    {
        $this->validate(['question' => ['required', 'string', 'max:2000']]);

        if ($this->pending !== null) {
            return;
        }

        $wait = $limit->attempt($this->user());

        if ($wait !== null) {
            $this->addError('question', __("You've sent too many questions. Try again in :seconds seconds.", ['seconds' => $wait]));

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

    /**
     * In-browser AI: search on the server, then hand the prompt to the
     * browser, which writes the answer with its own model.
     *
     * @return array<string, mixed>|null
     */
    public function sendOnDevice(ChatRateLimit $limit, AnswerService $answers): ?array
    {
        abort_unless((bool) config('docchat.device.enabled'), 404);

        $this->validate(['question' => ['required', 'string', 'max:2000']]);

        if ($this->pending !== null) {
            return null;
        }

        $wait = $limit->attempt($this->user());

        if ($wait !== null) {
            $this->addError('question', __("You've sent too many questions. Try again in :seconds seconds.", ['seconds' => $wait]));

            return null;
        }

        $conversation = $this->conversation ?? $this->startConversation();
        Gate::authorize('update', $conversation);

        $question = trim($this->question);
        $this->question = '';

        $result = $answers->prepareForDevice($conversation, $question);

        if (! $result instanceof DevicePrompt) {
            unset($this->messages, $this->conversations);

            return ['status' => 'answered'];
        }

        $this->pending = $question;

        return ['status' => 'generate'] + $result->toArray();
    }

    public function completeOnDevice(AnswerService $answers, string $token, string $answer, ?string $error = null): void
    {
        $conversation = $this->conversation;

        if ($conversation === null) {
            return;
        }

        Gate::authorize('update', $conversation);

        $answers->completeFromDevice(
            $conversation,
            $token,
            $answer,
            $error === null || trim($error) === '' ? null : __('The AI on this device failed: :error', ['error' => mb_substr($error, 0, 200)]),
        );

        $this->pending = null;
        unset($this->messages, $this->conversations);
    }

    public function rename(string $title): void
    {
        $conversation = $this->conversation;

        if ($conversation === null) {
            return;
        }

        Gate::authorize('update', $conversation);

        $title = trim($title);

        if ($title !== '') {
            $conversation->update(['title' => mb_substr($title, 0, 80)]);
        }

        unset($this->conversation, $this->conversations);
    }

    public function deleteConversation(): void
    {
        $conversation = $this->conversation;

        if ($conversation === null) {
            return;
        }

        Gate::authorize('delete', $conversation);

        $conversation->delete();

        $this->redirectRoute('chat.index', navigate: true);
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

        $chunk = Chunk::query()->inWorkspace($this->workspace())->find($chunkId);

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

        return $this->myConversations()->find($this->conversationId);
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
        return $this->myConversations()->latest('updated_at')->latest('id')->limit(50)->get();
    }

    /**
     * @return Collection<int, Document>
     */
    #[Computed]
    public function readyDocuments(): Collection
    {
        return Document::query()->inWorkspace($this->workspace())
            ->where('status', DocumentStatus::Ready)
            ->orderBy('title')
            ->get(['id', 'title']);
    }

    private function startConversation(): Conversation
    {
        $conversation = Conversation::forChannel($this->workspace(), ConversationChannel::Web, user: $this->user());
        $conversation->documents()->sync($this->allowedDocumentIds($this->documentIds));

        $this->conversationId = $conversation->id;
        unset($this->conversation, $this->conversations);

        $this->js('history.replaceState(history.state, "", '.json_encode(route('chat.show', $conversation)).')');

        return $conversation;
    }

    /**
     * Keep only ids of ready documents in this workspace; never trust the browser.
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

        return Document::query()->inWorkspace($this->workspace())
            ->where('status', DocumentStatus::Ready)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The signed-in member's own chats in the current workspace.
     *
     * @return Builder<Conversation>
     */
    private function myConversations(): Builder
    {
        return Conversation::query()
            ->inWorkspace($this->workspace())
            ->where('channel', ConversationChannel::Web->value)
            ->where('user_id', $this->user()->id);
    }

    private function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->require();
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

    <section
        class="flex min-w-0 flex-1 flex-col"
        x-data="deviceAi(@js(['webllmUrl' => config('docchat.device.webllm_url'), 'webllmModel' => config('docchat.device.webllm_model')]))"
    >
        @if ($this->readyDocuments->isEmpty())
            <div class="m-auto max-w-md rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
                <flux:icon name="document-plus" class="mx-auto size-8 text-zinc-400" />
                <flux:heading class="mt-3">{{ __('Upload a document first') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Chat answers come only from your documents, so you need at least one ready document.') }}</flux:text>
                <flux:button :href="route('documents.index')" class="mt-4" wire:navigate>{{ __('Go to Documents') }}</flux:button>
            </div>
        @else
            <div class="flex flex-wrap items-center gap-3 border-b border-zinc-200 pb-3 dark:border-zinc-700">
                <div class="flex min-w-0 items-center gap-1" x-data="{ editing: false, title: @js($this->conversation?->title ?? '') }">
                    <flux:heading size="lg" class="truncate" x-show="! editing">{{ $this->conversation?->title ?? __('New chat') }}</flux:heading>

                    @if ($this->conversation)
                        <form x-show="editing" x-cloak x-on:submit.prevent="$wire.rename(title); editing = false" class="flex items-center gap-2">
                            <flux:input size="sm" x-model="title" maxlength="80" x-ref="title" />
                            <flux:button size="sm" type="submit">{{ __('Save') }}</flux:button>
                        </form>

                        <flux:dropdown x-show="! editing">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Conversation options')" />
                            <flux:menu>
                                <flux:menu.item icon="pencil" x-on:click="editing = true; $nextTick(() => $refs.title.querySelector('input')?.focus())">{{ __('Rename') }}</flux:menu.item>
                                <flux:menu.item icon="trash" variant="danger" wire:click="deleteConversation" wire:confirm="{{ __('Delete this conversation and its messages?') }}">{{ __('Delete') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>
                <flux:spacer />
                @if (config('docchat.device.enabled'))
                    <flux:dropdown position="bottom" align="end" x-show="checked && (chrome !== 'unavailable' || webgpu)" x-cloak data-device-picker>
                        <flux:button size="sm" icon="cpu-chip" icon:trailing="chevron-down">
                            <span x-text="engine === 'server' ? @js(__('AI: server')) : @js(__('AI: this device'))"></span>
                        </flux:button>

                        <flux:menu>
                            <flux:menu.heading>{{ __('Who writes the answers') }}</flux:menu.heading>
                            <flux:menu.item x-on:click="choose('server')" data-engine="server"><span x-text="(engine === 'server' ? '\u2713 ' : '') + @js(__('DocChat server (default)'))"></span></flux:menu.item>
                            <flux:menu.item x-show="chrome !== 'unavailable'" x-on:click="choose('chrome')" data-engine="chrome"><span x-text="(engine === 'chrome' ? '\u2713 ' : '') + @js(__('This device: Chrome built-in AI'))"></span></flux:menu.item>
                            <flux:menu.item x-show="webgpu" x-on:click="choose('webllm')" data-engine="webllm"><span x-text="(engine === 'webllm' ? '\u2713 ' : '') + @js(__('This device: WebLLM (about 1 GB download)'))"></span></flux:menu.item>
                            <flux:menu.separator />
                            <div class="max-w-72 px-2 py-1 text-xs text-zinc-500">{{ __('On-device answers are free and the question never reaches an AI company, but they are slower and less accurate than the server. The first answer downloads the model once.') }}</div>
                        </flux:menu>
                    </flux:dropdown>
                @endif
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
                                    @if ($message->cache_hit)
                                        · <span data-test="from-cache">{{ __('from cache') }}</span>
                                    @endif
                                    @if ($message->ai_source === 'device')
                                        · <span data-test="on-device">{{ __('written on this device') }}</span>
                                    @elseif ($message->ai_source === 'workspace')
                                        · <span data-test="own-key">{{ __('your workspace key') }}</span>
                                    @endif
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
                            <span wire:stream="answer" data-device-answer><span class="animate-pulse text-zinc-500">{{ __('Thinking…') }}</span></span>
                            <div x-show="progress !== null" x-cloak class="mt-2 text-xs text-zinc-500">
                                <span x-text="progressText"></span> <span x-text="progress + '%'"></span>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <form x-on:submit.prevent="ask()" class="border-t border-zinc-200 pt-3 dark:border-zinc-700">
                <div class="flex items-end gap-2">
                    <flux:textarea
                        wire:model="question"
                        rows="2"
                        resize="none"
                        :placeholder="__('Ask about your documents…')"
                        :disabled="$pending !== null"
                        x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); ask() }"
                    />
                    <flux:button type="submit" variant="primary" icon="paper-airplane" :disabled="$pending !== null" wire:loading.attr="disabled" wire:target="send,ask,sendOnDevice,completeOnDevice" x-bind:disabled="busy">
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
