<?php

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\CallTransport;
use App\Enums\MessageRole;
use App\Jobs\PlaceTwilioCallJob;
use App\Mail\CallLinkMail;
use App\Models\Call;
use App\Models\CallContact;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Calls\CallingHours;
use App\Services\Calls\CallSettings;
use App\Support\CurrentWorkspace;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Calls')] class extends Component {
    // Settings
    public bool $widgetCalls = true;

    public string $greeting = '';

    public string $handoff = '';

    public string $phoneNumber = '';

    // New contact
    public string $contactName = '';

    public string $contactPhone = '';

    public string $contactEmail = '';

    public bool $contactConsent = false;

    public string $contactConsentNote = '';

    // New outbound call
    public ?int $callContactId = null;

    public string $callTransport = 'web';

    public string $callPurpose = '';

    public string $callOpening = '';

    public bool $callEmail = true;

    /** The call link just created, shown once. */
    public ?string $createdLink = null;

    public ?int $transcriptCallId = null;

    public function mount(): void
    {
        Gate::authorize('manage', $this->workspace);

        $settings = CallSettings::for($this->workspace);
        $this->widgetCalls = $settings['widget_calls'];
        $this->greeting = (string) $settings['greeting'];
        $this->handoff = (string) $settings['handoff'];
        $this->phoneNumber = (string) $settings['phone_number'];
    }

    public function saveSettings(): void
    {
        Gate::authorize('manage', $this->workspace);

        $this->validate([
            'greeting' => ['nullable', 'string', 'max:400'],
            'handoff' => ['nullable', 'string', 'max:200'],
            'phoneNumber' => ['nullable', 'string', 'max:24'],
        ]);

        $number = null;

        if (trim($this->phoneNumber) !== '') {
            $number = CallContact::normalizePhone($this->phoneNumber);

            if ($number === null) {
                $this->addError('phoneNumber', __('Use the international format, like +14155550123.'));

                return;
            }

            $owner = CallSettings::workspaceForNumber($number);

            if ($owner !== null && $owner->id !== $this->workspace->id) {
                $this->addError('phoneNumber', __('Another workspace already uses this number.'));

                return;
            }
        }

        CallSettings::save($this->workspace, [
            'widget_calls' => $this->widgetCalls,
            'greeting' => trim($this->greeting) ?: null,
            'handoff' => trim($this->handoff) ?: null,
            'phone_number' => $number,
        ]);

        $this->phoneNumber = (string) $number;
        unset($this->workspace);
        Flux::toast(variant: 'success', text: __('Call settings saved.'));
    }

    public function addContact(): void
    {
        Gate::authorize('manage', $this->workspace);

        $this->validate([
            'contactName' => ['required', 'string', 'max:80'],
            'contactPhone' => ['nullable', 'string', 'max:24'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'contactConsentNote' => [$this->contactConsent ? 'required' : 'nullable', 'string', 'max:200'],
        ], [
            'contactConsentNote.required' => __('Say how and when they agreed, like "Asked for a call on our website, 25 Nov".'),
        ]);

        $phone = null;

        if (trim($this->contactPhone) !== '') {
            $phone = CallContact::normalizePhone($this->contactPhone);

            if ($phone === null) {
                $this->addError('contactPhone', __('Use the international format, like +923001234567.'));

                return;
            }

            if (CallContact::query()->inWorkspace($this->workspace)->where('phone', $phone)->exists()) {
                $this->addError('contactPhone', __('A contact with this number already exists.'));

                return;
            }
        }

        if ($phone === null && trim($this->contactEmail) === '') {
            $this->addError('contactPhone', __('Add a phone number or an email address.'));

            return;
        }

        CallContact::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => trim($this->contactName),
            'phone' => $phone,
            'email' => trim($this->contactEmail) ?: null,
            'consent_at' => $this->contactConsent ? now() : null,
            'consent_note' => $this->contactConsent ? trim($this->contactConsentNote) : null,
        ]);

        $this->reset('contactName', 'contactPhone', 'contactEmail', 'contactConsent', 'contactConsentNote');
        unset($this->contacts);
        Flux::toast(variant: 'success', text: __('Contact added.'));
    }

    public function deleteContact(int $id): void
    {
        Gate::authorize('manage', $this->workspace);

        CallContact::query()->inWorkspace($this->workspace)->whereKey($id)->delete();
        unset($this->contacts);
    }

    public function startCall(): void
    {
        Gate::authorize('manage', $this->workspace);

        $this->validate([
            'callContactId' => ['required', 'integer'],
            'callTransport' => ['required', Rule::in(['web', 'phone'])],
            'callPurpose' => ['required', 'string', 'max:200'],
            'callOpening' => ['nullable', 'string', 'max:400'],
        ]);

        $contact = CallContact::query()->inWorkspace($this->workspace)->find($this->callContactId);

        if ($contact === null || ! $contact->mayBeCalled()) {
            $this->addError('callContactId', __('Only contacts who agreed to be called, and did not opt out, can be called.'));

            return;
        }

        $phone = $this->callTransport === 'phone';

        if ($phone) {
            if (! CallSettings::twilioConfigured() || ! filled(config('docchat.calls.twilio.from_number'))) {
                $this->addError('callTransport', __('The phone line is not set up on this server (TWILIO_* in .env).'));

                return;
            }

            if ($contact->phone === null) {
                $this->addError('callContactId', __('This contact has no phone number.'));

                return;
            }

            if (! CallingHours::allows()) {
                $this->addError('callTransport', __('Phone calls only start inside the calling hours: :hours.', ['hours' => CallingHours::describe()]));

                return;
            }
        }

        $call = Call::query()->create([
            'workspace_id' => $this->workspace->id,
            'contact_id' => $contact->id,
            'created_by_user_id' => Auth::id(),
            'direction' => CallDirection::Outbound,
            'transport' => $phone ? CallTransport::Phone : CallTransport::Web,
            'status' => $phone ? CallStatus::Queued : CallStatus::Waiting,
            'purpose' => trim($this->callPurpose),
            'opening' => trim($this->callOpening) ?: null,
            'to_number' => $phone ? $contact->phone : null,
            'expires_at' => $phone ? null : now()->addDays(max(1, (int) config('docchat.calls.link_valid_days'))),
        ]);

        if ($phone) {
            PlaceTwilioCallJob::dispatch($call->id);
            Flux::toast(variant: 'success', text: __('Calling :name now.', ['name' => $contact->name]));
        } else {
            // APP_URL, not the address this page was opened on: the link is sent to someone else.
            $this->createdLink = rtrim((string) config('app.url'), '/').'/call/'.$call->issueToken();

            if ($this->callEmail && $contact->email !== null) {
                Mail::to($contact->email)->send(new CallLinkMail($call->load('workspace', 'contact'), $this->createdLink));
                Flux::toast(variant: 'success', text: __('Call link emailed to :email.', ['email' => $contact->email]));
            } else {
                Flux::toast(variant: 'success', text: __('Call link created.'));
            }
        }

        $this->reset('callPurpose', 'callOpening');
        unset($this->calls);
    }

    public function cancelCall(int $id): void
    {
        Gate::authorize('manage', $this->workspace);

        $call = Call::query()->inWorkspace($this->workspace)->find($id);

        if ($call !== null && in_array($call->status, [CallStatus::Waiting, CallStatus::Queued], true)) {
            $call->finish(CallStatus::Canceled, 'canceled_by_owner');
        }

        unset($this->calls);
    }

    /**
     * A test call in this browser, as a website visitor would have it.
     */
    public function testCall(): void
    {
        Gate::authorize('manage', $this->workspace);

        $call = Call::query()->create([
            'workspace_id' => $this->workspace->id,
            'created_by_user_id' => Auth::id(),
            'direction' => CallDirection::Inbound,
            'transport' => CallTransport::Web,
            'status' => CallStatus::Queued,
            'purpose' => __('Test call'),
            'expires_at' => now()->addSeconds(Call::maxSeconds() + 300),
        ]);

        $this->dispatch('docchat-test-call', token: $call->issueToken());
        unset($this->calls);
    }

    public function showTranscript(int $id): void
    {
        $this->transcriptCallId = Call::query()->inWorkspace($this->workspace)->whereKey($id)->exists() ? $id : null;
        Flux::modal('transcript')->show();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Message>
     */
    public function transcript(): \Illuminate\Support\Collection
    {
        $call = $this->transcriptCallId === null ? null : Call::query()->inWorkspace($this->workspace)->with('conversation.messages')->find($this->transcriptCallId);

        return $call?->conversation?->messages ?? collect();
    }

    #[Computed]
    public function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->require()->refresh();
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
     * @return Collection<int, Call>
     */
    #[Computed]
    public function calls(): Collection
    {
        return Call::query()->inWorkspace($this->workspace)->with('contact')->latest('id')->limit(25)->get();
    }
}; ?>

