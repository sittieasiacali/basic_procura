<details class="border rounded p-3 mb-3" @if(old('_validation_context') === 'screening-available') open @endif>
    <summary class="fw-semibold text-success">Inventory Available &mdash; find stock by SKU</summary>
    <p class="small text-muted mt-3">Type or scan the SKU, check the item and available stock, then enter the quantity to release. Signing will release the stock and complete the request automatically.</p>
    @if($inventoryItems->whereNotNull('sku')->isEmpty())
        <p class="small mb-0">No stock is currently available. Imported and newly added inventory receives a SKU automatically.</p>
    @else
        <form method="POST" action="{{ route('request-flow.screening.store', $purchaseRequest) }}" data-validation-context="screening-available" data-stock-selection>
            @csrf
            <input type="hidden" name="_validation_context" value="screening-available">
            <input type="hidden" name="decision" value="AVAILABLE">
            <div data-sku-rows>
                @foreach(old('_validation_context') === 'screening-available' ? old('assets', [[]]) : [[]] as $index => $asset)
                    <x-inventory-sku-picker :id="'screening-sku-'.$index" :inventory-items="$inventoryItems" :name="'assets['.$index.'][inventory_item_id]'" :asset-index="$index" :selected-id="$asset['inventory_item_id'] ?? null" :quantity="$asset['quantity'] ?? 1" :recipient="$purchaseRequest->requestor->name" />
                @endforeach
            </div>
            <template data-sku-template>
                <x-inventory-sku-picker id="screening-sku-__ROW__" :inventory-items="$inventoryItems" name="assets[__ROW__][inventory_item_id]" asset-index="__ROW__" :recipient="$purchaseRequest->requestor->name" />
            </template>
            <button class="btn btn-sm btn-outline-primary mb-3" type="button" data-add-sku-row>Add another SKU</button>
            <div><label class="form-label" for="available-screening-evidence">Screening notes (optional)</label><textarea class="form-control mb-3" id="available-screening-evidence" name="evidence" maxlength="5000" rows="2">{{ old('_validation_context') === 'screening-available' ? old('evidence') : '' }}</textarea></div>
            <x-workflow-signature-pad id="available-screening-signature" instruction="Sign to release these items and complete the request." />
            <button class="btn btn-success mt-3" type="submit">Sign &amp; Release items</button>
        </form>
    @endif
</details>
