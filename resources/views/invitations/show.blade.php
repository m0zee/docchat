<x-layouts::auth :title="__('Workspace invitation')">
    <div class="flex flex-col gap-6">
        @if ($invitation === null)
            <x-auth-header :title="__('Invitation not valid')" :description="__('This invitation link was already used, has expired, or was replaced by a newer one. Ask the workspace owner to invite you again.')" />

            <flux:button :href="route('home')" variant="primary" class="w-full">{{ __('Go to DocChat') }}</flux:button>
        @else
            <x-auth-header
                :title="__('Join :workspace', ['workspace' => $invitation->workspace->name])"
                :description="__('You were invited as a :role. Invitation for :email.', ['role' => strtolower($invitation->role->label()), 'email' => $invitation->email])"
            />

            @if (session('invitation_error'))
                <flux:callout variant="danger" icon="exclamation-triangle" :text="session('invitation_error')" />
            @endif

            @if ($user)
                <form method="POST" action="{{ route('invitations.accept', $token) }}">
                    @csrf
                    <flux:button type="submit" variant="primary" class="w-full" data-test="accept-invitation">
                        {{ __('Accept and join') }}
                    </flux:button>
                </form>

                <flux:text class="text-center text-sm">{{ __('Signed in as :email', ['email' => $user->email]) }}</flux:text>
            @else
                <div class="flex flex-col gap-3">
                    <flux:button :href="route('register')" variant="primary" class="w-full">{{ __('Create an account') }}</flux:button>
                    <flux:button :href="route('login')" class="w-full">{{ __('I already have an account') }}</flux:button>
                </div>

                <flux:text class="text-center text-sm">{{ __('Use :email. You will come back here to join.', ['email' => $invitation->email]) }}</flux:text>
            @endif
        @endif
    </div>
</x-layouts::auth>
