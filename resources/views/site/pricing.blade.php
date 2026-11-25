@php
    $limits = [
        'questions' => (int) config('docchat.chat.rate_limit_per_minute'),
        'upload_mb' => (int) round(config('docchat.ingestion.max_upload_kb') / 1024),
        'api' => (int) config('docchat.api.rate_limit_per_minute'),
    ];

    $plans = [
        [
            'name' => __('Hosted'),
            'price' => __('Free'),
            'tagline' => __('Use this server with the app\'s free AI keys.'),
            'cta' => ['label' => __('Start free'), 'href' => auth()->check() ? route('dashboard') : route('register')],
            'featured' => false,
            'items' => [
                __('Unlimited workspaces, members and documents'),
                __('PDF, TXT and Markdown up to :mb MB each', ['mb' => $limits['upload_mb']]),
                __('Web chat, widget, Telegram, WhatsApp and Discord'),
                __('Voice calls on your website, in seven languages'),
                __('API, webhooks and answer cache'),
                __(':n questions a minute per person', ['n' => $limits['questions']]),
                __('Answers on shared free Groq and Gemini quota'),
            ],
        ],
        [
            'name' => __('Bring your own key'),
            'price' => __('Free'),
            'tagline' => __('Everything in Hosted, on your own free AI quota.'),
            'cta' => ['label' => __('How to add a key'), 'href' => route('docs.show', 'ai-keys')],
            'featured' => true,
            'items' => [
                __('Your own Groq or Gemini key, encrypted at rest'),
                __('Your limits, not shared with other workspaces'),
                __('Pick the model your answers use'),
                __('A Gemini key also handles search'),
                __('Optional answers in the visitor\'s browser'),
                __('Your own free Twilio trial for a phone line'),
                __('Free keys from Groq and Google AI Studio, no card'),
            ],
        ],
        [
            'name' => __('Self-host'),
            'price' => __('Free'),
            'tagline' => __('Run all of DocChat on your own server.'),
            'cta' => ['label' => __('Get the code'), 'href' => 'https://github.com/m0zee/docchat'],
            'featured' => false,
            'items' => [
                __('MIT licensed, every feature included'),
                __('One Docker Compose stack with Postgres and n8n'),
                __('Fits a free cloud VM, such as Oracle Always Free'),
                __('Optional natural voice for calls, run on your server'),
                __('Your data never leaves your server'),
                __('Use any of the supported AI providers'),
                __('Guides in the docs and the README'),
            ],
        ],
    ];

    $faq = [
        [__('Is it really free?'), __('Yes. DocChat is a portfolio project that runs only on free tiers. There is no paid plan and nothing to buy, and the app never asks for a card.')],
        [__('What are the limits on the hosted version?'), __('The app\'s AI keys are on free tiers that every workspace shares, so answers can be slow or briefly busy at peak times. Adding your own free key or turning on answers in the browser avoids that.')],
        [__('Can I use my ChatGPT, Gemini or Claude subscription?'), __('No. Those subscriptions can\'t be used by other apps under their terms. Free API keys from Groq or Google AI Studio work instead, and so does the AI built into Chrome.')],
        [__('Do voice calls cost anything?'), __('Website calls are free: they use the visitor\'s browser voice, or the open Kokoro voice if the server runs it. Voice clones are free too, on your own server. The phone line runs on a free Twilio trial. On the trial, Twilio\'s basic voice is free; its Amazon and Google voices and voicemail detection use the trial credit, and Twilio charges for them after the trial. DocChat labels those choices on the Calls page.')],
        [__('Where are my documents stored?'), __('On this server, private to your workspace. The hosted version is for testing, so please upload test documents only. Self-host for anything private.')],
        [__('Will paid plans come later?'), __('Not planned. If that ever changes, it will be announced here first, and the self-hosted version stays free under the MIT license.')],
    ];
@endphp

