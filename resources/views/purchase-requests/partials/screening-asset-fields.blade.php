<div class="border rounded p-3 mb-3" data-screening-asset>
    <div class="d-flex justify-content-between mb-2"><strong>Asset details</strong><button class="btn btn-sm btn-outline-danger" type="button" data-remove-screening-asset>Remove</button></div>
    <div class="row g-2">
        <div class="col-md-6"><label class="form-label">Inventory item</label><select class="form-select" name="assets[{{ $index }}][inventory_item_id]" required><option value="">Select available inventory</option>@foreach($inventoryItems as $inventoryItem)<option value="{{ $inventoryItem->id }}">{{ $inventoryItem->description }} ({{ $inventoryItem->quantity }} {{ $inventoryItem->unit }})</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label">Quantity provided</label><input class="form-control" name="assets[{{ $index }}][quantity]" type="number" min="0.01" step="0.01" value="1" required></div>
        <div class="col-md-3"><label class="form-label">Asset tag</label><input class="form-control" name="assets[{{ $index }}][asset_tag]" required></div>
        <div class="col-md-3"><label class="form-label">Item code</label><input class="form-control" name="assets[{{ $index }}][item_code]" required></div>
        <div class="col-md-6"><label class="form-label">Description</label><input class="form-control" name="assets[{{ $index }}][description]" required></div>
        <div class="col-md-3"><label class="form-label">Serial number</label><input class="form-control" name="assets[{{ $index }}][serial_number]"></div>
        <div class="col-md-3"><label class="form-label">Location</label><input class="form-control" name="assets[{{ $index }}][location]" required></div>
        <div class="col-md-3"><label class="form-label">Source</label><select class="form-select" name="assets[{{ $index }}][source]" required>
            @if($requestType === 'IT')<option value="IT_SELF_INVENTORY">IT self inventory system</option>@else<option value="PREMISES_EXCEL_IMPORT">Premises Excel import</option>@endif
            <option value="MANUAL">Manual entry</option>
        </select></div>
        <div class="col-md-3"><label class="form-label">Release date</label><input class="form-control" name="assets[{{ $index }}][release_date]" type="date" value="{{ now()->format('Y-m-d') }}" required></div>
        <div class="col-md-6"><label class="form-label">Recipient</label><input class="form-control" name="assets[{{ $index }}][recipient]" value="{{ $purchaseRequest->requestor->name }}" required></div>
    </div>
</div>
