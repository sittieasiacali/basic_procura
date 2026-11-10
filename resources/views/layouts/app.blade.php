<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#160D76">
    <title>@yield('title', 'Dashboard') &middot; {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/images/asi-coin.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('assets/images/asi-coin.png') }}">
    <link href="{{ asset('assets/vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <script>
        // Pages opened inside the dashboard action modal drop the app chrome.
        try {
            if (window.self !== window.top) document.documentElement.classList.add('is-embedded');
        } catch (_) {
            document.documentElement.classList.add('is-embedded');
        }

        // Set this before the module bundle loads so the browser does not restore
        // a stale scroll location while a Purchasing Team queue is changing.
        if (sessionStorage.getItem('asi-procura:creator-workflow-scroll') !== null && 'scrollRestoration' in history) {
            history.scrollRestoration = 'manual';
        }
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body>
@php
    $currentUser = auth()->user()->loadMissing(['role', 'campaign']);
    $roleSlug = $currentUser->activeRole()?->slug;
    $isAdmin = $currentUser->isAdministrator();
    $ownerRoles = [
        \App\Enums\RoleSlug::Requestor->value,
        \App\Enums\RoleSlug::TeamLeader->value,
    ];
    $isPurchaseOrderApprover = $currentUser->poRoleAssignments()->exists();
    $isRequestDetailFromWorkflow = request()->routeIs('purchase-requests.show')
        && in_array(request('from_stage'), [
            'approval', 'it', 'premises', 'inventory', 'canvassing', 'quotation', 'supplier-approval',
            'purchase-order', 'budget-request', 'final-approval', 'payment', 'receiving', 'fulfilled', 'done',
        ], true);
    $primaryNavigation = [[
        'label' => 'Dashboard',
        'url' => route('dashboard'),
        'active' => request()->routeIs('dashboard'),
        'icon' => 'dashboard',
    ]];

    if ($currentUser->can('create', \App\Models\PurchaseRequest::class)) {
        $primaryNavigation[] = [
            'label' => 'Create Request',
            'url' => route('purchase-requests.create'),
            'active' => request()->routeIs('purchase-requests.create'),
            'icon' => 'plus',
        ];
    }

    if (in_array($roleSlug, $ownerRoles, true)) {
        $primaryNavigation[] = [
            'label' => 'My Requests',
            'url' => route('purchase-requests.index'),
            'active' => request()->routeIs('purchase-requests.index', 'purchase-requests.edit')
                || (request()->routeIs('purchase-requests.show') && ! $isRequestDetailFromWorkflow),
            'icon' => 'request',
        ];
    } elseif (in_array($roleSlug, [\App\Enums\RoleSlug::ItStaff->value, \App\Enums\RoleSlug::PremisesStaff->value], true)) {
        // Screening teams work from their focused dashboard.
    } else {
        $actionRoutes = [
            \App\Enums\RoleSlug::OmSmtsApprover->value => ['url' => route('dashboard').'#my-actions-heading', 'dashboard' => true],
            \App\Enums\RoleSlug::ProcurementStaff->value => ['url' => route('dashboard').'#my-actions-heading', 'dashboard' => true],
            \App\Enums\RoleSlug::Purchasing->value => ['url' => route('dashboard').'#my-actions-heading', 'dashboard' => true],
            \App\Enums\RoleSlug::PurchasingTeam->value => ['url' => route('dashboard').'#my-actions-heading', 'dashboard' => true],
            \App\Enums\RoleSlug::President->value => ['url' => route('president-approvals.index'), 'president' => true, 'root' => true],
            \App\Enums\RoleSlug::Tl->value => ['url' => route('dashboard').'#my-actions-heading', 'dashboard' => true],
            \App\Enums\RoleSlug::Om->value => ['url' => route('dashboard').'#my-actions-heading', 'dashboard' => true],
            \App\Enums\RoleSlug::Smt->value => ['url' => route('dashboard').'#my-actions-heading', 'dashboard' => true],
            \App\Enums\RoleSlug::PoWorkflow->value, \App\Enums\RoleSlug::PoChecker->value, \App\Enums\RoleSlug::PoNoted->value, \App\Enums\RoleSlug::PoApprover->value => ['url' => route('dashboard').'#my-actions-heading', 'dashboard' => true],
            \App\Enums\RoleSlug::Finance->value => ['url' => route('dashboard').'#my-actions-heading', 'dashboard' => true],
            \App\Enums\RoleSlug::InventoryStaff->value => ['url' => route('dashboard').'#my-actions-heading', 'dashboard' => true],
            \App\Enums\RoleSlug::Inventory->value => ['url' => route('dashboard').'#my-actions-heading', 'dashboard' => true],
        ];
        $actionTarget = $actionRoutes[$roleSlug] ?? null;

        if ($actionTarget) {
            $actionIsActive = match (true) {
                isset($actionTarget['president']) => true,
                isset($actionTarget['budget']) => request()->routeIs('budget.*'),
                isset($actionTarget['dashboard']) => request()->routeIs('dashboard'),
                isset($actionTarget['stage']) => request('stage') === $actionTarget['stage'],
                isset($actionTarget['summary']) => request('summary') === $actionTarget['summary'],
                default => ! request()->filled('stage') && ! request()->filled('summary'),
            } && (isset($actionTarget['president'])
                ? request()->routeIs('president-approvals.*')
                : (isset($actionTarget['budget']) ? request()->routeIs('budget.*') : request()->routeIs('dashboard')));
            $primaryNavigation[] = [
                'label' => in_array($roleSlug, [\App\Enums\RoleSlug::President->value, \App\Enums\RoleSlug::OmSmtsApprover->value], true) ? 'For Approval' : 'My Actions',
                'url' => $actionTarget['url'],
                'active' => $actionIsActive,
                'icon' => 'flow',
            ];
        }

        if (! $isPurchaseOrderApprover) {
        $primaryNavigation[] = [
            'label' => 'Requests',
            'url' => route('purchase-requests.index'),
            'active' => request()->routeIs('purchase-requests.index', 'purchase-requests.create', 'purchase-requests.edit')
                || (request()->routeIs('purchase-requests.show') && ! $isRequestDetailFromWorkflow),
            'icon' => 'request',
        ];
        }


    }

    if ($isAdmin) {
        $primaryNavigation[] = [
            'label' => 'Manage Data',
            'url' => route('admin.data.index'),
            'active' => request()->routeIs('admin.data.*'),
            'icon' => 'file-text',
        ];
        $primaryNavigation[] = [
            'label' => 'Users',
            'url' => route('admin.accounts.index'),
            'active' => request()->routeIs('admin.accounts.*'),
            'icon' => 'users',
        ];
        $primaryNavigation[] = [
            'label' => 'Campaigns',
            'url' => route('admin.campaigns.index'),
            'active' => request()->routeIs('admin.campaigns.*'),
            'icon' => 'campaign',
        ];
        $primaryNavigation[] = [
            'label' => 'System Health',
            'url' => route('admin.system-health'),
            'active' => request()->routeIs('admin.system-health'),
            'icon' => 'shield-check',
        ];
    }

    $navbarNotifications = $currentUser->unreadNotifications()->latest()->limit(6)->get();
    $visibleNotifications = $navbarNotifications->take(5);
    $initials = str($currentUser->name)
        ->explode(' ')
        ->filter()
        ->take(2)
        ->map(fn ($part) => str($part)->substr(0, 1)->upper())
        ->join('');
