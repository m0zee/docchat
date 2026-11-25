<x-layouts::auth :title="__('Call from :name', ['name' => $workspace?->name ?? config('app.name')])">
    <div class="flex flex-col gap-6">
        @if (session('opted_out'))
            <x-auth-header :title="__('You will not be called again')" :description="__('We saved your choice. You can close this page.')" />
        @elseif ($call === null)
            <x-auth-header :title="__('This call link has ended')" :description="__('The call already happened, was canceled, or the link expired.')" />
        @else
            <x-auth-header
                :title="__(':workspace would like to talk', ['workspace' => $workspace->name])"
                :description="$call->purpose ? __('About: :purpose', ['purpose' => $call->purpose]) : __('An automated assistant can answer your questions.')"
            />

            <div id="call-box" class="hidden h-[480px] overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700" data-test="call-box"></div>

            <div id="call-intro" class="flex flex-col gap-3">
                <flux:text>{{ __('You will talk to an automated assistant that answers from :workspace\'s documents. Your browser will ask to use the microphone; you can also type. The conversation is saved as a transcript for :workspace.', ['workspace' => $workspace->name]) }}</flux:text>
                <flux:button id="call-start" variant="primary" class="w-full" icon="phone" data-test="start-call">{{ __('Start the call') }}</flux:button>
                <form method="POST" action="{{ route('calls.opt-out', $token) }}">
                    @csrf
                    <flux:button type="submit" variant="ghost" class="w-full" size="sm">{{ __('Don\'t call me again') }}</flux:button>
                </form>
            </div>

            <script src="{{ route('voice-call.script') }}"></script>
            <script>
                (function () {
                    var token = @json($token);
                    var base = @json(rtrim(url('/'), '/'));
                    document.getElementById('call-start').addEventListener('click', function () {
                        document.getElementById('call-intro').classList.add('hidden');
                        var box = document.getElementById('call-box');
                        box.classList.remove('hidden');
                        window.DocChatVoiceCall.mount(box, {
                            base: base,
                            start: function () {
                                return fetch(base + '/api/v1/call/answer', {
                                    method: 'POST',
                                    headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
                                }).then(function (r) {
                                    return r.json().then(function (body) {
                                        if (!r.ok) throw new Error(body && body.error ? body.error.message : 'The call could not start.');
                                        return Object.assign({}, body.data, { token: token });
                                    });
                                });
                            },
                        });
                    });
                })();
            </script>
        @endif
    </div>
</x-layouts::auth>
