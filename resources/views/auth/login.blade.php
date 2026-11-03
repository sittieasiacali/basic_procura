<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#FFFFFA">
    <title>Sign in &middot; {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/images/asi-coin.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('assets/images/asi-coin.png') }}">
    <link href="{{ asset('assets/vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<main class="auth-page" style="--auth-building-image: url('{{ asset('assets/images/Allianz.png') }}');">
    <div class="auth-layout">
        <section class="auth-form-panel" aria-labelledby="sign-in-title">
            <img class="auth-logo" src="{{ asset('assets/images/asi-procura-logo.png') }}" alt="ASI Procura">
            <div class="auth-heading-accent" aria-hidden="true"></div>
            <h1 id="sign-in-title">Welcome back</h1>
            <p class="auth-subtitle">Sign in to continue to your account.</p>

            @if($errors->any())
                <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}" novalidate>
                @csrf
                <div class="auth-field">
                    <label class="visually-hidden" for="email">Email or username</label>
                    <span class="auth-field-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    </span>
                    <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="text" value="{{ old('email') }}" placeholder="Email or username" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" @error('email') aria-describedby="email-error" @enderror>
                    @error('email')<div class="auth-field-error" id="email-error">{{ $message }}</div>@enderror
                </div>
                <div class="auth-field">
                    <label class="visually-hidden" for="password">Password</label>
                    <span class="auth-field-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </span>
                    <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password" placeholder="Password" required autocomplete="current-password" @error('password') aria-describedby="password-error" @enderror>
                    @error('password')<div class="auth-field-error" id="password-error">{{ $message }}</div>@enderror
                </div>
                <div class="form-check auth-remember">
                    <input class="form-check-input" id="remember" name="remember" type="checkbox" value="1">
                    <label class="form-check-label" for="remember">Keep me signed in</label>
                </div>
                <button class="btn btn-primary auth-submit" type="submit">Sign in <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></button>
            </form>
        </section>
    </div>
</main>
</body>
</html>
