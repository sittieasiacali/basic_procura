@php
    $approvedQuotations = \App\Models\SupplierQuotation::deduplicateForReview(
        $allQuotations->filter(fn ($quotation) => $quotation->is_approved && (int) $quotation->supplier_id === (int) $purchaseRequest->selected_supplier_id)
    );
    $approvedQuotation = $approvedQuotations->first();
    $approvedNetAmount = $approvedQuotations->sum(fn ($quotation) => $quotation->vatBreakdown()['base']);
    $approvedTotalAmount = $approvedQuotations->sum(fn ($quotation) => $quotation->vatBreakdown()['total']);
    $approvedLineAmounts = \App\Support\PurchaseOrderLinePricing::amounts($purchaseRequest->items, $approvedQuotations);
    $firstItem = $purchaseRequest->items->first();
    $requiresPoLineDetails = $purchaseRequest->items->contains(fn ($item) => $item->quantity === null || blank($item->unit) || $item->estimated_unit_price === null);
    $paymentTermsFromQuotation = filled($approvedQuotation?->delivery_terms);
@endphp

<section class="po-focus-hero" id="request-workflow" aria-labelledby="po-focus-heading">
    <div class="po-focus-hero-icon"><x-ui.icon name="request" size="27" /></div>
    <div class="po-focus-hero-copy">
        <h2 id="po-focus-heading">{{ $approvedQuotation ? 'Ready to create purchase order' : 'Purchase order prerequisites' }}</h2>
        <p>{{ $approvedQuotation ? 'The selected supplier has an approved quotation. Review the details, then create the PO.' : 'A selected supplier with an approved quotation is required before creating the PO.' }}</p>
    </div>
    <dl class="po-focus-hero-facts">
        <div><dt>Approved supplier</dt><dd>{{ $purchaseRequest->selectedSupplier?->name ?? 'Not selected' }}</dd></div>
        <div><dt>Approved amount</dt><dd>{{ $approvedQuotation ? '₱ '.number_format((float) $approvedTotalAmount, 2) : 'Not available' }}</dd></div>
    </dl>
</section>

<div class="po-focus-overview">
    <section class="po-focus-card" aria-labelledby="po-glance-heading">
        <h2 id="po-glance-heading"><span class="po-focus-card-icon"><x-ui.icon name="request" size="21" /></span>Request at a glance</h2>
        <dl class="po-focus-facts">
            <div><dt>Requested by</dt><dd>{{ $purchaseRequest->requestor->name }}</dd></div>
            <div><dt>Department</dt><dd>{{ $purchaseRequest->requestor->department ?: 'Not recorded' }}</dd></div>
            <div><dt>Required date</dt><dd>{{ $purchaseRequest->required_date?->format('M d, Y') ?? 'Not recorded' }}</dd></div>
            <div><dt>Delivery location</dt><dd>{{ $purchaseRequest->delivery_location }}</dd></div>
        </dl>
        <div class="po-focus-card-footer"><span>Requested item{{ $purchaseRequest->items->count() === 1 ? '' : 's' }}</span><strong>{{ $firstItem?->description ?? 'No items recorded' }}
            @if($firstItem?->quantity !== null) · Qty {{ number_format((float) $firstItem->quantity, 2) }} @endif
            @if($purchaseRequest->items->count() > 1) <small>+ {{ $purchaseRequest->items->count() - 1 }} more</small> @endif
        </strong></div>
    </section>

    <section class="po-focus-card" aria-labelledby="po-quotation-heading">
        <h2 id="po-quotation-heading"><span class="po-focus-card-icon po-focus-card-icon--star">★</span>Approved quotation</h2>
        <dl class="po-focus-facts">
            <div><dt>Supplier</dt><dd>{{ $purchaseRequest->selectedSupplier?->name ?? 'Not selected' }} @if($approvedQuotation)<span class="po-focus-approved">Approved</span>@endif</dd></div>
            <div><dt>Item amount (net)</dt><dd>&#8369; {{ number_format($approvedNetAmount - $approvedQuotations->sum('shipping_fee'), 2) }}</dd></div>
            <div><dt>Shipping fee</dt><dd>&#8369; {{ number_format($approvedQuotations->sum('shipping_fee'), 2) }}</dd></div>
            <div><dt>Quoted amount</dt><dd class="po-focus-amount">{{ $approvedQuotation ? '₱ '.number_format((float) $approvedTotalAmount, 2) : 'Not available' }}</dd></div>
            <div><dt>Lead time</dt><dd>{{ $purchaseRequest->purchaseOrder?->delivery_lead_time ?: 'Not provided' }}</dd></div>
            <div><dt>Warranty</dt><dd>{{ $approvedQuotation?->warranty ?: 'Not provided' }}</dd></div>
        </dl>
        <div class="po-focus-card-footer"><a href="#po-all-quotations" onclick="document.getElementById('po-all-quotations').open = true">Compare {{ $quotations->count() }} quotation{{ $quotations->count() === 1 ? '' : 's' }} <span aria-hidden="true">→</span></a></div>
    </section>
