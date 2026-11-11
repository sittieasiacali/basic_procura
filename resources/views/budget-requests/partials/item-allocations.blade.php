@php
    $editable = $editable ?? false;
    $allocationTotal = collect($itemAllocations)->sum(fn ($row) => (float) $row['total']);
    $allocationShipping = collect($itemAllocations)->sum(fn ($row) => (float) $row['shipping_fee']);
@endphp
@if(count($itemAllocations))
<section class="item-budget-allocation my-3 w-100" style="grid-column: 1 / -1" @if($editable) data-item-budget-allocation data-total="{{ $allocationTotal }}" data-shipping="{{ $allocationShipping }}" @endif aria-label="Budget allocation per item">
    <h3 class="h6 fw-semibold">Budget allocation per item</h3>
    <p class="small text-muted">{{ $editable ? 'Allocate the item budget including VAT. Shipping is distributed proportionally and added automatically.' : 'Item budgets include VAT, with shipping distributed proportionally. Allocated by the PO creator.' }}</p>
    @if($editable)
        @error('item_allocations')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror
    @endif
    <div class="table-responsive">
        <table class="table align-middle" data-mobile-cards>
            <thead><tr><th>Item</th><th>Item budget (including VAT)</th><th>Shipping share</th><th>Total allocation</th></tr></thead>
            <tbody>
            @foreach($itemAllocations as $allocation)
                <tr data-item-budget-row>
                    <td>{{ $allocation['description'] }}</td>
                    <td>
                        @if($editable)
                            <label class="visually-hidden" for="item-budget-{{ $allocation['purchase_request_item_id'] }}">Budget for {{ $allocation['description'] }}</label>
                            <input class="form-control" id="item-budget-{{ $allocation['purchase_request_item_id'] }}" name="item_allocations[{{ $allocation['purchase_request_item_id'] }}]" type="number" min="0" max="999999999999.99" step="0.01" value="{{ old('item_allocations.'.$allocation['purchase_request_item_id'], $allocation['amount']) }}" data-item-budget-input required>
                            @error('item_allocations.'.$allocation['purchase_request_item_id'])<div class="text-danger small">{{ $message }}</div>@enderror
                        @else
                            &#8369; {{ number_format((float) $allocation['amount'], 2) }}
                        @endif
                    </td>
                    <td data-item-shipping>&#8369; {{ number_format((float) $allocation['shipping_fee'], 2) }}</td>
                    <td class="fw-semibold" data-item-total>&#8369; {{ number_format((float) $allocation['total'], 2) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr><th>Total budget</th><td>&#8369; {{ number_format($allocationTotal - $allocationShipping, 2) }}</td><td>&#8369; {{ number_format($allocationShipping, 2) }}</td><td>&#8369; {{ number_format($allocationTotal, 2) }}</td></tr></tfoot>
        </table>
    </div>
    @if($editable)<p class="small mb-0" data-item-budget-feedback role="status" aria-live="polite"></p>@endif
</section>
@endif
