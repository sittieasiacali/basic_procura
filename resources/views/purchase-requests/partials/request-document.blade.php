{{-- Read-only, printable request summary. Expects $purchaseRequest with requestor, teamLeader, items, selectedSupplier and approvals.user loaded. --}}
@php
    $stageLabels = [
        'REQUESTOR_SUBMISSION' => 'Requestor submission',
        'OM_REVIEW' => 'Operations Manager review',
        'IT_PREMISES_SCREENING' => 'IT / Premises screening',
        'SCREENING_RECOMMENDATION' => 'IT / Premises recommendation',
        'PURCHASING' => 'Purchasing recommendation',
        'PRESIDENT_SUPPLIER_APPROVAL' => 'President supplier approval',
        'INVENTORY_RELEASE' => 'Inventory release',
    ];
    $itemsTotal = $purchaseRequest->items->sum(fn ($item) => (float) $item->estimated_total);

    // Every hand-off in order: signed steps from the approvals table, plus any hand-off
    // recorded only in the audit log (completed before signatures were captured).
    $handoffLabels = \App\Support\RequestStageReceipt::handoffLabels();
    $stageForAction = fn (string $action) => match (true) {
        str_contains($action, 'SUBMITTED_FOR_SCREENING'), str_contains($action, 'SUBMITTED_FOR_OM_APPROVAL') => 'Requestor submission',
        str_starts_with($action, 'OM_') => 'Operations Manager review',
        $action === 'INVENTORY_RELEASED' => 'Inventory release',
        str_starts_with($action, 'INVENTORY_') => 'IT / Premises screening',
        $action === 'SCREENING_QUOTATION_RECOMMENDED' => 'IT / Premises recommendation',
        $action === 'CANVASSING_UPDATED' => 'Purchasing recommendation',
        str_starts_with($action, 'PRESIDENT_') => 'President supplier approval',
        default => str($action)->replace('_', ' ')->title(),
    };
    $trail = $purchaseRequest->approvals->map(fn ($approval) => [
        'stage' => $stageLabels[$approval->approval_level] ?? str($approval->approval_level)->replace('_', ' ')->title(),
        'name' => $approval->signature_name ?: ($approval->user?->name ?? 'Not recorded'),
        'status' => $approval->displayStatus(),
        'at' => $approval->acted_at,
        'remarks' => $approval->remarks,
        'signature' => $approval->signature_data,
    ]);
    $signedKeys = $purchaseRequest->approvals->map(fn ($approval) => $approval->action.'|'.$approval->user_id)->all();
    foreach ($purchaseRequest->auditLogs->sortBy('id') as $log) {
        if (! array_key_exists($log->action, $handoffLabels) || in_array($log->action.'|'.$log->user_id, $signedKeys, true)) {
            continue;
        }
        $trail->push([
            'stage' => $stageForAction($log->action),
            'name' => $log->user?->name ?? 'Not recorded',
            'status' => $handoffLabels[$log->action],
            'at' => $log->created_at,
            'remarks' => $log->remarks,
            'signature' => null,
        ]);
    }
    $trail = $trail->sortBy(fn ($entry) => $entry['at']?->getTimestamp() ?? 0)->values();
@endphp

