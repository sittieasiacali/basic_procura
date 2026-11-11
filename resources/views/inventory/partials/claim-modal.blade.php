<div class="modal fade" id="inventory-claim-modal" tabindex="-1" aria-labelledby="inventory-claim-heading" aria-hidden="true" @if($errors->any() && old('_claim_release_id')) data-reopen-claim-id="{{ old('_claim_release_id') }}" @endif>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" method="POST" action="#" enctype="multipart/form-data" data-inventory-claim-form>
            @csrf
            <input type="hidden" name="_claim_release_id" value="{{ old('_claim_release_id') }}" data-claim-release-id>
            <div class="modal-header">
                <div><h2 class="modal-title fs-5" id="inventory-claim-heading">Claim item</h2><p class="small text-muted mb-0" data-inventory-claim-label></p></div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="inventory-claim-remarks">Remarks <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea class="form-control" id="inventory-claim-remarks" name="remarks" rows="3" maxlength="2000" placeholder="Describe the item and its condition when received.">{{ old('remarks') }}</textarea>
                    @error('remarks')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label" for="inventory-claim-photos">Evidence photos (1 to 5)</label>
                    <input class="form-control" id="inventory-claim-photos" type="file" data-max-files="5" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required>
                    <div class="form-text">Each photo can be up to 5 MB.</div>
                    @error('photos')<div class="text-danger small">{{ $message }}</div>@enderror
                    @error('photos.*')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
                <x-workflow-signature-pad id="inventory-claim-signature" instruction="Ask the recipient to sign here to confirm receipt. The signed-in account records the submission." />
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit">Sign &amp; Confirm claim</button>
            </div>
        </form>
    </div>
</div>
