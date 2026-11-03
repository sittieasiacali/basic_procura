@props(['id', 'inventoryItems', 'name', 'selectedId' => null, 'assetIndex' => null, 'quantity' => 1, 'recipient' => '', 'fixedQuantity' => false])
@php($stock = $inventoryItems->firstWhere('id', $selectedId))
<div class="border rounded p-3 mb-3" data-sku-picker>
    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
        <label class="form-label mb-0" for="{{ $id }}">SKU</label>
        @if($assetIndex !== null)<button class="btn btn-sm btn-outline-danger" type="button" data-remove-sku-row>Remove item</button>@endif
    </div>
    <input class="form-control" id="{{ $id }}" data-sku-input list="{{ $id }}-options" value="{{ $stock?->sku }}" placeholder="Type or scan SKU" maxlength="100" autocomplete="off" required aria-describedby="{{ $id }}-feedback">
    <datalist id="{{ $id }}-options">
        @foreach($inventoryItems->filter(fn ($item) => filled($item->sku)) as $item)
            <option value="{{ $item->sku }}" data-stock="{{ json_encode($item->only(['id', 'sku', 'inventory_code', 'asset_tag', 'description', 'quantity', 'unit', 'serial_number', 'location'])) }}">{{ $item->description }}</option>
        @endforeach
    </datalist>
    <input type="hidden" data-sku-id name="{{ $name }}" value="{{ $stock?->id }}">
    <div id="{{ $id }}-feedback" class="small text-muted mt-2" data-sku-feedback aria-live="polite">{{ $stock && !$stock->sku ? 'This item needs a SKU. Assign one in the Inventory tab first.' : 'Enter a SKU to see the item and available quantity.' }}</div>
    <div class="mt-3" data-sku-details @if(!$stock) hidden @endif>
        <div class="fw-semibold" data-sku-description>{{ $stock?->description }}</div>
        <div class="small text-muted"><span data-sku-available>{{ $stock?->quantity }} {{ $stock?->unit }} available</span> &middot; <span data-sku-location>{{ $stock?->location ?: 'Location not recorded' }}</span></div>
    </div>
    <div class="mt-3">
        <label class="form-label small" for="{{ $id }}-quantity">Quantity to release</label>
        <input class="form-control" style="max-width: 160px;" id="{{ $id }}-quantity" data-sku-quantity type="number" min="0.01" step="0.01" @if($stock) max="{{ $stock->quantity }}" @endif value="{{ $quantity }}" @if($assetIndex !== null) name="assets[{{ $assetIndex }}][quantity]" @endif @readonly($fixedQuantity) required>
    </div>
    @if($assetIndex !== null)
        @foreach([
            'asset_tag' => $stock?->asset_tag ?: $stock?->inventory_code,
            'item_code' => $stock?->sku ?: $stock?->inventory_code,
            'description' => $stock?->description,
            'serial_number' => $stock?->serial_number,
            'location' => $stock?->location ?: 'Not recorded',
            'source' => 'MANUAL',
            'release_date' => now()->toDateString(),
            'recipient' => $recipient,
        ] as $field => $value)
            <input type="hidden" data-sku-meta="{{ $field }}" name="assets[{{ $assetIndex }}][{{ $field }}]" value="{{ $value }}">
        @endforeach
    @endif
</div>
