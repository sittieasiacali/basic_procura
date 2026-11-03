@extends('layouts.app')

@section('title', 'Record payment')

@section('content')
@php
    $total = $purchaseRequests->sum(fn ($purchaseRequest) => \App\Http\Controllers\PaymentController::balanceDue($purchaseRequest));
    // Prefill the Budget Information when every selected BR already shares it.
    $shared = fn (string $field) => $purchaseRequests->map(fn ($purchaseRequest) => $purchaseRequest->budgetRequest?->{$field})->filter()->unique()->count() === 1
        ? $purchaseRequests->first()->budgetRequest?->{$field} : null;
    $brCount = $purchaseRequests->pluck('budgetRequest.id')->unique()->count();
    $receivingLineCounts = $purchaseRequests->mapWithKeys(fn ($purchaseRequest) => [$purchaseRequest->id => \App\Services\GoodsReceiving::lines($purchaseRequest)->count()]);
@endphp
<div class="budget-create-page budget-create-page--compact budget-create-page--wide procura-form-page">
    <x-ui.form-page-header class="budget-create-header" eyebrow="Payment" title="Record payment" description="One set of payment details for every selected PO. Each PO is paid its own amount and moves to receiving." icon="payment" />

    <form method="POST" action="{{ route('payments.batch.store') }}" class="budget-create-layout budget-create-layout--wide" enctype="multipart/form-data" data-validation-context="batch-payment" novalidate>
        @csrf
        @foreach($purchaseRequests as $purchaseRequest)
            <input type="hidden" name="ids[]" value="{{ $purchaseRequest->id }}">
        @endforeach

        <main class="budget-create-main">
            @error('ids')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror
            @error('status')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror

            <section class="budget-create-pos procura-form-section procura-form-context" aria-labelledby="batch-payment-selected-heading">
                <div class="budget-create-section-heading budget-create-card-heading"><span class="budget-create-heading-icon"><x-ui.icon name="receipt" size="21" /></span><div><div class="section-kicker">Awaiting payment</div><h2 id="batch-payment-selected-heading">Selected for payment</h2><p>{{ $purchaseRequests->count() }} {{ $purchaseRequests->count() === 1 ? 'PO' : 'POs' }} from {{ $brCount }} {{ $brCount === 1 ? 'Budget Request' : 'Budget Requests' }}.</p></div><span class="budget-create-count">{{ $purchaseRequests->count() }} selected</span></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 dashboard-table">
                        <thead><tr><th>BR Number</th><th>Purchase Order</th><th>Supplier</th><th class="text-end">Amount</th><th class="batch-received-cell"><label class="batch-received-toggle"><input class="form-check-input" type="checkbox" data-batch-received-all aria-label="Mark every selected PO as already received"> Already received</label></th></tr></thead>
                        <tbody>
                        @foreach($purchaseRequests as $purchaseRequest)
                            <tr>
                                <td class="fw-semibold">{{ $purchaseRequest->budgetRequest->displayNumber() }}</td>
                                <td>{{ $purchaseRequest->purchaseOrder?->po_number ?? 'No PO' }}</td>
                                <td>{{ $purchaseRequest->purchaseOrder?->supplier?->name ?? 'Not recorded' }}</td>
                                <td class="text-end fw-semibold text-nowrap">&#8369; {{ number_format(\App\Http\Controllers\PaymentController::balanceDue($purchaseRequest), 2) }}</td>
                                <td class="batch-received-cell">
                                    <label class="batch-received-toggle">
                                        <input class="form-check-input" type="checkbox" name="received_ids[]" value="{{ $purchaseRequest->id }}" data-batch-received @checked(in_array((string) $purchaseRequest->id, old('received_ids', []), true))>
                                        <span>Received <small class="text-muted">· {{ $receivingLineCounts[$purchaseRequest->id] }} {{ $receivingLineCounts[$purchaseRequest->id] === 1 ? 'item' : 'items' }} to inventory</small></span>
                                    </label>
                                </td>
                            </tr>
                            {{-- Inventory details for this PO, shown (and submitted) only while it is ticked Received. --}}
                            <tr class="batch-received-details" data-batch-received-details="{{ $purchaseRequest->id }}" hidden>
                                <td colspan="5">
                                    <fieldset disabled>
                                        <legend class="batch-received-details-title">Inventory details · {{ $purchaseRequest->purchaseOrder?->po_number ?? $purchaseRequest->request_number }}</legend>
                                        @include('purchase-requests.partials.goods-received-fields', [
                                            'idPrefix' => 'batch-received-'.$purchaseRequest->id,
                                            'namePrefix' => 'receiving['.$purchaseRequest->id.']',
                                            'itemsOnly' => true,
                                        ])
                                    </fieldset>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="budget-create-card procura-form-section" aria-labelledby="batch-payment-budget-heading">
                <div class="section-kicker" id="batch-payment-budget-heading">Budget Information</div>
                <div class="row g-3 mt-0">
                    <div class="col-md-3"><label class="form-label" for="batch-transaction-type">Type of transaction</label><select class="form-select @error('payment_method') is-invalid @enderror" id="batch-transaction-type" name="payment_method" required><option value="">Select type</option>@foreach(['ONLINE' => 'Online Transaction', 'CASH' => 'Cash Transaction', 'CHECK' => 'Check Transaction', 'COD' => 'Cash on Delivery (COD)'] as $value => $label)<option value="{{ $value }}" @selected(old('payment_method', $shared('payment_method')) === $value)>{{ $label }}</option>@endforeach</select>@error('payment_method')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-md-3"><label class="form-label" for="batch-account-type">Account type</label><select class="form-select @error('account_type') is-invalid @enderror" id="batch-account-type" name="account_type" required><option value="">Select account type</option>@foreach(['ACA' => 'ACA', 'OTHER_PARTY' => 'Other Party', 'OWN_ACCOUNT' => 'Own Account'] as $value => $label)<option value="{{ $value }}" @selected(old('account_type', $shared('account_type')) === $value)>{{ $label }}</option>@endforeach</select>@error('account_type')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-md-3"><label class="form-label" for="batch-fund-source">Fund source</label><select class="form-select @error('fund_source') is-invalid @enderror" id="batch-fund-source" name="fund_source" required><option value="">Select fund source</option>@foreach(['MAIN_FUND' => 'Main Fund', 'CAMPAIGN_FUND' => 'Campaign Fund', 'OTHER' => 'Other'] as $value => $label)<option value="{{ $value }}" @selected(old('fund_source', $shared('fund_source')) === $value)>{{ $label }}</option>@endforeach</select>@error('fund_source')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-md-3"><label class="form-label" for="batch-main-fund">Main fund</label><input class="form-control @error('main_fund') is-invalid @enderror" id="batch-main-fund" name="main_fund" maxlength="255" value="{{ old('main_fund', $shared('main_fund')) }}" required>@error('main_fund')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                </div>
            </section>

            <section class="budget-create-card procura-form-section" aria-labelledby="batch-payment-details-heading">
                <div class="section-kicker" id="batch-payment-details-heading">Payment</div>
                <div class="row g-3 mt-0">
                    <div class="col-md-6">
                        <label class="form-label" for="batch-budget-custodian">Budget Custodian <span class="text-muted">(optional)</span></label>
                        <select class="form-select @error('budget_custodian_id') is-invalid @enderror" id="batch-budget-custodian" name="budget_custodian_id">
                            <option value="">Not assigned</option>
                            @foreach($paymentCustodians as $custodian)
                                <option value="{{ $custodian->id }}" @selected((string) old('budget_custodian_id', $shared('budget_custodian_id')) === (string) $custodian->id)>{{ $custodian->name }}</option>
                            @endforeach
                        </select>
                        @error('budget_custodian_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3"><label class="form-label" for="batch-reference">Reference Number</label><input class="form-control @error('reference_number') is-invalid @enderror" id="batch-reference" type="text" name="reference_number" maxlength="255" value="{{ old('reference_number') }}">@error('reference_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-md-3"><label class="form-label" for="batch-paid-at">Payment Date</label><input class="form-control @error('paid_at') is-invalid @enderror" id="batch-paid-at" type="date" name="paid_at" value="{{ old('paid_at', now()->toDateString()) }}">@error('paid_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-12"><label class="form-label" for="batch-details">Details <span class="text-muted">(optional)</span></label><textarea class="form-control" id="batch-details" rows="2" name="details" maxlength="2000">{{ old('details') }}</textarea></div>
                    <div class="col-12">
                        <label class="form-label" for="batch-receipts">Receipt evidence</label>
                        <input class="form-control @error('receipts') is-invalid @enderror @error('receipts.*') is-invalid @enderror" id="batch-receipts" type="file" name="receipts[]" accept=".jpg,.jpeg,.png,.webp,.pdf" multiple required aria-describedby="batch-receipts-help">
                        <div class="form-text" id="batch-receipts-help">Attached to every selected PO's payment. JPG, PNG, WEBP or PDF, up to 5 files of 10 MB each. Each PO is paid its remaining balance.</div>
                        @error('receipts')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @error('receipts.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12"><div class="form-text">POs not ticked <strong>Already received</strong> move to <strong>For receiving</strong> until their goods arrive.</div></div>
                </div>
            </section>

            <fieldset class="budget-create-card procura-form-section" data-receiving-fields @if(old('received_ids') === null) hidden disabled @endif>
                <legend class="section-kicker">Goods received: add to inventory</legend>
                <p class="small text-muted">Every PO ticked <strong>Already received</strong> is logged to inventory with its PO items now. Serial numbers can be added later from Inventory.</p>
                <label class="form-label" for="batch-receiving-remarks">Receiving remarks <span class="text-muted">(optional)</span></label>
                <textarea class="form-control mb-3" id="batch-receiving-remarks" rows="2" name="receiving_remarks" maxlength="2000">{{ old('receiving_remarks') }}</textarea>
                <x-workflow-signature-pad id="batch-received-signature" label="Received by" instruction="Sign to confirm the ticked goods were received in full and added to inventory." />
            </fieldset>

            <div class="budget-create-actions">
                <a class="btn btn-outline-secondary budget-create-cancel" href="{{ route('dashboard') }}" data-embedded-dismiss>Cancel</a>
                <div class="budget-create-actions-total"><span>Total for {{ $purchaseRequests->count() }} {{ $purchaseRequests->count() === 1 ? 'PO' : 'POs' }}</span><strong>&#8369; {{ number_format($total, 2) }}</strong></div>
                <button class="btn btn-primary budget-create-submit" type="submit">Record {{ $purchaseRequests->count() === 1 ? 'payment' : $purchaseRequests->count().' payments' }}</button>
            </div>
        </main>
    </form>
</div>
@endsection
