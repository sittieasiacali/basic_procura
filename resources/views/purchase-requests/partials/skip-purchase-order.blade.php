{{-- The PO step is optional. Expects $purchaseRequest; shown to the PO preparer while the request waits for its PO. --}}
<form class="border-top pt-3 mt-3 d-flex flex-wrap align-items-end gap-2" method="POST" action="{{ route('purchase-orders.skip', $purchaseRequest) }}" onsubmit="return confirm('Send this request to Budget without a Purchase Order? This cannot be undone, and no PO will be created for it.');">
    @csrf
    <div class="flex-grow-1">
        <label class="form-label small mb-1" for="skip-po-remarks">No PO needed? <span class="text-muted fw-normal">Remarks (optional)</span></label>
        <input class="form-control form-control-sm" id="skip-po-remarks" name="remarks" maxlength="2000" placeholder="Why this request does not need a Purchase Order">
    </div>
    <button class="btn btn-sm btn-outline-secondary" type="submit">Proceed without PO</button>
</form>
