@extends('layouts.home')

@section('title', 'Edit Purchase Request')

@section('content')
    <x-ui.form-page-header eyebrow="Request management" title="Edit {{ $purchaseRequest->request_number }}" :description="'Update the request and submit it directly to IT / Premises screening.'" icon="request" />

    <form id="purchase-request-form" class="purchase-request-builder procura-form-page" method="POST" action="{{ route('purchase-requests.update', $purchaseRequest) }}" enctype="multipart/form-data" novalidate>
        @csrf
        @method('PUT')
        @include('purchase-requests.partials.form')
    </form>
@endsection
