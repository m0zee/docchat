<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Widget preview') }}</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; background: #f8fafc; color: #334155; }
        main { max-width: 640px; margin: 0 auto; padding: 32px 20px; }
        .bar { height: 12px; border-radius: 6px; background: #e2e8f0; margin: 14px 0; }
        @media (prefers-color-scheme: dark) { body { background: #0f172a; color: #cbd5e1; } .bar { background: #1e293b; } }
    </style>
</head>
<body>
    <main>
        <h1>{{ __('Your website') }}</h1>
        <p>{{ __('This is how the chat looks on your pages. Questions asked here use your real documents and count toward usage.') }}</p>
        <div class="bar" style="width: 90%"></div>
        <div class="bar" style="width: 75%"></div>
        <div class="bar" style="width: 82%"></div>
    </main>
    <script src="{{ route('widget.script') }}" data-key="{{ $key }}" data-open async></script>
</body>
</html>
