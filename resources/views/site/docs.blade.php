<x-site :title="$page === 'index' ? __('Docs') : __($title).' - '.__('Docs')" :description="__('How to use DocChat: documents, chat, the website widget, chat apps, the API and AI options.')">
    <div class="mx-auto flex max-w-6xl gap-10 px-6 py-10 md:py-14">
        <aside class="hidden w-56 shrink-0 md:block">
            <nav class="sticky top-6 space-y-6 text-sm" aria-label="{{ __('Docs') }}">
                @foreach (\App\Support\Docs::PAGES as $section => $pages)
                    <div>
                        <div class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __($section) }}</div>
                        <ul class="space-y-1">
                            @foreach ($pages as $slug => $label)
                                <li>
                                    <a href="{{ route('docs.show', $slug === 'index' ? null : $slug) }}" @class([
                                        'block rounded-md px-2 py-1',
                                        'bg-zinc-100 font-medium text-zinc-900 dark:bg-zinc-800 dark:text-white' => $slug === $page,
                                        'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => $slug !== $page,
                                    ])>{{ __($label) }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
                <div>
                    <div class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Reference') }}</div>
                    <a href="{{ url('/docs/api') }}" class="block rounded-md px-2 py-1 text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">{{ __('API reference') }} ↗</a>
                </div>
            </nav>
        </aside>

        <article class="min-w-0 flex-1">
            <div class="mb-6 md:hidden">
                <flux:select :label="__('Docs page')" onchange="location.href = this.value">
                    @foreach (\App\Support\Docs::all() as $slug => $label)
                        <option value="{{ route('docs.show', $slug === 'index' ? null : $slug) }}" @selected($slug === $page)>{{ __($label) }}</option>
                    @endforeach
                </flux:select>
            </div>

            <h1 class="text-3xl font-semibold tracking-tight">{{ $page === 'index' ? __('DocChat docs') : __($title) }}</h1>

            <div class="docs-content mt-6 max-w-3xl text-zinc-700 dark:text-zinc-300
                [&_a]:font-medium [&_a]:text-zinc-900 [&_a]:underline [&_a]:underline-offset-2 dark:[&_a]:text-white
                [&_code]:rounded [&_code]:bg-zinc-100 [&_code]:px-1 [&_code]:py-0.5 [&_code]:text-[0.9em] dark:[&_code]:bg-zinc-800
                [&_h2]:mt-10 [&_h2]:mb-3 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-zinc-900 dark:[&_h2]:text-white
                [&_h3]:mt-6 [&_h3]:mb-2 [&_h3]:font-semibold [&_h3]:text-zinc-900 dark:[&_h3]:text-white
                [&_li]:my-1 [&_ol]:my-4 [&_ol]:list-decimal [&_ol]:ps-6 [&_ul]:my-4 [&_ul]:list-disc [&_ul]:ps-6
                [&_p]:my-4 [&_p]:leading-7
                [&_pre]:my-4 [&_pre]:overflow-x-auto [&_pre]:rounded-lg [&_pre]:bg-zinc-100 [&_pre]:p-4 [&_pre]:text-sm dark:[&_pre]:bg-zinc-900 [&_pre_code]:bg-transparent [&_pre_code]:p-0
                [&_strong]:text-zinc-900 dark:[&_strong]:text-white
                [&_table]:my-4 [&_table]:w-full [&_table]:text-sm [&_td]:border-t [&_td]:border-zinc-200 [&_td]:py-2 [&_td]:pe-4 dark:[&_td]:border-zinc-800 [&_th]:py-2 [&_th]:pe-4 [&_th]:text-start [&_th]:font-medium [&_th]:text-zinc-900 dark:[&_th]:text-white">
                {!! $html !!}
            </div>

            <nav class="mt-12 flex justify-between gap-4 border-t border-zinc-200 pt-6 text-sm dark:border-zinc-800" aria-label="{{ __('Previous and next') }}">
                @if ($previous)
                    <a href="{{ route('docs.show', $previous === 'index' ? null : $previous) }}" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">← {{ __(\App\Support\Docs::title($previous)) }}</a>
                @else
                    <span></span>
                @endif
                @if ($next)
                    <a href="{{ route('docs.show', $next) }}" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">{{ __(\App\Support\Docs::title($next)) }} →</a>
                @endif
            </nav>
        </article>
    </div>
</x-site>
