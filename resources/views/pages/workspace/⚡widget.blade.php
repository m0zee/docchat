<?php

use App\Models\Workspace;
use App\Services\Widget\WidgetSettings;
use App\Support\CurrentWorkspace;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Widget')] class extends Component {
    public string $title = '';

    public string $welcome = '';

    public string $color = '';

    public string $position = 'right';

    public string $handoffEmail = '';

    public string $handoffText = '';

    /** Allowed websites, one per line. */
    public string $origins = '';

    /** Bumped on save so the preview reloads. */
    public int $previewVersion = 0;

    public function mount(): void
    {
        Gate::authorize('manage', $this->workspace);

        $settings = WidgetSettings::for($this->workspace);
        $this->title = $settings['title'];
        $this->welcome = $settings['welcome'];
        $this->color = $settings['color'];
        $this->position = $settings['position'];
        $this->handoffEmail = (string) $settings['handoff_email'];
        $this->handoffText = $settings['handoff_text'];
        $this->origins = implode("\n", WidgetSettings::key($this->workspace)?->allowed_origins ?? []);
    }

    public function save(): void
    {
        Gate::authorize('manage', $this->workspace);

        $this->validate([
            'title' => ['required', 'string', 'max:60'],
            'welcome' => ['required', 'string', 'max:300'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'position' => ['required', 'in:left,right'],
            'handoffEmail' => ['nullable', 'email', 'max:255'],
            'handoffText' => ['nullable', 'string', 'max:200'],
            'origins' => ['nullable', 'string', 'max:4000'],
        ]);

        [$origins, $invalid] = WidgetSettings::parseOrigins($this->origins);

        if ($invalid !== []) {
            $this->addError('origins', __('These are not website addresses: :list', ['list' => implode(', ', $invalid)]));

            return;
        }

        if (count($origins) > 20) {
            $this->addError('origins', __('Add at most 20 websites.'));

            return;
        }

        WidgetSettings::save($this->workspace, [
            'title' => $this->title,
            'welcome' => $this->welcome,
            'color' => strtolower($this->color),
            'position' => $this->position,
            'handoff_email' => $this->handoffEmail !== '' ? $this->handoffEmail : null,
            'handoff_text' => $this->handoffText,
        ]);

        $key = WidgetSettings::key($this->workspace);

        if ($key === null) {
            WidgetSettings::rotateKey($this->workspace->refresh(), $origins, Auth::user());
        } else {
            $key->update(['allowed_origins' => $origins]);
        }

        $this->origins = implode("\n", $origins);
        $this->previewVersion++;
        unset($this->workspace);
        Flux::toast(variant: 'success', text: __('Widget saved.'));
    }

    public function rotateKey(): void
    {
        Gate::authorize('manage', $this->workspace);

        // Keep the saved websites, not unsaved edits in the form.
        WidgetSettings::rotateKey($this->workspace, WidgetSettings::key($this->workspace)?->allowed_origins ?? [], Auth::user());

        $this->previewVersion++;
        unset($this->workspace);
        Flux::toast(text: __('New widget key created. Update the snippet on your website.'));
    }

    public function snippet(): ?string
    {
        $key = WidgetSettings::plainKey($this->workspace);

        return $key === null ? null : '<script src="'.route('widget.script').'" data-key="'.e($key).'" async></script>';
    }

    #[Computed]
    public function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->require()->refresh();
    }
}; ?>

<x-pages::workspace.layout :heading="__('Website widget')" :subheading="__('A chat bubble for your website. Visitors ask without an account; answers come only from this workspace\'s documents.')">
    <div class="space-y-8">
        <form wire:submit="save" class="space-y-4">
            <flux:input wire:model="title" :label="__('Title')" required />
            <flux:textarea wire:model="welcome" :label="__('Welcome message')" rows="2" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="color" type="color" :label="__('Brand color')" />
                <flux:select wire:model="position" :label="__('Position')">
                    <flux:select.option value="right">{{ __('Bottom right') }}</flux:select.option>
                    <flux:select.option value="left">{{ __('Bottom left') }}</flux:select.option>
                </flux:select>
            </div>
            <flux:textarea wire:model="origins" :label="__('Websites allowed to use the widget')" :description="__('One per line, like example.com or https://shop.example.com. The widget refuses to work anywhere else.')" rows="3" placeholder="example.com" />
            <flux:input wire:model="handoffEmail" type="email" :label="__('Handoff email')" :description="__('Shown when the documents have no answer, so visitors can reach a person.')" placeholder="support@example.com" />
            <flux:input wire:model="handoffText" :label="__('Handoff text')" />
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
        </form>

        @if ($snippet = $this->snippet())
            <div class="space-y-2" x-data="{ copied: false }">
                <flux:heading>{{ __('Embed code') }}</flux:heading>
                <flux:text>{{ __('Paste this before </body> on every page that should show the chat.') }}</flux:text>
                <pre class="overflow-x-auto rounded-lg bg-zinc-100 p-3 text-xs dark:bg-zinc-800"><code x-ref="snippet" data-test="widget-snippet">{{ $snippet }}</code></pre>
                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" icon="clipboard" x-on:click="navigator.clipboard.writeText($refs.snippet.textContent); copied = true; setTimeout(() => copied = false, 2000)">
                        <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'">{{ __('Copy') }}</span>
                    </flux:button>
                    <flux:button size="sm" wire:click="rotateKey" wire:confirm="{{ __('Make a new key? The old snippet stops working.') }}">{{ __('New key') }}</flux:button>
                </div>
                <flux:text class="text-xs">{{ __('The key in the snippet is public: it can only ask questions, only from the websites above, with a limit of :n questions a minute per visitor.', ['n' => config('docchat.api.public_rate_limit_per_minute')]) }}</flux:text>
            </div>

            <div class="space-y-2">
                <flux:heading>{{ __('Preview') }}</flux:heading>
                <iframe wire:key="preview-{{ $previewVersion }}" src="{{ route('workspace.widget.preview', ['v' => $previewVersion]) }}" title="{{ __('Widget preview') }}" class="h-[640px] w-full rounded-xl border border-zinc-200 dark:border-zinc-700"></iframe>
            </div>
        @else
            <flux:text>{{ __('Save once to create the widget key and the embed code.') }}</flux:text>
        @endif
    </div>
</x-pages::workspace.layout>
