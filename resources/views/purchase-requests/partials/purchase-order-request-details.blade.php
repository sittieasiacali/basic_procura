<details class="po-focus-disclosure po-focus-request-details">
    <summary><span><x-ui.icon name="request" size="19" />Full request details</span><span aria-hidden="true">⌄</span></summary>
    <div class="po-focus-disclosure-body">
        <dl class="po-focus-facts">
            <div><dt>Request number</dt><dd>{{ $purchaseRequest->request_number }}</dd></div>
            <div><dt>Request type</dt><dd>{{ $requestTypeLabel }}</dd></div>
            <div><dt>Created</dt><dd>{{ $purchaseRequest->created_at->format('M d, Y g:i A') }}</dd></div>
            @if($purchaseRequest->submitted_at)<div><dt>Submitted</dt><dd>{{ $purchaseRequest->submitted_at->format('M d, Y g:i A') }}</dd></div>@endif
            <div><dt>Campaign</dt><dd>{{ $purchaseRequest->department_campaign }}</dd></div>
            @if(auth()->user()?->canViewPriority())<div><dt>Priority</dt><dd>{{ \App\Models\PurchaseRequest::priorityLabel($purchaseRequest->priority_level) ?? 'Not recorded' }}</dd></div>@endif
            <div><dt>Purpose</dt><dd>{{ $purchaseRequest->purpose }}</dd></div>
            <div><dt>Remarks</dt><dd>{{ $purchaseRequest->remarks ?: 'N/A' }}</dd></div>
        </dl>
        @php
            $hasRequestEstimates = $purchaseRequest->items->contains(fn ($item) => $item->quantity !== null || $item->unit !== null || $item->estimated_unit_price !== null);
        @endphp
        <div class="table-responsive"><table class="table mb-0"><thead><tr><th>Item</th><th>Quotation</th>@if($hasRequestEstimates)<th>Qty</th><th>Unit</th><th>Unit price</th><th>Estimated total</th>@endif</tr></thead><tbody>
            @foreach($purchaseRequest->items as $item)
                <tr><td>{{ $item->description }}@if($item->reference_url) <a href="{{ $item->reference_url }}" target="_blank" rel="noopener noreferrer">Reference</a>@endif @foreach($item->attachments as $attachment)<a href="{{ route('attachments.download', $attachment) }}">{{ $attachment->original_name }}</a> @endforeach</td><td>{{ $item->brand_specifications ?: '—' }}</td>@if($hasRequestEstimates)<td>{{ $item->quantity === null ? '—' : number_format((float) $item->quantity, 2) }}</td><td>{{ $item->unit ?: '—' }}</td><td>{{ $item->estimated_unit_price === null ? '—' : '₱ '.number_format((float) $item->estimated_unit_price, 2) }}</td><td>{{ $item->estimated_total === null ? '—' : '₱ '.number_format((float) $item->estimated_total, 2) }}</td>@endif</tr>
            @endforeach
        </tbody>@if($hasRequestEstimates && $purchaseRequest->total_amount !== null)<tfoot><tr><th colspan="5">Request estimate</th><th>₱ {{ number_format((float) $purchaseRequest->total_amount, 2) }}</th></tr></tfoot>@endif</table></div>
        @if($purchaseRequest->screening)
            <div class="mt-3"><strong>Screening evidence</strong>
                <dl class="po-focus-facts mt-2">
                    <div><dt>Team</dt><dd>{{ $purchaseRequest->screening->team }}</dd></div>
                    <div><dt>Decision</dt><dd>{{ str($purchaseRequest->screening->decision)->title() }}</dd></div>
                    <div><dt>Screened by</dt><dd>{{ $purchaseRequest->screening->screener?->name ?? 'Unavailable' }}</dd></div>
                    <div><dt>Decision date</dt><dd>{{ $purchaseRequest->screening->decided_at?->format('M d, Y g:i A') ?? 'Not recorded' }}</dd></div>
                    <div><dt>Screening remarks</dt><dd>{{ $purchaseRequest->screening->evidence ?: 'N/A' }}</dd></div>
                    @foreach($purchaseRequest->screening->attachments as $attachment)
                        <div><dt>Attachment</dt><dd><a href="{{ route('attachments.download', $attachment) }}">{{ $attachment->original_name }}</a></dd></div>
                    @endforeach
                </dl>
                @if($purchaseRequest->screening->assets->isNotEmpty())
                    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Asset tag</th><th>Item code</th><th>Description</th><th>Serial</th><th>Location</th><th>Source</th><th>Release</th><th>Released by</th><th>Recipient</th></tr></thead><tbody>
                        @foreach($purchaseRequest->screening->assets as $asset)
                            <tr><td>{{ $asset->asset_tag }}</td><td>{{ $asset->item_code }}</td><td>{{ $asset->description }}</td><td>{{ $asset->serial_number ?: '—' }}</td><td>{{ $asset->location }}</td><td>{{ str($asset->source)->replace('_', ' ')->title() }}</td><td>{{ $asset->release_date->format('M d, Y') }}</td><td>{{ $asset->releaser?->name ?? 'Unavailable' }}</td><td>{{ $asset->recipient }}</td></tr>
                        @endforeach
                    </tbody></table></div>
                @endif
            </div>
        @endif
        <div class="po-focus-extra-actions"><a href="{{ $requestFlowUrl }}">Request flow</a><button type="button" onclick="window.print()">Print summary</button></div>
    </div>
</details>
