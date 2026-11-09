@extends('layouts.home')

@section('title', 'Create Purchase Request')
@section('body-class', 'purchase-request-create-page')

@section('content')
    <div class="request-create-layout">
        <div class="request-create-main">
            <x-ui.form-page-header eyebrow="Request management" title="Create Purchase Request" description="Create and submit a procurement request for approval." icon="request" class="purchase-request-builder-header">
                <x-slot:actions>
                    <div class="d-flex align-items-center gap-2">
                        <span class="status-badge status-draft d-inline-flex align-items-center gap-1" aria-label="Request status: Draft"><span class="builder-status-dot" aria-hidden="true"></span>Draft</span>
                        <button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1" type="button" data-close-purchase-request data-close-destination="{{ route('purchase-requests.index') }}" title="Close and return to Purchase Requests">
                            <x-ui.icon name="x-circle" size="16" />
                            <span>Close</span>
                        </button>
                    </div>
                </x-slot:actions>
            </x-ui.form-page-header>

            <form id="purchase-request-form" class="purchase-request-builder procura-form-page" method="POST" action="{{ route('purchase-requests.store') }}" enctype="multipart/form-data" novalidate>
                @csrf
                @include('purchase-requests.partials.form')
            </form>
        </div>
        @include('purchase-requests.partials.request-compass')
    </div>
@endsection
