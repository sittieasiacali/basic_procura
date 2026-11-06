@if($creatorWorkflow === 'canvassing')
    @include('partials.table-toolbar', ['toolbar' => [
        'prefix' => 'canvassing',
        'label' => 'Filter canvassing processed',
        'anchor' => 'processed-requests-heading',
        'searchPlaceholder' => 'Search request, title, requestor…',
        'dateLabel' => 'Updated',
    ]])
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 dashboard-table">
            <thead><tr><th class="table-row-number">#</th><th>Request</th><th>Suppliers quoted</th><th>Recommendation</th><th>Status</th><th class="text-end">Updated</th><th class="text-end">Action</th></tr></thead>
            <tbody>
                @forelse($canvassingRecords as $record)
                    @php($recommended = $record->quotations->first(fn ($quotation) => $quotation->is_recommended))
                    <tr>
                        <td class="table-row-number">{{ $canvassingRecords->firstItem() + $loop->index }}</td>
                        <td><a class="fw-semibold text-decoration-none" href="{{ route('purchase-requests.show', $record) }}" data-workflow-request-preview>{{ $record->request_number }}</a><div class="small text-muted">{{ $record->title }}</div></td>
                        <td>{{ $record->quotations->pluck('supplier_id')->unique()->count() }}</td>
                        <td>{{ $recommended?->supplier?->name ?? ($record->quotations->isEmpty() ? 'Submitted without quotation' : 'President to review') }}</td>
                        <td><x-status-badge :status="$record->status" /></td>
                        <td class="text-end small text-muted text-nowrap">{{ $record->updated_at->format('M j, Y g:i A') }}</td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-requests.show', $record) }}" data-workflow-request-preview>View request</a><x-admin-delete-transaction :purchase-request="$record" /></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-ui.empty-state icon="receipt" title="No canvassing records found" description="Requests you personally submit from Canvassing will appear here." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($canvassingRecords->hasPages())<div class="card-footer">{{ $canvassingRecords->fragment('processed-requests-heading')->links() }}</div>@endif
@elseif($creatorWorkflow === 'purchase-order')
    @include('partials.table-toolbar', ['toolbar' => [
        'prefix' => 'created_po',
        'label' => 'Filter Purchase Orders processed',
        'anchor' => 'processed-requests-heading',
        'searchPlaceholder' => 'Search PO, request, title, supplier…',
        'statusOptions' => \App\Http\Controllers\DashboardController::PO_STATUSES,
        'statusPlaceholder' => 'All PO statuses',
        'dateLabel' => 'Created',
    ]])
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 dashboard-table">
            <thead><tr><th class="table-row-number">#</th><th>PO Number</th><th>Request</th><th>Supplier</th><th>Status</th><th>Created by</th><th class="text-end">Amount</th><th class="text-end">Action</th></tr></thead>
            <tbody>
                @forelse($createdPurchaseOrders as $purchaseOrder)
                    <tr>
                        <td class="table-row-number">{{ $createdPurchaseOrders->firstItem() + $loop->index }}</td>
                        <td class="fw-semibold">{{ $purchaseOrder->po_number }}</td>
                        <td>@if($purchaseOrder->purchaseRequest)<a href="{{ route('purchase-requests.show', $purchaseOrder->purchaseRequest) }}" data-workflow-request-preview>{{ $purchaseOrder->purchaseRequest->request_number }}</a><div class="small text-muted">{{ $purchaseOrder->purchaseRequest->title }}</div>@else<span class="text-muted">Request unavailable</span>@endif</td>
                        <td>{{ $purchaseOrder->supplier?->name ?? 'Not recorded' }}</td>
                        <td><span class="badge text-bg-light border text-dark">{{ str($purchaseOrder->status)->replace('_', ' ')->title() }}</span></td>
                        <td>{{ $purchaseOrder->creator?->name ?? 'Not recorded' }}<div class="small text-muted">{{ $purchaseOrder->created_at?->format('M j, Y g:i A') }}</div></td>
                        <td class="text-end text-nowrap fw-semibold">&#8369; {{ number_format((float) $purchaseOrder->total_amount, 2) }}</td>
                        <td class="text-end text-nowrap"><button class="btn btn-sm btn-outline-primary" type="button" data-po-preview-url="{{ route('purchase-orders.preview', $purchaseOrder) }}">View PO</button><x-admin-delete-transaction :record="$purchaseOrder" /></td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-ui.empty-state icon="receipt" title="No Purchase Orders found" description="Purchase Orders will appear here after they are created." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($createdPurchaseOrders->hasPages())<div class="card-footer">{{ $createdPurchaseOrders->fragment('processed-requests-heading')->links() }}</div>@endif
@else
    @include('budget-requests.partials.register', ['embedded' => true, 'filterUrl' => route('dashboard')])
@endif
