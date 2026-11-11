@extends('layouts.app')

@section('title', 'Inventory')

@section('content')
    <div class="inventory-page">
    <x-ui.page-header
        class="inventory-hero"
        style="--inventory-header-image: url('{{ asset('images/inventory-workspace-hero.webp') }}')"
        eyebrow="{{ $canViewRegister ? 'Inventory register' : 'My items' }}"
        title="{{ $canViewRegister ? 'Inventory' : 'My items' }}"
        description="{{ $canViewRegister ? 'Review all inventory, newly added items, and every release. Goods received through purchasing are logged automatically.' : 'Confirm receipt of items released to your account and attach proof photos.' }}"
    >
        <x-slot:actions><a class="btn btn-outline-secondary" href="{{ route('dashboard') }}">&larr; Back to dashboard</a></x-slot:actions>
    </x-ui.page-header>

    @if($canViewRegister)
    <div class="row g-3 mb-4 inventory-summary-grid">
        @foreach([
            'items' => ['Inventory items', 'inventory', 'primary'],
            'in_stock' => ['In stock', 'check-circle', 'success'],
            'released' => ['Fully released', 'receipt', 'secondary'],
        ] as $key => [$label, $icon, $tone])
            <div class="col-12 col-sm-4">
                <div class="card inventory-summary-card h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <span class="inventory-summary-icon inventory-summary-icon--{{ $tone }}" aria-hidden="true"><x-ui.icon :name="$icon" size="20" /></span>
                        <div class="inventory-summary-copy">
                            <div class="small text-muted inventory-summary-label">{{ $label }}</div>
                            <div class="h4 mb-0 inventory-summary-value">{{ number_format($summary[$key]) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    @endif

    <section class="card inventory-register" aria-labelledby="inventory-register-heading">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center w-100 inventory-register-header">
                <h2 class="section-title mb-0" id="inventory-register-heading">{{ match($view) { 'added' => 'Added items', 'released' => 'Released items', 'claimed' => 'Claimed items', default => 'All stock' } }}</h2>
                @can('create', \App\Models\InventoryItem::class)
                    <div class="d-flex flex-wrap gap-2 inventory-register-actions">
                        <a class="btn btn-outline-primary btn-sm" href="{{ route('inventory.import-template') }}" download>Download template</a>
                        <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#inventory-import-modal">Import CSV</button>
                        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#inventory-add-modal">Add item</button>
                    </div>
                @endcan
            </div>
        </div>
        <nav class="po-history-tabs" aria-label="Inventory views">
            @foreach(($canViewRegister
                ? ['stock' => ['All stock', $summary['items']], 'added' => ['Added items', $summary['items']], 'released' => ['Released items', $summary['release_entries']], 'claimed' => ['Claimed items', $summary['claimed_entries']]]
                : ['released' => ['Released items', $summary['release_entries']], 'claimed' => ['Claimed items', $summary['claimed_entries']]]) as $key => [$label, $count])
                <a class="po-history-tab {{ $view === $key ? 'is-active' : '' }}" href="{{ route('inventory.index', ['view' => $key]) }}" @if($view === $key) aria-current="page" @endif>{{ $label }} <span class="po-history-tab-count">{{ number_format($count) }}</span></a>
            @endforeach
        </nav>
        @if(in_array($view, ['stock', 'added'], true))
        <form class="table-toolbar" method="GET" action="{{ route('inventory.index') }}" role="search" aria-label="Filter inventory">
            <input type="hidden" name="view" value="{{ $view }}">
            <div class="table-toolbar-field table-toolbar-search">
                <label class="visually-hidden" for="inventory-search">Search</label>
                <span class="table-toolbar-search-icon" aria-hidden="true"><x-ui.icon name="search" size="15" /></span>
                <input class="form-control form-control-sm" id="inventory-search" type="search" name="search" value="{{ request('search') }}" placeholder="Search code, description, serial, request…" maxlength="200">
            </div>
            <div class="table-toolbar-field">
                <label class="visually-hidden" for="inventory-stock">Stock</label>
                <select class="form-select form-select-sm" id="inventory-stock" name="stock">
                    <option value="">All stock</option>
                    <option value="in_stock" @selected(request('stock') === 'in_stock')>In stock</option>
                    <option value="released" @selected(request('stock') === 'released')>Fully released</option>
                </select>
            </div>
            <div class="table-toolbar-field table-toolbar-dates">
                <label class="visually-hidden" for="inventory-date-from">Logged from</label>
                <input class="form-control form-control-sm" id="inventory-date-from" type="date" name="date_from" value="{{ request('date_from') }}" title="Logged from">
                <span class="small text-muted" aria-hidden="true">to</span>
                <label class="visually-hidden" for="inventory-date-to">Logged to</label>
                <input class="form-control form-control-sm" id="inventory-date-to" type="date" name="date_to" value="{{ request('date_to') }}" title="Logged to">
            </div>
            <div class="table-toolbar-actions">
                <button class="btn btn-sm btn-primary" type="submit">Filter</button>
                @if(request()->hasAny(['search', 'stock', 'date_from', 'date_to']))
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('inventory.index', ['view' => $view]) }}">Clear</a>
                @endif
            </div>
        </form>
        @include('inventory.partials.items-table')
        @if($items->hasPages())<div class="card-footer">{{ $items->links() }}</div>@endif
        @elseif($view === 'released')
            <div class="table-responsive">
                <table data-mobile-cards class="table table-hover align-middle mb-0">
                    <thead><tr><th>#</th><th>Item</th><th class="text-end">Quantity released</th><th>Released to</th><th>Released by</th><th>Released on</th><th>Remarks</th><th>Claim</th></tr></thead>
                    <tbody>
                    @forelse($releases as $release)
                        <tr>
                            <td>{{ $releases->firstItem() + $loop->index }}</td>
                            <td><div class="fw-semibold">{{ $release->inventoryItem?->description ?? 'Removed item' }}</div><div class="small text-muted">{{ $release->inventoryItem?->sku ?? $release->inventoryItem?->inventory_code ?? 'No SKU' }}</div></td>
                            <td class="text-end">{{ rtrim(rtrim(number_format((float) $release->quantity, 2), '0'), '.') }} {{ $release->inventoryItem?->unit }}</td>
                            <td>{{ $release->released_to }}</td>
                            <td>
                                @if($release->signature_data)
                                    <img class="d-block mb-1" src="{{ \App\Support\WorkflowSignature::forDisplay($release->signature_data) }}" alt="{{ $release->signature_name }} signature" style="max-width:120px;max-height:44px;object-fit:contain">
                                @endif
                                {{ $release->releaser?->name ?? 'Unknown' }}
                                <div class="small text-muted">{{ $release->releaser?->role?->name ?? 'No role' }}</div>
                            </td>
                            <td class="text-nowrap">{{ $release->released_at?->format('M j, Y g:i A') }}</td>
                            <td class="workflow-remarks-cell">
                                @if(filled($release->remarks))<div class="workflow-remarks-entry"><strong class="workflow-remarks-meta">Release remarks</strong><div class="workflow-remarks-text">{{ $release->remarks }}</div></div>@endif
                                @if(filled($release->claim_remarks))<div class="workflow-remarks-entry"><strong class="workflow-remarks-meta">Claim remarks</strong><div class="workflow-remarks-text">{{ $release->claim_remarks }}</div></div>@endif
                                @if(blank($release->remarks) && blank($release->claim_remarks))<span class="text-muted">N/A</span>@endif
                            </td>
                            <td>
                                @if($release->claimed_at)
                                    <span class="badge text-bg-success">Claimed</span>
                                @elseif($release->recipient_user_id && (
                                    (int) $release->recipient_user_id === (int) auth()->id()
                                    || auth()->user()->can('linkRecipient', [\App\Models\InventoryItem::class, $release])
                                ))
                                    <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#inventory-claim-modal"
                                        data-inventory-claim-id="{{ $release->id }}"
                                        data-inventory-claim-url="{{ route('inventory.claim', $release) }}"
                                        data-inventory-claim-recipient="{{ $release->recipient?->name ?? $release->released_to }}"
                                        data-inventory-claim-name="{{ $release->inventoryItem?->description ?? 'Inventory item' }}">Claim item</button>
                                @elseif($release->recipient_user_id)
                                    <span class="badge text-bg-warning">Awaiting recipient</span>
                                @else
                                    <span class="badge text-bg-secondary">No account linked</span>
                                    @can('linkRecipient', [\App\Models\InventoryItem::class, $release])
                                        <form class="d-flex gap-1 mt-2" method="POST" action="{{ route('inventory.link-recipient', $release) }}">
                                            @csrf
                                            @method('PATCH')
                                            <label class="visually-hidden" for="release-recipient-{{ $release->id }}">Recipient account</label>
                                            <select class="form-select form-select-sm" id="release-recipient-{{ $release->id }}" name="recipient_user_id" required>
                                                <option value="">Select account</option>
                                                @foreach($recipients as $recipient)
                                                    <option value="{{ $recipient->id }}">{{ $recipient->name }} ({{ $recipient->role?->name ?? 'No role' }})</option>
                                                @endforeach
                                            </select>
                                            <button class="btn btn-sm btn-outline-primary" type="submit">Link</button>
                                        </form>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><x-ui.empty-state icon="inventory" title="No released items yet" description="Released stock will appear here." /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($releases->hasPages())<div class="card-footer">{{ $releases->links() }}</div>@endif
        @else
            <div class="row g-3 p-3">
                @forelse($claimedReleases as $release)
                    <div class="col-12 col-lg-6">
                        <article class="card h-100">
                            <div class="card-body">
                                <div class="fw-semibold">{{ $release->inventoryItem?->description ?? 'Removed item' }}</div>
                                <div class="small text-muted">{{ $release->inventoryItem?->sku }} · {{ rtrim(rtrim(number_format((float) $release->quantity, 2), '0'), '.') }} {{ $release->inventoryItem?->unit }}</div>
                                <div class="small mt-2">Recipient: <strong>{{ $release->recipient?->name ?? $release->released_to }}</strong>. Recorded by {{ $release->claimant?->name ?? 'Unknown' }} ({{ $release->claimant?->role?->name ?? 'No role' }}) on {{ $release->claimed_at?->format('M j, Y g:i A') }}</div>
                                <p class="small mt-2 mb-1"><strong>Remarks:</strong> {{ $release->claim_remarks ?: 'N/A' }}</p>
                                @if($release->claim_signature_data)
                                    <img class="d-block mt-2" src="{{ \App\Support\WorkflowSignature::forDisplay($release->claim_signature_data) }}" alt="{{ $release->claim_signature_name }} signature" style="max-width:180px;max-height:65px;object-fit:contain">
                                @endif
                                <div class="d-flex flex-wrap gap-2 mt-3" aria-label="Claim proof gallery">
                                    @foreach($release->proofs as $proof)
                                        <a href="{{ route('inventory.proofs.show', $proof) }}" target="_blank" rel="noopener" aria-label="Open claim proof {{ $loop->iteration }} for {{ $release->inventoryItem?->description }}">
                                            <img src="{{ route('inventory.proofs.show', $proof) }}" alt="Claim proof {{ $loop->iteration }}" width="112" height="84" style="object-fit:cover;border-radius:.4rem;border:1px solid #dce2e8">
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        </article>
                    </div>
                @empty
                    <div class="col-12"><x-ui.empty-state icon="check-circle" title="No claimed items yet" description="Recipient claims and proof photos will appear here." /></div>
                @endforelse
            </div>
            @if($claimedReleases->hasPages())<div class="card-footer">{{ $claimedReleases->links() }}</div>@endif
        @endif
    </section>

    @if($canViewRegister)
    <section class="card mt-4" aria-labelledby="inventory-activity-heading">
        <div class="card-header"><h2 class="section-title mb-0" id="inventory-activity-heading">Activity log</h2></div>
        <div class="table-responsive">
            <table data-mobile-cards class="table table-hover align-middle mb-0 inventory-activity-table">
                <thead><tr><th class="col-when">When</th><th class="col-activity">Activity</th><th>Item</th><th class="col-qty text-end">Quantity</th><th class="col-account">Account</th></tr></thead>
                <tbody>
                    @forelse($activities as $activity)
                        @php($actorName = $activity->actor?->name ?? 'Unknown')
                        <tr>
                            <td class="text-nowrap">
                                <span class="activity-date">{{ $activity->occurred_at?->format('M j, Y') }}</span>
                                <span class="activity-time">{{ $activity->occurred_at?->format('g:i A') }}</span>
                            </td>
                            <td><span class="activity-pill activity-pill--{{ strtolower($activity->action) }}">{{ ucfirst(strtolower(str_replace('_', ' ', $activity->action))) }}</span></td>
                            <td>
                                <span class="activity-item">{{ $activity->item?->description ?? 'Removed item' }}</span>
                                @if($activity->item?->sku)<span class="activity-sku">{{ $activity->item->sku }}</span>@endif
                            </td>
                            <td class="text-end text-nowrap">@if($activity->quantity !== null)<span class="activity-qty">{{ rtrim(rtrim(number_format((float) $activity->quantity, 2), '0'), '.') }}</span> <span class="activity-unit">{{ $activity->item?->unit }}</span>@else&mdash;@endif</td>
                            <td>
                                <div class="activity-actor">
                                    <span class="activity-avatar" aria-hidden="true">{{ collect(explode(' ', $actorName))->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('') }}</span>
                                    <span>
                                        <span class="activity-actor-name">{{ $actorName }}</span>
                                        <span class="activity-actor-role">{{ $activity->actor?->role?->name ?? 'No role' }}</span>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="inventory" title="No activity yet" description="Added, released, and claimed items will appear here." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($activities->hasPages())<div class="card-footer">{{ $activities->links() }}</div>@endif
    </section>
    @endif

    </div>
    @can('create', \App\Models\InventoryItem::class)
        @include('inventory.partials.add-modal')
        @include('inventory.partials.import-modal')
    @endcan
    @if($canViewRegister)
        @include('inventory.partials.release-modal')
    @endif
    @if($view === 'released')
        @include('inventory.partials.claim-modal')
    @endif
@endsection
