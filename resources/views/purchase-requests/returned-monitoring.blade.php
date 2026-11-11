@extends('layouts.app')
@section('title', 'Return Monitoring')
@section('content')
<div class="dashboard-page">
    <x-ui.page-header eyebrow="Purchasing workspace" title="Return Monitoring" description="Track requests returned for correction, their reasons, and their live workflow status.">
        <x-slot:actions>
            <a class="btn btn-outline-primary" href="{{ route('workflow-tracker.index') }}">Workflow Tracker</a>
            <a class="btn btn-outline-primary" href="{{ route('voided-monitoring.index') }}">Voided Monitoring</a>
        </x-slot:actions>
    </x-ui.page-header>
    <section class="card" aria-labelledby="return-monitor-heading">
        <div class="card-header"><h2 class="h5 mb-0" id="return-monitor-heading">Returned requests <span class="badge text-bg-secondary">{{ $returnedMonitoring->total() }}</span></h2></div>
        @include('partials.table-toolbar', ['toolbar' => ['prefix' => 'returned', 'label' => 'Filter returned requests', 'searchPlaceholder' => 'Search request, requestor, reason...', 'dateLabel' => 'Returned date', 'statusOptions' => ['RETURNED' => 'Awaiting correction', 'RESUBMITTED' => 'Resubmitted']]])
        <div class="table-responsive"><table class="table table-hover align-middle mb-0" data-mobile-cards>
            <thead><tr><th>Request</th><th>Requestor</th><th>Returned by / date</th><th>Correction reason</th><th>Current workflow status</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($returnedMonitoring as $row)
                @php($returned = $row->latestReturnApproval)
                <tr>
                    <td><strong>{{ $row->request_number }}</strong><div class="small">{{ $row->title }}</div>@if($row->purchaseOrder)<small>{{ $row->purchaseOrder->po_number }}</small>@endif</td>
                    <td>{{ $row->requestor?->name }}</td>
                    <td>{{ $returned?->user?->name ?? 'Not recorded' }}<div class="small text-muted">{{ $returned?->acted_at?->format('M j, Y g:i A') }}</div></td>
                    <td style="white-space: pre-wrap">{{ $returned?->remarks }}</td>
                    <td><x-status-badge :status="$row->status" /><div class="small text-muted mt-1">{{ $row->status === \App\Enums\PurchaseRequestStatus::Returned ? 'Awaiting requestor correction' : $row->responsibleRole?->name }}</div></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-requests.show', $row) }}" data-workflow-request-preview>View request</a>@if($row->purchaseOrder)<a class="btn btn-sm btn-outline-secondary mt-1" href="{{ route('workflow-tracker.show', $row->purchaseOrder) }}">View timeline</a>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6"><x-ui.empty-state icon="file-text" title="No returned requests found" description="Requests returned to requestors appear here and remain visible after resubmission." /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        @if($returnedMonitoring->hasPages())<div class="card-footer">{{ $returnedMonitoring->links() }}</div>@endif
    </section>
</div>
@endsection