<x-site :title="__('Pricing')" :description="__('DocChat is free: use the hosted version, bring your own free AI key, or self-host it.')">
    <section class="mx-auto max-w-6xl px-6 py-16 md:py-20">
        <div class="text-center">
            <h1 class="text-4xl font-semibold tracking-tight">{{ __('Simple pricing: free') }}</h1>
            <p class="mx-auto mt-4 max-w-2xl text-lg text-zinc-600 dark:text-zinc-400">{{ __('Every plan has every feature. The only choice is whose free AI quota answers your questions, and whose server runs it.') }}</p>
        </div>

        <div class="mt-12 grid gap-6 lg:grid-cols-3">
            @foreach ($plans as $plan)
                <div @class([
                    'flex flex-col rounded-2xl border p-6',
                    'border-zinc-900 shadow-lg dark:border-white' => $plan['featured'],
                    'border-zinc-200 dark:border-zinc-800' => ! $plan['featured'],
                ]) data-test="plan">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold">{{ $plan['name'] }}</h2>
                        @if ($plan['featured'])
                            <span class="rounded-full bg-zinc-900 px-2 py-0.5 text-xs text-white dark:bg-white dark:text-zinc-900">{{ __('Best for busy sites') }}</span>
                        @endif
                    </div>
                    <div class="mt-4 flex items-baseline gap-2">
                        <span class="text-4xl font-semibold">{{ $plan['price'] }}</span>
                        <span class="text-sm text-zinc-500">{{ __('forever') }}</span>
                    </div>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $plan['tagline'] }}</p>

                    <ul class="mt-6 flex-1 space-y-3 text-sm">
                        @foreach ($plan['items'] as $item)
                            <li class="flex gap-2">
                                <flux:icon name="check" variant="mini" class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <flux:button :href="$plan['cta']['href']" :variant="$plan['featured'] ? 'primary' : 'outline'" class="mt-8 w-full">{{ $plan['cta']['label'] }}</flux:button>
                </div>
            @endforeach
        </div>

        <div class="mx-auto mt-20 max-w-3xl" data-test="voice-costs">
            <h2 class="text-2xl font-semibold tracking-tight">{{ __('Voice calls: what is free') }}</h2>
            <p class="mt-2 text-zinc-600 dark:text-zinc-400">{{ __('DocChat itself never charges. Some voices come from other services with their own free tiers.') }}</p>
            <div class="mt-6 overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                        <tr><th class="px-4 py-3 font-medium">{{ __('Part') }}</th><th class="px-4 py-3 font-medium">{{ __('Cost') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @foreach ([
                            [__('Website calls with the browser\'s voice'), __('Free')],
                            [__('Self-hosted natural voice (Kokoro)'), __('Free, on your own server')],
                            [__('Voice clones, with the speaker\'s recorded consent (Pocket TTS)'), __('Free, on your own server')],
                            [__('Speech recognition (Groq Whisper)'), __('Free tier, shared: 300 recordings a day per workspace')],
                            [__('Phone line on a Twilio trial'), __('Free: 75 minutes over 30 days, to numbers you verify')],
                            [__('Twilio basic phone voice'), __('Free')],
                            [__('Amazon and Google phone voices, voicemail detection'), __('Use the Twilio trial credit, paid to Twilio after it')],
                        ] as [$part, $cost])
                            <tr><td class="px-4 py-3">{{ $part }}</td><td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">{{ $cost }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mx-auto mt-20 max-w-3xl">
            <h2 class="text-2xl font-semibold tracking-tight">{{ __('Questions') }}</h2>
            <dl class="mt-6 divide-y divide-zinc-200 dark:divide-zinc-800">
                @foreach ($faq as [$question, $answer])
                    <div class="py-5">
                        <dt class="font-medium">{{ $question }}</dt>
                        <dd class="mt-2 text-zinc-600 dark:text-zinc-400">{{ $answer }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>
</x-site>
