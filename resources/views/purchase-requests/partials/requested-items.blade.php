<ol class="requestor-items" aria-label="Requested items">
    @forelse($purchaseRequest->items as $item)
        <li class="requestor-item">
            <div class="requestor-item-heading">
                <span class="requestor-item-number" aria-label="Item {{ $loop->iteration }}">{{ $loop->iteration }}</span>
                <div class="requestor-item-copy">
                    <h3 class="requestor-item-title">{{ $item->description }}</h3>
                    @if(filled($item->brand_specifications))
                        <p class="requestor-item-notes">{{ $item->brand_specifications }}</p>
                    @endif
                </div>
                @if($item->reference_url)
                    <a class="requestor-item-reference" href="{{ $item->reference_url }}" target="_blank" rel="noopener noreferrer"><x-ui.icon name="link" size="15" />Product reference <span class="visually-hidden">for {{ $item->description }} (opens in a new tab)</span></a>
                @endif
            </div>
            @php
                $attachmentGroups = $item->attachments->sortBy(fn ($attachment) => [
                    $attachment->category === \App\Models\PurchaseRequestItem::ATTACHMENT_EMAIL_APPROVAL ? 0 : ($attachment->category === \App\Models\PurchaseRequestItem::ATTACHMENT_QUOTATION ? 1 : 2),
                    $attachment->id,
                ])->groupBy('category');
            @endphp
            @if($attachmentGroups->isNotEmpty())
                <div class="requestor-item-files">
                    @foreach($attachmentGroups as $category => $attachments)
                        <section class="requestor-file-group" aria-label="{{ \App\Models\PurchaseRequestItem::attachmentLabel($category) }} files for {{ $item->description }}">
                            <h4 class="requestor-file-heading">{{ \App\Models\PurchaseRequestItem::attachmentLabel($category) }} <span>{{ $attachments->count() }}</span></h4>
                            <div class="requestor-file-grid">
                                @foreach($attachments as $attachment)
                                    <div @class(['requestor-file', 'requestor-file--image' => $attachment->isImage()])>
                                        <x-attachment-link :attachment="$attachment" thumbnail />
                                        @if($attachment->isImage())
                                            <small class="requestor-image-hint">Scroll to read long images. Click to expand.</small>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
            @endif
        </li>
    @empty
        <li class="p-4 text-muted">No requested items recorded.</li>
    @endforelse
</ol>
