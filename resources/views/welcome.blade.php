<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => __('Chat with your documents')])
    </head>
    <body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <header class="mx-auto flex max-w-5xl items-center justify-between px-6 py-6">
            <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold">
                <x-app-logo-icon class="size-7 fill-current" />
                {{ config('app.name', 'DocChat') }}
            </a>

            <nav class="flex items-center gap-2">
                @auth
                    <flux:button :href="route('dashboard')" variant="primary" size="sm">{{ __('Open DocChat') }}</flux:button>
                @else
                    <flux:button :href="route('login')" variant="ghost" size="sm">{{ __('Log in') }}</flux:button>
                    @if (Route::has('register'))
                        <flux:button :href="route('register')" variant="primary" size="sm">{{ __('Sign up') }}</flux:button>
                    @endif
                @endauth
            </nav>
        </header>

        <main class="mx-auto max-w-5xl px-6 pb-20">
            <section class="py-16 text-center md:py-24">
                <h1 class="text-4xl font-semibold tracking-tight md:text-5xl">{{ __('Ask your documents. Get answers with sources.') }}</h1>
                <p class="mx-auto mt-5 max-w-2xl text-lg text-zinc-600 dark:text-zinc-400">
                    {{ __('Upload PDF, TXT or Markdown files and chat with them. Every answer cites the document and page it came from, and when the answer is not in your files, DocChat says so instead of guessing.') }}
                </p>
                <div class="mt-8 flex justify-center gap-3">
                    <flux:button :href="auth()->check() ? route('documents.index') : route('register')" variant="primary">{{ __('Upload a document') }}</flux:button>
                    <flux:button href="https://github.com/m0zee/docchat" target="_blank" icon="code-bracket">{{ __('View the code') }}</flux:button>
                </div>
            </section>

            <section class="grid gap-4 md:grid-cols-3">
                @foreach ([
                    ['icon' => 'document-arrow-up', 'title' => __('Upload'), 'text' => __('Files are parsed page by page, split into overlapping chunks and embedded in the background.')],
                    ['icon' => 'magnifying-glass', 'title' => __('Retrieve'), 'text' => __('Your question is matched to the closest chunks with pgvector. Weak matches are dropped.')],
                    ['icon' => 'chat-bubble-left-right', 'title' => __('Answer with citations'), 'text' => __('The answer streams in and cites each source as document, page and snippet.')],
                ] as $step)
                    <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-800">
                        <flux:icon :name="$step['icon']" class="size-6 text-zinc-500" />
                        <h2 class="mt-3 font-semibold">{{ $step['title'] }}</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $step['text'] }}</p>
                    </div>
                @endforeach
            </section>

            <p class="mt-12 text-center text-sm text-zinc-500">
                {{ __('A portfolio project running on free tiers for testing. Please upload test documents only.') }}
            </p>
        </main>

        @fluxScripts
    </body>
</html>
