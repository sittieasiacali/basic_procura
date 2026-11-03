<div class="card action-panel mb-3 no-print" id="inventory-release-actions">
    <div class="card-header fw-semibold">Inventory release</div>
    <div class="card-body">
        <p class="text-muted">Confirm each SKU and the quantity to release, then sign to release it to the recipient. This deducts stock and fulfills the request.</p>
        <form method="POST" action="{{ route('purchase-requests.inventory-release', $purchaseRequest) }}" data-validation-context="inventory-release">
            @csrf
            <input type="hidden" name="_validation_context" value="inventory-release">
            @foreach($purchaseRequest->screening?->assets ?? [] as $asset)
                <p class="small mb-2"><strong>Screened item:</strong> {{ $asset->description }} &middot; {{ $asset->quantity }} required</p>
                <x-inventory-sku-picker :id="'release-sku-'.$asset->id" :inventory-items="$inventoryItems" :name="'inventory['.$asset->id.']'" :selected-id="old('inventory.'.$asset->id, $asset->inventory_item_id)" :quantity="$asset->quantity" :fixed-quantity="true" />
            @endforeach
            <div class="mb-3"><label class="form-label" for="inventory-release-recipient">Released to</label><input class="form-control" id="inventory-release-recipient" name="released_to" value="{{ old('released_to', $purchaseRequest->requestor->name) }}" maxlength="255" required></div>
            <div class="mb-3"><label class="form-label" for="inventory-release-remarks">Release remarks (optional)</label><textarea class="form-control" id="inventory-release-remarks" name="remarks" maxlength="2000" rows="2">{{ old('_validation_context') === 'inventory-release' ? old('remarks') : '' }}</textarea></div>
            <x-workflow-signature-pad id="inventory-release-signature" instruction="Sign to confirm the stock has been released to the recipient." />
            <button class="btn btn-success mt-3" type="submit">Sign &amp; Release items</button>
        </form>
    </div>
</div>