@endphp

@unless($hideNavigation ?? false)
<nav class="navbar navbar-expand-xl navbar-dark app-navbar app-navbar--workspace sticky-top" aria-label="Primary navigation">
    <div class="container-fluid px-3 px-xxl-4">
        <a class="app-brand me-xl-3" href="{{ route('dashboard') }}">
            <img class="app-brand-logo" src="{{ asset('assets/images/asi-procura-logo.png') }}" alt="ASI Procura">
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#appNavigation" aria-controls="appNavigation" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="appNavigation">
            <ul class="navbar-nav me-auto align-items-xl-center">
                @foreach($primaryNavigation as $item)
                    <li class="nav-item">
                        <a class="nav-link {{ $item['active'] ? 'active' : '' }}" href="{{ $item['url'] }}" @if($item['active']) aria-current="page" @endif>
                            <x-ui.icon :name="$item['icon'] ?? 'request'" size="15" />
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach

            </ul>

            <div class="navbar-tools">
                <div class="dropdown">
                    <button class="navbar-tool" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications">
                        <x-ui.icon name="notification" size="19" />
                        @if($navbarNotifications->isNotEmpty())
                            <span class="notification-indicator">{{ $navbarNotifications->count() > 5 ? '5+' : $navbarNotifications->count() }}</span>
                        @endif
                    </button>
                    <div class="dropdown-menu dropdown-menu-end notification-menu">
                        <div class="d-flex justify-content-between align-items-center px-2 py-2">
                            <strong class="small">Notifications</strong>
                            <span class="small text-muted">{{ $navbarNotifications->count() > 5 ? '5+' : $navbarNotifications->count() }} unread</span>
                        </div>
                        <div class="dropdown-divider my-1"></div>
                        @forelse($visibleNotifications as $notification)
                            <div class="notification-row">
                                <div class="small fw-semibold">{{ data_get($notification->data, 'title', 'Procurement update') }}</div>
                                <div class="small text-muted">{{ data_get($notification->data, 'message', data_get($notification->data, 'body', 'A new notification is available.')) }}</div>
                                <div class="small text-muted mt-1">{{ $notification->created_at->diffForHumans() }}</div>
                            </div>
                        @empty
                            <div class="empty-state text-center px-3 py-4">
                                <x-ui.icon class="mb-2" name="notification" size="22" />
                                <div class="empty-state-title small">No unread notifications</div>
                                <div class="small">You're up to date.</div>
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="dropdown">
                    <button class="user-menu-button dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu: {{ $currentUser->name }}, {{ $currentUser->activeRole()?->name ?? 'Unassigned role' }}">
                        <span class="user-avatar">{{ $initials ?: 'U' }}</span>
                        <span class="user-menu-details d-none d-sm-block">
                            <span class="user-menu-name">{{ $currentUser->name }}</span>
                            <span class="user-menu-role">{{ $currentUser->activeRole()?->name ?? 'Unassigned role' }}</span>
                        </span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <div class="px-2 py-2">
                            <div class="small fw-semibold">{{ $currentUser->name }}</div>
                            <div class="small text-muted">{{ $currentUser->email }}</div>
                            <div class="small text-muted mt-1">{{ $currentUser->campaign?->name ?? $currentUser->department ?? 'No campaign assigned' }}</div>
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
            </div>
        </div>
    </div>
