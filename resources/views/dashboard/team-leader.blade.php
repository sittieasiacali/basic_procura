@extends('layouts.app')

@section('title', 'My Requests')

@section('content')
    <div class="team-leader-dashboard">
        <x-ui.page-header :eyebrow="$user->activeRole()?->name.' workspace'" title="My requests" description="Track your requests in one simple view.">
            <x-slot:actions>
                @if($myItemsCount > 0)
                    <a class="btn btn-outline-primary d-inline-flex align-items-center gap-2" href="{{ route('inventory.index', ['view' => 'released']) }}"><x-ui.icon name="check-circle" size="17" />My items</a>
                @endif
                <a class="btn btn-primary d-inline-flex align-items-center gap-2" href="{{ route('purchase-requests.create') }}">
                    <x-ui.icon name="plus" size="17" />Create Purchase Request
                </a>
            </x-slot:actions>
        </x-ui.page-header>

        @include('dashboard.partials.filters', ['idPrefix' => 'team'])

        <section class="card mb-4" aria-labelledby="pending-requests-heading">
            <div class="card-header"><div><h2 id="pending-requests-heading" class="h5 mb-1">Pending requests</h2><p class="mb-0 small text-muted">Requests still moving through the workflow.</p></div></div>
            <div class="flow-request-grid">
                @forelse($pendingRequests as $purchaseRequest)
                    <article class="flow-request-card">
                        <div class="flow-request-card-topline">
                            <a class="flow-request-number" href="{{ route('purchase-requests.show', $purchaseRequest) }}" data-workflow-request-preview>{{ $purchaseRequest->request_number }}</a>
                        </div>
                        <h3 class="flow-request-title">{{ $purchaseRequest->title }}</h3>
                        <div class="flow-request-details">
                            <span><strong>Workflow status:</strong> <x-status-badge :status="$purchaseRequest->status" /></span>
                            <span><strong>Updated:</strong> {{ $purchaseRequest->updated_at->format('M j, Y g:i A') }}</span>
                        </div>
                        
                        <div class="flow-request-card-footer">
                            <x-admin-delete-transaction :purchase-request="$purchaseRequest" />
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-requests.show', $purchaseRequest) }}" data-workflow-request-preview>View request <x-ui.icon name="chevron-right" size="15" /></a>
                            @can('withdraw', $purchaseRequest)
                                <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#void-request-modal" data-void-url="{{ \App\Support\WorkflowUrl::route('purchase-requests.withdraw', $purchaseRequest) }}" data-void-number="{{ $purchaseRequest->request_number }}" data-void-title="{{ $purchaseRequest->title }}" data-void-stage="{{ \App\Support\RequestWithdrawal::stageLabel($purchaseRequest->status->value) }}" data-void-immediate="{{ \App\Support\RequestWithdrawal::voidsImmediately($purchaseRequest) ? '1' : '0' }}"><x-ui.icon name="x-circle" size="15" /> Void</button>
                            @endcan
                        </div>
                    </article>
                @empty
                    <x-ui.empty-state class="flow-request-empty" icon="hourglass" title="No pending requests" description="Your active purchase requests will appear here." />
                @endforelse
            </div>
        </section>

        {{-- One Void form for every pending card; the card's button points it at its request. --}}
        <div class="modal fade" id="void-request-modal" tabindex="-1" aria-labelledby="void-request-heading" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" method="POST" action="#" data-void-form>
                    @csrf
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title fs-5" id="void-request-heading">Void request</h2>
                            <p class="small text-muted mb-0"><span data-void-number></span> · <span data-void-title></span></p>
                        </div>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning small d-flex gap-2" role="note">
                            <x-ui.icon name="warning" size="16" />
                            <div data-void-message-acknowledged>This withdraws the <strong>whole request and all its items</strong>. Processing pauses now, and the staff handling it (<span data-void-stage></span>) acknowledge it before it is voided. This cannot be undone.</div>
                            <div data-void-message-immediate hidden>This voids the <strong>whole request and all its items</strong> right away. No staff are handling it yet, so no acknowledgment is needed. This cannot be undone.</div>
                        </div>
                        <label class="form-label" for="void-reason">Reason</label>
                        <textarea class="form-control" id="void-reason" name="withdrawal_reason" rows="3" minlength="5" maxlength="2000" required placeholder="e.g. No longer needed; duplicate of another request."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Keep request</button>
                        <button class="btn btn-danger" type="submit">Void request</button>
                    </div>
                </form>
            </div>
        </div>

        <section class="card" aria-labelledby="completed-requests-heading">
            <div class="card-header flex-column align-items-stretch gap-3">
                <div><h2 id="completed-requests-heading" class="h5 mb-1">{{ match ($requestTab) { 'returned' => 'Returned requests', 'voided' => 'Voided requests', default => 'Completed requests' } }}</h2><p class="mb-0 small text-muted">{{ match ($requestTab) { 'returned' => 'Requests returned by an approver for correction.', 'voided' => 'Requests you withdrew: waiting for staff acknowledgment, or voided.', default => 'Completed, fulfilled, cancelled, or rejected requests.' } }}</p></div>
                <nav class="nav nav-pills gap-2" aria-label="Request history">
                    <a class="nav-link {{ $requestTab === 'completed' ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['request_tab' => 'completed']) }}#completed-requests-heading" @if($requestTab === 'completed') aria-current="page" @endif>Completed</a>
                    <a class="nav-link {{ $requestTab === 'returned' ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['request_tab' => 'returned']) }}#completed-requests-heading" @if($requestTab === 'returned') aria-current="page" @endif>Returned <span class="badge bg-secondary ms-1">{{ $returnedCount }}</span></a>
                    <a class="nav-link {{ $requestTab === 'voided' ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['request_tab' => 'voided']) }}#completed-requests-heading" @if($requestTab === 'voided') aria-current="page" @endif>Voided <span class="badge bg-secondary ms-1">{{ $voidedCount }}</span>@if($voidPendingCount > 0)<span class="badge text-bg-warning ms-1" title="Waiting for staff acknowledgment">{{ $voidPendingCount }} pending</span>@endif</a>
                </nav>
            </div>
            @if($requestTab === 'returned')
                @include('partials.table-toolbar', ['toolbar' => [
                    'prefix' => 'returned',
                    'label' => 'Filter returned requests',
                    'anchor' => 'completed-requests-heading',
                    'searchPlaceholder' => 'Search request, title...',
                ]])
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th class="table-row-number">#</th><th>Request</th><th>Returned by</th><th>Return remarks</th><th>Returned on</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                        @forelse($returnedRequests as $purchaseRequest)
                            @php($returnApproval = $purchaseRequest->latestReturnApproval)
                            <tr>
                                <td class="table-row-number">{{ $returnedRequests->firstItem() + $loop->index }}</td>
                                <td><span class="fw-semibold">{{ $purchaseRequest->request_number }}</span><div class="small text-muted text-truncate request-title">{{ $purchaseRequest->title }}</div></td>
                                <td>
                                    @if(filled($returnApproval?->signature_data))
                                        <img class="d-block mb-1" src="{{ \App\Support\WorkflowSignature::forDisplay($returnApproval->signature_data) }}" alt="{{ $returnApproval->signature_name ?? $returnApproval->user?->name ?? 'Returning approver' }} signature" style="max-width: 160px; max-height: 56px; object-fit: contain;">
                                    @else
                                        <div class="small text-muted mb-1">Signature not recorded</div>
                                    @endif
                                    <span class="fw-semibold">{{ $returnApproval?->user?->name ?? $returnApproval?->signature_name ?? 'Approver not recorded' }}</span>
                                    <div class="small text-muted">{{ $returnApproval?->user?->role?->name ?? $returnApproval?->approval_level }}</div>
                                </td>
                                <td class="small"><x-workflow-remarks :request="$purchaseRequest" /></td>
                                <td class="small text-muted text-nowrap">{{ $returnApproval?->acted_at?->format('M j, Y g:i A') ?? 'Not recorded' }}</td>
                                <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-requests.show', $purchaseRequest) }}" data-workflow-request-preview>View request <x-ui.icon name="chevron-right" size="15" /></a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-ui.empty-state icon="file-text" title="No returned requests found" description="Requests returned for correction will appear here with the approver and their remarks." /></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($returnedRequests->hasPages())<div class="card-footer">{{ $returnedRequests->fragment('completed-requests-heading')->links() }}</div>@endif
            @elseif($requestTab === 'voided')
                @include('dashboard.partials.voided-requests')
            @else
            @include('partials.table-toolbar', ['toolbar' => [
                'prefix' => 'completed',
                'label' => 'Filter completed requests',
                'anchor' => 'completed-requests-heading',
                'searchPlaceholder' => 'Search request, title…',
                'statusOptions' => collect([
                    \App\Enums\PurchaseRequestStatus::Completed,
                    \App\Enums\PurchaseRequestStatus::Fulfilled,
                    \App\Enums\PurchaseRequestStatus::Cancelled,
                    \App\Enums\PurchaseRequestStatus::Rejected,
                ])->mapWithKeys(fn ($status) => [$status->value => str($status->value)->replace('_', ' ')->title()->toString()])->all(),
                'dateLabel' => 'Completed',
            ]])
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th class="table-row-number">#</th><th>Request</th><th>Workflow status</th><th>Completed</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    @forelse($doneRequests as $purchaseRequest)
                        <tr>
                            <td class="table-row-number">{{ $doneRequests->firstItem() + $loop->index }}</td>
                            <td><span class="fw-semibold">{{ $purchaseRequest->request_number }}</span><div class="small text-muted text-truncate request-title">{{ $purchaseRequest->title }}</div></td>
                            <td><x-status-badge :status="$purchaseRequest->status" /></td>
                            <td class="small text-muted text-nowrap">{{ $purchaseRequest->updated_at->format('M j, Y g:i A') }}</td>
                            <td class="text-end text-nowrap"><button class="btn btn-sm btn-outline-primary" type="button" data-request-preview-url="{{ route('purchase-requests.preview', $purchaseRequest) }}">Preview signed request <x-ui.icon name="file-text" size="15" /></button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="check-circle" title="No completed requests found" description="Completed requests will appear here with their signed preview. Adjust the search or filters if you expected results." /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($doneRequests->hasPages())<div class="card-footer">{{ $doneRequests->fragment('completed-requests-heading')->links() }}</div>@endif
            @endif
        </section>
    </div>

    @include('purchase-requests.partials.request-preview-modal')
@endsection
