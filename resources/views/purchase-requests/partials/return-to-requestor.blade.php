@can('returnToRequestor', $purchaseRequest)
<div class="modal fade" id="po-return-requestor-modal" tabindex="-1" aria-labelledby="po-return-requestor-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ \App\Support\WorkflowUrl::route('purchase-orders.return-to-requestor', $purchaseRequest) }}" data-validation-context="po-return-requestor">
            @csrf
            @if(request()->boolean('_embedded'))<input type="hidden" name="_embedded" value="1">@endif
            <div class="modal-header"><h2 class="modal-title fs-5" id="po-return-requestor-title">Return to Requestor</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <p class="small text-muted">The requestor can correct and resubmit the request. Screening and supplier approval will be required again.</p>
                <label class="form-label" for="po-correction-reason">Reason for correction <span class="text-danger">*</span></label>
                <textarea class="form-control @error('correction_reason') is-invalid @enderror" id="po-correction-reason" name="correction_reason" rows="4" maxlength="5000" required>{{ old('correction_reason') }}</textarea>
                @error('correction_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-warning" type="submit">Return to Requestor</button></div>
        </form>
    </div>
</div>
@endcan

@if($errors->has('correction_reason'))
    @push('scripts')
        <script>bootstrap.Modal.getOrCreateInstance(document.getElementById('po-return-requestor-modal')).show();</script>
    @endpush
@endif