</div>

<details class="po-focus-disclosure" id="po-all-quotations">
    <summary><span><x-ui.icon name="request" size="19" />All quotations <small>({{ $quotations->count() }})</small></span><span aria-hidden="true">⌄</span></summary>
    <div class="po-focus-disclosure-body table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Supplier</th><th>Quoted item</th><th>Amount</th><th>Lead time</th><th>Warranty</th><th>Decision</th><th>Document</th></tr></thead>
            <tbody>
            @forelse($quotations as $quotation)
                <tr>
                    <td>{{ $quotation->supplier?->name ?? 'Unavailable' }}</td>
                    <td>{{ $quotation->item?->description ?? $quotation->item_or_service }}</td>
                    <td class="text-nowrap">₱ {{ number_format($quotation->vatBreakdown()['total'], 2) }}</td>
                    <td>{{ $quotation->delivery_lead_time ?: 'Not provided' }}</td>
                    <td>{{ $quotation->warranty ?: 'Not provided' }}</td>
                    <td>{{ $quotation->is_approved ? 'Approved' : ($quotation->is_recommended ? 'Recommended' : 'Not approved') }}</td>
                    <td>@if($quotation->quotationDestination())<a href="{{ $quotation->quotationDestination() }}" target="_blank" rel="noopener noreferrer">Open</a>@else—@endif</td>
                </tr>
            @empty
                <tr><td colspan="7">No quotations recorded.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</details>

