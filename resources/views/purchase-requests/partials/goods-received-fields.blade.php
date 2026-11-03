{{--
    Goods received: lines come from the Purchase Order or legacy request items and go straight to inventory.
    Per line the receiver can adjust the quantity and unit received, and add a serial number and storage location.
    Expects $purchaseRequest (with purchaseOrder.items and items) and $idPrefix.
    Optional $namePrefix nests the inputs (e.g. "receiving[12]" for several POs in one form);
    $itemsOnly leaves out the summary, remarks and signature (the batch form has one shared set).
--}}
@php
    $receivingLines = \App\Services\GoodsReceiving::lines($purchaseRequest);
    $namePrefix = $namePrefix ?? '';
    $itemsOnly = $itemsOnly ?? false;
    $fieldName = fn (string $field, string $key) => $namePrefix !== '' ? "{$namePrefix}[{$field}][{$key}]" : "{$field}[{$key}]";
    $oldKey = fn (string $field, string $key) => ($namePrefix !== '' ? str_replace(['][', '[', ']'], ['.', '.', ''], $namePrefix).'.' : '').$field.'.'.$key;
    $quantityText = fn (float $quantity) => rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
@endphp
@unless($itemsOnly)
    <div class="goods-received-summary">
        <div><span>Received date</span><strong>{{ now()->format('M j, Y') }}</strong></div>
        <div><span>Purchase Order</span><strong>{{ $purchaseRequest->purchaseOrder?->po_number ?? 'Not available' }}</strong></div>
        <div><span>Location</span><strong>{{ $purchaseRequest->delivery_location ?: 'Not recorded' }}</strong></div>
    </div>
    @unless($purchaseRequest->purchaseOrder)
        <p class="small text-muted mt-2 mb-0">No Purchase Order is linked. The requested items shown below will be used for this receipt.</p>
    @endunless
@endunless
<div class="table-responsive">
    <table class="table table-sm align-middle goods-received-table mb-0">
        <thead><tr><th class="table-row-number">#</th><th>Item</th><th class="text-end">Ordered</th><th>Qty received</th><th>Unit</th><th>Serial number <span class="text-muted fw-normal">(optional)</span></th><th>Location</th></tr></thead>
        <tbody>
        @foreach($receivingLines as $line)
            @php
                $lineId = $idPrefix.'-'.$line['key'];
            @endphp
            <tr>
                <td class="table-row-number">{{ $loop->iteration }}</td>
                <td>{{ $line['description'] }}</td>
                <td class="text-end text-nowrap">{{ $quantityText($line['quantity']) }} {{ $line['unit'] }}</td>
                <td class="goods-received-qty">
                    <label class="visually-hidden" for="{{ $lineId }}-quantity">Quantity received for {{ $line['description'] }}</label>
                    <input class="form-control form-control-sm @error($oldKey('quantities', $line['key'])) is-invalid @enderror" id="{{ $lineId }}-quantity" type="number" step="0.01" min="0.01" name="{{ $fieldName('quantities', $line['key']) }}" value="{{ old($oldKey('quantities', $line['key']), $quantityText($line['quantity'])) }}" required>
                </td>
                <td class="goods-received-unit">
                    <label class="visually-hidden" for="{{ $lineId }}-unit">Unit for {{ $line['description'] }}</label>
                    <input class="form-control form-control-sm" id="{{ $lineId }}-unit" type="text" name="{{ $fieldName('units', $line['key']) }}" value="{{ old($oldKey('units', $line['key']), $line['unit']) }}" maxlength="50">
                </td>
                <td>
                    <label class="visually-hidden" for="{{ $lineId }}-serial">Serial number for {{ $line['description'] }}</label>
                    <input class="form-control form-control-sm" id="{{ $lineId }}-serial" type="text" name="{{ $fieldName('serial_numbers', $line['key']) }}" value="{{ old($oldKey('serial_numbers', $line['key'])) }}" maxlength="255">
                </td>
                <td>
                    <label class="visually-hidden" for="{{ $lineId }}-location">Storage location for {{ $line['description'] }}</label>
                    <input class="form-control form-control-sm" id="{{ $lineId }}-location" type="text" name="{{ $fieldName('locations', $line['key']) }}" value="{{ old($oldKey('locations', $line['key']), $purchaseRequest->delivery_location) }}" maxlength="255" placeholder="Storage location">
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@unless($itemsOnly)
    <div class="mt-3">
        <label class="form-label" for="{{ $idPrefix }}-remarks">Remarks <span class="text-muted fw-normal">(optional)</span></label>
        <textarea class="form-control" id="{{ $idPrefix }}-remarks" rows="2" name="remarks" maxlength="2000">{{ old('remarks') }}</textarea>
    </div>
    <x-workflow-signature-pad class="mt-3" :id="$idPrefix.'-signature'" label="Received by" instruction="Sign to confirm the goods were received in full and added to inventory." />
@endunless
