<x-mail::message>
# {{ __(':workspace asks to use a copy of your voice', ['workspace' => $clone->workspace->name]) }}

{{ __('Hi :name,', ['name' => $clone->speaker_name]) }}

{{ __(':workspace would like its virtual assistant to speak with an AI copy of your voice on calls. Calls still say they are a virtual assistant.', ['workspace' => $clone->workspace->name]) }}

{{ __('This only happens if you agree. Open the link, read a short consent statement aloud and record it in your browser. You can withdraw at any time from the same link, which deletes the recording.') }}

<x-mail::button :url="$url">
{{ __('Review and decide') }}
</x-mail::button>

{{ __('The link works until :date. If you don\'t agree, you can ignore this email or cancel the request on the page.', ['date' => $clone->expires_at?->toFormattedDayDateString()]) }}
</x-mail::message>
