<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @isset($purchaseRequest)<meta name="workflow-request-code" content="{{ $purchaseRequest->request_number }}">@endisset
    <meta name="theme-color" content="#0b3b8f">
    <title>@yield('title', 'Home') &middot; {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/images/asi-coin.png') }}">
    <link href="{{ asset('assets/vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <script>
        try {
            if (window.self !== window.top) document.documentElement.classList.add('is-embedded');
        } catch (_) {
            document.documentElement.classList.add('is-embedded');
        }
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="procura-home-body @yield('body-class')">
    @php($homeUser = $user ?? auth()->user()->loadMissing('role'))
    <header class="procura-home-header">
        <a class="procura-home-brand" href="{{ route('dashboard') }}" aria-label="ASI Procura home">
            <img src="{{ asset('assets/images/asi-procura-logo.png') }}" alt="ASI Procura">
        </a>

        <div class="dropdown">
            <button class="procura-home-profile dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="procura-home-avatar">{{ str($homeUser->name)->explode(' ')->filter()->take(2)->map(fn ($part) => str($part)->substr(0, 1)->upper())->join('') ?: 'U' }}</span>
                <span class="procura-home-profile-copy">
                    <strong>{{ $homeUser->name }}</strong>
                    <small>{{ $homeUser->activeRole()?->name ?? 'User' }}</small>
                </span>
            </button>
            <div class="dropdown-menu dropdown-menu-end procura-home-profile-menu">
                <div class="px-3 py-2">
                    <div class="fw-semibold small">{{ $homeUser->name }}</div>
                    <div class="small text-muted">{{ $homeUser->email }}</div>
                </div>
                <div class="dropdown-divider"></div>
                @include('partials.workspace-role-menu')
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="dropdown-item text-danger d-flex align-items-center gap-2" type="submit">
                        <x-ui.icon name="logout" size="16" />Sign out
                    </button>
                </form>
            </div>
        </div>
    </header>

    <main class="procura-home-main">
        @include('partials.workflow-handoff')
        @if(session('success') && ! session('workflow_submission_notice'))
            <div class="alert alert-success alert-dismissible fade show" role="status">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
            </div>
        @endif
        @if(session('error'))
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
            </div>
        @endif

        @yield('content')
    </main>

    @include('partials.workflow-request-modal')
    @include('dashboard.partials.workflow-action-modal')
    @include('partials.workflow-submission-modal')

    <script src="{{ asset('assets/vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
    @stack('scripts')
</body>
</html>
