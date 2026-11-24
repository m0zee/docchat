<?php

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Workspace')] class extends Component {
    public string $name = '';

    public bool $answerCache = true;

    public function mount(): void
    {
        $this->name = $this->workspace->name;
        $this->answerCache = $this->workspace->setting('answer_cache', true) !== false;
    }

    public function save(): void
    {
        Gate::authorize('update', $this->workspace);

        $this->validate(['name' => ['required', 'string', 'max:80']]);

        $this->workspace->update([
            'name' => trim($this->name),
            'settings' => array_merge($this->workspace->settings ?? [], ['answer_cache' => $this->answerCache]),
        ]);

        Flux::toast(variant: 'success', text: __('Workspace saved.'));
    }

    public function leave(): void
    {
        $user = auth()->user();
        $workspace = $this->workspace;

        $owners = $workspace->members()->wherePivot('role', WorkspaceRole::Owner->value)->count();

        if ($workspace->isOwner($user) && $owners <= 1) {
            Flux::toast(variant: 'danger', text: __('Make someone else an owner before you leave.'));

            return;
        }

        $workspace->members()->detach($user->id);

        if ($user->workspaces()->doesntExist()) {
            Workspace::createFor($user);
        }

        app(CurrentWorkspace::class)->forget();
        $this->redirectRoute('dashboard', navigate: true);
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

        <flux:switch wire:model="answerCache" :disabled="! $this->isOwner" :label="__('Answer repeated questions from the cache')" :description="__('Saves tokens when people ask the same thing, for example on the website widget. Cached answers are dropped whenever documents change.')" />

        <flux:text>{{ __('Your role: :role', ['role' => __(ucfirst((string) $this->workspace->roleOf(auth()->user())?->value))]) }}</flux:text>

        @if ($this->isOwner)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>

    <flux:separator class="my-8" />

    <div class="space-y-3">
        <flux:heading>{{ __('Leave workspace') }}</flux:heading>
        <flux:text>{{ __('You lose access to its documents and your chats in it.') }}</flux:text>
        <flux:button variant="danger" wire:click="leave" wire:confirm="{{ __('Leave :name?', ['name' => $this->workspace->name]) }}">{{ __('Leave') }}</flux:button>
    </div>
</x-pages::workspace.layout>
