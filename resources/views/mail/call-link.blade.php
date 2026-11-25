<x-mail::message>
# {{ __(':workspace would like to talk', ['workspace' => $call->workspace->name]) }}

{{ __('Hi :name,', ['name' => $call->contact?->name ?? __('there')]) }}

@if ($call->purpose)
{{ __(':workspace set up a short voice call with you about: :purpose', ['workspace' => $call->workspace->name, 'purpose' => $call->purpose]) }}
@else
{{ __(':workspace set up a short voice call with you.', ['workspace' => $call->workspace->name]) }}
@endif

{{ __('You will talk to an automated assistant in your browser. It answers from :workspace\'s documents.', ['workspace' => $call->workspace->name]) }}

<x-mail::button :url="$url">
{{ __('Start the call') }}
</x-mail::button>

{{ __('The link works until :date. If you do not want calls like this, open the link and choose "Don\'t call me again".', ['date' => $call->expires_at?->toFormattedDayDateString()]) }}
</x-mail::message>
