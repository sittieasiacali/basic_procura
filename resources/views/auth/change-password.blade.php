<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change your password &middot; {{ config('app.name') }}</title>
    <link href="{{ asset('assets/vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<main class="first-password-page">
    <section class="first-password-card" aria-labelledby="first-password-title">
        <img src="{{ asset('assets/images/asi-procura-logo.png') }}" alt="ASI Procura" width="170">
        <h1 id="first-password-title">Set your password</h1>
        <p>Choose a new password before continuing to PROCURA.</p>
        <div class="first-password-account">{{ auth()->user()->email }}</div>
        @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <label class="form-label" for="password">New password</label>
            <input class="form-control mb-3" id="password" name="password" type="password" minlength="8" placeholder="At least 8 characters" required autofocus autocomplete="new-password">
            <label class="form-label" for="password_confirmation">Confirm new password</label>
            <input class="form-control mb-4" id="password_confirmation" name="password_confirmation" type="password" minlength="8" required autocomplete="new-password">
            <button class="btn btn-primary w-100" type="submit">Save password and continue</button>
        </form>
        <form method="POST" action="{{ route('logout') }}" class="mt-3 text-center">@csrf<button class="btn btn-link btn-sm" type="submit">Sign out</button></form>
    </section>
</main>
</body>
</html>