</nav>
@endunless

<main class="app-main {{ request()->routeIs('dashboard') ? 'app-main--dashboard' : '' }}">
    @include('partials.workflow-handoff')
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="status">
            <div class="d-flex align-items-start gap-2">
                <x-ui.icon name="check" size="18" />
                <div>{{ session('success') }}</div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss notification"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <div class="d-flex align-items-start gap-2">
                <x-ui.icon name="warning" size="18" />
                <div>{{ session('error') }}</div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss notification"></button>
        </div>
    @endif

    @if($errors->any())
        @php
            $validationMessages = $errors->getMessages();
            $validationFieldCount = count($validationMessages);
            $firstValidationKey = array_key_first($validationMessages);
        @endphp
        <div class="alert alert-danger validation-error-summary" id="validation-error-summary" role="alert" tabindex="-1" data-first-error-key="{{ $firstValidationKey }}">
            <div class="d-flex align-items-start gap-2">
                <x-ui.icon name="warning" size="18" />
                <div class="flex-grow-1">
                    <div class="section-kicker text-danger mb-1">Error</div>
                    <strong>{{ $validationFieldCount === 1 ? 'Please fix the highlighted field:' : "Please fix {$validationFieldCount} highlighted fields." }}</strong>
                    <div class="mt-1">{{ $validationFieldCount === 1 ? 'Please fix the following error:' : 'Please fix the following errors:' }}</div>
                    <ul class="mb-0 mt-2 ps-3">
                        @foreach($validationMessages as $key => $messages)
                            @foreach($messages as $message)
                                <li>
                                    <a class="validation-error-link" href="#validation-error-field-{{ $loop->parent->index }}" data-validation-error-link data-error-key="{{ $key }}">{{ $message }}</a>
                                </li>
                            @endforeach
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
        <script>
            window.__validationErrors = @json($validationMessages);
        </script>
    @endif


    @yield('content')
</main>

@include('partials.workflow-request-modal')
@include('dashboard.partials.workflow-action-modal')
@include('partials.workflow-submission-modal')
@can('create', \App\Models\User::class)
    @include('admin.accounts.partials.create-modal')
@endcan

<script src="{{ asset('assets/vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
@stack('scripts')
</body>
</html>
