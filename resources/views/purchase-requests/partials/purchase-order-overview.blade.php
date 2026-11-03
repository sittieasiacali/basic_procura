@php
    $purchaseOrder = isset($purchaseRequest) ? $purchaseRequest->purchaseOrder : null;
    $processedPurchaseOrders = $processedPurchaseOrders ?? collect();
@endphp

<section class="purchase-request-section purchase-order-overview-section" id="purchase-order-summary" data-section="processed-purchase-orders" aria-labelledby="processed-purchase-orders-heading">
    <div class="card purchase-order-overview-card">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <x-ui.icon name="receipt" size="20" />
                <div>
                    <div class="section-kicker">Saved records</div>
                    <h2 class="h5 mb-0" id="processed-purchase-orders-heading">Processed Purchase Orders</h2>
                </div>
            </div>
            <span class="badge text-bg-secondary">{{ method_exists($processedPurchaseOrders, 'total') ? $processedPurchaseOrders->total() : $processedPurchaseOrders->count() }} processed</span>
        </div>
        <div class="card-body p-0">
            @if($processedPurchaseOrders->isEmpty())
                <div class="processed-purchase-order-empty">
                    <x-ui.icon name="clock" size="20" />
                    <div><strong>No processed purchase orders yet.</strong><br><span>A purchase order appears here after final approval.</span></div>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 processed-purchase-orders-table">
                        <thead>
                            <tr>
                                <th scope="col">PO Number</th><th scope="col">Request</th><th scope="col">Campaign / Source Account</th>
                                <th scope="col">Transaction</th><th scope="col">Fund Source</th><th scope="col">Expected Delivery</th>
                                <th scope="col" class="text-end">Total</th><th scope="col">PO Approved</th><th scope="col">Status</th><th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($processedPurchaseOrders as $processedOrder)
                                @php
                                    $sourceAccount = $processedOrder->budget_campaign_account_number ?: $processedOrder->budgetCampaign?->account_number ?: $processedOrder->budget_source_bank_account ?: $processedOrder->account_number;
                                    $transaction = match($processedOrder->budget_transaction_type) { 'ONLINE' => 'Online Transaction', 'CASH' => 'Cash Transaction', 'CHECK' => 'Check Transaction', default => filled($processedOrder->budget_transaction_type) ? str($processedOrder->budget_transaction_type)->replace('_', ' ')->title() : 'Not recorded' };
                                @endphp
                                <tr>
                                    <td><button class="btn btn-link p-0 processed-purchase-order-code" type="button" data-po-preview-url="{{ route('purchase-orders.preview', $processedOrder) }}">{{ $processedOrder->po_number }}</button></td>
                                    <td><a href="{{ route('purchase-requests.show', ['purchaseRequest' => $processedOrder->purchase_request_id, 'from_stage' => 'purchase-order']) }}">{{ $processedOrder->purchaseRequest->request_number }}</a></td>
                                    <td class="text-break">{{ $sourceAccount ?: 'Not recorded' }}</td>
                                    <td>{{ $transaction }}</td>
                                    <td>{{ filled($processedOrder->budget_fund_source) ? str($processedOrder->budget_fund_source)->replace('_', ' ')->title() : 'Not recorded' }}</td>
                                    <td>{{ $processedOrder->expected_delivery_date?->format('M j, Y') ?? 'Not recorded' }}</td>
                                    <td class="text-end fw-semibold text-nowrap">&#8369; {{ number_format((float) $processedOrder->total_amount, 2) }}</td>
                                    <td>{{ ($processedOrder->approved_at ?? $processedOrder->issued_at)?->format('M j, Y') ?? 'Not recorded' }}</td>
                                    <td><span class="status-badge status-completed">{{ str($processedOrder->status)->replace('_', ' ')->title() }}</span></td>
                                    <td class="text-end"><button class="btn btn-outline-primary btn-sm" type="button" data-po-preview-url="{{ route('purchase-orders.preview', $processedOrder) }}"><x-ui.icon name="eye" size="15" /> <span>Preview</span></button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if(method_exists($processedPurchaseOrders, 'hasPages') && $processedPurchaseOrders->hasPages())
            <div class="card-footer">{{ $processedPurchaseOrders->links() }}</div>
        @endif
    </div>
</section>

@include('purchase-orders.partials.preview-modal')