<article class="request-document" aria-label="Purchase request {{ $purchaseRequest->request_number }}">
    @include('partials.document-letterhead')
    <header class="request-document__header">
        <div>
            <div class="request-document__kicker">Purchase request</div>
            <h2 class="request-document__title">{{ $purchaseRequest->title ?: $purchaseRequest->request_number }}</h2>
        </div>
        <div class="request-document__reference">
            <strong>{{ $purchaseRequest->request_number }}</strong>
            <span>{{ str($purchaseRequest->status->value)->replace('_', ' ')->title() }}</span>
        </div>
    </header>

    <dl class="request-document__facts">
        <div><dt>Requested by</dt><dd>{{ $purchaseRequest->requestor?->name ?? 'Not recorded' }}</dd></div>
        <div><dt>Team leader</dt><dd>{{ $purchaseRequest->teamLeader?->name ?? 'Not recorded' }}</dd></div>
        <div><dt>Department / campaign</dt><dd>{{ $purchaseRequest->department_campaign ?: 'Not recorded' }}</dd></div>
        <div><dt>Request date</dt><dd>{{ $purchaseRequest->created_at?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
        <div><dt>Required date</dt><dd>{{ $purchaseRequest->required_date?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
        <div><dt>Delivery location</dt><dd>{{ $purchaseRequest->delivery_location ?: 'Not recorded' }}</dd></div>
        <div><dt>Selected supplier</dt><dd>{{ $purchaseRequest->selectedSupplier?->name ?? 'Not selected yet' }}</dd></div>
        <div class="request-document__wide"><dt>Purpose</dt><dd>{{ $purchaseRequest->purpose ?: 'Not recorded' }}</dd></div>
        <div class="request-document__wide"><dt>Remarks</dt><dd>{{ $purchaseRequest->remarks ?: 'N/A' }}</dd></div>
    </dl>

    <section>
        <h3 class="request-document__section-title">Requested items</h3>
        @if(request()->routeIs('purchase-requests.preview'))
            @include('purchase-requests.partials.requested-items')
        @else
        <table class="request-document__table">
            <thead><tr><th>#</th><th>Description</th><th>Quotation</th><th class="text-end">Qty</th><th>Unit</th><th class="text-end">Unit price</th><th class="text-end">Total</th></tr></thead>
            <tbody>
                @forelse($purchaseRequest->items as $item)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>
                            {{ $item->description }}
                            @if($item->relationLoaded('attachments'))
                                @foreach($item->attachments as $attachment)
                                    <div class="request-document__attachment"><x-attachment-link :attachment="$attachment" :label="\App\Models\PurchaseRequestItem::attachmentLabel($attachment->category)" /></div>
                                @endforeach
                            @endif
                        </td>
                        <td>{{ $item->brand_specifications ?: '—' }}</td>
                        <td class="text-end">{{ $item->quantity !== null ? rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.') : '—' }}</td>
                        <td>{{ $item->unit ?: '—' }}</td>
                        <td class="text-end">{{ $item->estimated_unit_price !== null ? '₱ '.number_format((float) $item->estimated_unit_price, 2) : '—' }}</td>
                        <td class="text-end">{{ $item->estimated_total !== null ? '₱ '.number_format((float) $item->estimated_total, 2) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">No items were recorded.</td></tr>
                @endforelse
            </tbody>
            @if($itemsTotal > 0)
                <tfoot><tr><th colspan="6" class="text-end">Estimated total</th><td class="text-end">₱ {{ number_format($itemsTotal, 2) }}</td></tr></tfoot>
            @endif
        </table>
        @endif
    </section>

    <section>
        <h3 class="request-document__section-title">Approval trail</h3>
        @if($trail->isEmpty())
            <p class="request-document__muted">No workflow steps have been completed yet.</p>
        @else
            <div class="request-document__signatures">
                @foreach($trail as $entry)
                    <section class="request-document__signature">
                        <div class="request-document__signature-stage">{{ $entry['stage'] }}</div>
                        <div class="request-document__signature-space">
                            @if(filled($entry['signature']))
                                <img src="{{ \App\Support\WorkflowSignature::forDisplay($entry['signature']) }}" alt="{{ $entry['name'] }} signature">
                            @else
                                <span class="request-document__no-signature">Signature not recorded</span>
                            @endif
                        </div>
                        <strong>{{ $entry['name'] }}</strong>
                        <small>{{ $entry['status'] }} · {{ $entry['at']?->format('M j, Y g:i A') ?? 'Date not recorded' }}</small>
                        @if(filled($entry['remarks']))<p>{{ $entry['remarks'] }}</p>@endif
                    </section>
                @endforeach
            </div>
            @if($trail->contains(fn ($entry) => blank($entry['signature'])))
                <p class="request-document__muted small mt-2">Steps marked "Signature not recorded" were completed before electronic signatures were captured for that stage.</p>
            @endif
        @endif
    </section>
</article>
