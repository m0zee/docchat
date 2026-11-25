<x-layouts::auth :title="__('Voice consent')">
    <div class="flex flex-col gap-6">
        @if (session('withdrawn') || $clone?->status === \App\Enums\VoiceCloneStatus::Withdrawn)
            <x-auth-header :title="__('Consent withdrawn')" :description="__('Your recording and every clip made from it were deleted. The voice can no longer be used.')" />
        @elseif ($clone === null)
            <x-auth-header :title="__('This link is not valid')" :description="__('Ask the person who sent it for a new link.')" />
        @elseif ($clone->isReady())
            <x-auth-header :title="__('Your voice is ready')" :description="__(':workspace can use a copy of your voice for its virtual assistant. Calls still say they are a virtual assistant.', ['workspace' => $workspace->name])" />
            <flux:text>{{ __('You agreed on :date. You can change your mind at any time: withdrawing deletes your recording and every clip made from it.', ['date' => $clone->consented_at?->toFormattedDayDateString()]) }}</flux:text>
            <form method="POST" action="{{ route('voice-consent.withdraw', $token) }}">
                @csrf
                <flux:button type="submit" variant="danger" class="w-full" data-test="withdraw-consent">{{ __('Withdraw my consent') }}</flux:button>
            </form>
        @elseif (! $clone->canRecord())
            <x-auth-header :title="__('This link has ended')" :description="__('It expired or had too many tries. Ask :workspace for a new link.', ['workspace' => $workspace->name])" />
        @else
            <x-auth-header
                :title="__('Lend your voice to :workspace', ['workspace' => $workspace->name])"
                :description="__(':workspace would like its virtual assistant to speak with a copy of your voice. This only happens if you agree, in your own voice, right here.', ['workspace' => $workspace->name])"
            />

            <div class="flex flex-col gap-2 text-sm">
                <flux:text>{{ __('How it works') }}</flux:text>
                <ul class="list-disc space-y-1 ps-5 text-zinc-600 dark:text-zinc-300">
                    <li>{{ __('Press record and read both parts below aloud, in one take, in a quiet room (:min to :max seconds).', ['min' => $minSeconds, 'max' => $maxSeconds]) }}</li>
                    <li>{{ __('The recording is checked for the statement, then kept privately and used only as the voice for :workspace\'s assistant.', ['workspace' => $workspace->name]) }}</li>
                    <li>{{ __('Calls made with your voice still say they are a virtual assistant.') }}</li>
                    <li>{{ __('Come back to this link at any time to withdraw. That deletes the recording and every clip.') }}</li>
                </ul>
            </div>

            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text class="text-xs uppercase tracking-wide">{{ __('1. The consent statement') }}</flux:text>
                <p class="mt-1 text-lg font-medium" data-test="consent-statement">{{ $clone->statement }}</p>
                <flux:text class="mt-4 text-xs uppercase tracking-wide">{{ __('2. Then read this') }}</flux:text>
                <p class="mt-1">{{ $passage }}</p>
            </div>

            <flux:field variant="inline">
                <flux:checkbox id="consent-confirm" data-test="consent-confirm" />
                <flux:label for="consent-confirm">{{ __('I am :name, this is my own voice, and I agree to the statement above.', ['name' => $clone->speaker_name]) }}</flux:label>
            </flux:field>

            <div class="flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <flux:button id="rec-start" variant="primary" icon="microphone" data-test="rec-start">{{ __('Start recording') }}</flux:button>
                    <flux:button id="rec-stop" class="hidden" icon="stop" data-test="rec-stop">{{ __('Stop') }}</flux:button>
                    <span id="rec-time" class="font-mono text-sm tabular-nums" data-test="rec-time">0:00</span>
                </div>
                <audio id="rec-preview" class="hidden w-full" controls></audio>
                <flux:button id="rec-send" class="hidden" variant="primary" icon="check" data-test="rec-send">{{ __('Send my recording') }}</flux:button>
                <p id="rec-status" class="text-sm" role="status" aria-live="polite" data-test="rec-status"></p>
            </div>

            <form method="POST" action="{{ route('voice-consent.withdraw', $token) }}">
                @csrf
                <flux:button type="submit" variant="ghost" size="sm" class="w-full">{{ __('I don\'t agree. Cancel this request') }}</flux:button>
            </form>

            <script>{!! file_get_contents(resource_path('widget/voice-recorder.js')) !!}</script>
            <script>
                window.DocChatVoiceRecorder.mount({
                    url: @json(route('voice-consent.record', $token)),
                    csrf: @json(csrf_token()),
                    minSeconds: @json($minSeconds),
                    maxSeconds: @json($maxSeconds),
                    text: {{ \Illuminate\Support\Js::from([
                        'again' => __('Record again'),
                        'short' => __('That was a little short. Please read both parts.'),
                        'listen' => __('Listen back if you like, then send it.'),
                        'tick' => __('Please tick the box to confirm it is your voice and you agree.'),
                        'asking' => __('Asking for the microphone…'),
                        'recording' => __('Recording. Read the statement, then the passage.'),
                        'noMic' => __('The microphone could not be used. Allow it in your browser and try again.'),
                        'checking' => __('Checking your recording…'),
                        'failed' => __('Something went wrong. Please try again.'),
                    ]) }},
                });
            </script>
        @endif
    </div>
</x-layouts::auth>
