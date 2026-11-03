<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#160D76">
    <title>@yield('code') &middot; {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/images/asi-coin.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('assets/images/asi-coin.png') }}">
    <link href="{{ asset('assets/vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body>
<main class="error-page">
    <div class="text-center" style="max-width: 36rem">
        <a class="app-brand justify-content-center mb-5" href="{{ auth()->check() ? route('dashboard') : route('login') }}" style="color: var(--asi-navy)">
            <img class="error-brand-logo" src="{{ asset('assets/images/asi-procura-logo.png') }}" alt="ASI Procura">
        </a>
        <div class="error-code">@yield('code')</div>
        <h1 class="h2 mt-4">@yield('title')</h1>
        <p class="text-muted mb-4">@yield('message')</p>
        <a class="btn btn-primary" href="{{ auth()->check() ? route('dashboard') : route('login') }}">
            {{ auth()->check() ? 'Return to dashboard' : 'Return to sign in' }}
        </a>
    </div>
</main>
</body>
</html>
