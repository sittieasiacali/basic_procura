@extends('layouts.app')

@section('title', 'Edit Purchase Order Budget Details')

@section('content')
    <x-ui.form-page-header eyebrow="Budget preparation" title="Edit {{ $purchaseRequest->purchaseOrder->po_number }}" description="Update this Purchase Order's budget details before final approval." icon="budget" />

    @php($order = $purchaseRequest->purchaseOrder)
    <section class="procura-form-context card mb-3" aria-labelledby="po-context-heading">
        <div class="card-header"><div class="procura-section-heading"><span class="procura-section-icon"><x-ui.icon name="receipt" size="20" /></span><div><div class="section-kicker">Request context</div><h2 id="po-context-heading">Purchase Order Details</h2></div></div></div>
        <div class="card-body procura-context-grid">
            <div><span>PO Number</span><strong>{{ $order->po_number }}</strong></div>
            <div><span>Supplier</span><strong>{{ $order->supplier?->name ?? 'Not recorded' }}</strong></div>
            <div><span>Requestor</span><strong>{{ $purchaseRequest->teamLeader?->name ?? $purchaseRequest->requestor?->name ?? 'Not recorded' }}</strong></div>
            <div><span>PO Total</span><strong>&#8369; {{ number_format((float) $order->total_amount, 2) }}</strong></div>
            <div><span>Status</span><x-status-badge :status="$order->status ?? $purchaseRequest->status" /></div>
            <div><a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-requests.show', $purchaseRequest) }}#purchase-order-summary">View PO Details</a></div>
        </div>
    </section>
    <form method="POST" action="{{ route('budget.po-details.update', $purchaseRequest) }}" class="card procura-form-page procura-simple-form">
        @csrf
        @method('PATCH')
        <div class="card-header"><div class="procura-section-heading"><span class="procura-section-icon"><x-ui.icon name="wallet" size="20" /></span><div><div class="section-kicker">Form information</div><h2>Budget Details</h2></div></div></div>
        <div class="card-body row g-3">
            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="campaign_id">Campaign</label>
                <select class="form-select @error('campaign_id') is-invalid @enderror" id="campaign_id" name="campaign_id" data-budget-campaign required>
                    <option value="">Select campaign</option>
                    @foreach($campaigns as $campaign)
                        <option value="{{ $campaign->id }}" data-account-number="{{ $campaign->account_number }}" @selected((string) old('campaign_id', $order->budget_campaign_id) === (string) $campaign->id)>{{ $campaign->name }}</option>
                    @endforeach
                </select>
                @error('campaign_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="campaign_account_number">Campaign Account Number</label>
                <input class="form-control" id="campaign_account_number" data-campaign-account-output value="{{ $order->budget_campaign_account_number }}" readonly>
            </div>
            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="account_type">Account Type</label>
                <select class="form-select @error('account_type') is-invalid @enderror" id="account_type" name="account_type" required>
                    @foreach(['ACA' => 'ACA', 'OTHER_PARTY' => 'Other Party', 'OWN_ACCOUNT' => 'Own Account'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('account_type', $order->budget_account_type) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('account_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="transaction_type">Type of Transaction</label>
                <select class="form-select @error('transaction_type') is-invalid @enderror" id="transaction_type" name="transaction_type" required>
                    @foreach(['ONLINE' => 'Online Transaction', 'CASH' => 'Cash Transaction', 'CHECK' => 'Check Transaction'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('transaction_type', $order->budget_transaction_type) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('transaction_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="payable_on">When Can Pay</label>
                <input class="form-control @error('payable_on') is-invalid @enderror" id="payable_on" name="payable_on" type="date" value="{{ old('payable_on', $order->budget_payable_on?->format('Y-m-d')) }}">
                @error('payable_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="fund_source">Fund Source</label>
                <select class="form-select @error('fund_source') is-invalid @enderror" id="fund_source" name="fund_source" required>
                    @foreach(['MAIN_FUND' => 'Main Fund', 'CAMPAIGN_FUND' => 'Campaign Fund', 'OTHER' => 'Other'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('fund_source', $order->budget_fund_source) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('fund_source')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="main_fund">Main Fund</label>
                <input class="form-control @error('main_fund') is-invalid @enderror" id="main_fund" name="main_fund" maxlength="255" value="{{ old('main_fund', $order->main_fund) }}" required>
                @error('main_fund')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="requested_by">Requested By</label>
                <input class="form-control" id="requested_by" value="{{ $purchaseRequest->teamLeader?->name ?? $purchaseRequest->requestor?->name ?? 'Not recorded' }}" readonly>
                @error('requested_by')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="account_number">Account Number</label>
                <input class="form-control @error('account_number') is-invalid @enderror" id="account_number" name="account_number" maxlength="255" value="{{ old('account_number', $order->account_number) }}" required>
                @error('account_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="source_bank_account">Source Bank Account</label>
                <input class="form-control @error('source_bank_account') is-invalid @enderror" id="source_bank_account" name="source_bank_account" data-source-bank-account maxlength="2000" value="{{ old('source_bank_account', $order->budget_source_bank_account) }}" required>
                @error('source_bank_account')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="payment_details">Payment Details</label>
                <textarea class="form-control @error('payment_details') is-invalid @enderror" id="payment_details" name="payment_details" rows="2" maxlength="2000">{{ old('payment_details', $order->payment_details) }}</textarea>
                @error('payment_details')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="card-footer d-flex flex-wrap justify-content-end gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('purchase-requests.show', $purchaseRequest) }}">Cancel</a>
            <button class="btn btn-primary" type="submit">Save Budget Details</button>
        </div>
    </form>
@endsection
