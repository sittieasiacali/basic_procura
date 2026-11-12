@auth
    @unless($hideNavigation ?? false)
        @php
            $mobileUser = auth()->user();
            $mobileUnread = $navbarUnreadCount ?? $mobileUser->unreadNotifications()->count();
            $mobilePageTitle = request()->routeIs('dashboard') ? 'ASI Procura' : trim($__env->yieldContent('title', 'ASI Procura'));
            if (request()->routeIs('dashboard') && request('workflow') === 'canvassing') $mobilePageTitle = 'Canvassing';
            if (request()->routeIs('dashboard') && request('workflow') === 'budget-request') $mobilePageTitle = 'Budget Requests';
            if (request()->routeIs('purchase-requests.index')) $mobilePageTitle = 'Requests';
            if (request()->routeIs('chat.*')) $mobilePageTitle = 'Chats';
            if (request()->routeIs('dashboard') && request('mobile_tab') === 'actions') $mobilePageTitle = 'My actions';
            if (request()->routeIs('dashboard') && request('mobile_tab') === 'approvals') $mobilePageTitle = 'Approvals';
            if (request()->routeIs('dashboard') && (request('workflow') === 'purchase-order' || request('mobile_tab') === 'pos')) $mobilePageTitle = 'Purchase Orders';
            $mobileIsHome = request()->routeIs('dashboard') && ! request()->hasAny(['workflow', 'mobile_tab', 'search', 'action_search', 'processed_search', 'approval_page', 'payment_tab', 'po_history_tab']);
            $mobilePageSubtitle = match (true) {
                request()->routeIs('purchase-requests.index') => 'Procurement requests and requisitions.',
                request()->routeIs('chat.*') => 'Request conversations and updates.',
                request()->routeIs('mobile.menu') => 'Workspace tools and account settings.',
                default => 'Procurement management',
            };
        @endphp
        <header @class(['mobile-app-header', 'mobile-app-header--home' => $mobileIsHome])>
            <span aria-hidden="true"></span>
            @if($mobileIsHome)
                <span class="mobile-connection-status" data-connection-status><i aria-hidden="true"></i><span>Checking</span></span>
            @else
            <div class="mobile-app-tools">
                <a href="{{ route('chat.index') }}" aria-label="Conversations"><x-ui.icon name="message-square" size="21" /></a>
                <a href="{{ route('notifications.index') }}" aria-label="Notifications{{ $mobileUnread ? ', '.$mobileUnread.' unread' : '' }}">
                    <x-ui.icon name="notification" size="22" />
                    @if($mobileUnread)<span class="mobile-app-unread" aria-hidden="true"></span>@endif
                </a>
                <a href="{{ route('mobile.menu') }}" aria-label="Account and menu"><x-ui.icon name="user" size="22" /></a>
            </div>
            @endif
            @unless($mobileIsHome)
                <div class="mobile-app-heading"><strong>{{ $mobilePageTitle }}</strong><small>{{ $mobilePageSubtitle }}</small></div>
            @endunless
        </header>
    @endunless
@endauth
