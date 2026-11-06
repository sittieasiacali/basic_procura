@props(['purchaseRequest' => null, 'record' => null])

@if(auth()->user()?->isAdministrator())
    @php
        $purchaseRequest = $purchaseRequest ?? ($record ? app(\App\Services\AdminRecordDeletion::class)->relatedRequests($record)->first() : null);
        $currentRoute = request()->route()?->getName();
        $returnTo = in_array($currentRoute, \App\Http\Controllers\Admin\TransactionDeletionController::RETURN_ROUTES, true) ? $currentRoute : 'purchase-requests.index';
    @endphp
    @if($purchaseRequest && ! $purchaseRequest->trashed())
    <a {{ $attributes->class('btn btn-sm btn-outline-danger') }} href="{{ route('admin.transactions.delete', ['purchaseRequest' => $purchaseRequest, 'return_to' => $returnTo], false) }}" target="_top" data-admin-delete-transaction="{{ $purchaseRequest->id }}" aria-label="Delete entire transaction {{ $purchaseRequest->request_number }}" onclick="event.stopPropagation()" onkeydown="event.stopPropagation()">Delete</a>
    @endif
@endif
