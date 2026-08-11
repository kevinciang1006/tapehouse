<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Tapehouse')</title>

    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="user-id" content="{{ auth()->id() }}">

    {{-- Fonts are linked here, not @import'd from SCSS, so the request
    fires immediately instead of being serialised behind the stylesheet. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ \App\Support\Manifest::asset('app.css') }}">
</head>
<body>
@yield('body')
<script src="{{ \App\Support\Manifest::asset('app.js') }}"></script>
</body>
</html>
