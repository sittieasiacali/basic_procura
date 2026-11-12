<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="attachment-limits" data-max-files="{{ (int) ini_get('max_file_uploads') }}" data-max-bytes="{{ ini_parse_quantity(ini_get('post_max_size')) }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#160D76">
    <title>@yield('title', 'Dashboard') &middot; {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/images/asi-coin.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('assets/images/asi-coin.png') }}">
    <link href="{{ asset('assets/vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    @include('partials.workflow-frame-context')
    <script>
        // Set this before the module bundle loads so the browser does not restore
        // a stale scroll location while a Purchasing Team queue is changing.
        if (sessionStorage.getItem('asi-procura:creator-workflow-scroll') !== null && 'scrollRestoration' in history) {
            history.scrollRestoration = 'manual';
        }
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @vite('resources/css/workspace-theme.css')
    @vite('resources/css/mobile.css')
    @stack('head')
</head>
<body class="workspace-theme {{ ($hideNavigation ?? false) ? 'workspace-theme--without-navigation' : '' }}">
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

    $primaryNavigation[] = [
        'label' => 'Procura Chats',
        'url' => route('chat.index'),
        'active' => request()->routeIs('chat.*'),
        'icon' => 'notification',
    ];

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
                'active' => $actionIsActive && ! isset($actionTarget['dashboard']),
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

    if ($isAdmin && $currentUser->hasRole(\App\Enums\RoleSlug::Admin->value)) {
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

    if ($currentUser->can('viewAny', \App\Models\InventoryItem::class)) {
        $primaryNavigation[] = [
            'label' => 'Inventory',
            'url' => route('inventory.index'),
            'active' => request()->routeIs('inventory.*'),
            'icon' => 'inventory',
        ];
    }

    if ($currentUser->hasRole(\App\Enums\RoleSlug::PurchasingTeam->value)) {
        $primaryNavigation[] = [
            'label' => 'Workflow Tracker',
            'url' => route('workflow-tracker.index'),
            'active' => request()->routeIs('workflow-tracker.*'),
            'icon' => 'flow',
        ];
        $primaryNavigation[] = [
            'label' => 'Voided Monitoring',
            'url' => route('voided-monitoring.index'),
            'active' => request()->routeIs('voided-monitoring.*'),
            'icon' => 'x-circle',
        ];
        $primaryNavigation[] = [
            'label' => 'Return Monitoring',
            'url' => route('returned-monitoring.index'),
            'active' => request()->routeIs('returned-monitoring.*'),
            'icon' => 'file-text',
        ];
    }

    if ($currentUser->hasRole(\App\Enums\RoleSlug::BudgetMaker->value)) {
        $primaryNavigation[] = [
            'label' => 'Budget Requests',
            'url' => route('budget.index'),
            'active' => request()->routeIs('budget.*'),
            'icon' => 'wallet',
        ];
    }
    if ($currentUser->can('viewAny', \App\Models\Supplier::class)) {
        $primaryNavigation[] = ['label' => 'Supplier Management', 'url' => route('suppliers.index'), 'active' => request()->routeIs('suppliers.*'), 'icon' => 'users'];
    }
    $navbarUnreadCount = $currentUser->unreadNotifications()->count();
    $navbarNotifications = $currentUser->unreadNotifications()->latest()->limit(5)->get();
    $visibleNotifications = $navbarNotifications->take(5);
    $initials = str($currentUser->name)
        ->explode(' ')
        ->filter()
        ->take(2)
        ->map(fn ($part) => str($part)->substr(0, 1)->upper())
        ->join('');
@endphp

@unless($hideNavigation ?? false)
<a class="workspace-skip-link" href="#workspace-content">Skip to content</a>
<aside class="offcanvas-lg offcanvas-start workspace-sidebar" tabindex="-1" id="workspaceSidebar" aria-labelledby="workspace-sidebar-title">
    <div class="workspace-sidebar-brand">
        <a href="{{ route('dashboard') }}" id="workspace-sidebar-title">
            <img src="{{ asset('assets/images/asi-procura-logo.png') }}" alt="ASI Procura">
        </a>
        <button class="btn-close d-lg-none" type="button" data-bs-dismiss="offcanvas" data-bs-target="#workspaceSidebar" aria-label="Close navigation"></button>
    </div>
    <div class="workspace-sidebar-role">
        <span>Workspace</span>
        <strong>{{ $currentUser->activeRole()?->name ?? 'Unassigned role' }}</strong>
    </div>
    <nav class="workspace-sidebar-links" aria-label="Workspace navigation">
        @foreach($primaryNavigation as $item)
            <a class="workspace-sidebar-link {{ $item['active'] ? 'is-active' : '' }}" href="{{ $item['url'] }}" @if($item['active']) aria-current="page" @endif>
                <x-ui.icon :name="$item['icon']" size="18" />
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
    <div class="workspace-sidebar-footer">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="workspace-sidebar-link w-100" type="submit"><x-ui.icon name="logout" size="18" />Sign out</button>
        </form>
    </div>
</aside>
<nav class="navbar navbar-expand-xl navbar-dark app-navbar app-navbar--workspace sticky-top" aria-label="Primary navigation">
    <div class="container-fluid px-3 px-xxl-4">
        <button class="workspace-menu-toggle d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#workspaceSidebar" aria-controls="workspaceSidebar" aria-label="Open navigation">
            <span aria-hidden="true">&#9776;</span>
        </button>
        <div class="workspace-welcome">Welcome back, <strong>{{ $currentUser->name }}</strong><span class="workspace-date">{{ now()->format('D, d M Y') }}</span></div>
        <div class="collapse navbar-collapse" id="appNavigation">
            <div class="navbar-tools">
                <div class="dropdown">
                    <button class="navbar-tool" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications">
                        <x-ui.icon name="notification" size="19" />
                        <span class="notification-indicator" data-notification-count data-summary-url="{{ route('notifications.summary') }}" @if($navbarUnreadCount === 0) hidden @endif>{{ $navbarUnreadCount }}</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end notification-menu">
                        <div class="d-flex justify-content-between align-items-center px-2 py-2">
                            <a class="small fw-semibold" href="{{ route('notifications.index') }}">Notifications</a>
                            <span class="small text-muted" data-notification-unread>{{ $navbarUnreadCount }} unread</span>
                        </div>
                        <div class="dropdown-divider my-1"></div>
                        <div class="px-2 pb-2"><a href="{{ route('notifications.index') }}">View all updates</a></div>@forelse($visibleNotifications as $notification)
                            <div class="notification-row">
                                <a class="small fw-semibold" href="{{ route('notifications.index') }}">{{ data_get($notification->data, 'title', 'Procurement update') }}</a>
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
                        @can('viewAny', \App\Models\Supplier::class)<a class="dropdown-item" href="{{ route('suppliers.index') }}">Supplier Management</a>@endcan
                        <a class="dropdown-item" href="{{ route('notifications.index') }}">Notification history</a>
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

@include('partials.mobile-app-header')

<main id="workspace-content" tabindex="-1" class="app-main {{ request()->routeIs('dashboard') ? 'app-main--dashboard' : '' }}">
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

@include('partials.mobile-navigation')
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
