<x-mail::message>
@php
    $local = $call->scheduledLocal();
@endphp
# {{ $reminder ? __('Your call with :workspace is ready', ['workspace' => $call->workspace->name]) : __(':workspace would like to talk', ['workspace' => $call->workspace->name]) }}

{{ __('Hi :name,', ['name' => $call->contact?->name ?? __('there')]) }}

@if ($reminder)
{{ __('It\'s time for your call with :workspace. Open the link below whenever you are ready.', ['workspace' => $call->workspace->name]) }}
@elseif ($call->purpose)
{{ __(':workspace set up a short voice call with you about: :purpose', ['workspace' => $call->workspace->name, 'purpose' => $call->purpose]) }}
@else
{{ __(':workspace set up a short voice call with you.', ['workspace' => $call->workspace->name]) }}
@endif

@if ($local && ! $reminder)
**{{ __('When: :time (:zone)', ['time' => $local->isoFormat('dddd D MMMM, HH:mm'), 'zone' => $local->getTimezone()->getName()]) }}**. {{ __('The link opens a few minutes before; we will email you again when it is time.') }}
@endif

{{ __('You will talk to an automated assistant in your browser. It answers from :workspace\'s documents.', ['workspace' => $call->workspace->name]) }}

<x-mail::button :url="$url">
{{ __('Start the call') }}
</x-mail::button>

{{ __('The link works until :date. If you do not want calls like this, open the link and choose "Don\'t call me again".', ['date' => $call->expires_at?->toFormattedDayDateString()]) }}
</x-mail::message>
