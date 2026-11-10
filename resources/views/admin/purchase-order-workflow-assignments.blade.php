@extends('layouts.app')

@section('title', 'PO Workflow Assignments')

@section('content')
    <x-ui.page-header eyebrow="Administration" title="PO Workflow Assignments" description="Assign existing active accounts to the separate Purchase Order workflow. These assignments do not alter Purchase Request roles or permissions." />

    <form method="POST" action="{{ route('admin.po-workflow-assignments.update') }}" class="card">
        @csrf @method('PUT')
        <div class="card-header"><div class="section-kicker">Separate PO process</div><h2 class="section-title">Required stage assignees</h2></div>
        <div class="card-body">
            <p class="text-muted small">These are default stage assignments. Users with the corresponding active workspace role may check, note, or approve a pending PO. The account that signs is recorded on the PO. No Request Process role is changed here.</p>
            @foreach(\App\Enums\PurchaseOrderApprovalStage::cases() as $stage)
                @php($assignment = $assignments->get($stage->value))
                <div class="row align-items-center g-2 mb-3">
                    <div class="col-md-4"><label class="form-label mb-0" for="{{ $stage->value }}">{{ match($stage) { \App\Enums\PurchaseOrderApprovalStage::PreparedBy => 'Prepared by', \App\Enums\PurchaseOrderApprovalStage::CheckedBy => 'Checked by', \App\Enums\PurchaseOrderApprovalStage::NotedBy => 'Noted by', \App\Enums\PurchaseOrderApprovalStage::ApprovedBy => 'Approved by' } }}</label></div>
                    <div class="col-md-8"><select class="form-select @error('assignments.'.$stage->value) is-invalid @enderror" id="{{ $stage->value }}" name="assignments[{{ $stage->value }}]" required><option value="">Select an existing active account</option>@foreach($users as $account)<option value="{{ $account->id }}" @selected((string) old('assignments.'.$stage->value, $assignment?->user_id) === (string) $account->id)>{{ $account->name }} ({{ $account->email }})</option>@endforeach</select>@error('assignments.'.$stage->value)<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                </div>
            @endforeach
        </div>
        <div class="card-footer"><button class="btn btn-primary" type="submit">Save PO assignments</button></div>
    </form>
@endsection
