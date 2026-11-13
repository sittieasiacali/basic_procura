@auth
    @unless($hideNavigation ?? false)
        @php
            $mobileUser = auth()->user();
            $mobileUnread = $navbarUnreadCount ?? \App\Support\NotificationInbox::forUser($mobileUser)->whereNull('read_at')->count();
            $mobilePageTitle = request()->routeIs('dashboard') ? 'ASI Procura' : trim($__env->yieldContent('title', 'ASI Procura'));
            if (request()->routeIs('dashboard') && request('workflow') === 'canvassing') $mobilePageTitle = 'Canvassing';
            if (request()->routeIs('dashboard') && request('workflow') === 'budget-request') $mobilePageTitle = 'Budget Requests';
            if (request()->routeIs('purchase-requests.index')) $mobilePageTitle = 'Requests';
            if (request()->routeIs('chat.*')) $mobilePageTitle = 'Procura Chat';
            if (request()->routeIs('dashboard') && request('mobile_tab') === 'actions') $mobilePageTitle = 'My Activities';
            if (request()->routeIs('dashboard') && request('mobile_tab') === 'approvals') $mobilePageTitle = 'Approvals';
            if (request()->routeIs('dashboard') && (request('workflow') === 'purchase-order' || request('mobile_tab') === 'pos')) $mobilePageTitle = 'Purchase Orders';
            $mobileIsHome = request()->routeIs('dashboard') && ! request()->hasAny(['workflow', 'mobile_tab', 'search', 'action_search', 'processed_search', 'approval_page', 'payment_tab', 'po_history_tab']);
            $mobilePageSubtitle = match (true) {
                $mobilePageTitle === 'Purchase Orders' => 'Manage and track your purchase orders',
                $mobilePageTitle === 'My Activities' => 'Manage your requests and follow-ups',
                request()->routeIs('purchase-requests.index') => 'Procurement requests and requisitions.',
                request()->routeIs('chat.*') => \App\Support\ProcuraChatAccess::isCampaignRole($mobileUser) ? ($mobileUser->campaign?->name ?? 'No campaign assigned') : 'Request conversations and updates.',
                request()->routeIs('mobile.menu') => 'Workspace tools and account settings.',
                default => 'Procurement management',
            };
        @endphp
        <header @class(['mobile-app-header', 'mobile-app-header--home' => $mobileIsHome, 'mobile-app-header--compact' => request()->routeIs('notifications.index')])>
            <div class="mobile-brand-group">
            @if($hasWorkspaceDrawer ?? false)
            <button class="mobile-drawer-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#workspaceSidebar" aria-controls="workspaceSidebar" aria-label="Open menu"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
            @endif
            <a class="mobile-app-brand" href="{{ \App\Support\ProcuraChatAccess::isCampaignRole($mobileUser) ? route('chat.index') : route('dashboard') }}" aria-label="ASI Procura home">
                <img class="mobile-brand-logo" src="{{ asset('assets/images/asi-procura-logo-transparent.png') }}" alt="ASI Procura">
            </a>
            </div>
            @if($mobileIsHome)
                <span class="mobile-connection-status" data-connection-status><i aria-hidden="true"></i><span>Checking</span></span>
            @else
            <div class="mobile-app-tools">
                @if(request()->routeIs('chat.index'))
                    <button class="mobile-chat-search" type="button" data-chat-search-toggle aria-label="Search requests" aria-controls="pc-search-panel" aria-expanded="false"><x-ui.icon name="search" size="21" /></button>
                @else
                    <a href="{{ route('chat.index') }}" aria-label="Conversations"><x-ui.icon name="message-square" size="21" /></a>
                @endif
                <a href="{{ route('notifications.index') }}" aria-label="Notifications{{ $mobileUnread ? ', '.$mobileUnread.' unread' : '' }}">
                    <x-ui.icon name="notification" size="22" />
                    <span class="mobile-app-unread" data-notification-count data-summary-url="{{ route('notifications.summary') }}" @if($mobileUnread === 0) hidden @endif aria-hidden="true">{{ $mobileUnread }}</span>
                </a>
                <a href="{{ route('mobile.menu') }}" aria-label="Account and menu"><x-ui.icon name="user" size="22" /></a>
            </div>
            @endif
            @unless($mobileIsHome || request()->routeIs('notifications.index'))
                <div class="mobile-app-heading">
                    <strong>{{ $mobilePageTitle }}</strong>
                    <small>{{ $mobilePageSubtitle }}</small>
                    <span class="mobile-app-heading-icon" aria-hidden="true"><x-ui.icon :name="$mobilePageTitle === 'Purchase Orders' ? 'cart' : 'clipboard-check'" size="58" /></span>
                </div>
            @endunless
        </header>
    @endunless
@endauth
