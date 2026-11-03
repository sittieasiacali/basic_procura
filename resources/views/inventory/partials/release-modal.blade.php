{{-- Shared release form; resources/js/inventory.js points it at the chosen item. --}}
<div class="modal fade" id="inventory-release-modal" tabindex="-1" aria-labelledby="inventory-release-heading" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="#" data-inventory-release-form>
            @csrf
            <div class="modal-header">
                <div><h2 class="modal-title fs-5" id="inventory-release-heading">Release item</h2><p class="small text-muted mb-0" data-inventory-release-label></p></div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="inventory-release-quantity">Quantity <span class="text-muted fw-normal" data-inventory-release-available></span></label>
                    <input class="form-control" id="inventory-release-quantity" type="number" step="0.01" min="0.01" name="quantity" required>
                </div>
                <div class="mb-3"><label class="form-label" for="inventory-release-to">Recipient account</label>
                    <select class="form-select" id="inventory-release-to" name="recipient_user_id" required>
                        <option value="">Select recipient</option>
                        @foreach($recipients as $recipient)
                            <option value="{{ $recipient->id }}">{{ $recipient->name }} ({{ $recipient->role?->name ?? 'No role' }})</option>
                        @endforeach
                    </select>
                    <div class="form-text">The recipient will confirm the claim and upload proof photos from their account.</div>
                </div>
                <div class="mb-3"><label class="form-label" for="inventory-release-remarks">Remarks</label><textarea class="form-control" id="inventory-release-remarks" rows="2" name="remarks" maxlength="2000"></textarea></div>
                <x-workflow-signature-pad id="inventory-release-signature" instruction="Sign to release this stock to the selected account." />
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit">Sign &amp; Release</button>
            </div>
        </form>
    </div>
</div>
