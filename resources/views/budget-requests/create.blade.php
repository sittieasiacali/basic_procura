@extends('layouts.app')

@section('title', 'Create Budget Request')

@section('content')
@php
    // Without a PO (step skipped) this is an unsaved stand-in priced from the approved quotation.
    $order = $purchaseRequest->budgetPurchaseOrder();
    $orderReference = $order->po_number ?? $purchaseRequest->request_number;
    $approvedQuote = $purchaseRequest->quotations->first(fn ($quote) => (int) $quote->supplier_id === (int) $order->supplier_id && $quote->is_approved);
    $bankDetails = $approvedQuote?->bank_details ?: ($order->account_number ? trim(($order->account_name ?: $order->supplier?->name).' '.$order->account_number) : 'N/A');
@endphp
<div class="budget-create-page budget-create-page--compact budget-create-page--wide procura-form-page">
    <x-ui.form-page-header class="budget-create-header" eyebrow="Budget preparation" title="Create Budget Request" description="Choose the contributing campaign accounts, enter each share, then submit." icon="wallet" />

    <form method="POST" action="{{ route('budget.store') }}" class="budget-create-layout budget-create-layout--wide" data-validation-context="budget" data-budget-create-form novalidate>
        @csrf
        <input type="hidden" name="purchase_request_id" value="{{ $purchaseRequest->id }}">

        <main class="budget-create-main">
            @error('purchase_request_id')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror

            <section class="budget-create-card procura-form-section" aria-labelledby="budget-accounts-heading">
                <div class="budget-create-section-heading budget-create-card-heading"><span class="budget-create-heading-icon"><x-ui.icon name="wallet" size="21" /></span><div><div class="section-kicker">Account selection</div><h2 id="budget-accounts-heading">Campaign Source Accounts</h2><p>Select the accounts contributing to this Budget Request.</p></div></div>
                <div class="budget-create-field-grid">
                    <div class="budget-create-field budget-create-field--wide" data-budget-contributions data-total="{{ number_format((float) $order->total_amount, 2, '.', '') }}">
                        <h3 class="h6">Campaign2 / Campaign source accounts <span class="text-danger">*</span></h3>
                        <p class="small text-muted">Add the contributing accounts and the amount each will cover. Contributions must total the approved PO amount, including VAT and shipping.</p>
                        @error('source_accounts')<div class="alert alert-danger">{{ $message }}</div>@enderror
                        <div data-source-account-rows>
                            @foreach(old('source_accounts', [['campaign_id' => old('campaign_id', $order->budget_campaign_id), 'amount' => number_format((float) $order->total_amount, 2, '.', '')]]) as $sourceIndex => $source)
                                @include('budget-requests.partials.source-account-row')
                            @endforeach
                        </div>
                        <template data-source-account-template>@include('budget-requests.partials.source-account-row', ['sourceIndex' => '__INDEX__', 'source' => ['campaign_id' => '', 'amount' => '']])</template>
                        <button class="btn btn-outline-primary mt-2" type="button" data-add-source-account>Add source account</button>
                        <p class="small mt-2 mb-0" data-source-account-feedback role="status" aria-live="polite"></p>
                    </div>
                    <div class="budget-create-field budget-create-field--wide">
                        <label for="team-account-{{ $purchaseRequest->id }}">Request team based account — {{ $orderReference }}</label>
                        <input class="form-control" id="team-account-{{ $purchaseRequest->id }}" value="{{ $order->budgetCampaign?->name ?? $purchaseRequest->department_campaign }} — {{ $order->budget_campaign_account_number ?: $order->budgetCampaign?->account_number ?: 'Not configured' }}" readonly>
                        <small class="text-muted">Automatically filled from this PO's request team account.</small>
                    </div>
                </div>
            </section>

            <section class="budget-create-card procura-form-section" aria-labelledby="budget-preparation-heading">
                <div class="budget-create-section-heading budget-create-card-heading"><span class="budget-create-heading-icon"><x-ui.icon name="clipboard-check" size="21" /></span><div><div class="section-kicker">Step 1 · Budget Maker</div><h2 id="budget-preparation-heading">Budget Request Details</h2><p>Review the defaults and complete the manual fields. Enter N/A for text that does not apply. These details appear in the Final Budget and Finalized tab.</p></div></div>
                <div class="budget-create-field-grid">
                    {{-- Priority Level is chosen at the Final budget, not here. --}}
                    <div class="budget-create-field">
                        <label for="budget_request_campaign">Campaign <span aria-hidden="true">*</span></label>
                        <input class="form-control @error('request_campaign') is-invalid @enderror" id="budget_request_campaign" name="request_campaign" maxlength="255" value="{{ old('request_campaign', $purchaseRequest->department_campaign ?: 'N/A') }}" required>
                        @error('request_campaign')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="budget-create-field budget-create-field--wide">
                        <label for="budget_details">Details <span aria-hidden="true">*</span></label>
                        <textarea class="form-control @error('details') is-invalid @enderror" id="budget_details" name="details" maxlength="2000" rows="3" required>{{ old('details', $orderReference.' — '.$purchaseRequest->title) }}</textarea>
                        @error('details')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="budget-create-field">
                        <label for="budget_approved_amount">Amount</label>
                        <input class="form-control" id="budget_approved_amount" value="&#8369; {{ number_format((float) $order->total_amount, 2) }}" readonly>
                        <small class="text-muted">{{ $order->exists ? 'The approved PO total.' : 'The approved quotation total. This request has no PO.' }}</small>
                    </div>
                    <div class="budget-create-field">
                        <label for="budget_requested_payment_method">Mode of Payment</label>
                        <select class="form-select @error('requested_payment_method') is-invalid @enderror" id="budget_requested_payment_method" name="requested_payment_method">
                            <option value="">To be set by Finance</option>
                            @foreach(['ONLINE' => 'Online', 'CASH' => 'Cash', 'CHECK' => 'Check', 'N/A' => 'N/A'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('requested_payment_method', $order->budget_transaction_type) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">Requested mode; Finance confirms the payment method during payment.</small>
                        @error('requested_payment_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="budget-create-field budget-create-field--wide">
                        <label for="budget_bank_details">Bank Details (for Deposit) <span aria-hidden="true">*</span></label>
                        <textarea class="form-control @error('bank_details') is-invalid @enderror" id="budget_bank_details" name="bank_details" maxlength="2000" rows="2" required>{{ old('bank_details', $bankDetails ?: 'N/A') }}</textarea>
                        @error('bank_details')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="budget-create-field">
                        <label for="budget_requesting_team">Requesting Team</label>
                        <input class="form-control" id="budget_requesting_team" value="{{ $purchaseRequest->requestor?->department ?: $purchaseRequest->department_campaign ?: 'N/A' }}" readonly>
                        <small class="text-muted">Automatically filled from the requestor's department or request campaign.</small>
                    </div>
                    <div class="budget-create-field">
                        <label for="budget_payable_on">Date to Pay</label>
                        <input class="form-control @error('payable_on') is-invalid @enderror" id="budget_payable_on" name="payable_on" type="date" value="{{ old('payable_on', $order->budget_payable_on?->format('Y-m-d')) }}">
                        <small class="text-muted">Leave blank if not applicable.</small>
                        @error('payable_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="budget-create-field budget-create-field--wide">
                        <label for="budget_preparation_remarks">Remarks</label>
                        <textarea class="form-control @error('remarks') is-invalid @enderror" id="budget_preparation_remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $order->remarks ?: 'N/A') }}</textarea>
                        @error('remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </section>

            <section class="budget-create-pos procura-form-section procura-form-context" aria-labelledby="selected-pos-heading">
                <div class="budget-create-section-heading budget-create-card-heading"><span class="budget-create-heading-icon"><x-ui.icon name="receipt" size="21" /></span><div><div class="section-kicker">Request context</div><h2 id="selected-pos-heading">Purchase Order Details</h2><p>{{ $order->exists ? 'The approved PO for this Budget Request.' : 'The Purchase Order step was skipped. Amounts come from the approved quotation.' }}</p></div><span class="budget-create-count">{{ $order->exists ? '1 PO' : 'No PO' }}</span></div>
                <div class="budget-create-po-list">
                        @php
                            $approvedQuote = $purchaseRequest->quotations->first(fn ($quote) => (int) $quote->supplier_id === (int) $order->supplier_id && $quote->is_approved);
                        @endphp
                        <article class="budget-create-po" aria-labelledby="po-title-{{ $purchaseRequest->id }}">
                            <div class="budget-create-po-top">
                                <div class="budget-create-po-identity"><a class="budget-create-po-number" href="{{ route('purchase-requests.show', $purchaseRequest) }}{{ $order->exists ? '#purchase-order-summary' : '' }}">{{ $orderReference }}</a><h3 id="po-title-{{ $purchaseRequest->id }}">{{ $order->supplier?->name ?? 'Supplier not recorded' }}</h3><p>{{ $purchaseRequest->requestor?->name ?? 'Requestor not recorded' }}</p></div>
                                <div class="budget-create-po-status"><span>PO Status</span>@if($order->exists)<x-status-badge :status="$order->status ?? $purchaseRequest->status" />@else<span class="status-badge status-draft">No PO</span>@endif</div>
                                <div class="budget-create-po-amount"><span>PO Total</span><strong>&#8369; {{ number_format((float) $order->total_amount, 2) }}</strong></div>
                            </div>
                            <details class="budget-create-po-disclosure" data-po-details>
                                <summary aria-expanded="false">View PO Details <span aria-hidden="true">&rarr;</span></summary>
                                <div class="budget-create-po-disclosure-body">
                                    <dl class="budget-create-po-extra"><div><dt>Purchase Request</dt><dd>{{ $purchaseRequest->request_number }} &middot; {{ $purchaseRequest->title }}</dd></div><div><dt>Campaign</dt><dd>{{ $purchaseRequest->department_campaign ?: 'Not recorded' }}</dd></div><div><dt>Expected Delivery</dt><dd>{{ $order->expected_delivery_date?->format('M d, Y') ?? 'Not recorded' }}</dd></div><div><dt>Priority</dt><dd>{{ \App\Models\PurchaseRequest::priorityLabel($purchaseRequest->priority_level) ?? 'Not recorded' }}</dd></div><div><dt>PO Remarks</dt><dd>{{ $order->remarks ?: 'N/A' }}</dd></div><div><dt>Payee Account</dt><dd>{{ $order->account_name ?: $order->supplier?->name ?: 'Not recorded' }} &middot; {{ $order->account_number ?: 'Not recorded' }}</dd></div><div><dt>Payment Terms</dt><dd>{{ $order->payment_terms ?: 'Not recorded' }}</dd></div><div><dt>Payment Details</dt><dd>{{ $order->payment_details ?: 'Not recorded' }}</dd></div><div><dt>Approved Quotation</dt><dd>@if($approvedQuote)&#8369; {{ number_format((float) $approvedQuote->price, 2) }} &middot; {{ $approvedQuote->delivery_lead_time ?: 'Lead time not recorded' }} &middot; {{ $approvedQuote->warranty ?: 'Warranty not recorded' }}@else Not recorded @endif</dd></div></dl>
                                    <div class="budget-create-item-list"><h4>Items</h4>@foreach($order->items as $item)<div class="budget-create-item"><div><strong>{{ $item->description }}</strong>@if($item->specifications)<div>{{ $item->specifications }}</div>@endif</div><span>{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }} {{ $item->unit }} &middot; &#8369; {{ number_format((float) $item->total_price, 2) }}</span></div>@endforeach</div>
                                </div>
                            </details>
                        </article>
                </div>
            </section>


            @if(config('procurement.budget_preparation.signature_required'))
            <section class="budget-create-card procura-form-section" aria-label="Budget Maker signature">
                <x-workflow-signature-pad id="budget-maker-signature" label="Budget Maker signature (required)" instruction="Draw your signature to confirm this PO and create its Budget Request." />
            </section>
            @endif
            <div class="budget-create-actions">
                <a class="btn btn-outline-secondary budget-create-cancel" href="{{ url()->previous() !== url()->current() ? url()->previous() : route('dashboard') }}" data-embedded-dismiss>Cancel</a>
                <div class="budget-create-actions-total"><span>Budget Request total</span><strong>&#8369; {{ number_format((float) $order->total_amount, 2) }}</strong></div>
                <button class="btn btn-primary budget-create-submit" type="submit" data-budget-submit><span data-submit-label>Submit Budget Request</span><span class="visually-hidden" data-submit-working>Submitting</span></button>
            </div>
        </main>
    </form>
</div>
@endsection
