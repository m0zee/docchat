<x-site :description="__('Upload PDF, TXT or Markdown files and chat with them. Every answer cites the document and page it came from.')">
    {{-- Hero --}}
    <section class="mx-auto grid max-w-6xl items-center gap-12 px-6 py-16 md:py-24 lg:grid-cols-2">
        <div>
            <span class="inline-flex items-center gap-2 rounded-full border border-zinc-200 px-3 py-1 text-xs text-zinc-600 dark:border-zinc-800 dark:text-zinc-400">
                <span class="size-1.5 rounded-full bg-emerald-500"></span>
                {{ __('Free and open source, MIT licensed') }}
            </span>
            <h1 class="mt-5 text-4xl font-semibold tracking-tight md:text-5xl">{{ __('Ask your documents. Get answers with sources.') }}</h1>
            <p class="mt-5 max-w-xl text-lg text-zinc-600 dark:text-zinc-400">
                {{ __('Upload PDF, TXT or Markdown files and chat with them on your site, in Telegram, WhatsApp or Discord. Every answer cites the document and page it came from, and when the answer is not in your files, DocChat says so instead of guessing.') }}
            </p>
            <div class="mt-8 flex flex-wrap gap-3">
                <flux:button :href="auth()->check() ? route('documents.index') : route('register')" variant="primary">{{ __('Upload a document') }}</flux:button>
                <flux:button :href="route('docs.show')" icon="book-open">{{ __('Read the docs') }}</flux:button>
                <flux:button href="https://github.com/m0zee/docchat" target="_blank" variant="ghost" icon="code-bracket">{{ __('Code') }}</flux:button>
            </div>
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" aria-hidden="true">
            <div class="flex items-center gap-2 border-b border-zinc-200 pb-3 text-sm text-zinc-500 dark:border-zinc-800">
                <flux:icon name="chat-bubble-left-right" class="size-4" />
                {{ __('Library handbook') }}
            </div>
            <div class="space-y-4 pt-4 text-sm">
                <div class="flex justify-end">
                    <div class="rounded-2xl bg-zinc-800 px-4 py-2 text-white dark:bg-zinc-200 dark:text-zinc-900">{{ __('How long can I keep a DVD?') }}</div>
                </div>
                <div class="max-w-[90%] rounded-2xl bg-white px-4 py-3 text-zinc-800 shadow-sm dark:bg-zinc-800 dark:text-zinc-100">
                    {{ __('DVDs can be borrowed for 7 days and renewed once [1]. Late returns cost 50 cents a day [2].') }}
                </div>
                <div class="space-y-2">
                    @foreach ([[1, __('Harbor Point library handbook, page 4'), __('Films on DVD are lent for seven days…')], [2, __('Harbor Point library handbook, page 9'), __('Overdue items are charged per day…')]] as [$n, $source, $snippet])
                        <div class="rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900">
                            <div class="font-medium">[{{ $n }}] {{ $source }}</div>
                            <div class="text-zinc-500">{{ $snippet }}</div>
                        </div>
                    @endforeach
                </div>
                <div class="text-xs text-zinc-500">{{ __('1.2 s · 2 sources') }}</div>
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section class="border-y border-zinc-200/70 bg-zinc-50/60 dark:border-zinc-800/70 dark:bg-zinc-900/40">
        <div class="mx-auto max-w-6xl px-6 py-16">
            <h2 class="text-2xl font-semibold tracking-tight">{{ __('How it works') }}</h2>
            <div class="mt-8 grid gap-4 md:grid-cols-3">
                @foreach ([
                    ['icon' => 'document-arrow-up', 'title' => __('1. Upload'), 'text' => __('Files are read page by page, split into overlapping chunks and embedded in the background. Drop them in a Google Drive folder and they are added for you.')],
                    ['icon' => 'magnifying-glass', 'title' => __('2. Retrieve'), 'text' => __('Each question is matched to the closest passages with pgvector. Weak matches are dropped, so nothing is made up from them.')],
                    ['icon' => 'chat-bubble-left-right', 'title' => __('3. Answer with citations'), 'text' => __('The answer streams in and cites each source as document, page and snippet. No match means an honest "not in your documents".')],
                ] as $step)
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950">
                        <flux:icon :name="$step['icon']" class="size-6 text-zinc-500" />
                        <h3 class="mt-3 font-semibold">{{ $step['title'] }}</h3>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $step['text'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Features --}}
    <section id="features" class="mx-auto max-w-6xl scroll-mt-20 px-6 py-16">
        <h2 class="text-2xl font-semibold tracking-tight">{{ __('Everything a team needs to answer from its documents') }}</h2>
        <p class="mt-2 max-w-2xl text-zinc-600 dark:text-zinc-400">{{ __('One workspace per organisation, with its own documents, people, keys and usage.') }}</p>

        <div class="mt-8 grid gap-x-8 gap-y-8 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['icon' => 'globe-alt', 'title' => __('Website widget'), 'text' => __('One script tag adds a chat bubble to your site for visitors, with sources, saved history and a handoff email when the documents have no answer.')],
                ['icon' => 'device-phone-mobile', 'title' => __('Telegram, WhatsApp and Discord'), 'text' => __('Link a chat with a one-time code and it answers from your workspace. Send a file to the bot to add it.')],
                ['icon' => 'users', 'title' => __('Workspaces and teams'), 'text' => __('Invite people by email link. Owners manage keys, the widget, chat apps, webhooks and usage.')],
                ['icon' => 'key', 'title' => __('Bring your own AI key'), 'text' => __('Paste your own free Groq or Gemini key and your answers run on your own quota. Keys are encrypted and never shown again.')],
                ['icon' => 'cpu-chip', 'title' => __('AI on the device'), 'text' => __('Let the browser write answers with Chrome\'s built-in AI or WebLLM. DocChat still finds the sources; the answer costs no tokens.')],
                ['icon' => 'bolt', 'title' => __('Answer cache'), 'text' => __('Repeated questions are answered from a cache that is cleared whenever documents change, so popular questions cost nothing.')],
                ['icon' => 'code-bracket-square', 'title' => __('API and webhooks'), 'text' => __('Upload, list and ask over a documented REST API with streaming. Signed webhooks tell you when a document is ready.')],
                ['icon' => 'arrow-path', 'title' => __('Automations'), 'text' => __('n8n workflows sync a Google Drive folder, clean up deleted files and email a nightly quality score.')],
                ['icon' => 'chart-bar', 'title' => __('Usage you can read'), 'text' => __('Questions, tokens, cache hits and latency per channel and month. Question text is never stored in usage logs.')],
            ] as $feature)
                <div>
                    <flux:icon :name="$feature['icon']" class="size-6 text-zinc-500" />
                    <h3 class="mt-3 font-semibold">{{ $feature['title'] }}</h3>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $feature['text'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Where answers run --}}
    <section class="border-y border-zinc-200/70 bg-zinc-50/60 dark:border-zinc-800/70 dark:bg-zinc-900/40">
        <div class="mx-auto max-w-6xl px-6 py-16">
            <h2 class="text-2xl font-semibold tracking-tight">{{ __('You choose where the AI runs') }}</h2>
            <p class="mt-2 max-w-2xl text-zinc-600 dark:text-zinc-400">{{ __('All three are free. Search always runs in DocChat, so every answer keeps its sources.') }}</p>
            <div class="mt-8 grid gap-4 md:grid-cols-3">
                @foreach ([
                    ['title' => __('DocChat\'s keys'), 'tag' => __('Default'), 'text' => __('Nothing to set up. Answers use the app\'s free Groq and Gemini quota, shared fairly between workspaces.')],
                    ['title' => __('Your own key'), 'tag' => __('Workspace > AI keys'), 'text' => __('Your own free Groq or Gemini key, with your own limits. Gemini keys also handle search.')],
                    ['title' => __('Your visitor\'s browser'), 'tag' => __('Optional'), 'text' => __('Chrome\'s built-in AI or WebLLM write the answer on the device. Slower, but private and token-free.')],
                ] as $option)
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950">
                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $option['tag'] }}</div>
                        <h3 class="mt-2 font-semibold">{{ $option['title'] }}</h3>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $option['text'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Call to action --}}
    <section class="mx-auto max-w-6xl px-6 py-16 text-center">
        <h2 class="text-2xl font-semibold tracking-tight">{{ __('Try it with your own documents') }}</h2>
        <p class="mx-auto mt-2 max-w-xl text-zinc-600 dark:text-zinc-400">{{ __('Free to use, free to host yourself. No card, no paid plan.') }}</p>
        <div class="mt-6 flex justify-center gap-3">
            <flux:button :href="auth()->check() ? route('dashboard') : route('register')" variant="primary">{{ __('Start free') }}</flux:button>
            <flux:button :href="route('pricing')">{{ __('See pricing') }}</flux:button>
        </div>
    </section>
</x-site>
