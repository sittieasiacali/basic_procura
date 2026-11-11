{{-- Inventory items table. Expects $items (paginated, from InventoryItem::registerFilters()). --}}
<div class="table-responsive">
    <table data-mobile-cards class="table table-hover align-middle mb-0">
        <thead><tr><th class="table-row-number">#</th><th>Item</th><th class="text-end">Available</th><th>Location</th><th>Source</th><th>Logged</th><th>Status</th><th class="text-end">Action</th></tr></thead>
        <tbody>
        @forelse($items as $item)
            @php($available = (float) $item->quantity)
            <tr>
                <td class="table-row-number">{{ $items->firstItem() + $loop->index }}</td>
                <td>
                    <div class="fw-semibold inventory-item-name">{{ $item->description }}</div>
                    <div class="small inventory-item-sku"><span class="text-muted">SKU:</span> <span class="fw-semibold">{{ $item->sku }}</span></div>
                    <div class="small text-muted">{{ $item->inventory_code }}@if($item->serial_number) · S/N {{ $item->serial_number }}@endif</div>
                </td>
                <td class="text-end text-nowrap">
                    <span class="fw-semibold">{{ rtrim(rtrim(number_format($available, 2), '0'), '.') }}</span> {{ $item->unit }}
                </td>
                <td>{{ $item->location ?: 'Not recorded' }}</td>
                <td>
                    @if($item->purchaseRequest)
                        <a class="text-decoration-none" href="{{ route('purchase-requests.show', $item->purchaseRequest) }}">{{ $item->purchaseRequest->request_number }}</a>
                        <div class="small text-muted text-truncate request-title">{{ $item->purchaseRequest->title }}</div>
                    @else
                        <span class="text-muted">Not linked</span>
                    @endif
                </td>
                <td class="small text-nowrap"><div>{{ $item->logged_at?->format('M j, Y g:i A') ?? 'Not recorded' }}</div><div class="text-muted">{{ $item->logger?->name ?? 'Not recorded' }}</div></td>
                <td><span class="badge {{ $available > 0 ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $available > 0 ? 'In stock' : 'Released' }}</span></td>
                <td class="text-end text-nowrap">
                    @can('release', $item)
                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#inventory-release-modal"
                            data-inventory-release-url="{{ route('inventory.release', $item) }}"
                            data-inventory-release-name="{{ $item->description }} ({{ $item->inventory_code }})"
                            data-inventory-release-max="{{ $available }}"
                            data-inventory-release-unit="{{ $item->unit }}">Release</button>
                    @else
                        <span class="small text-muted">View only</span>
                    @endcan
                    <x-admin-delete-transaction :record="$item" />
                </td>
            </tr>
        @empty
            <tr><td colspan="8"><x-ui.empty-state icon="inventory" title="No inventory items found" description="Received purchases and manually added stock appear here. Adjust the search or filters if you expected results." /></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
