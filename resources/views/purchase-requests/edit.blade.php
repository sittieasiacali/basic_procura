@extends('layouts.home')

@section('title', 'Edit Purchase Request')

@section('content')
    <x-ui.form-page-header eyebrow="Request management" title="Edit {{ $purchaseRequest->request_number }}" :description="'Update the request and submit it directly to IT / Premises screening.'" icon="request" />

    @if($purchaseRequest->status === \App\Enums\PurchaseRequestStatus::Returned)
        <div class="alert alert-warning" role="status"><strong>Returned for correction:</strong> {{ $purchaseRequest->latestReturnApproval?->remarks }}<div class="small mt-1">Update the request, then select Submit to send it back for screening.</div></div>
    @endif

    <form id="purchase-request-form" class="purchase-request-builder procura-form-page" method="POST" action="{{ route('purchase-requests.update', $purchaseRequest) }}" enctype="multipart/form-data" novalidate>
        @csrf
        @method('PUT')
        @include('purchase-requests.partials.form')
    </form>
@endsection
