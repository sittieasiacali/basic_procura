@auth
    @if(\App\Support\ProcuraChatAccess::isCampaignRole(auth()->user()) && ! ($hideNavigation ?? false))
        @php
            $mobileCampaignAllowed = request()->routeIs('chat.*', 'notifications.*', 'mobile.menu', 'password.*');
            $mobileCampaignRequest = request()->route('purchaseRequest');
            $mobileCampaignRequestId = $mobileCampaignRequest instanceof \App\Models\PurchaseRequest ? $mobileCampaignRequest->id : $mobileCampaignRequest;
            $mobileCampaignUrl = route('chat.index', $mobileCampaignRequestId ? ['request' => $mobileCampaignRequestId] : []);
        @endphp
        <meta name="mobile-campaign-workspace" data-allowed="{{ $mobileCampaignAllowed ? 'true' : 'false' }}" data-url="{{ $mobileCampaignUrl }}">
    @endif
@endauth
