@php
    $document = $purchaseOrderDocument;
    $order = $document['purchaseOrder'];
    $request = $document['purchaseRequest'];
    $supplier = $order->supplier;
@endphp

<section class="purchase-order-document po-details-template" aria-labelledby="po-details-template-heading">
    @include('partials.document-letterhead')
    <header class="po-details-template-header">
        <div>
            <div class="section-kicker">Saved purchase order</div>
            <h3 id="po-details-template-heading">Purchase Order Details</h3>
        </div>
        <div class="po-details-template-status">
            <span class="status-badge status-completed">{{ $document['statusLabel'] }}</span>
            <strong>{{ $document['poNumber'] }}</strong>
            <span>Created {{ $order->created_at?->format('M j, Y') ?? 'Not recorded' }}</span>
        </div>
    </header>

    <div class="po-details-template-grid">
        <section class="po-details-template-panel" aria-labelledby="po-supplier-information">
            <h4 id="po-supplier-information">Supplier Information</h4>
            <dl>
                <div><dt>Supplier</dt><dd>{{ $document['supplierName'] }}</dd></div>
                <div><dt>Address</dt><dd>{{ $supplier?->address ?: 'Not provided' }}</dd></div>
                <div><dt>TIN</dt><dd>{{ $supplier?->tax_id ?: 'Not provided' }}</dd></div>
                <div><dt>Contact</dt><dd>{{ $supplier?->contact_person ?: 'Not provided' }}@if($supplier?->email)<br>{{ $supplier->email }}@endif @if($supplier?->phone)<br>{{ $supplier->phone }}@endif</dd></div>
            </dl>
        </section>

        <section class="po-details-template-panel" aria-labelledby="po-request-information">
            <h4 id="po-request-information">Request Information</h4>
            <dl>
                <div><dt>Requested by</dt><dd>{{ $document['requestedBy'] }}</dd></div>
                <div><dt>Request date</dt><dd>{{ $request->created_at?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
                <div><dt>Lead time</dt><dd>{{ $order->delivery_lead_time ?: 'Not recorded' }}</dd></div><div><dt>Expected delivery</dt><dd>{{ $order->expected_delivery_date?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
                <div><dt>Delivery location</dt><dd>{{ $request->delivery_location ?: 'Not provided' }}</dd></div>
                <div><dt>Remarks</dt><dd>{{ $order->remarks ?: 'N/A' }}</dd></div>
            </dl>
        </section>
    </div>

    <section class="po-details-template-panel po-details-template-payment" aria-labelledby="po-payment-information">
        <h4 id="po-payment-information">Purchase Order Details</h4>
        <dl>
            <div><dt>Campaign Bank Account</dt><dd>{{ $document['sourceBankAccount'] }}</dd></div>
            <div><dt>Type of Transaction</dt><dd>{{ $document['transactionTypeLabel'] }}</dd></div>
            <div><dt>Payment Account Number</dt><dd>{{ $order->account_number ?: 'Not provided' }}</dd></div>
            <div><dt>Payee Account Name</dt><dd>{{ $order->account_name ?: $supplier?->name ?: 'Not provided' }}</dd></div>
            <div><dt>Payment Terms</dt><dd>{{ $order->payment_terms ?: 'Not provided' }}</dd></div>
            <div><dt>Payment Details</dt><dd>{{ $order->payment_details ?: 'Not provided' }}</dd></div>
        </dl>
    </section>

    <section class="po-details-template-items" aria-labelledby="po-requested-items">
        <h4 id="po-requested-items">Requested Items</h4>
        <div class="table-responsive">
            <table>
                <thead><tr><th>#</th><th>Description</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Amount</th></tr></thead>
                <tbody>
                    @forelse($document['items'] as $item)
                        <tr>
                            <td>{{ $item['line_number'] }}</td>
                            <td><strong>{{ $item['details'] }}</strong>@if($item['specifications'])<small>{{ $item['specifications'] }}</small>@endif</td>
                            <td>{{ number_format($item['quantity'], 2) }}</td>
                            <td>{{ $item['unit'] ?: '—' }}</td>
                            <td>&#8369; {{ number_format($item['unit_price'], 2) }}</td>
                            <td>&#8369; {{ number_format($item['amount'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No Purchase Order items were recorded.</td></tr>
                    @endforelse
                </tbody>
                <tfoot><tr><th colspan="5">Total</th><td>&#8369; {{ number_format($document['totalAmount'], 2) }}</td></tr></tfoot>
            </table>
        </div>
    </section>
</section>