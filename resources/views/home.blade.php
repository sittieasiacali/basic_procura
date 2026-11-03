@extends('layouts.home')

@section('title', 'Home')

@section('content')
    <section class="procura-home-welcome">
        <div>
            <div class="procura-home-kicker"><x-ui.icon name="users" size="28" />Procurement workspace</div>
            <h1>Welcome, {{ $user->name }}!</h1>
            <p>Here?s an overview of your procurement requests.</p>
        </div>
        @can('create', \App\Models\PurchaseRequest::class)
            <a class="btn procura-home-create" href="{{ route('purchase-requests.create') }}">
                <x-ui.icon name="plus" size="18" />Create Purchase Request
            </a>
        @endcan
    </section>

    @php
        $summaryCards = [
            ['label' => 'Total Requests', 'value' => $summaryCounts['total'], 'icon' => 'request', 'tone' => 'blue', 'filter' => 'all'],
            ['label' => 'Pending', 'value' => $summaryCounts['pending'], 'icon' => 'hourglass', 'tone' => 'green', 'filter' => 'pending'],
            ['label' => 'Completed', 'value' => $summaryCounts['completed'], 'icon' => 'check-circle', 'tone' => 'purple', 'filter' => 'completed'],
            ['label' => 'Rejected / Cancelled', 'value' => $summaryCounts['rejected'], 'icon' => 'x-circle', 'tone' => 'red', 'filter' => 'rejected'],
        ];
    @endphp
    <section class="procura-home-summary" aria-label="Request summary">
        @foreach($summaryCards as $card)
            <a class="procura-summary-card procura-summary-card--{{ $card['tone'] }} {{ $activeSummary === $card['filter'] ? 'is-active' : '' }}" href="{{ route('dashboard', $card['filter'] === 'all' ? [] : ['summary' => $card['filter']]) }}#recent-requests-heading">
                <span class="procura-summary-icon"><x-ui.icon :name="$card['icon']" size="25" /></span>
                <span class="procura-summary-copy">
                    <span>{{ $card['label'] }}</span>
                    <strong>{{ number_format($card['value']) }}</strong>
                </span>
                <x-ui.icon class="procura-summary-arrow" name="chevron-right" size="19" />
            </a>
        @endforeach
    </section>

    @if($omApprovalRequests->isNotEmpty())
        <section class="procura-recent-card procura-approval-queue" aria-labelledby="approval-queue-heading">
            <header class="procura-recent-heading">
                <span class="procura-recent-heading-icon"><x-ui.icon name="check-circle" size="25" /></span>
                <div>
                    <h2 id="approval-queue-heading">Awaiting My Approval <span>{{ $omApprovalRequests->count() }}</span></h2>
                    <p>Requests assigned to you for Operations Manager review.</p>
                </div>
            </header>

            <div class="table-responsive">
                <table class="table procura-recent-table align-middle mb-0">
                    <thead>
                        <tr><th>Request ID</th><th>Title</th><th>Workflow Status</th><th>Updated</th><th class="text-center">Action</th></tr>
                    </thead>
                    <tbody>
                        @foreach($omApprovalRequests as $purchaseRequest)
                            <tr>
                                <td><a href="{{ route('purchase-requests.show', $purchaseRequest) }}" class="procura-request-id">{{ $purchaseRequest->request_number }}</a></td>
                                <td class="procura-request-title">{{ $purchaseRequest->title }}</td>
                                <td><x-status-badge :status="$purchaseRequest->status" /></td>
                                <td class="text-muted small text-nowrap" title="{{ $purchaseRequest->updated_at->format('M j, Y g:i A') }}">{{ $purchaseRequest->updated_at->diffForHumans() }}</td>
                                <td class="text-center"><a class="btn procura-approve-button" href="{{ route('purchase-requests.show', ['purchaseRequest' => $purchaseRequest, 'from_stage' => 'approval']) }}#om-actions" data-workflow-action-modal data-workflow-action-title="Review / Approve"><x-ui.icon name="check" size="16" />Review / Approve</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if($screeningRequests->isNotEmpty())
        <section class="procura-recent-card procura-screening-queue" aria-labelledby="screening-queue-heading">
            <header class="procura-recent-heading">
                <span class="procura-recent-heading-icon"><x-ui.icon name="inventory" size="25" /></span>
                <div>
                    <h2 id="screening-queue-heading">{{ $screeningQueueLabel }} <span>{{ $screeningRequests->count() }}</span></h2>
                    <p>Requests ready for your team’s classification or inventory screening.</p>
                </div>
            </header>

            <div class="table-responsive">
                <table class="table procura-recent-table align-middle mb-0">
                    <thead>
                        <tr><th>Request ID</th><th>Title</th><th>Workflow Status</th><th>Updated</th><th class="text-center">Action</th></tr>
                    </thead>
                    <tbody>
                        @foreach($screeningRequests as $purchaseRequest)
                            <tr>
                                <td><a href="{{ route('purchase-requests.show', $purchaseRequest) }}" class="procura-request-id">{{ $purchaseRequest->request_number }}</a></td>
                                <td class="procura-request-title">{{ $purchaseRequest->title }}</td>
                                <td><x-status-badge :status="$purchaseRequest->status" /></td>
                                <td class="text-muted small text-nowrap" title="{{ $purchaseRequest->updated_at->format('M j, Y g:i A') }}">{{ $purchaseRequest->updated_at->diffForHumans() }}</td>
                                <td class="text-center">
                                    @can('claim', $purchaseRequest)
                                        <form class="d-inline" method="POST" action="{{ route('request-flow.claim', $purchaseRequest) }}">
                                            @csrf
                                            <button class="btn procura-screen-button" type="submit"><x-ui.icon name="users" size="16" />Claim request</button>
                                        </form>
                                    @elsecan('screen', $purchaseRequest)
                                        <a class="btn procura-screen-button" href="{{ route('purchase-requests.show', $purchaseRequest) }}#screening-actions" data-workflow-action-modal data-workflow-action-title="Screen request"><x-ui.icon name="check" size="16" />Screen request</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="procura-recent-card" aria-labelledby="recent-requests-heading">
        <header class="procura-recent-heading">
            <span class="procura-recent-heading-icon"><x-ui.icon name="receipt" size="25" /></span>
            <div>
                <h2 id="recent-requests-heading">My Recent Requests</h2>
                <p>Track the status of your submitted requests.</p>
            </div>
            <form class="procura-home-filter" method="GET" action="{{ route('dashboard') }}">
                <label for="home-summary-filter">Filter</label>
                <select id="home-summary-filter" name="summary" onchange="this.form.submit()">
                    <option value="all" @selected($activeSummary === 'all')>All requests</option>
                    <option value="pending" @selected($activeSummary === 'pending')>Pending</option>
                    <option value="completed" @selected($activeSummary === 'completed')>Completed</option>
                    <option value="rejected" @selected($activeSummary === 'rejected')>Rejected / Cancelled</option>
                </select>
            </form>
        </header>

        <div class="table-responsive">
            <table class="table procura-recent-table align-middle mb-0">
                <thead>
                    <tr><th>Request ID</th><th>Title</th><th>Workflow Status</th><th>Updated</th><th class="text-center">Action</th></tr>
                </thead>
                <tbody>
                    @forelse($recentRequests as $purchaseRequest)
                        <tr>
                            <td><a href="{{ route('purchase-requests.show', $purchaseRequest) }}" class="procura-request-id">{{ $purchaseRequest->request_number }}</a></td>
                            <td class="procura-request-title">{{ $purchaseRequest->title }}</td>
                            <td><x-status-badge :status="$purchaseRequest->status" /></td>
                            <td class="text-muted small text-nowrap" title="{{ $purchaseRequest->updated_at->format('M j, Y g:i A') }}">{{ $purchaseRequest->updated_at->diffForHumans() }}</td>
                            <td class="text-center">
                                @if($purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForOmApproval && auth()->user()->can('reviewOm', $purchaseRequest))
                                    <a class="btn procura-approve-button" href="{{ route('purchase-requests.show', ['purchaseRequest' => $purchaseRequest, 'from_stage' => 'approval']) }}#om-actions" data-workflow-action-modal data-workflow-action-title="Review / Approve"><x-ui.icon name="check" size="16" />Review / Approve</a>
                                @else
                                    <a class="btn procura-view-button" href="{{ route('purchase-requests.show', $purchaseRequest) }}" data-workflow-request-preview><x-ui.icon name="eye" size="16" />View</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="request" title="No requests yet" description="Requests available to your account will appear here." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
