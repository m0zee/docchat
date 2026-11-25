<?php

use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $newName = '';

    public function switchTo(int $workspaceId): void
    {
        $workspace = Workspace::query()->find($workspaceId);

        if ($workspace !== null && $this->user()->switchWorkspace($workspace)) {
            app(CurrentWorkspace::class)->forget();
            $this->redirectRoute('dashboard', navigate: true);
        }
    }

    public function create(): void
    {
        $this->validate(['newName' => ['required', 'string', 'max:80']]);

        $workspace = Workspace::createFor($this->user(), $this->newName);
        $this->user()->switchWorkspace($workspace);
        app(CurrentWorkspace::class)->forget();

        $this->redirectRoute('dashboard', navigate: true);
    }

    #[Computed]
    public function current(): Workspace
    {
        return app(CurrentWorkspace::class)->require();
    }

    /**
     * @return Collection<int, Workspace>
     */
    #[Computed]
    public function workspaces(): Collection
    {
        return $this->user()->workspaces()->get();
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<div>
    <flux:dropdown position="bottom" align="start" class="w-full">
        <flux:button variant="ghost" icon="building-office-2" icon:trailing="chevrons-up-down" class="w-full justify-start! truncate" data-test="workspace-switcher">
            <span class="truncate">{{ $this->current->name }}</span>
        </flux:button>

        <flux:menu class="min-w-64">
            <flux:menu.heading>{{ __('Workspaces') }}</flux:menu.heading>

            @foreach ($this->workspaces as $workspace)
                <flux:menu.item
                    wire:click="switchTo({{ $workspace->id }})"
                    :icon="$workspace->id === $this->current->id ? 'check' : 'building-office-2'"
                    wire:key="ws-{{ $workspace->id }}"
                >
                    <span class="truncate">{{ $workspace->name }}</span>
                    <span class="ms-auto ps-3 text-xs text-zinc-400">{{ __(ucfirst($workspace->pivot->role)) }}</span>
                </flux:menu.item>
            @endforeach

            <flux:menu.separator />

            <flux:menu.item :href="route('workspace.general')" icon="cog-6-tooth" wire:navigate>
                {{ __('Workspace settings') }}
            </flux:menu.item>

            <flux:modal.trigger name="create-workspace">
                <flux:menu.item icon="plus">{{ __('New workspace') }}</flux:menu.item>
            </flux:modal.trigger>
        </flux:menu>
    </flux:dropdown>

    <flux:modal name="create-workspace" class="md:w-96">
        <form wire:submit="create" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('New workspace') }}</flux:heading>
                <flux:text class="mt-2">{{ __('A workspace has its own documents, chats, members and API keys.') }}</flux:text>
            </div>

            <flux:input wire:model="newName" :label="__('Name')" required maxlength="80" />

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