<x-pages::workspace.layout :heading="__('Calls')" :subheading="__('A voice agent that answers from this workspace\'s documents: website visitors call from the widget, people call your phone number, and you can call contacts who agreed to it.')">
    <div class="space-y-10">
        {{-- Test call --}}
        <section class="space-y-3" x-data="{
                open: false,
                base: @js(rtrim(url('/'), '/')),
                load() {
                    if (window.DocChatVoiceCall) return Promise.resolve();
                    return new Promise((resolve, reject) => {
                        const tag = document.createElement('script');
                        tag.src = @js(route('voice-call.script'));
                        tag.onload = resolve;
                        tag.onerror = reject;
                        document.head.appendChild(tag);
                    });
                },
                start(token) {
                    this.open = true;
                    this.load().then(() => window.DocChatVoiceCall.mount(this.$refs.box, {
                        base: this.base,
                        start: () => fetch(this.base + '/api/v1/call/answer', { method: 'POST', headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' } })
                            .then((r) => r.json())
                            .then((b) => {
                                if (!b.data) throw new Error((b.error && b.error.message) || 'The call could not start.');
                                return { token: token, greeting: b.data.greeting, max_seconds: b.data.max_seconds };
                            }),
                        onEnd: () => $wire.$refresh(),
                    }));
                },
            }" x-on:docchat-test-call.window="start($event.detail.token)">
            <flux:heading>{{ __('Try it') }}</flux:heading>
            <flux:text>{{ __('Talk to the agent in this browser, the way a website visitor would. Your browser asks for the microphone; you can also type.') }}</flux:text>
            <flux:button wire:click="testCall" icon="phone" variant="primary" data-test="test-call">{{ __('Start a test call') }}</flux:button>
            <div x-show="open" x-cloak x-ref="box" class="h-[460px] overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700" wire:ignore></div>
        </section>


        {{-- Settings --}}
        <section class="space-y-4">
            <flux:heading>{{ __('Inbound calls') }}</flux:heading>
            <form wire:submit="saveSettings" class="space-y-4">
                <flux:switch wire:model="widgetCalls" :label="__('Website visitors can call from the widget')" />
                <flux:textarea wire:model="greeting" :label="__('Greeting')" rows="2" :placeholder="\App\Services\Calls\CallSettings::defaultGreeting($this->workspace)" :description="__('The first thing callers hear. Say that it is an automated assistant.')" />
                <flux:input wire:model="handoff" :label="__('When the documents have no answer')" :placeholder="__('You can also email us at support@example.com.')" />
                <flux:input wire:model="phoneNumber" :label="__('Phone number (Twilio)')" placeholder="+14155550123" :description="__('Calls to this Twilio number reach this workspace. In Twilio, set the number\'s voice webhook to :url (HTTP POST).', ['url' => \App\Services\Twilio\TwilioSignature::url('twilio/voice/inbound')])" />
                @unless (\App\Services\Calls\CallSettings::twilioConfigured())
                    <flux:callout icon="information-circle" :text="__('The phone line needs a free Twilio trial account: the server admin sets TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN and TWILIO_PHONE_NUMBER (see docs/calls.md). Browser calls work without it.')" />
                @endunless
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </form>
        </section>

        {{-- Contacts --}}
        <section class="space-y-4">
            <flux:heading>{{ __('Contacts') }}</flux:heading>
            <flux:text>{{ __('Only call people who asked for it or agreed to it. Anyone who says "stop calling" on a call is opted out for good.') }}</flux:text>
            <form wire:submit="addContact" class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="grid gap-3 sm:grid-cols-3">
                    <flux:input wire:model="contactName" :label="__('Name')" required />
                    <flux:input wire:model="contactPhone" :label="__('Phone')" placeholder="+923001234567" />
                    <flux:input wire:model="contactEmail" type="email" :label="__('Email')" />
                </div>
                <flux:checkbox wire:model.live="contactConsent" :label="__('This person agreed to be called')" />
                @if ($contactConsent)
                    <flux:input wire:model="contactConsentNote" :label="__('How and when they agreed')" :placeholder="__('Asked for a call on our website, 25 Nov')" />
                @endif
                <flux:button type="submit" size="sm">{{ __('Add contact') }}</flux:button>
            </form>

            <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($this->contacts as $contact)
                    <div class="flex flex-wrap items-center gap-2 p-3" wire:key="contact-{{ $contact->id }}">
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium">{{ $contact->name }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ collect([$contact->phone, $contact->email])->filter()->implode(' · ') }}</div>
                        </div>
                        @if ($contact->opted_out_at)
                            <flux:badge size="sm" color="red">{{ __('Opted out') }}</flux:badge>
                        @elseif ($contact->consent_at)
                            <flux:badge size="sm" color="green" :title="$contact->consent_note">{{ __('Agreed') }}</flux:badge>
                        @else
                            <flux:badge size="sm">{{ __('No consent') }}</flux:badge>
                        @endif
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteContact({{ $contact->id }})" wire:confirm="{{ __('Delete this contact?') }}" :aria-label="__('Delete')" />
                    </div>
                @empty
                    <div class="p-4 text-sm text-zinc-500">{{ __('No contacts yet.') }}</div>
                @endforelse
            </div>
        </section>

        {{-- Outbound --}}
        <section class="space-y-4">
            <flux:heading>{{ __('Call someone') }}</flux:heading>
            <form wire:submit="startCall" class="space-y-3">
                <flux:select wire:model="callContactId" :label="__('Contact')">
                    <flux:select.option value="">{{ __('Choose a contact who agreed') }}</flux:select.option>
                    @foreach ($this->contacts->filter->mayBeCalled() as $contact)
                        <flux:select.option :value="$contact->id">{{ $contact->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:radio.group wire:model.live="callTransport" :label="__('How')">
                    <flux:radio value="web" :label="__('Send a call link')" :description="__('They open it and talk in the browser. Free, works anywhere.')" />
                    <flux:radio value="phone" :label="__('Phone call (Twilio)')" :description="__('Rings their phone. On a Twilio trial, only your verified numbers in your own country. Calling hours: :hours.', ['hours' => \App\Services\Calls\CallingHours::describe()])" />
                </flux:radio.group>
                <flux:input wire:model="callPurpose" :label="__('What the call is about')" :placeholder="__('your library card renewal')" required />
                <flux:textarea wire:model="callOpening" :label="__('Opening line (optional)')" rows="2" :placeholder="__('Your card expires next week. I can explain how to renew it or answer any questions.')" />
                @if ($callTransport === 'web')
                    <flux:checkbox wire:model="callEmail" :label="__('Email the link to the contact')" />
                @endif
                <flux:button type="submit" variant="primary" icon="phone-arrow-up-right">{{ $callTransport === 'web' ? __('Create call link') : __('Call now') }}</flux:button>
            </form>

            @if ($createdLink)
                <div class="space-y-2" x-data="{ copied: false }">
                    <flux:text>{{ __('Send this link to the contact. It works until they finish the call, for :days days.', ['days' => config('docchat.calls.link_valid_days')]) }}</flux:text>
                    <pre class="overflow-x-auto rounded-lg bg-zinc-100 p-3 text-xs dark:bg-zinc-800"><code x-ref="link" data-test="call-link">{{ $createdLink }}</code></pre>
                    <flux:button size="sm" icon="clipboard" x-on:click="navigator.clipboard.writeText($refs.link.textContent); copied = true; setTimeout(() => copied = false, 2000)">
                        <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'">{{ __('Copy') }}</span>
                    </flux:button>
                </div>
            @endif
        </section>

        {{-- Log --}}
        <section class="space-y-2">
            <flux:heading>{{ __('Recent calls') }}</flux:heading>
            <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($this->calls as $call)
                    <div class="flex flex-wrap items-center gap-2 p-3 text-sm" wire:key="call-{{ $call->id }}">
                        <flux:badge size="sm" :icon="$call->direction === \App\Enums\CallDirection::Inbound ? 'phone-arrow-down-left' : 'phone-arrow-up-right'">{{ $call->direction->label() }}</flux:badge>
                        <flux:badge size="sm" color="zinc">{{ $call->transport->label() }}</flux:badge>
                        <div class="min-w-0 flex-1 truncate">{{ $call->counterpart() }}@if ($call->purpose) <span class="text-zinc-500">· {{ $call->purpose }}</span>@endif</div>
                        <div class="text-xs text-zinc-500">{{ $call->status->label() }}@if ($call->duration_seconds !== null) · {{ gmdate($call->duration_seconds >= 3600 ? 'H:i:s' : 'i:s', $call->duration_seconds) }}@endif · {{ $call->created_at?->diffForHumans() }}</div>
                        @if (in_array($call->status, [\App\Enums\CallStatus::Waiting, \App\Enums\CallStatus::Queued], true))
                            <flux:button size="sm" variant="ghost" wire:click="cancelCall({{ $call->id }})">{{ __('Cancel') }}</flux:button>
                        @endif
                        @if ($call->conversation_id)
                            <flux:button size="sm" wire:click="showTranscript({{ $call->id }})">{{ __('Transcript') }}</flux:button>
                        @endif
                    </div>
                @empty
                    <div class="p-4 text-sm text-zinc-500">{{ __('No calls yet.') }}</div>
                @endforelse
            </div>
        </section>
    </div>

    <flux:modal name="transcript" class="w-full max-w-lg">
        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Transcript') }}</flux:heading>
            <div class="max-h-[60vh] space-y-2 overflow-y-auto">
                @forelse ($this->transcript() as $message)
                    <div @class(['rounded-lg p-2 text-sm', 'bg-zinc-100 dark:bg-zinc-800' => $message->role === \App\Enums\MessageRole::Assistant, 'bg-indigo-50 dark:bg-indigo-950' => $message->role === \App\Enums\MessageRole::User]) wire:key="t-{{ $message->id }}">
                        <div class="text-xs font-medium text-zinc-500">{{ $message->role === \App\Enums\MessageRole::User ? __('Caller') : __('Agent') }}</div>
                        <div class="whitespace-pre-line">{{ $message->content !== '' ? $message->content : $message->error }}</div>
                    </div>
                @empty
                    <flux:text>{{ __('Nothing was said on this call.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </flux:modal>
</x-pages::workspace.layout>
