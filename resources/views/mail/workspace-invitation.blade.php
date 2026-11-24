<x-mail::message>
# {{ __('You are invited to :workspace', ['workspace' => $invitation->workspace->name]) }}

{{ __(':name invited you to join the workspace ":workspace" on :app as a :role.', [
    'name' => $invitation->invitedBy?->name ?? __('Someone'),
    'workspace' => $invitation->workspace->name,
    'app' => config('app.name'),
    'role' => strtolower($invitation->role->label()),
]) }}

<x-mail::button :url="$url">
{{ __('Join the workspace') }}
</x-mail::button>

{{ __('The link works once and expires on :date. If you don\'t have an account yet, create one with this email address (:email) and the link takes you back here.', [
    'date' => $invitation->expires_at->toFormattedDayDateString(),
    'email' => $invitation->email,
]) }}
</x-mail::message>
