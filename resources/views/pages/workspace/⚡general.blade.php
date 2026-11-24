<?php

use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Workspace')] class extends Component {
    public string $name = '';

    public function mount(): void
    {
        $this->name = $this->workspace->name;
    }

    public function save(): void
    {
        Gate::authorize('update', $this->workspace);

        $this->validate(['name' => ['required', 'string', 'max:80']]);

        $this->workspace->update(['name' => trim($this->name)]);

        Flux::toast(variant: 'success', text: __('Workspace renamed.'));
    }

    #[Computed]
    public function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->require();
    }

    #[Computed]
    public function isOwner(): bool
    {
        return auth()->user()->can('update', $this->workspace);
    }
}; ?>

<x-pages::workspace.layout :heading="__('General')" :subheading="__('The name your team and API clients see.')">
    <form wire:submit="save" class="w-full space-y-6">
        <flux:input wire:model="name" :label="__('Workspace name')" :disabled="! $this->isOwner" required maxlength="80" />

        <flux:text>{{ __('Your role: :role', ['role' => __(ucfirst((string) $this->workspace->roleOf(auth()->user())?->value))]) }}</flux:text>

        @if ($this->isOwner)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>
</x-pages::workspace.layout>
