@extends('layouts.app')

@section('title', 'Delete Transaction')

@section('content')
    <x-ui.page-header eyebrow="Administration" title="Delete Transaction" description="Review the entire purchase request and its linked workflow records before deleting.">
        <x-slot:actions><a class="btn btn-outline-secondary" href="{{ route($returnTo) }}">Cancel</a></x-slot:actions>
    </x-ui.page-header>
    @if($errors->any())<div class="alert alert-danger" role="alert"><strong>Transaction was not deleted.</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if($blocked)<div class="alert alert-danger" role="alert">{{ $blocked }}</div>@endif
    <div class="card mb-3"><div class="card-body">
        <h2 class="h5">{{ $purchaseRequest->request_number }} — {{ $purchaseRequest->title }}</h2>
        <p>Requestor: <strong>{{ $purchaseRequest->requestor?->name }}</strong></p>
        <p>This deletes the linked quotations, purchase orders, budget, payments, receiving, inventory records, and attachment entries, then archives the purchase request. Audit history, record snapshots, and uploaded evidence are retained.</p>
        <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Linked records</th><th class="text-end">Count</th></tr></thead><tbody>@foreach($counts as $label => $count)<tr><td>{{ $label }}</td><td class="text-end">{{ $count }}</td></tr>@endforeach</tbody></table></div>
        @if($stockRestorations->isNotEmpty())
            <div class="alert alert-warning">Reversing this transaction also returns its recorded releases to surviving stock:
                <ul class="mb-0">@foreach($stockRestorations as $restoration)<li>Inventory #{{ $restoration['inventory_item_id'] }}: {{ $restoration['quantity'] }} units restored.</li>@endforeach</ul>
            </div>
        @endif
        <p class="mb-0">Deletion notifications go to the requestor ({{ $purchaseRequest->requestor?->email ?: 'email missing' }}), assigned OM ({{ $purchaseRequest->requestor?->om_notification_email ?: 'email missing' }}), and assigned SMT ({{ $purchaseRequest->requestor?->smt_notification_email ?: 'email missing' }}).</p>
    </div></div>
    <form method="POST" action="{{ route('admin.transactions.destroy', $purchaseRequest, false) }}" class="card">
        @csrf
        @method('DELETE')
        <input type="hidden" name="return_to" value="{{ $returnTo }}">
        <input type="hidden" name="plan_token" value="{{ $planToken }}">
        <div class="card-body">
            <label class="form-label" for="transaction-delete-reason">Reason for deleting {{ $purchaseRequest->request_number }}</label>
            <textarea class="form-control mb-3" id="transaction-delete-reason" name="reason" rows="3" minlength="5" maxlength="2000" required>{{ old('reason') }}</textarea>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="transaction-delete-confirm" name="confirmation" value="1" required><label class="form-check-label" for="transaction-delete-confirm">Delete this entire purchase request and all linked workflow records shown above.</label></div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2"><a class="btn btn-outline-secondary" href="{{ route($returnTo) }}">Cancel</a><button class="btn btn-danger" type="submit" @disabled($blocked)>Delete entire transaction</button></div>
    </form>
@endsection
