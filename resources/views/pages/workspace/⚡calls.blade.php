<?php

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\CallTransport;
use App\Enums\MessageRole;
use App\Jobs\PlaceTwilioCallJob;
use App\Mail\CallLinkMail;
use App\Mail\VoiceConsentMail;
use App\Models\Call;
use App\Models\CallContact;
use App\Models\Message;
use App\Models\VoiceClone;
use App\Enums\VoiceCloneStatus;
use App\Models\Workspace;
use App\Services\Calls\CallingHours;
use App\Services\Calls\CallSettings;
use App\Services\Calls\Phrases;
use App\Services\Calls\VoiceProfile;
use App\Exceptions\SpeechException;
use App\Services\Voice\KokoroTextToSpeech;
use App\Services\Voice\PocketTtsVoice;
use App\Services\Voice\VoiceConsent;
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

    /**
     * Voice and personality (VoiceProfile).
     *
     * @var array<string, mixed>
     */
    public array $voice = [];

    // New voice clone
    public string $cloneName = '';

    public string $cloneSpeaker = '';

    public string $cloneEmail = '';

    public string $cloneLanguage = 'en';

    public bool $cloneSendEmail = true;

    /** The consent link just created, shown once. */
    public ?string $cloneLink = null;

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
        $this->voice = $this->voiceForm(VoiceProfile::for($this->workspace));
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function voiceForm(array $profile): array
    {
        $profile['speed'] = number_format((float) $profile['speed'], 2);
        $profile['pitch'] = number_format((float) $profile['pitch'], 2);
        $profile['browser_voice'] = (string) $profile['browser_voice'];
        $profile['voicemail_message'] = (string) $profile['voicemail_message'];
        $profile['clone_id'] = $profile['clone_id'] === null ? '' : (string) $profile['clone_id'];

        return $profile;
    }

    public function saveVoice(): void
    {
        Gate::authorize('manage', $this->workspace);

        $rules = collect(VoiceProfile::rules())->mapWithKeys(fn ($rule, $key) => ['voice.'.$key => $rule])->all();
        $this->validate($rules, [], collect(VoiceProfile::rules())->keys()->mapWithKeys(fn ($key) => ['voice.'.$key => str_replace('_', ' ', $key)])->all());

        if ($this->voice['web_engine'] === 'kokoro' && ! VoiceProfile::kokoroAvailable()) {
            $this->addError('voice.web_engine', __('The self-hosted voice is not set up on this server (DOCCHAT_KOKORO_URL).'));

            return;
        }

        $usesClone = $this->voice['web_engine'] === 'clone' || $this->voice['phone_voice'] === 'clone';

        if ($usesClone && ! $this->readyClones->contains('id', (int) $this->voice['clone_id'])) {
            $this->addError('voice.clone_id', __('Pick a voice clone that is ready, or choose another voice.'));

            return;
        }

        VoiceProfile::save($this->workspace, [...$this->voice, 'clone_id' => filled($this->voice['clone_id']) ? (int) $this->voice['clone_id'] : null]);
        unset($this->workspace);
        $this->voice = $this->voiceForm(VoiceProfile::for($this->workspace));
        Flux::toast(variant: 'success', text: __('Voice settings saved.'));
    }

    /**
     * The line the preview buttons read, in the chosen language and name.
     */
    public function sampleLine(): string
    {
        $language = VoiceProfile::LANGUAGES[$this->voice['language'] ?? 'en-US']['phrases'] ?? 'en';
        $name = trim((string) ($this->voice['assistant_name'] ?? '')) ?: VoiceProfile::DEFAULTS['assistant_name'];

        return Phrases::get($language, 'greeting', ['name' => $name, 'workspace' => $this->workspace->name]);
    }

    /**
     * Speak the sample line with the chosen Kokoro voice. Returns a data URL.
     */
    public function previewKokoro(): ?string
    {
        Gate::authorize('manage', $this->workspace);

        if (! VoiceProfile::kokoroAvailable()) {
            return null;
        }

        $profile = array_merge(VoiceProfile::for($this->workspace), array_intersect_key($this->voice, VoiceProfile::DEFAULTS));
        $voice = VoiceProfile::kokoroVoice($profile);

        if ($voice === null || ! array_key_exists((string) $this->voice['kokoro_voice'], VoiceProfile::KOKORO_VOICES)) {
            Flux::toast(variant: 'warning', text: __('The self-hosted voice has no voice for this language yet. Calls in it use the browser voice.'));

            return null;
        }

        try {
            $audio = app(KokoroTextToSpeech::class)->speakAs($this->sampleLine(), $voice, VoiceProfile::prosody($profile)['rate']);
        } catch (SpeechException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return null;
        }

        return 'data:audio/mpeg;base64,'.base64_encode($audio);
    }

    /**
     * Twilio voices for the chosen language.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function phoneVoices(): array
    {
        $language = (string) ($this->voice['language'] ?? 'en-US');
        $options = [];

        foreach (VoiceProfile::PHONE_VOICES as $id => $voice) {
            if ($id === 'basic') {
                $options[$id] = in_array($language, $voice['languages'], true)
                    ? __('Basic voice (free)')
                    : __('Basic voice (free, speaks US English for this language)');

                continue;
            }

            if (in_array($language, $voice['languages'], true)) {
                $options[$id] = $voice['label'].' · '.__('uses Twilio credit');
            }
        }

        if (VoiceProfile::kokoroAvailable() && VoiceProfile::LANGUAGES[$language]['kokoro'] !== null) {
            $options['kokoro'] = __('Self-hosted natural voice (free)');
        }

        if ($this->readyClones->isNotEmpty() && VoiceProfile::LANGUAGES[$language]['phrases'] === PocketTtsVoice::language()) {
            $options['clone'] = __('Voice clone (free, self-hosted)');
        }

        return $options;
    }

    /**
     * Kokoro voices for the chosen language, if Kokoro speaks it.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function kokoroVoices(): array
    {
        $prefix = VoiceProfile::LANGUAGES[(string) ($this->voice['language'] ?? 'en-US')]['kokoro'] ?? null;

        return collect(VoiceProfile::KOKORO_VOICES)
            ->filter(fn (array $voice, string $id) => $prefix !== null && str_starts_with($id, $prefix))
            ->map(fn (array $voice) => $voice['label'].' · '.__(VoiceProfile::GENDERS[$voice['gender']]))
            ->all();
    }

    public function updatedVoiceLanguage(): void
    {
        // Keep the phone and Kokoro picks valid for the new language.
        if (! array_key_exists((string) $this->voice['phone_voice'], $this->phoneVoices)) {
            $this->voice['phone_voice'] = 'basic';
        }

        unset($this->phoneVoices, $this->kokoroVoices);
        $kokoro = $this->kokoroVoices;

        if ($kokoro !== [] && ! array_key_exists((string) $this->voice['kokoro_voice'], $kokoro)) {
            $this->voice['kokoro_voice'] = (string) array_key_first($kokoro);
        }

        $this->voice['browser_voice'] = '';
    }

    public function createClone(): void
    {
        Gate::authorize('manage', $this->workspace);

        $this->validate([
            'cloneName' => ['required', 'string', 'max:60'],
            'cloneSpeaker' => ['required', 'string', 'max:80', 'regex:/^[\pL\pM][\pL\pM\s\'.-]*$/u'],
            'cloneEmail' => ['nullable', 'email', 'max:255'],
            'cloneLanguage' => ['required', Rule::in(array_keys(VoiceConsent::LANGUAGES))],
        ], [], ['cloneName' => __('voice name'), 'cloneSpeaker' => __('speaker\'s name'), 'cloneEmail' => __('email')]);

        $limit = max(1, (int) config('docchat.voice.clone.per_workspace', 3));
        $active = VoiceClone::query()->inWorkspace($this->workspace)->whereIn('status', [VoiceCloneStatus::Waiting, VoiceCloneStatus::Ready])->count();

        if ($active >= $limit) {
            $this->addError('cloneName', __('A workspace can have :count voice clones. Delete one first.', ['count' => $limit]));

            return;
        }

        $token = VoiceClone::newToken();
        $words = VoiceConsent::codeWords($this->cloneLanguage);
        $speaker = trim($this->cloneSpeaker);

        $clone = VoiceClone::query()->create([
            'workspace_id' => $this->workspace->id,
            'created_by_user_id' => Auth::id(),
            'name' => trim($this->cloneName),
            'speaker_name' => $speaker,
            'speaker_email' => trim($this->cloneEmail) ?: null,
            'language' => $this->cloneLanguage,
            'status' => VoiceCloneStatus::Waiting,
            'token_hash' => VoiceClone::hash($token),
            'code_words' => $words,
            'statement' => VoiceConsent::statement($this->cloneLanguage, $speaker, $this->workspace->name, $words),
            'expires_at' => now()->addDays(max(1, (int) config('docchat.voice.clone.link_valid_days', 7))),
        ]);

        $this->cloneLink = rtrim((string) config('app.url'), '/').'/voice-consent/'.$token;

        if ($this->cloneSendEmail && $clone->speaker_email !== null) {
            Mail::to($clone->speaker_email)->send(new VoiceConsentMail($clone->load('workspace'), $this->cloneLink));
            Flux::toast(variant: 'success', text: __('Consent link emailed to :email.', ['email' => $clone->speaker_email]));
        } else {
            Flux::toast(variant: 'success', text: __('Consent link created.'));
        }

        $this->reset('cloneName', 'cloneSpeaker', 'cloneEmail');
        unset($this->clones, $this->readyClones);
    }

    /**
     * A fresh consent link (the old one stops working) with new code words.
     */
    public function renewCloneLink(int $id): void
    {
        Gate::authorize('manage', $this->workspace);

        $clone = VoiceClone::query()->inWorkspace($this->workspace)->find($id);

        if ($clone === null || ! in_array($clone->status, [VoiceCloneStatus::Waiting, VoiceCloneStatus::Failed], true)) {
            return;
        }

        $token = VoiceClone::newToken();
        $words = VoiceConsent::codeWords($clone->language);

        $clone->forceFill([
            'token_hash' => VoiceClone::hash($token),
            'code_words' => $words,
            'statement' => VoiceConsent::statement($clone->language, $clone->speaker_name, $this->workspace->name, $words),
            'status' => VoiceCloneStatus::Waiting,
            'attempts' => 0,
            'failure' => null,
            'expires_at' => now()->addDays(max(1, (int) config('docchat.voice.clone.link_valid_days', 7))),
        ])->save();

        $this->cloneLink = rtrim((string) config('app.url'), '/').'/voice-consent/'.$token;
        unset($this->clones);
    }

    public function useClone(int $id): void
    {
        Gate::authorize('manage', $this->workspace);

        $clone = $this->readyClones->firstWhere('id', $id);

        if ($clone === null) {
            return;
        }

        $values = ['clone_id' => $clone->id, 'web_engine' => 'clone'];

        if (VoiceProfile::LANGUAGES[VoiceProfile::for($this->workspace)['language']]['phrases'] === PocketTtsVoice::language()) {
            $values['phone_voice'] = 'clone';
        }

        VoiceProfile::save($this->workspace, $values);
        unset($this->workspace, $this->phoneVoices);
        $this->voice = $this->voiceForm(VoiceProfile::for($this->workspace));
        Flux::toast(variant: 'success', text: __('Calls now use the voice ":name".', ['name' => $clone->name]));
    }

    public function deleteClone(int $id): void
    {
        Gate::authorize('manage', $this->workspace);

        VoiceClone::query()->inWorkspace($this->workspace)->find($id)?->delete();

        if ((int) VoiceProfile::for($this->workspace)['clone_id'] === $id) {
            VoiceProfile::save($this->workspace, ['clone_id' => null, 'web_engine' => 'browser', 'phone_voice' => 'basic']);
            unset($this->workspace);
            $this->voice = $this->voiceForm(VoiceProfile::for($this->workspace));
        }

        unset($this->clones, $this->readyClones, $this->phoneVoices);
        Flux::toast(text: __('Voice clone deleted, with its recording.'));
    }

    /**
     * Speak the sample line in a clone's voice. Returns a data URL.
     */
    public function previewClone(int $id): ?string
    {
        Gate::authorize('manage', $this->workspace);

        $clone = $this->readyClones->firstWhere('id', $id);

        if ($clone === null || ! PocketTtsVoice::canSpeak($clone)) {
            Flux::toast(variant: 'warning', text: __('The voice clone server is not set up (DOCCHAT_CLONE_URL), or speaks another language.'));

            return null;
        }

        $name = trim((string) ($this->voice['assistant_name'] ?? '')) ?: VoiceProfile::DEFAULTS['assistant_name'];

        try {
            $audio = app(PocketTtsVoice::class)->speak($clone, Phrases::get($clone->language, 'greeting', ['name' => $name, 'workspace' => $this->workspace->name]));
        } catch (SpeechException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return null;
        }

        return 'data:audio/wav;base64,'.base64_encode($audio);
    }

    /**
     * @return Collection<int, VoiceClone>
     */
    #[Computed]
    public function clones(): Collection
    {
        return VoiceClone::query()->inWorkspace($this->workspace)->latest('id')->get();
    }

    /**
     * @return Collection<int, VoiceClone>
     */
    #[Computed]
    public function readyClones(): Collection
    {
        return $this->clones->filter(fn (VoiceClone $clone): bool => $clone->isReady())->values();
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
                                return Object.assign({}, b.data, { token: token });
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
                <flux:textarea wire:model="greeting" :label="__('Greeting')" rows="2" :placeholder="\App\Services\Calls\CallSettings::defaultGreeting($this->workspace)" :description="__('The first thing callers hear. If it doesn\'t say it is a virtual assistant, that is added.')" />
                <flux:input wire:model="handoff" :label="__('When the documents have no answer')" :placeholder="__('You can also email us at support@example.com.')" />
                <flux:input wire:model="phoneNumber" :label="__('Phone number (Twilio)')" placeholder="+14155550123" :description="__('Calls to this Twilio number reach this workspace. In Twilio, set the number\'s voice webhook to :url (HTTP POST).', ['url' => \App\Services\Twilio\TwilioSignature::url('twilio/voice/inbound')])" />
                @unless (\App\Services\Calls\CallSettings::twilioConfigured())
                    <flux:callout icon="information-circle" :text="__('The phone line needs a free Twilio trial account: the server admin sets TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN and TWILIO_PHONE_NUMBER (see docs/calls.md). Browser calls work without it.')" />
                @endunless
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </form>
        </section>

        {{-- Voice and personality --}}
        <section class="space-y-4" x-data="{
                voices: [],
                previewing: false,
                list() {
                    const lang = ($wire.voice.language || 'en-US').toLowerCase();
                    const base = lang.split('-')[0];
                    const all = (window.speechSynthesis && speechSynthesis.getVoices()) || [];
                    this.voices = all.filter((v) => (v.lang || '').toLowerCase().replace('_', '-').split('-')[0] === base)
                        .sort((a, b) => (/(natural|neural|online|google)/i.test(b.name) - /(natural|neural|online|google)/i.test(a.name)) || a.name.localeCompare(b.name));
                },
                init() {
                    this.list();
                    if (window.speechSynthesis) speechSynthesis.addEventListener('voiceschanged', () => this.list());
                    this.$watch('$wire.voice.language', () => this.$nextTick(() => this.list()));
                },
                async previewBrowser() {
                    if (!window.speechSynthesis) return;
                    const text = await $wire.sampleLine();
                    const v = $wire.voice;
                    const lang = v.language;
                    const all = speechSynthesis.getVoices();
                    let voice = all.find((x) => x.name === v.browser_voice);
                    if (!voice) voice = this.voices[0] || null;
                    const nudge = { young: [0.04, 0.12], adult: [0, 0], mature: [-0.06, -0.12] }[v.age] || [0, 0];
                    speechSynthesis.cancel();
                    const u = new SpeechSynthesisUtterance(text);
                    if (voice) u.voice = voice;
                    u.lang = voice ? voice.lang : lang;
                    u.rate = parseFloat(v.speed) + nudge[0];
                    u.pitch = parseFloat(v.pitch) + nudge[1];
                    speechSynthesis.speak(u);
                },
                async previewKokoro() {
                    this.previewing = true;
                    try {
                        const url = await $wire.previewKokoro();
                        if (url) await new Audio(url).play();
                    } finally { this.previewing = false; }
                },
            }" data-test="voice-settings">
            <div>
                <flux:heading>{{ __('Voice and personality') }}</flux:heading>
                <flux:text class="mt-1">{{ __('How the assistant sounds on calls. It always says it is a virtual assistant at the start; after that it talks like a person would.') }}</flux:text>
            </div>
            <form wire:submit="saveVoice" class="space-y-5 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="voice.assistant_name" :label="__('Assistant name')" :description="__('Used in the greeting: Hi, this is Ava...')" maxlength="40" />
                    <flux:select wire:model="voice.personality" :label="__('Personality')">
                        @foreach (\App\Services\Calls\VoiceProfile::PERSONALITIES as $key => $personality)
                            <flux:select.option :value="$key">{{ __($personality['label']) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="voice.language" :label="__('Language and accent')">
                        @foreach (\App\Services\Calls\VoiceProfile::LANGUAGES as $tag => $language)
                            <flux:select.option :value="$tag">{{ __($language['label']) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="flex items-end">
                        <flux:switch wire:model="voice.auto_language" :label="__('Answer in the caller\'s language')" :description="__('Website calls detect the language spoken. Phone calls use the language above.')" />
                    </div>
                    <flux:select wire:model="voice.gender" :label="__('Voice')">
                        @foreach (\App\Services\Calls\VoiceProfile::GENDERS as $key => $label)
                            <flux:select.option :value="$key">{{ __($label) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="voice.age" :label="__('Age')">
                        @foreach (\App\Services\Calls\VoiceProfile::AGES as $key => $label)
                            <flux:select.option :value="$key">{{ __($label) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="voice.speed" :label="__('Speed')">
                        @foreach (['0.85' => 'Slower', '0.95' => 'A little slower', '1.00' => 'Normal', '1.10' => 'A little faster', '1.20' => 'Faster'] as $value => $label)
                            <flux:select.option :value="$value">{{ __($label) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="voice.pitch" :label="__('Pitch')">
                        @foreach (['0.85' => 'Lower', '0.95' => 'A little lower', '1.00' => 'Normal', '1.08' => 'A little higher', '1.15' => 'Higher'] as $value => $label)
                            <flux:select.option :value="$value">{{ __($label) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:heading size="sm">{{ __('Website calls') }}</flux:heading>
                    <flux:radio.group wire:model.live="voice.web_engine" :label="__('Voice engine')">
                        <flux:radio value="browser" :label="__('Browser voice (free)')" :description="__('The most natural voice on each visitor\'s device. Edge and Chrome have very natural online voices.')" />
                        <flux:radio value="kokoro" :label="__('Self-hosted natural voice (free)')" :description="\App\Services\Calls\VoiceProfile::kokoroAvailable() ? __('Kokoro, an open voice model running on your server. Same voice for every visitor.') : __('Needs the Kokoro service on the server (DOCCHAT_KOKORO_URL, see the docs).')" :disabled="! \App\Services\Calls\VoiceProfile::kokoroAvailable()" />
                        <flux:radio value="clone" :label="__('Voice clone (free, self-hosted)')" :description="$this->readyClones->isNotEmpty() ? __('A real person\'s voice, copied with their recorded consent. Used when the call is in the clone\'s language.') : __('Make a voice clone below first.')" :disabled="$this->readyClones->isEmpty()" data-test="engine-clone" />
                    </flux:radio.group>
                    @if ($voice['web_engine'] === 'clone' || $voice['phone_voice'] === 'clone')
                        <flux:select wire:model="voice.clone_id" :label="__('Voice clone')" data-test="voice-clone-pick">
                            <flux:select.option value="">{{ __('Pick a voice') }}</flux:select.option>
                            @foreach ($this->readyClones as $clone)
                                <flux:select.option :value="(string) $clone->id">{{ $clone->name }} · {{ \App\Services\Voice\VoiceConsent::LANGUAGES[$clone->language] ?? $clone->language }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                    @if ($voice['web_engine'] === 'clone')
                    @elseif ($voice['web_engine'] === 'kokoro')
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="min-w-56 flex-1">
                                <flux:select wire:model="voice.kokoro_voice" :label="__('Self-hosted voice')">
                                    @forelse ($this->kokoroVoices as $id => $label)
                                        <flux:select.option :value="$id">{{ $label }}</flux:select.option>
                                    @empty
                                        <flux:select.option value="{{ $voice['kokoro_voice'] }}">{{ __('No voice for this language yet (browser voice is used)') }}</flux:select.option>
                                    @endforelse
                                </flux:select>
                            </div>
                            <flux:button type="button" icon="play" x-on:click="previewKokoro()" x-bind:disabled="previewing">{{ __('Preview') }}</flux:button>
                        </div>
                    @else
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="min-w-56 flex-1">
                                <flux:field>
                                    <flux:label>{{ __('Preferred browser voice') }}</flux:label>
                                    <flux:description>{{ __('Voices differ by device. Visitors without this one get the closest match.') }}</flux:description>
                                    <select wire:model="voice.browser_voice" class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-test="browser-voice">
                                        <option value="">{{ __('Best match on each device') }}</option>
                                        <template x-for="v in voices" :key="v.name">
                                            <option :value="v.name" x-text="v.name + ' (' + v.lang + ')'" :selected="v.name === $wire.voice.browser_voice"></option>
                                        </template>
                                    </select>
                                </flux:field>
                            </div>
                            <flux:button type="button" icon="play" x-on:click="previewBrowser()">{{ __('Preview') }}</flux:button>
                        </div>
                    @endif
                    <flux:select wire:model="voice.background" :label="__('Background sound')" :description="__('A very quiet room sound, so silence feels less empty.')">
                        @foreach (\App\Services\Calls\VoiceProfile::BACKGROUNDS as $key => $label)
                            <flux:select.option :value="$key">{{ __($label) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:heading size="sm">{{ __('Phone calls') }}</flux:heading>
                    <flux:select wire:model.live="voice.phone_voice" :label="__('Phone voice')" :description="__('The basic voice is free. Amazon and Google voices sound more natural; on a Twilio trial they use the free credit, later they cost a little per call.')">
                        @foreach ($this->phoneVoices as $id => $label)
                            <flux:select.option :value="$id">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="voice.voicemail" :label="__('When an answering machine picks up (calls you make)')" :description="__('Voicemail detection uses Twilio credit.')">
                        @foreach (\App\Services\Calls\VoiceProfile::VOICEMAIL as $key => $label)
                            <flux:select.option :value="$key">{{ __($label) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($voice['voicemail'] === 'leave_message')
                        <flux:textarea wire:model="voice.voicemail_message" rows="2" :label="__('Voicemail message')" :placeholder="__('Leave empty for a short message that says who called and why.')" />
                    @endif
                </div>

                <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:heading size="sm">{{ __('Conversation') }}</flux:heading>
                    <flux:select wire:model="voice.pace" :label="__('Pace')" :description="__('How long a pause ends the caller\'s turn, and how quickly the assistant answers.')">
                        @foreach (\App\Services\Calls\VoiceProfile::PACES as $key => $pace)
                            <flux:select.option :value="$key">{{ __($pace['label']) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:switch wire:model="voice.interruptions" :label="__('Callers can interrupt')" :description="__('Talking over the assistant stops it, like on a real call.')" />
                    <flux:switch wire:model="voice.fillers" :label="__('Short fillers')" :description="__('Says \'Hmm, let me check.\' when an answer takes a moment.')" />
                    <flux:switch wire:model="voice.hold_music" :label="__('Soft hold music')" :description="__('Plays quietly while a longer answer is being written.')" />
                </div>

                <flux:button type="submit" variant="primary" data-test="save-voice">{{ __('Save voice settings') }}</flux:button>
            </form>
        </section>

        {{-- Voice clones --}}
        <section class="space-y-4" x-data="{
                playing: null,
                async preview(id) {
                    this.playing = id;
                    try {
                        const url = await $wire.previewClone(id);
                        if (url) await new Audio(url).play();
                    } finally { this.playing = null; }
                },
            }" data-test="voice-clones">
            <div>
                <flux:heading>{{ __('Voice clones') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Give the assistant a real person\'s voice, such as your own or a team member\'s. A voice is only copied after that person reads a consent statement aloud on their own consent page, and they can withdraw at any time. Calls still say they are a virtual assistant.') }}</flux:text>
            </div>

            @unless (\App\Services\Voice\PocketTtsVoice::configured())
                <flux:callout icon="information-circle" :heading="__('The voice clone server is not set up')" :text="__('Start the free voice-clone service (docker compose --profile clone) and set DOCCHAT_CLONE_URL. You can still collect consent now.')" />
            @endunless

            <form wire:submit="createClone" class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input wire:model="cloneName" :label="__('Voice name')" :placeholder="__('Sara at the front desk')" data-test="clone-name" />
                    <flux:input wire:model="cloneSpeaker" :label="__('Speaker\'s full name')" :description="__('They say it in the consent statement.')" data-test="clone-speaker" />
                    <flux:input wire:model="cloneEmail" type="email" :label="__('Speaker\'s email (optional)')" :description="__('Leave empty to record your own voice.')" />
                    <flux:select wire:model="cloneLanguage" :label="__('Language they will speak')" :description="__('The clone server speaks :language.', ['language' => \App\Services\Voice\VoiceConsent::LANGUAGES[\App\Services\Voice\PocketTtsVoice::language()]])">
                        @foreach (\App\Services\Voice\VoiceConsent::LANGUAGES as $code => $label)
                            <flux:select.option :value="$code">{{ __($label) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:checkbox wire:model="cloneSendEmail" :label="__('Email the consent link to the speaker')" />
                <flux:button type="submit" icon="microphone" data-test="create-clone">{{ __('Ask for consent') }}</flux:button>
            </form>

            @if ($cloneLink)
                <flux:callout icon="link" color="green" :heading="__('Consent link (shown once)')">
                    <flux:callout.text>{{ __('Open it to record your own voice, or send it to the speaker. It works for :days days.', ['days' => (int) config('docchat.voice.clone.link_valid_days', 7)]) }}</flux:callout.text>
                    <flux:input :value="$cloneLink" readonly copyable data-test="clone-link" />
                </flux:callout>
            @endif

            @if ($this->clones->isNotEmpty())
                <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($this->clones as $clone)
                        <div class="flex flex-wrap items-center gap-3 p-3" wire:key="clone-{{ $clone->id }}" data-test="clone-row">
                            <div class="min-w-48 flex-1">
                                <div class="font-medium">{{ $clone->name }}</div>
                                <flux:text class="text-xs">
                                    {{ $clone->speaker_name }} · {{ __(\App\Services\Voice\VoiceConsent::LANGUAGES[$clone->language] ?? $clone->language) }}
                                    @if ($clone->consented_at)
                                        · {{ __('agreed :date', ['date' => $clone->consented_at->toFormattedDayDateString()]) }}
                                    @endif
                                    @if ($clone->failure && $clone->status !== \App\Enums\VoiceCloneStatus::Ready)
                                        · {{ $clone->failure }}
                                    @endif
                                </flux:text>
                            </div>
                            <flux:badge size="sm" :color="match ($clone->status) { \App\Enums\VoiceCloneStatus::Ready => 'green', \App\Enums\VoiceCloneStatus::Waiting => 'amber', default => 'zinc' }">{{ $clone->status->label() }}</flux:badge>
                            @if ($clone->isReady())
                                <flux:button size="sm" icon="play" x-on:click="preview({{ $clone->id }})" x-bind:disabled="playing !== null">{{ __('Preview') }}</flux:button>
                                @if ((string) $voice['clone_id'] === (string) $clone->id && $voice['web_engine'] === 'clone')
                                    <flux:badge size="sm" color="blue">{{ __('In use') }}</flux:badge>
                                @else
                                    <flux:button size="sm" wire:click="useClone({{ $clone->id }})" data-test="use-clone">{{ __('Use on calls') }}</flux:button>
                                @endif
                            @elseif (in_array($clone->status, [\App\Enums\VoiceCloneStatus::Waiting, \App\Enums\VoiceCloneStatus::Failed], true))
                                <flux:button size="sm" wire:click="renewCloneLink({{ $clone->id }})">{{ __('New link') }}</flux:button>
                            @endif
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteClone({{ $clone->id }})" wire:confirm="{{ __('Delete this voice and its recording?') }}" :aria-label="__('Delete')" />
                        </div>
                    @endforeach
                </div>
            @endif
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
