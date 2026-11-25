<?php

use App\Enums\CallStatus;
use App\Enums\CallTransport;
use App\Enums\ConversationChannel;
use App\Enums\MessageRole;
use App\Exceptions\CallPlanException;
use App\Models\Call;
use App\Models\CallContact;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Services\Calls\CallingHours;
use App\Services\Calls\CallSettings;
use App\Services\Calls\OutboundCalls;
use App\Services\Conversations\ConversationSummarizer;
use App\Services\Conversations\SummaryException;
use App\Support\CurrentWorkspace;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Conversations')] class extends Component {
    use WithPagination;

    /** Channel groups for the filter: key => channels. */
    public const FILTERS = [
        'all' => [],
        'web' => ['web'],
        'widget' => ['widget'],
        'apps' => ['telegram', 'whatsapp', 'discord'],
        'calls' => ['voice', 'phone'],
        'api' => ['api'],
    ];

    public const PERIODS = ['7', '30', '90', 'all'];

    #[Url(as: 'tab')]
    public string $tab = 'conversations';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'channel')]
    public string $channel = 'all';

    #[Url(as: 'days')]
    public string $period = '30';

    #[Url(as: 'c')]
    public ?int $selectedId = null;

    // Schedule a call
    public string $contactMode = 'existing';

    public ?int $contactId = null;

    public string $newName = '';

    public string $newPhone = '';

    public string $newEmail = '';

    public bool $newConsent = false;

    public string $newConsentNote = '';

    public string $transport = 'web';

    public string $when = 'now';

    public string $date = '';

    public string $time = '10:00';

    public string $timezone = '';

    public string $purpose = '';

    public string $opening = '';

    public string $agenda = '';

    public bool $sendEmail = true;

    /** The call link just made, shown once. */
    public ?string $createdLink = null;

    // Reschedule
    public ?int $rescheduleId = null;

    public string $rescheduleDate = '';

    public string $rescheduleTime = '';

    public string $rescheduleTimezone = '';

    public function mount(): void
    {
        Gate::authorize('manage', $this->workspace);

        $this->date = now()->addDay()->toDateString();
        $this->tab = in_array($this->tab, ['conversations', 'calls'], true) ? $this->tab : 'conversations';
        $this->channel = array_key_exists($this->channel, self::FILTERS) ? $this->channel : 'all';
        $this->period = in_array($this->period, self::PERIODS, true) ? $this->period : '30';

        if (! CallSettings::twilioConfigured()) {
            $this->transport = 'web';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'channel', 'period'], true)) {
            $this->resetPage();
        }
    }

    public function select(int $id): void
    {
        $this->selectedId = $this->baseQuery()->whereKey($id)->exists() ? $id : null;
    }

    public function summarize(ConversationSummarizer $summarizer, bool $refresh = false): void
    {
        Gate::authorize('manage', $this->workspace);

        $conversation = $this->selected;

        if ($conversation === null) {
            return;
        }

        if (! $refresh && $summarizer->isCurrent($conversation)) {
            return;
        }

        $key = 'summaries:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('docchat.chat.rate_limit_per_minute', 10)))) {
            Flux::toast(variant: 'warning', text: __('Too many summaries at once. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]));

            return;
        }

        RateLimiter::hit($key, 60);

        try {
            $summarizer->summarize($conversation, $refresh);
        } catch (SummaryException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }

        unset($this->selected);
    }

    public function schedule(OutboundCalls $calls): void
    {
        Gate::authorize('manage', $this->workspace);
        $this->createdLink = null;

        $this->validate([
            'contactMode' => ['required', 'in:existing,new'],
            'contactId' => [$this->contactMode === 'existing' ? 'required' : 'nullable', 'integer'],
            'newName' => [$this->contactMode === 'new' ? 'required' : 'nullable', 'string', 'max:80'],
            'newPhone' => ['nullable', 'string', 'max:24'],
            'newEmail' => ['nullable', 'email', 'max:255'],
            'newConsentNote' => [$this->contactMode === 'new' ? 'required' : 'nullable', 'string', 'max:200'],
            'transport' => ['required', 'in:web,phone'],
            'when' => ['required', 'in:now,later'],
            'date' => [$this->when === 'later' ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'time' => [$this->when === 'later' ? 'required' : 'nullable', 'date_format:H:i'],
            'timezone' => ['nullable', 'timezone:all'],
            'purpose' => ['required', 'string', 'max:200'],
            'opening' => ['nullable', 'string', 'max:400'],
            'agenda' => ['nullable', 'string', 'max:2000'],
        ], [
            'contactId.required' => __('Choose a contact, or add a new one.'),
            'newConsentNote.required' => __('Say how and when they agreed, like "Asked for a call on our website, 25 Nov".'),
        ], [
            'newName' => __('name'),
            'purpose' => __('what the call is about'),
        ]);

        if ($this->contactMode === 'new' && ! $this->newConsent) {
            $this->addError('newConsent', __('Only people who agreed to be called can be called.'));

            return;
        }

        $contact = $this->contactMode === 'new' ? $this->createContact() : CallContact::query()->inWorkspace($this->workspace)->find($this->contactId);

        if ($contact === null) {
            if ($this->contactMode === 'existing') {
                $this->addError('contactId', __('Choose a contact, or add a new one.'));
            }

            return;
        }

        $at = null;

        if ($this->when === 'later') {
            $at = OutboundCalls::parseLocal($this->date, $this->time, $this->timezone);

            if ($at === null) {
                $this->addError('date', __('Pick a valid date and time.'));

                return;
            }
        }

        try {
            $result = $calls->create(
                $this->workspace,
                $contact,
                CallTransport::from($this->transport),
                $this->purpose,
                Auth::user(),
                $this->opening,
                OutboundCalls::cleanAgenda($this->agenda),
                $at,
                $this->timezone,
                $this->sendEmail,
            );
        } catch (CallPlanException $exception) {
            $this->addError(match ($exception->field) {
                'contact' => $this->contactMode === 'new' ? 'newPhone' : 'contactId',
                'when' => 'date',
                default => $exception->field,
            }, $exception->getMessage());

            return;
        }

        $this->createdLink = $result['link'];
        $name = $contact->name;
        $local = $result['call']->scheduledLocal();

        Flux::toast(variant: 'success', text: match (true) {
            $local !== null && $this->transport === 'phone' => __('Call to :name scheduled for :time.', ['name' => $name, 'time' => $local->isoFormat('ddd D MMM, HH:mm')]),
            $local !== null => __('Call link for :name scheduled for :time.', ['name' => $name, 'time' => $local->isoFormat('ddd D MMM, HH:mm')]).($result['emailed'] ? ' '.__('Emailed to :email.', ['email' => $contact->email]) : ''),
            $this->transport === 'phone' => __('Calling :name now.', ['name' => $name]),
            default => __('Call link created.').($result['emailed'] ? ' '.__('Emailed to :email.', ['email' => $contact->email]) : ''),
        });

        $this->reset('purpose', 'opening', 'agenda', 'newName', 'newPhone', 'newEmail', 'newConsent', 'newConsentNote');
        $this->contactMode = 'existing';
        $this->contactId = $contact->id;
        unset($this->contacts, $this->upcoming);
    }

    private function createContact(): ?CallContact
    {
        $phone = null;

        if (trim($this->newPhone) !== '') {
            $phone = CallContact::normalizePhone($this->newPhone);

            if ($phone === null) {
                $this->addError('newPhone', __('Use the international format, like +923001234567.'));

                return null;
            }
        }

        if ($this->transport === 'phone' && $phone === null) {
            $this->addError('newPhone', __('A phone call needs a phone number.'));

            return null;
        }

        if ($phone !== null && ($existing = CallContact::query()->inWorkspace($this->workspace)->where('phone', $phone)->first()) !== null) {
            $this->addError('newPhone', __('A contact with this number already exists: :name. Choose them from your contacts.', ['name' => $existing->name]));

            return null;
        }

        return CallContact::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => trim($this->newName),
            'phone' => $phone,
            'email' => trim($this->newEmail) ?: null,
            'consent_at' => now(),
            'consent_note' => trim($this->newConsentNote),
        ]);
    }

    public function cancelCall(int $id, OutboundCalls $calls): void
    {
        Gate::authorize('manage', $this->workspace);

        $call = Call::query()->inWorkspace($this->workspace)->find($id);

        if ($call !== null && $calls->cancel($call)) {
            Flux::toast(text: __('Call canceled.'));
        }

        unset($this->upcoming);
    }

    public function editSchedule(int $id): void
    {
        $call = Call::query()->inWorkspace($this->workspace)->find($id);

        if ($call === null || ! OutboundCalls::isReschedulable($call)) {
            return;
        }

        $local = $call->scheduledLocal();
        $this->rescheduleId = $call->id;
        $this->rescheduleDate = (string) $local?->toDateString();
        $this->rescheduleTime = (string) $local?->format('H:i');
        $this->rescheduleTimezone = (string) $call->timezone;
        $this->resetErrorBag();
        Flux::modal('reschedule')->show();
    }

    public function saveSchedule(OutboundCalls $calls): void
    {
        Gate::authorize('manage', $this->workspace);

        $this->validate([
            'rescheduleDate' => ['required', 'date_format:Y-m-d'],
            'rescheduleTime' => ['required', 'date_format:H:i'],
            'rescheduleTimezone' => ['nullable', 'timezone:all'],
        ]);

        $call = $this->rescheduleId === null ? null : Call::query()->inWorkspace($this->workspace)->find($this->rescheduleId);
        $at = OutboundCalls::parseLocal($this->rescheduleDate, $this->rescheduleTime, $this->rescheduleTimezone);

        if ($call === null || $at === null) {
            $this->addError('rescheduleDate', __('Pick a valid date and time.'));

            return;
        }

        try {
            $calls->reschedule($call, $at, $this->rescheduleTimezone);
        } catch (CallPlanException $exception) {
            $this->addError('rescheduleDate', $exception->getMessage());

            return;
        }

        Flux::modal('reschedule')->close();
        Flux::toast(variant: 'success', text: __('Call moved to :time.', ['time' => $call->scheduledLocal()?->isoFormat('ddd D MMM, HH:mm')]));
        $this->rescheduleId = null;
        unset($this->upcoming);
    }

    public function agendaPlaceholder(): string
    {
        return implode("\n", [
            __('Can you still make Tuesday at 3 pm?'),
            __('Do you need parking at the venue?'),
            __('Shall we send you a reminder the day before?'),
        ]);
    }

    /**
     * Who the conversation was with.
     */
    public function who(Conversation $conversation): string
    {
        if ($conversation->call !== null) {
            return $conversation->call->counterpart();
        }

        return match ($conversation->channel) {
            ConversationChannel::Web => $conversation->user?->name ?? __('Former member'),
            ConversationChannel::Widget => __('Website visitor'),
            ConversationChannel::Telegram, ConversationChannel::WhatsApp, ConversationChannel::Discord => __(':app user', ['app' => $conversation->channel->label()]),
            ConversationChannel::Api => __('API'),
            default => $conversation->channel->label(),
        };
    }

    /**
     * @return Builder<Conversation>
     */
    private function baseQuery(): Builder
    {
        return Conversation::query()
            ->inWorkspace($this->workspace)
            ->where('channel', '!=', ConversationChannel::Eval->value)
            ->whereHas('messages');
    }

    /**
     * @return LengthAwarePaginator<int, Conversation>
     */
    #[Computed]
    public function conversations(): LengthAwarePaginator
    {
        $query = $this->baseQuery()
            ->with(['user', 'call.contact'])
            ->withCount('messages');

        if (($channels = self::FILTERS[$this->channel] ?? []) !== []) {
            $query->whereIn('channel', $channels);
        }

        if ($this->period !== 'all') {
            $query->where('updated_at', '>=', now()->subDays((int) $this->period));
        }

        $search = trim($this->search);

        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($search, 0, 100)).'%';

            $query->where(function (Builder $q) use ($like): void {
                $q->where('title', 'ilike', $like)
                    ->orWhere('summary', 'ilike', $like)
                    ->orWhereHas('messages', fn (Builder $m) => $m->where('content', 'ilike', $like))
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'ilike', $like))
                    ->orWhereHas('call.contact', fn (Builder $c) => $c->where('name', 'ilike', $like)->orWhere('phone', 'ilike', $like));
            });
        }

        return $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(15);
    }

    #[Computed]
    public function selected(): ?Conversation
    {
        return $this->selectedId === null ? null : $this->baseQuery()->with(['messages', 'user', 'call.contact', 'call.createdBy'])->find($this->selectedId);
    }

    /**
     * @return Collection<int, CallContact>
     */
    #[Computed]
    public function contacts(): Collection
    {
        return CallContact::query()->inWorkspace($this->workspace)->orderBy('name')->get();
    }

    /**
     * Outbound calls that haven't finished: scheduled, waiting for the link
     * to be opened, or ringing now. Soonest first.
     *
     * @return Collection<int, Call>
     */
    #[Computed]
    public function upcoming(): Collection
    {
        return Call::query()->inWorkspace($this->workspace)
            ->where('direction', 'outbound')
            ->whereIn('status', [CallStatus::Scheduled->value, CallStatus::Waiting->value, CallStatus::Queued->value, CallStatus::Ringing->value, CallStatus::InProgress->value])
            ->with('contact')
            ->orderByRaw('scheduled_at is null, scheduled_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    #[Computed]
    public function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->require();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Conversations') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Every chat, bot chat and call in :workspace, with one-click summaries. Schedule calls with an agenda from here too.', ['workspace' => $this->workspace->name]) }}</flux:text>
        </div>
        <div class="flex gap-2" role="tablist">
            <flux:button size="sm" :variant="$tab === 'conversations' ? 'primary' : 'ghost'" icon="chat-bubble-left-right" wire:click="$set('tab', 'conversations')" data-test="tab-conversations">{{ __('Conversations') }}</flux:button>
            <flux:button size="sm" :variant="$tab === 'calls' ? 'primary' : 'ghost'" icon="calendar-days" wire:click="$set('tab', 'calls')" data-test="tab-calls">{{ __('Schedule calls') }}@if ($this->upcoming->isNotEmpty()) <span class="ms-1 text-xs opacity-80">({{ $this->upcoming->count() }})</span>@endif</flux:button>
        </div>
    </div>

    @if ($tab === 'conversations')
        <div class="grid gap-6 lg:grid-cols-[minmax(0,380px)_minmax(0,1fr)]">
            {{-- List --}}
            <div class="flex min-w-0 flex-col gap-3">
                <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Search messages, names, numbers')" data-test="search" />
                <div class="grid grid-cols-2 gap-2">
                    <flux:select wire:model.live="channel" :aria-label="__('Channel')" data-test="channel-filter">
                        <flux:select.option value="all">{{ __('All channels') }}</flux:select.option>
                        <flux:select.option value="web">{{ __('Member chats') }}</flux:select.option>
                        <flux:select.option value="widget">{{ __('Website widget') }}</flux:select.option>
                        <flux:select.option value="apps">{{ __('Telegram, WhatsApp, Discord') }}</flux:select.option>
                        <flux:select.option value="calls">{{ __('Calls') }}</flux:select.option>
                        <flux:select.option value="api">{{ __('API') }}</flux:select.option>
                    </flux:select>
                    <flux:select wire:model.live="period" :aria-label="__('Period')">
                        <flux:select.option value="7">{{ __('Last 7 days') }}</flux:select.option>
                        <flux:select.option value="30">{{ __('Last 30 days') }}</flux:select.option>
                        <flux:select.option value="90">{{ __('Last 90 days') }}</flux:select.option>
                        <flux:select.option value="all">{{ __('All time') }}</flux:select.option>
                    </flux:select>
                </div>

                <div class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="conversation-list">
                    @forelse ($this->conversations as $conversation)
                        <button type="button" wire:key="conv-{{ $conversation->id }}" wire:click="select({{ $conversation->id }})"
                            @class(['block w-full p-3 text-start hover:bg-zinc-50 dark:hover:bg-zinc-800', 'bg-zinc-100 dark:bg-zinc-800' => $selectedId === $conversation->id])>
                            <div class="flex items-center gap-2">
                                <div class="min-w-0 flex-1 truncate text-sm font-medium">{{ $this->who($conversation) }}</div>
                                <div class="shrink-0 text-xs text-zinc-500">{{ $conversation->updated_at?->diffForHumans(short: true) }}</div>
                            </div>
                            <div class="mt-0.5 truncate text-sm text-zinc-600 dark:text-zinc-400">{{ $conversation->call?->purpose ?: ($conversation->title ?: __('Untitled')) }}</div>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <flux:badge size="sm" :icon="$conversation->call ? 'phone' : 'chat-bubble-left'">{{ $conversation->channel->label() }}</flux:badge>
                                <span class="text-xs text-zinc-500">{{ trans_choice(':count message|:count messages', $conversation->messages_count) }}</span>
                                @if ($conversation->call?->duration_seconds)
                                    <span class="text-xs text-zinc-500">· {{ gmdate($conversation->call->duration_seconds >= 3600 ? 'H:i:s' : 'i:s', $conversation->call->duration_seconds) }}</span>
                                @endif
                                @if ($conversation->summary)
                                    <flux:badge size="sm" color="indigo" icon="sparkles">{{ __('Summary') }}</flux:badge>
                                @endif
                            </div>
                        </button>
                    @empty
                        <div class="p-6 text-center text-sm text-zinc-500">{{ __('No conversations match.') }}</div>
                    @endforelse
                </div>
                {{ $this->conversations->links() }}
            </div>

            {{-- Detail --}}
            <div class="min-w-0">
                @if ($conversation = $this->selected)
                    @php($call = $conversation->call)
                    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="conversation-detail">
                        <div class="flex flex-wrap items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <flux:heading size="lg">{{ $this->who($conversation) }}</flux:heading>
                                <flux:text class="mt-0.5">
                                    {{ $conversation->channel->label() }} · {{ $conversation->created_at?->isoFormat('D MMM YYYY, HH:mm') }}
                                    @if ($call) · {{ $call->status->label() }}@if ($call->duration_seconds !== null) · {{ gmdate($call->duration_seconds >= 3600 ? 'H:i:s' : 'i:s', $call->duration_seconds) }}@endif @endif
                                </flux:text>
                            </div>
                            <flux:button icon="sparkles" variant="primary" size="sm" wire:click="summarize" wire:loading.attr="disabled" wire:target="summarize" data-test="summarize">
                                <span wire:loading.remove wire:target="summarize">{{ $conversation->summary ? __('Summary') : __('Summarize') }}</span>
                                <span wire:loading wire:target="summarize">{{ __('Summarizing…') }}</span>
                            </flux:button>
                        </div>

                        @if ($call && ($call->purpose || $call->agendaPoints() !== []))
                            <div class="rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800/60">
                                @if ($call->purpose)
                                    <div><span class="font-medium">{{ __('About') }}:</span> {{ $call->purpose }}</div>
                                @endif
                                @if ($call->agendaPoints() !== [])
                                    <div class="mt-1 font-medium">{{ __('Agenda') }}</div>
                                    <ol class="ms-5 list-decimal text-zinc-600 dark:text-zinc-300">
                                        @foreach ($call->agendaPoints() as $point)
                                            <li>{{ $point }}</li>
                                        @endforeach
                                    </ol>
                                @endif
                            </div>
                        @endif

                        @if ($conversation->summary)
                            <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-3 dark:border-indigo-900 dark:bg-indigo-950/50" data-test="summary">
                                <div class="mb-1 flex items-center gap-2">
                                    <flux:icon.sparkles variant="micro" class="text-indigo-500" />
                                    <span class="text-xs font-medium text-indigo-700 dark:text-indigo-300">{{ __('AI summary') }} · {{ $conversation->summarized_at?->diffForHumans() }}</span>
                                    <flux:spacer />
                                    @if ((int) $conversation->summary_message_id !== (int) $conversation->messages->last()?->id)
                                        <flux:button size="xs" variant="ghost" wire:click="summarize(true)">{{ __('Update with new messages') }}</flux:button>
                                    @else
                                        <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="summarize(true)" :aria-label="__('Summarize again')" />
                                    @endif
                                </div>
                                <div class="whitespace-pre-line text-sm">{{ $conversation->summary }}</div>
                            </div>
                        @endif

                        <div class="max-h-[65vh] space-y-2 overflow-y-auto" data-test="transcript">
                            @foreach ($conversation->messages as $message)
                                <div @class(['rounded-lg p-2 text-sm', 'bg-zinc-100 dark:bg-zinc-800' => $message->role === MessageRole::Assistant, 'bg-indigo-50 dark:bg-indigo-950' => $message->role === MessageRole::User]) wire:key="m-{{ $message->id }}">
                                    <div class="flex text-xs font-medium text-zinc-500">
                                        <span>{{ $message->role === MessageRole::User ? ($call ? __('Caller') : $this->who($conversation)) : ($call ? __('Agent') : __('Assistant')) }}</span>
                                        <flux:spacer />
                                        <span>{{ $message->created_at?->format('H:i') }}</span>
                                    </div>
                                    <div class="whitespace-pre-line">{{ $message->content !== '' ? $message->content : $message->error }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="flex h-full min-h-48 items-center justify-center rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700">
                        {{ __('Pick a conversation to read it and get its summary.') }}
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            {{-- Schedule form --}}
            <form wire:submit="schedule" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
                x-data x-init="if (! $wire.timezone) { $wire.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC' }">
                <flux:heading>{{ __('Call someone') }}</flux:heading>

                <flux:radio.group wire:model.live="contactMode" :label="__('Who')">
                    <flux:radio value="existing" :label="__('A saved contact')" />
                    <flux:radio value="new" :label="__('Someone new')" data-test="contact-new" />
                </flux:radio.group>

                @if ($contactMode === 'existing')
                    <flux:select wire:model="contactId" :label="__('Contact')" data-test="contact-pick">
                        <flux:select.option value="">{{ __('Choose a contact who agreed') }}</flux:select.option>
                        @foreach ($this->contacts->filter->mayBeCalled() as $contact)
                            <flux:select.option :value="$contact->id">{{ $contact->name }}{{ $contact->phone ? ' · '.$contact->phone : '' }}{{ $contact->email ? ' · '.$contact->email : '' }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <div class="grid gap-3 sm:grid-cols-3">
                        <flux:input wire:model="newName" :label="__('Name')" data-test="new-name" />
                        <flux:input wire:model="newPhone" :label="__('Phone')" placeholder="+923001234567" data-test="new-phone" />
                        <flux:input wire:model="newEmail" type="email" :label="__('Email')" data-test="new-email" />
                    </div>
                    <flux:checkbox wire:model="newConsent" :label="__('This person agreed to be called')" data-test="new-consent" />
                    <flux:input wire:model="newConsentNote" :label="__('How and when they agreed')" :placeholder="__('Asked for a call on our website, 25 Nov')" data-test="new-consent-note" />
                @endif

                <flux:radio.group wire:model.live="transport" :label="__('How')">
                    <flux:radio value="web" :label="__('Invitation link')" :description="__('They open a link and talk in the browser. Free, works anywhere. Emailed if they have an email, or copy it.')" />
                    <flux:radio value="phone" :label="__('Phone call (Twilio)')" :description="\App\Services\Calls\CallSettings::twilioConfigured() ? __('Rings their phone. Calling hours: :hours.', ['hours' => CallingHours::describe()]) : __('Needs the free Twilio trial set up on the server (TWILIO_* in .env).')" :disabled="! \App\Services\Calls\CallSettings::twilioConfigured()" data-test="transport-phone" />
                </flux:radio.group>

                <flux:radio.group wire:model.live="when" :label="__('When')">
                    <flux:radio value="now" :label="__('Now')" />
                    <flux:radio value="later" :label="__('At a set time')" data-test="when-later" />
                </flux:radio.group>

                @if ($when === 'later')
                    <div class="grid gap-3 sm:grid-cols-3">
                        <flux:input type="date" wire:model="date" :label="__('Date')" :min="now()->toDateString()" data-test="date" />
                        <flux:input type="time" wire:model="time" :label="__('Time')" data-test="time" />
                        <flux:select wire:model="timezone" :label="__('Time zone')" data-test="timezone">
                            @foreach ($this->timezones as $zone)
                                <flux:select.option :value="$zone">{{ str_replace('_', ' ', $zone) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <flux:text class="text-xs">{{ __('Use the person\'s own time zone. Phone calls ring at that time; invitation links open :minutes minutes before and are emailed again when it\'s time.', ['minutes' => Call::EARLY_MINUTES]) }}</flux:text>
                @endif

                <flux:input wire:model="purpose" :label="__('What the call is about')" :placeholder="__('your appointment next week')" data-test="purpose" />
                <flux:textarea wire:model="agenda" rows="4" :label="__('Agenda (optional)')" :description="__('One point per line, written the way the assistant should ask it. It asks them in order, answers questions from your documents in between, and the summary reports each answer. Up to :count points.', ['count' => \App\Services\Calls\OutboundCalls::MAX_AGENDA_POINTS])" :placeholder="$this->agendaPlaceholder()" data-test="agenda" />
                <flux:textarea wire:model="opening" rows="2" :label="__('Opening line (optional)')" :placeholder="__('Thanks for booking with us.')" />

                @if ($transport === 'web')
                    <flux:checkbox wire:model="sendEmail" :label="__('Email the link to them')" />
                @endif

                <flux:text class="text-xs">{{ __('Every call starts by saying it is a virtual assistant, and anyone can say "stop calling" to opt out for good.') }}</flux:text>

                <div>
                    <flux:button type="submit" variant="primary" :icon="$when === 'later' ? 'calendar-days' : 'phone-arrow-up-right'" data-test="schedule">
                        {{ $when === 'later' ? __('Schedule call') : ($transport === 'phone' ? __('Call now') : __('Create call link')) }}
                    </flux:button>
                </div>

                @if ($createdLink)
                    <div class="space-y-2" x-data="{ copied: false }">
                        <flux:text>{{ __('Send this link to them. It is only shown once.') }}</flux:text>
                        <pre class="overflow-x-auto rounded-lg bg-zinc-100 p-3 text-xs dark:bg-zinc-800"><code x-ref="link" data-test="call-link">{{ $createdLink }}</code></pre>
                        <flux:button size="sm" icon="clipboard" x-on:click="navigator.clipboard.writeText($refs.link.textContent); copied = true; setTimeout(() => copied = false, 2000)">
                            <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'">{{ __('Copy') }}</span>
                        </flux:button>
                    </div>
                @endif
            </form>

            {{-- Upcoming --}}
            <div class="flex min-w-0 flex-col gap-3">
                <flux:heading>{{ __('Upcoming and open calls') }}</flux:heading>
                <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="upcoming">
                    @forelse ($this->upcoming as $call)
                        @php($local = $call->scheduledLocal())
                        <div class="flex flex-col gap-1.5 p-3 text-sm" wire:key="up-{{ $call->id }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:badge size="sm" :icon="$call->transport === CallTransport::Phone ? 'phone' : 'link'">{{ $call->transport === CallTransport::Phone ? __('Phone') : __('Link') }}</flux:badge>
                                <div class="min-w-0 flex-1 truncate font-medium">{{ $call->counterpart() }}</div>
                                <flux:badge size="sm" :color="$call->status === CallStatus::Scheduled ? 'indigo' : 'zinc'">{{ $call->status->label() }}</flux:badge>
                            </div>
                            <div class="text-zinc-600 dark:text-zinc-400">
                                @if ($local)
                                    {{ $local->isoFormat('ddd D MMM, HH:mm') }} <span class="text-xs">({{ str_replace('_', ' ', $local->getTimezone()->getName()) }})</span> ·
                                @endif
                                {{ $call->purpose }}
                            </div>
                            @if ($call->agendaPoints() !== [])
                                <div class="text-xs text-zinc-500">{{ trans_choice(':count agenda point|:count agenda points', count($call->agendaPoints())) }}</div>
                            @endif
                            @if ($call->status->isPending())
                                <div class="flex gap-2">
                                    @if (\App\Services\Calls\OutboundCalls::isReschedulable($call))
                                        <flux:button size="xs" icon="clock" wire:click="editSchedule({{ $call->id }})">{{ __('Change time') }}</flux:button>
                                    @endif
                                    <flux:button size="xs" variant="ghost" wire:click="cancelCall({{ $call->id }})" wire:confirm="{{ __('Cancel this call?') }}" data-test="cancel-{{ $call->id }}">{{ __('Cancel') }}</flux:button>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="p-6 text-center text-sm text-zinc-500">{{ __('No calls planned. Finished calls and their transcripts are under Conversations.') }}</div>
                    @endforelse
                </div>
                <flux:text class="text-xs">{{ __('Contacts, voice and phone number settings are on the') }} <flux:link :href="route('workspace.calls')" wire:navigate>{{ __('Calls settings page') }}</flux:link>.</flux:text>
            </div>
        </div>
    @endif

    <flux:modal name="reschedule" class="w-full max-w-md">
        <form wire:submit="saveSchedule" class="space-y-4">
            <flux:heading size="lg">{{ __('Change the time') }}</flux:heading>
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:input type="date" wire:model="rescheduleDate" :label="__('Date')" />
                <flux:input type="time" wire:model="rescheduleTime" :label="__('Time')" />
            </div>
            <flux:select wire:model="rescheduleTimezone" :label="__('Time zone')">
                @foreach ($this->timezones as $zone)
                    <flux:select.option :value="$zone">{{ str_replace('_', ' ', $zone) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
        </form>
    </flux:modal>
</div>
