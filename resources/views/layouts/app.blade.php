<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Tapehouse')</title>

    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="user-id" content="{{ auth()->id() }}">

    {{-- Reverb connection config for echo.js. Read from the broadcasting
    config (not env() directly) so this keeps working when config is
    cached. --}}
    {{-- client_host, NOT host: the browser and the queue worker's
    server-to-server broadcast publish read two different config keys on
    purpose — see config/broadcasting.php for why reusing one for both
    left the browser unable to resolve the address it was handed. --}}
    <meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
    <meta name="reverb-host" content="{{ config('broadcasting.connections.reverb.options.client_host') }}">
    <meta name="reverb-port" content="{{ config('broadcasting.connections.reverb.options.port') }}">
    <meta name="reverb-scheme" content="{{ config('broadcasting.connections.reverb.options.scheme') }}">

    {{-- Fonts are self-hosted: @font-face declarations live in
    resources/scss/_fonts.scss (imported first in app.scss) and are bundled
    into app.css below, so there is no external font request at all. --}}
    <link rel="stylesheet" href="{{ \App\Support\Manifest::asset('app.css') }}">
</head>
<body>
@yield('body')
<script src="{{ \App\Support\Manifest::asset('app.js') }}"></script>
</body>
</html>
