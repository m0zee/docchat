@props(['title' => null, 'description' => null])

@php
    $nav = [
        ['label' => __('Features'), 'href' => route('home').'#features', 'active' => false],
        ['label' => __('Pricing'), 'href' => route('pricing'), 'active' => request()->routeIs('pricing')],
        ['label' => __('Docs'), 'href' => route('docs.show'), 'active' => request()->routeIs('docs.*')],
        ['label' => __('API'), 'href' => url('/docs/api'), 'active' => false],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $title ?? __('Chat with your documents')])
        @if ($description)
            <meta name="description" content="{{ $description }}">
        @endif
    </head>
    <body class="flex min-h-screen flex-col bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <header class="border-b border-zinc-200/70 dark:border-zinc-800/70" x-data="{ open: false }">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-4">
                <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold">
                    <x-app-logo-icon class="size-7 fill-current" />
                    {{ config('app.name', 'DocChat') }}
                </a>

                <nav class="hidden items-center gap-1 md:flex" aria-label="{{ __('Main') }}">
                    @foreach ($nav as $item)
                        <a href="{{ $item['href'] }}" @class([
                            'rounded-lg px-3 py-2 text-sm',
                            'font-medium text-zinc-900 dark:text-white' => $item['active'],
                            'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => ! $item['active'],
                        ])>{{ $item['label'] }}</a>
                    @endforeach
                </nav>

                <div class="flex items-center gap-2">
                    @auth
                        <flux:button :href="route('dashboard')" variant="primary" size="sm">{{ __('Open DocChat') }}</flux:button>
                    @else
                        <flux:button :href="route('login')" variant="ghost" size="sm">{{ __('Log in') }}</flux:button>
                        @if (Route::has('register'))
                            <flux:button :href="route('register')" variant="primary" size="sm">{{ __('Start free') }}</flux:button>
                        @endif
                    @endauth
                    <flux:button size="sm" variant="ghost" icon="bars-3" class="md:hidden" x-on:click="open = ! open" :aria-label="__('Menu')" />
                </div>
            </div>

            <nav class="border-t border-zinc-200 px-6 py-2 md:hidden dark:border-zinc-800" x-show="open" x-cloak aria-label="{{ __('Main') }}">
                @foreach ($nav as $item)
                    <a href="{{ $item['href'] }}" class="block py-2 text-sm text-zinc-700 dark:text-zinc-300">{{ $item['label'] }}</a>
                @endforeach
            </nav>
        </header>

        <main class="flex-1">
            {{ $slot }}
        </main>

        <footer class="border-t border-zinc-200/70 dark:border-zinc-800/70">
            <div class="mx-auto flex max-w-6xl flex-col gap-4 px-6 py-8 text-sm text-zinc-500 md:flex-row md:items-center md:justify-between">
                <p>{{ __('A portfolio project running on free tiers for testing. Please upload test documents only.') }}</p>
                <nav class="flex flex-wrap gap-4" aria-label="{{ __('Footer') }}">
                    <a href="{{ route('pricing') }}" class="hover:text-zinc-900 dark:hover:text-white">{{ __('Pricing') }}</a>
                    <a href="{{ route('docs.show') }}" class="hover:text-zinc-900 dark:hover:text-white">{{ __('Docs') }}</a>
                    <a href="{{ url('/docs/api') }}" class="hover:text-zinc-900 dark:hover:text-white">{{ __('API reference') }}</a>
                    <a href="https://github.com/m0zee/docchat" target="_blank" rel="noopener" class="hover:text-zinc-900 dark:hover:text-white">GitHub</a>
                </nav>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
