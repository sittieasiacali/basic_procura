{{-- Preview and edit modals for each quotation. Expects $quotations and $purchaseRequest. --}}
@foreach($quotations as $quotation)
    <div class="modal fade" id="quotation-preview-{{ $quotation->id }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg"><div class="modal-content">
            <div class="modal-header"><h2 class="modal-title fs-5">Quotation Q-{{ str_pad((string) $quotation->id, 5, '0', STR_PAD_LEFT) }}</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><dl class="row mb-3">
                <dt class="col-sm-4">Quotation stage</dt><dd class="col-sm-8">{{ $quotation->sourceLabel() }}</dd>
                <dt class="col-sm-4">Supplier</dt><dd class="col-sm-8">{{ $quotation->supplier?->name }}</dd>
                <dt class="col-sm-4">Item amount (net)</dt><dd class="col-sm-8">&#8369; {{ number_format($quotation->vatBreakdown()['base'] - (float) $quotation->shipping_fee, 2) }}</dd>
                <dt class="col-sm-4">Shipping fee</dt><dd class="col-sm-8">&#8369; {{ number_format((float) $quotation->shipping_fee, 2) }}</dd>
                <dt class="col-sm-4">VAT</dt><dd class="col-sm-8">&#8369; {{ number_format($quotation->vatBreakdown()['vat'], 2) }} ({{ $quotation->vatBreakdown()['rate'] }}%)</dd>
                <dt class="col-sm-4">Total</dt><dd class="col-sm-8">&#8369; {{ number_format($quotation->vatBreakdown()['total'], 2) }} &middot; {{ match ($quotation->vatBreakdown()['treatment']) { 'EXCLUSIVE' => 'VAT (12%)', 'INCLUSIVE' => 'VAT inclusive (12%)', default => 'Non-VAT' } }}</dd>
                <dt class="col-sm-4">Delivery location</dt><dd class="col-sm-8">{{ $quotation->delivery_location ?: $purchaseRequest->delivery_location ?: 'Not recorded' }}</dd>
                <dt class="col-sm-4">Requestor / TL</dt><dd class="col-sm-8">{{ $purchaseRequest->requestor?->name ?: 'Not recorded' }} / {{ $purchaseRequest->teamLeader?->name ?: 'Not recorded' }}</dd>
                <dt class="col-sm-4">Recommendation</dt><dd class="col-sm-8">{{ $quotation->recommendationStatus() }}</dd>
                <dt class="col-sm-4">Remarks</dt><dd class="col-sm-8">{{ $quotation->remarks ?: 'N/A' }}</dd>
                @if($quotation->attachments->isNotEmpty())
                    <dt class="col-sm-4">Quotation attachments</dt>
                    <dd class="col-sm-8">@foreach($quotation->attachments as $attachment)<div><x-attachment-link :attachment="$attachment" /></div>@endforeach</dd>
                @endif
            </dl><div class="row g-3 border-top pt-3"><div class="col-md-6"><strong>Created By / Requestor</strong><div class="border-bottom my-4"></div><div>{{ $quotation->creator?->name ?: 'Not recorded' }}</div><small>{{ $quotation->creator?->role?->name ?: '' }} · {{ $quotation->quoted_at?->format('M d, Y') ?: 'Date not recorded' }}</small></div><div class="col-md-6"><strong>Approved By / Approver</strong><div class="border-bottom my-4"></div>@if($quotation->is_approved)<div>{{ $quotation->approver?->name ?: 'Not recorded' }}</div><small>{{ $quotation->approver?->role?->name ?: '' }} · {{ $quotation->approved_at?->format('M d, Y') }}</small>@else<div class="text-muted">Not approved</div>@endif</div></div></div>
            <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button></div>
        </div></div>
    </div>
    @if((auth()->id() === $quotation->created_by || auth()->user()->isAdministrator()) && $quotation->belongsToWorkspace(auth()->user()) && ! $quotation->is_approved)
        <div class="modal fade" id="quotation-edit-{{ $quotation->id }}" tabindex="-1" aria-labelledby="quotation-edit-title-{{ $quotation->id }}" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" method="POST" action="{{ route('canvassing.quotations.update', [$purchaseRequest, $quotation]) }}" data-unsaved-changes>
                    @csrf
                    @method('PATCH')
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="quotation-edit-title-{{ $quotation->id }}">Edit your quotation</h2>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @if((int) session('quotation_edit_failed') === $quotation->id)
                            <div class="alert alert-danger" role="alert">
                                <strong>Changes were not saved.</strong>
                                <ul class="mb-0 mt-1">
                                    @foreach($errors->all() as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label" for="quotation-{{ $quotation->id }}-supplier">Supplier</label>
                                <input class="form-control" id="quotation-{{ $quotation->id }}-supplier" name="supplier_name" value="{{ old('supplier_name', $quotation->supplier?->name) }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="quotation-{{ $quotation->id }}-amount">Amount</label>
                                <input class="form-control" id="quotation-{{ $quotation->id }}-amount" type="number" min="0" step="0.01" name="quoted_amount" value="{{ old('quoted_amount', $quotation->price) }}" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="quotation-{{ $quotation->id }}-vat">VAT treatment</label>
                                <select class="form-select" id="quotation-{{ $quotation->id }}-vat" name="vat_treatment">
                                    <option value="EXEMPT" @selected(old('vat_treatment', $quotation->vat_treatment) === 'EXEMPT')>Non-Vat</option>
                                    <option value="EXCLUSIVE" @selected(old('vat_treatment', $quotation->vat_treatment ?: 'EXCLUSIVE') !== 'EXEMPT')>VAT (12%)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="quotation-{{ $quotation->id }}-location">Delivery Location</label>
                                <select class="form-select" id="quotation-{{ $quotation->id }}-location" name="delivery_location">
                                    <option value="">Use saved request location</option>
                                    @foreach(\App\Models\SupplierQuotation::DELIVERY_LOCATIONS as $location)
                                        <option value="{{ $location }}" @selected(old('delivery_location', $quotation->delivery_location) === $location)>{{ $location }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="quotation-{{ $quotation->id }}-shipping">Shipping fee (added after VAT)</label>
                                <input class="form-control" id="quotation-{{ $quotation->id }}-shipping" type="number" min="0" step="0.01" name="shipping_fee" value="{{ old('shipping_fee', $quotation->shipping_fee) }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="quotation-{{ $quotation->id }}-warranty">Warranty</label>
                                <input class="form-control" id="quotation-{{ $quotation->id }}-warranty" name="warranty" value="{{ old('warranty', $quotation->warranty) }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="quotation-{{ $quotation->id }}-url">Quotation URL</label>
                                <input class="form-control" id="quotation-{{ $quotation->id }}-url" type="url" name="quotation_url" value="{{ old('quotation_url', $quotation->quotation_url) }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="quotation-{{ $quotation->id }}-bank">Supplier Bank Details</label>
                                <textarea class="form-control" id="quotation-{{ $quotation->id }}-bank" name="bank_details">{{ old('bank_details', $quotation->bank_details) }}</textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="quotation-{{ $quotation->id }}-remarks">Remarks</label>
                                <textarea class="form-control" id="quotation-{{ $quotation->id }}-remarks" name="remarks">{{ old('remarks', $quotation->remarks) }}</textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                        <a class="btn btn-link" href="{{ route('purchase-requests.show', $purchaseRequest) }}#supplier-quotations">Back</a>
                        <button class="btn btn-primary" type="submit">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endforeach
