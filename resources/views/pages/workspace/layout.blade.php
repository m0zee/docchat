@php
    $workspace = app(\App\Support\CurrentWorkspace::class)->require();
    $owner = auth()->user()->can('manage', $workspace);
@endphp

<section class="w-full">
    <div class="relative mb-6 w-full">
        <flux:heading size="xl" level="1">{{ __('Workspace') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ $workspace->name }}</flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-[220px]">
            <flux:navlist aria-label="{{ __('Workspace settings') }}">
                <flux:navlist.item :href="route('workspace.general')" :current="request()->routeIs('workspace.general')" wire:navigate>{{ __('General') }}</flux:navlist.item>
                @if ($owner)
                    @foreach (config('docchat.workspace_nav', []) as $route => $label)
                        @if (\Illuminate\Support\Facades\Route::has($route))
                            <flux:navlist.item :href="route($route)" :current="request()->routeIs($route)" wire:navigate>{{ __($label) }}</flux:navlist.item>
                        @endif
                    @endforeach
                @endif
            </flux:navlist>
        </div>

        <flux:separator class="md:hidden" />

        <div class="flex-1 self-stretch max-md:pt-6">
            <flux:heading>{{ $heading ?? '' }}</flux:heading>
            <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

            <div class="mt-5 w-full max-w-2xl">
                {{ $slot }}
            </div>
        </div>
    </div>
</section>
