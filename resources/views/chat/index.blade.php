@extends('layouts.app')
@section('title', 'Procura Chats')
@section('content')
@php
    $chatUser = auth()->user();
    $campaignWorkspace = \App\Support\ProcuraChatAccess::isCampaignRole($chatUser);
    $chatScreeningTeam = \App\Support\ProcuraChatAccess::screeningTeamFor($chatUser);
@endphp
<div id="procura-chat" class="pc-compact" data-campaign-home="{{ $campaignWorkspace ? 'true' : 'false' }}" data-base="{{ route('chat.requests') }}" data-user="{{ auth()->id() }}" data-name="{{ auth()->user()->name }}"
     data-om-approve="{{ route('om-approvals.approve', '__ID__') }}" data-om-return="{{ route('om-approvals.return', '__ID__') }}" data-om-reject="{{ route('om-approvals.reject', '__ID__') }}"
     data-president="{{ route('president-approvals.supplier.decision', '__ID__') }}" data-request="{{ request('request') }}">
    <header class="pc-page-header">
        <div><span class="pc-eyebrow">ASI PROCURA &middot; WORKSPACE</span><h1>Procura Chat</h1><p>One conversation per request/PO. Track, review, and take action.</p></div>
        <span class="pc-connection" id="pc-connection" role="status">Connecting...</span>
    </header>
    <section class="pc-sound-controls" data-campaign-alert-setup aria-label="Chat notification sound">
        <button class="btn btn-sm btn-outline-primary" type="button" data-notification-sound-enable aria-pressed="false">Enable sound</button>
        @unless(\App\Support\ProcuraChatAccess::isCampaignRole(auth()->user()))
            <button class="btn btn-sm btn-outline-secondary" type="button" data-notification-sound-test>Test sound</button>
        @endunless
        <span class="small text-muted" data-notification-sound-status role="status">Sound is off.</span>
    </section>
    <div class="pc-error" id="pc-error" role="alert" hidden></div>
    @if($campaignWorkspace)
        <section id="pc-campaign-home" class="pc-campaign-home" aria-label="My campaign">
            <div class="pc-welcome"><span class="pc-person-avatar">{{ mb_substr($chatUser->name, 0, 1) }}</span><div><h2>Hello, {{ $chatUser->name }}.</h2><p>{{ $chatScreeningTeam ? 'Monitor all requests routed to '.$chatScreeningTeam.'.' : 'Monitor requests and updates for your assigned campaign.' }}</p></div></div>
            <div class="pc-campaign-heading"><h2>My Campaign</h2><span>{{ $chatUser->campaign?->is_active ? '1 Campaign' : 'No active campaign' }}</span></div>
            @if($chatUser->campaign?->is_active)
                <button id="pc-open-campaign" class="pc-campaign-card" type="button"><span class="pc-folder" aria-hidden="true"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 7V5a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg></span><span><strong>{{ $chatUser->campaign->name }}</strong><small>{{ $chatScreeningTeam ? 'All requests routed to '.$chatScreeningTeam : 'Campaign requests' }}</small><span class="pc-status-badge pc-status-success">Active</span></span><span class="pc-card-arrow" aria-hidden="true">&#8250;</span></button>
            @else
                <p class="pc-readonly">Contact your administrator to assign an active campaign.</p>
            @endif
        </section>
    @endif
    <div class="pc-sidebar-toolbar">
        <button id="pc-sidebar-toggle" class="pc-sidebar-toggle" type="button" aria-label="Hide request list" title="Hide request list" aria-controls="pc-sidebar" aria-expanded="true"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M5 8h2M5 12h2M5 16h2"/></svg></button>
        <div class="pc-toolbar-heading">
            <div class="pc-toolbar-title"><strong>{{ $campaignWorkspace ? ($chatUser->campaign?->name ?? 'My campaign') : 'Request conversations' }}</strong><span id="pc-toolbar-request" class="pc-toolbar-request"></span></div>
            <p id="pc-toolbar-campaign" class="pc-toolbar-campaign"></p>
        </div>
        <button id="pc-context-toggle" class="pc-context-toggle" type="button" aria-label="Show request information" title="Show request information" aria-expanded="false" aria-controls="pc-workflow pc-inspector"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/></svg></button>
    </div>
    <div class="pc-shell">
        <aside id="pc-sidebar" class="pc-sidebar" aria-label="Request conversations">

            @if($campaignWorkspace && $chatScreeningTeam)
                <label class="pc-scope-menu" for="pc-scope">
                    <span>View</span>
                    <select id="pc-scope" aria-label="Request type" aria-describedby="pc-scope-description">
                        <option value="department" data-description="Submitted by {{ $chatUser->campaign?->name }}">{{ $chatScreeningTeam === 'IT' ? 'IT' : 'Premises' }} Requests</option>
                        <option value="screening" selected data-description="Requests routed to {{ $chatScreeningTeam }} across all campaigns">{{ $chatScreeningTeam === 'IT' ? 'IT' : 'Premises' }} Screening</option>
                    </select>
                </label>
                <p id="pc-scope-description" class="visually-hidden" aria-live="polite">Requests routed to {{ $chatScreeningTeam }} across all campaigns</p>
            @endif
            <label id="pc-search-panel" class="pc-search"><span aria-hidden="true">&#8981;</span><input id="pc-search" type="search" placeholder="Search request number or title..." aria-label="Search conversations"></label>
            <div class="pc-filters" role="group" aria-label="Conversation filters">
                <button type="button" data-filter="all" class="active" aria-pressed="true">All</button>
                <button type="button" data-filter="unread" aria-pressed="false">Unread</button>
                @unless(\App\Support\ProcuraChatAccess::isCampaignRole(auth()->user()))
                    <button type="button" data-filter="mine" aria-pressed="false">My requests</button>
                @endunless
                @if(auth()->user()->hasAnyRole(['om', 'om-smts-approver', 'president']))
                    <button type="button" data-filter="approval" aria-pressed="false">For approval</button>
                @endif
            </div>
            <div id="pc-list" class="pc-list"></div>
            <nav class="pc-pagination" aria-label="Conversation pages"><button id="pc-prev" type="button">Previous</button><span id="pc-page"></span><button id="pc-next" type="button">Next</button></nav>
        </aside>
        <section class="pc-conversation" aria-label="Conversation">
            <div id="pc-empty" class="pc-empty"><span class="pc-empty-icon">&#8599;</span><h2>Keep your requests moving</h2><p>View the sidebar to choose a request, see updates, and review approvals.</p></div>
            <div id="pc-thread" hidden>
                <header class="pc-thread-header">

                    <div class="pc-request-icon">PR</div>
                    <div class="pc-thread-heading"><h2 id="pc-title"></h2><p id="pc-subtitle"></p></div>
                    <span class="pc-stage" id="pc-stage"></span>
                </header>
                <nav id="pc-workflow" class="pc-workflow" aria-label="Request workflow"></nav>
                <div class="pc-thread-body">
                <aside id="pc-inspector" class="pc-inspector" aria-label="Request information">
                    <details class="pc-info-card" open><summary><span class="pc-info-title"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h8l4 4v14H6zM14 3v5h4M9 12h6M9 16h6"/></svg>Request details</span></summary><dl id="pc-request-summary"></dl></details>
                    <details class="pc-info-card" open><summary><span class="pc-info-title"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6M18 15a5 5 0 0 1 3 4v2"/></svg>Workflow participants</span></summary><div id="pc-participants"></div></details>
                    <details class="pc-details pc-info-card"><summary><span class="pc-info-title"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 11-8 8a6 6 0 0 1-8-8l9-9a4 4 0 0 1 6 6L10 18a2 2 0 0 1-3-3l9-9"/></svg>Items &amp; quotations</span></summary><div id="pc-details-content"></div></details>
                </aside>
                <div class="pc-discussion">
                <div id="pc-actions" class="pc-actions"></div>
                <div id="pc-messages" class="pc-messages" role="log" aria-label="Messages"></div>
                <form id="pc-compose" class="pc-compose">
                    <label class="pc-attach" title="Attach PDF or image">&#43;<input data-chat-attachment id="pc-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" aria-label="Attach PDF or image"></label>
                    <div class="pc-compose-input"><textarea id="pc-body" rows="1" maxlength="5000" placeholder="Type a message..." aria-label="Message"></textarea><span id="pc-file-name"></span></div>
                    <button type="submit" id="pc-send">Send &#8599;</button>
                </form>
                <p id="pc-readonly" class="pc-readonly" hidden>You can read this conversation. Messaging is available to the requestor and current or previous handler.</p>
                </div>

                </div>
            </div>
        </section>
    </div>
    <dialog id="pc-decision" class="pc-dialog" aria-labelledby="pc-decision-title">
        <form id="pc-decision-form">
            <h2 id="pc-decision-title">Confirm decision</h2>
            <p>Review the request details before signing as <strong>{{ auth()->user()->name }}</strong>.</p>
            <label for="pc-remarks">Remarks <span id="pc-required"></span></label><textarea id="pc-remarks" rows="3" maxlength="2000"></textarea>
            <label for="pc-signature">Draw your signature</label>
            <canvas id="pc-signature" width="600" height="180" aria-label="Signature drawing area"></canvas>
            <button id="pc-clear" type="button" class="pc-clear">Clear signature</button>
            <p id="pc-decision-error" role="alert"></p>
            <div class="pc-dialog-footer"><button id="pc-cancel" type="button">Cancel</button><button type="submit" id="pc-confirm">Sign &amp; confirm</button></div>
        </form>
    </dialog>
</div>
@endsection
