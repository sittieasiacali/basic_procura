{{-- Manual stock entry (IT / Premises). Include only when the user can create inventory items. --}}
<div class="modal fade" id="inventory-add-modal" tabindex="-1" aria-labelledby="inventory-add-heading" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('inventory.store') }}">
            @csrf
            <div class="modal-header">
                <div><h2 class="modal-title fs-5" id="inventory-add-heading">Add inventory item</h2><p class="small text-muted mb-0">For stock that arrived outside a purchase request.</p></div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12"><div class="form-text">A unique SKU is generated automatically when this item is added.</div></div>
                    <div class="col-12"><label class="form-label" for="inventory-add-description">Description</label><input class="form-control" id="inventory-add-description" type="text" name="description" value="{{ old('description') }}" required maxlength="2000"></div>
                    <div class="col-md-4"><label class="form-label" for="inventory-add-quantity">Quantity</label><input class="form-control" id="inventory-add-quantity" type="number" step="0.01" min="0.01" name="quantity" value="{{ old('quantity') }}" required></div>
                    <div class="col-md-4"><label class="form-label" for="inventory-add-unit">Unit</label><input class="form-control" id="inventory-add-unit" type="text" name="unit" value="{{ old('unit', 'pc') }}" required maxlength="50"></div>
                    <div class="col-md-4"><label class="form-label" for="inventory-add-serial">Serial number</label><input class="form-control" id="inventory-add-serial" type="text" name="serial_number" value="{{ old('serial_number') }}" maxlength="255"></div>
                    <div class="col-12"><label class="form-label" for="inventory-add-location">Location</label><input class="form-control" id="inventory-add-location" type="text" name="location" value="{{ old('location') }}" maxlength="255"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit">Add to inventory</button>
            </div>
        </form>
    </div>
</div>