<section class="po-focus-card po-focus-form-card" id="purchase-order-actions" aria-labelledby="po-details-heading">
    <h2 id="po-details-heading"><span class="po-focus-card-icon"><x-ui.icon name="request" size="21" /></span>Purchase order details</h2>
    @can('prepare', \App\Models\PurchaseOrder::class)
        @if($approvedQuotation)
            <form method="POST" action="{{ route('purchase-orders.store', ['purchaseRequest' => $purchaseRequest, 'from_stage' => $returnStage]) }}" data-validation-context="purchase-order" data-po-create-form class="po-focus-form">
                @csrf
                <input type="hidden" name="_validation_context" value="purchase-order">
                <div class="po-form-group-heading po-form-group-heading--order"><span>Order information</span><small>Request and supplier details</small></div>
                <div class="po-focus-field">
                    <label>Lead time (PO Team)</label><input class="form-control mb-2" name="delivery_lead_time" maxlength="255" value="{{ old('delivery_lead_time') }}"><label for="expected_delivery_date">Expected delivery date <span class="text-danger">*</span></label>
                    <input class="form-control @error('expected_delivery_date') is-invalid @enderror" id="expected_delivery_date" name="expected_delivery_date" type="date" value="{{ old('expected_delivery_date', $purchaseRequest->required_date?->copy()->addDays(7)->format('Y-m-d')) }}" required>
                    @error('expected_delivery_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="po-focus-field po-field-campaign"><label>Campaign / Source Account</label><input class="form-control" value="{{ $purchaseRequest->teamLeader?->campaign?->name ?? $purchaseRequest->requestor?->campaign?->name ?? $purchaseRequest->department_campaign }}" readonly><small class="text-muted">Automatically resolved from the Purchase Request campaign.</small></div>
                <div class="po-focus-field">
                    <label for="po_account_number">Payment account number <span class="text-danger">*</span></label>
                    <input class="form-control @error('account_number') is-invalid @enderror" id="po_account_number" name="account_number" maxlength="255" value="{{ old('account_number') }}" autocomplete="off" required aria-describedby="po_account_number_help">
                    <small class="text-muted" id="po_account_number_help">Required. Confirm the payment account number from the approved supplier payment details.</small>
                    @error('account_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="po-focus-field">
                    <label for="po_account_name">Payee account name <span class="text-danger">*</span></label>
                    <input class="form-control @error('account_name') is-invalid @enderror" id="po_account_name" name="account_name" maxlength="255" value="{{ old('account_name', $purchaseRequest->selectedSupplier?->name) }}" readonly required>
                    <small class="text-muted">Automatically filled from the selected supplier.</small>
                    @error('account_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="po-focus-field">
                    <label for="po_payment_terms">Payment terms <span class="text-danger">*</span></label>
                    <input class="form-control @error('payment_terms') is-invalid @enderror" id="po_payment_terms" name="payment_terms" maxlength="255" value="{{ old('payment_terms', $approvedQuotation?->delivery_terms) }}" placeholder="Example: 30 days" @if($paymentTermsFromQuotation) readonly @endif required>
                    <small class="text-muted">{{ $paymentTermsFromQuotation ? 'Automatically filled from the approved quotation.' : 'Enter the agreed payment terms.' }}</small>
                    @error('payment_terms')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="po-form-group-heading po-form-group-heading--payment"><span>Payment information</span><small>Details needed for payment processing</small></div>
                <div class="po-focus-field po-field-payable-on"><label for="po_payable_on">When can pay <span class="text-danger">*</span></label><input class="form-control @error('payable_on') is-invalid @enderror" id="po_payable_on" name="payable_on" type="date" value="{{ old('payable_on') }}" required>@error('payable_on')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="po-focus-field po-field-payment-details"><label for="po_payment_details">Payment details <span class="text-danger">*</span></label><textarea class="form-control @error('payment_details') is-invalid @enderror" id="po_payment_details" name="payment_details" rows="1" maxlength="2000" placeholder="Payment instructions or reference" required>{{ old('payment_details') }}</textarea>@error('payment_details')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                @if($requiresPoLineDetails)
                    <fieldset class="po-line-details">
                        <legend>Purchase order line details</legend>
                        <p>Enter the missing quantities and units. Prices and line totals are calculated automatically from the approved quotation's net amount (₱ {{ number_format((float) $approvedNetAmount, 2) }}).</p>
                        @error('po_items')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
                        @foreach($purchaseRequest->items as $item)
                            @php
                                $lineQuantity = old("po_items.$item->id.quantity", $item->quantity);
                                $lineBaseAmount = $approvedLineAmounts[$item->id] ?? 0;
                                $automaticUnitPrice = \App\Support\PurchaseOrderLinePricing::unitPrice($lineBaseAmount, (float) $lineQuantity);
                            @endphp
                            <div class="po-line-detail-row" data-po-line-amount="{{ number_format($lineBaseAmount, 2, '.', '') }}">
                                <strong>{{ $loop->iteration }}. {{ $item->description }}</strong>
                                <div class="row g-2 mt-1">
                                    <div class="col-sm-4"><label class="form-label" for="po-item-{{ $item->id }}-quantity">Quantity</label><input class="form-control @error("po_items.$item->id.quantity") is-invalid @enderror" id="po-item-{{ $item->id }}-quantity" name="po_items[{{ $item->id }}][quantity]" type="number" min="0.01" step="0.01" value="{{ $lineQuantity }}" data-po-quantity required>@error("po_items.$item->id.quantity")<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                    <div class="col-sm-4"><label class="form-label" for="po-item-{{ $item->id }}-unit">Unit</label><select class="form-select @error("po_items.$item->id.unit") is-invalid @enderror" id="po-item-{{ $item->id }}-unit" name="po_items[{{ $item->id }}][unit]" required><option value="">Select unit</option>@foreach(config('procurement.units') as $value => $label)<option value="{{ $value }}" @selected(old("po_items.$item->id.unit", $item->unit) === $value)>{{ $label }}</option>@endforeach</select>@error("po_items.$item->id.unit")<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                    <div class="col-sm-4"><label class="form-label" for="po-item-{{ $item->id }}-price">Unit price (₱)</label><input class="form-control" id="po-item-{{ $item->id }}-price" type="number" value="{{ number_format($automaticUnitPrice, 2, '.', '') }}" data-po-unit-price readonly><small class="text-muted">Approved line total: ₱ {{ number_format($lineBaseAmount, 2) }}</small></div>
                                </div>
                            </div>
                        @endforeach
                    </fieldset>
                @endif
                <div class="po-focus-field po-focus-remarks po-field-remarks">
                    <label for="po_remarks">Remarks <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea class="form-control @error('remarks') is-invalid @enderror" id="po_remarks" name="remarks" rows="1" maxlength="2000" placeholder="Notes for the purchase order">{{ old('remarks') }}</textarea>
                    @error('remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="po-focus-create-signature">
                    <x-workflow-signature-pad
                        id="po-create-prepared-signature"
                        label="Prepared By signature"
                        instruction="Draw your signature. Creating the Purchase Order will submit it directly to the assigned Checker."
                    />
                </div>
                <div class="po-focus-preview-hint"><x-ui.icon name="file-text" size="16" />The signed Purchase Order will be submitted to the assigned Checker.</div>
                <button class="btn btn-primary po-focus-submit" type="submit" data-po-submit><span data-po-submit-label><x-ui.icon name="request" size="18" />Create purchase order</span></button>
            </form>

        @else
            <p class="mb-0 text-muted">The approved quotation must be available before a purchase order can be created.</p>
        @endif
    @else
        <p class="mb-0 text-muted">An authorized Purchasing user must create this purchase order.</p>
    @endcan
</section>
