<?php

use App\Enums\WorkspaceRole;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Support\CurrentWorkspace;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Members')] class extends Component {
    public string $email = '';

    public string $role = 'member';

    /**
     * The last invite link, shown once so the owner can copy it.
     */
    public ?string $inviteUrl = null;

    public function mount(): void
    {
        Gate::authorize('manage', $this->workspace);
    }

    /**
     * Add someone who already has an account; invite them otherwise.
     */
    public function add(): void
    {
        Gate::authorize('manage', $this->workspace);
        $this->validateForm();

        $user = User::query()->whereRaw('lower(email) = ?', [Str::lower(trim($this->email))])->first();

        if ($user === null) {
            $this->addError('email', __('No account uses this email yet. Send an invite link instead.'));

            return;
        }

        if ($this->workspace->hasMember($user)) {
            $this->addError('email', __(':email is already a member.', ['email' => $user->email]));

            return;
        }

        $this->workspace->members()->attach($user->id, ['role' => $this->role]);
        $this->reset('email');
        unset($this->members);

        Flux::toast(variant: 'success', text: __(':name was added.', ['name' => $user->name]));
    }

    public function invite(): void
    {
        Gate::authorize('manage', $this->workspace);
        $this->validateForm();

        [$invitation, $token] = WorkspaceInvitation::issue(
            $this->workspace,
            $this->email,
            WorkspaceRole::from($this->role),
            Auth::user(),
        );

        $this->inviteUrl = route('invitations.show', $token);
        Mail::to($invitation->email)->queue(new WorkspaceInvitationMail($invitation, $this->inviteUrl));

        $this->reset('email');
        unset($this->invitations);

        Flux::toast(variant: 'success', text: __('Invitation sent to :email.', ['email' => $invitation->email]));
    }

    public function revokeInvitation(int $invitationId): void
    {
        Gate::authorize('manage', $this->workspace);

        WorkspaceInvitation::query()->where('workspace_id', $this->workspace->id)->whereKey($invitationId)->delete();
        unset($this->invitations);
    }

    public function changeRole(int $userId, string $role): void
    {
        Gate::authorize('manage', $this->workspace);

        $role = WorkspaceRole::tryFrom($role);
        $member = $this->workspace->members()->whereKey($userId)->first();

        if ($role === null || $member === null) {
            return;
        }

        if ($role !== WorkspaceRole::Owner && $this->isLastOwner($member)) {
            Flux::toast(variant: 'danger', text: __('A workspace needs at least one owner.'));

            return;
        }

        $this->workspace->members()->updateExistingPivot($userId, ['role' => $role->value]);
        unset($this->members);
    }

    public function remove(int $userId): void
    {
        Gate::authorize('manage', $this->workspace);

        $member = $this->workspace->members()->whereKey($userId)->first();

        if ($member === null) {
            return;
        }

        if ($this->isLastOwner($member)) {
            Flux::toast(variant: 'danger', text: __('A workspace needs at least one owner.'));

            return;
        }

        $this->workspace->members()->detach($userId);
        unset($this->members);

        if ($userId === Auth::id()) {
            app(CurrentWorkspace::class)->forget();
            $this->redirectRoute('dashboard', navigate: true);
        }
    }

    #[Computed]
    public function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->require();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->workspace->members()->orderBy('users.name')->get();
    }

    /**
     * @return Collection<int, WorkspaceInvitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        return WorkspaceInvitation::query()->where('workspace_id', $this->workspace->id)->open()->latest()->get();
    }

    private function isLastOwner(User $member): bool
    {
        return $member->pivot?->getAttribute('role') === WorkspaceRole::Owner->value
            && $this->workspace->members()->wherePivot('role', WorkspaceRole::Owner->value)->count() <= 1;
    }

    private function validateForm(): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
        ]);
    }
}; ?>

<x-pages::workspace.layout :heading="__('Members')" :subheading="__('Owners manage the workspace. Members upload documents and chat.')">
    <div class="space-y-8">
        <form class="space-y-4" wire:submit="invite">
            <div class="grid gap-3 sm:grid-cols-[1fr_9rem]">
                <flux:input wire:model="email" type="email" :label="__('Email')" placeholder="teammate@company.com" required />
                <flux:select wire:model="role" :label="__('Role')">
                    <flux:select.option value="member">{{ __('Member') }}</flux:select.option>
                    <flux:select.option value="owner">{{ __('Owner') }}</flux:select.option>
                </flux:select>
            </div>

            <div class="flex flex-wrap gap-2">
                <flux:button type="submit" variant="primary" icon="envelope">{{ __('Send invite link') }}</flux:button>
                <flux:button type="button" wire:click="add" icon="user-plus">{{ __('Add existing user') }}</flux:button>
            </div>

            @if ($inviteUrl)
                <flux:callout icon="link" data-test="invite-url">
                    <flux:callout.heading>{{ __('Invite link (valid for :days days, works once)', ['days' => \App\Models\WorkspaceInvitation::VALID_DAYS]) }}</flux:callout.heading>
                    <flux:callout.text>
                        <span class="break-all font-mono text-xs">{{ $inviteUrl }}</span>
                    </flux:callout.text>
                    <x-slot name="actions">
                        <flux:button size="sm" x-on:click="navigator.clipboard.writeText(@js($inviteUrl))">{{ __('Copy') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @endif
        </form>

        <div>
            <flux:heading size="lg" class="mb-3">{{ __('People') }}</flux:heading>
            <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->members as $member)
                    <div class="flex flex-wrap items-center gap-3 p-3" wire:key="member-{{ $member->id }}">
                        <flux:avatar :name="$member->name" :initials="$member->initials()" size="sm" />
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ $member->name }} @if ($member->id === auth()->id()) <span class="text-xs text-zinc-500">({{ __('you') }})</span> @endif</div>
                            <div class="truncate text-sm text-zinc-500">{{ $member->email }}</div>
                        </div>
                        <flux:select size="sm" class="w-32" wire:change="changeRole({{ $member->id }}, $event.target.value)">
                            @foreach (\App\Enums\WorkspaceRole::cases() as $case)
                                <flux:select.option :value="$case->value" :selected="$member->pivot->role === $case->value">{{ __($case->label()) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="remove({{ $member->id }})" wire:confirm="{{ __('Remove :name from this workspace?', ['name' => $member->name]) }}" :aria-label="__('Remove')" />
                    </div>
                @endforeach
            </div>
        </div>

        @if ($this->invitations->isNotEmpty())
            <div>
                <flux:heading size="lg" class="mb-3">{{ __('Pending invitations') }}</flux:heading>
                <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($this->invitations as $invitation)
                        <div class="flex items-center gap-3 p-3" wire:key="inv-{{ $invitation->id }}">
                            <div class="min-w-0 flex-1">
                                <div class="truncate">{{ $invitation->email }}</div>
                                <div class="text-sm text-zinc-500">{{ __($invitation->role->label()) }} · {{ __('expires :date', ['date' => $invitation->expires_at->diffForHumans()]) }}</div>
                            </div>
                            <flux:button size="sm" variant="ghost" wire:click="revokeInvitation({{ $invitation->id }})">{{ __('Revoke') }}</flux:button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-pages::workspace.layout>
