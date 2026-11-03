{{-- Quotation entry (Add quotation). Expects $purchaseRequest, $quotations and $teamCanRecommendQuotation. --}}
@can('manageCanvassing', $purchaseRequest)
    @if(in_array($purchaseRequest->status, [\App\Enums\PurchaseRequestStatus::ForProcurement, \App\Enums\PurchaseRequestStatus::ForCanvassing, \App\Enums\PurchaseRequestStatus::ForSupplierApproval], true))
        @php ($screeningQuotationEntry = auth()->user()->hasAnyRole([\App\Enums\RoleSlug::ItStaff->value, \App\Enums\RoleSlug::PremisesStaff->value]) && $teamCanRecommendQuotation); @endphp
        <div class="card action-panel mb-3 no-print quotation-entry-panel" id="canvassing-actions">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center gap-2">
                <span>{{ $screeningQuotationEntry ? 'Quotations' : 'Canvassing & Quotations' }} <span class="badge text-bg-primary ms-1" aria-label="{{ $quotations->count() }} saved quotations">{{ $quotations->count() }}</span><small class="text-muted fw-normal d-block">{{ $screeningQuotationEntry ? 'Record quotations to complete this stage and submit the request to Canvassing.' : 'Purchasing submits a supplier recommendation; President approval is required.' }}</small></span>
                <div class="d-flex gap-2">
                    @can('undoScreening', $purchaseRequest)
                        <form method="POST" action="{{ route('request-flow.screening.undo', $purchaseRequest) }}" onsubmit="return confirm('Undo the Inventory Unavailable decision? The request returns to screening so you can decide whether stock is available.');">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-secondary" type="submit">Undo decision</button>
                        </form>
                    @endcan
                    <button class="btn btn-sm btn-outline-primary" id="add-quotation" type="button" aria-controls="quotation-entry-body" aria-expanded="{{ session('quotation_saved') ? 'false' : 'true' }}">Add quotation</button>
                </div>
            </div>
            <div class="card-body{{ session('quotation_saved') ? ' d-none' : '' }}" id="quotation-entry-body">
                <form method="POST" action="{{ route('canvassing.quotations.store', $purchaseRequest) }}" class="row g-3" data-validation-context="canvassing" data-quotation-form novalidate>
                    @csrf
                    <input type="hidden" name="_validation_context" value="canvassing">
                    <div class="col-12"><div class="alert alert-danger d-none mb-0" data-quotation-errors role="alert" tabindex="-1"><strong>Please complete the highlighted quotation fields.</strong><ul class="mb-0 mt-1" data-quotation-error-list></ul></div></div>
                    @if(old('_validation_context') === 'canvassing' && $errors->any())
                        <div class="col-12"><div class="alert alert-danger mb-0" role="alert"><strong>Quotation was not submitted.</strong><ul class="mb-0 mt-1">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div></div>
                    @endif
                    <div class="col-12">
                        <fieldset class="quotation-mode-picker">
                            <legend class="form-label mb-2">Quotation mode</legend>
                            <div class="quotation-mode-options">
                                <label class="form-check"><input class="form-check-input" type="radio" name="quotation_mode" value="manual" checked> <span class="form-check-label">Manual quotation</span></label>
                                <label class="form-check"><input class="form-check-input" type="radio" name="quotation_mode" value="bulk"> <span class="form-check-label">Bulk quotation — one supplier, multiple requested items</span></label>
                            </div>
                        </fieldset>
                    </div>
                    <div id="quotation-rows">
                        <div class="col-12 quote-row" data-quotation-row data-quote-index="0">
                            <div class="quotation-entry-card">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <strong>Quotation <span data-quotation-number>1</span></strong>
                                    <button class="btn btn-sm btn-outline-danger" type="button" data-remove-quotation aria-label="Remove quotation">Remove</button>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-3"><label class="form-label">Supplier <span class="text-danger">*</span></label><input class="form-control" type="text" name="quotations[0][supplier_name]" required></div>
                                    <div class="col-md-2 quote-manual-fields"><label class="form-label">Amount <span class="text-danger">*</span></label><input class="form-control" type="number" step="0.01" min="0" name="quotations[0][quoted_amount]" required></div><div class="col-md-2"><label class="form-label">VAT TYPE <span class="text-danger">*</span></label><select class="form-select" name="quotations[0][vat_treatment]" required><option value="EXEMPT">Non-Vat</option><option value="EXCLUSIVE">VAT (12%)</option></select></div>
                                    <div class="col-md-2"><label class="form-label">Lead Time <span class="text-danger">*</span></label><input class="form-control" type="text" name="quotations[0][delivery_lead_time]" required></div><div class="col-md-3"><label class="form-label">Delivery Location <span class="text-danger">*</span></label><select class="form-select" name="quotations[0][delivery_location]" required><option value="">Select delivery location</option>@foreach(\App\Models\SupplierQuotation::DELIVERY_LOCATIONS as $location)<option value="{{ $location }}" @selected($purchaseRequest->delivery_location === $location)>{{ $location }}</option>@endforeach</select></div>
                                    <div class="col-md-2"><label class="form-label">Warranty <span class="text-danger">*</span></label><input class="form-control" type="text" name="quotations[0][warranty]" required></div>
                                    <div class="col-md-3"><label class="form-label">Quotation URL <span class="text-danger">*</span></label><input class="form-control" type="url" name="quotations[0][quotation_url]" required></div>
                                    <div class="col-md-6"><label class="form-label">Supplier Bank Details <span class="text-danger">*</span></label><textarea class="form-control quotation-compact-textarea" rows="2" maxlength="2000" name="quotations[0][bank_details]" required placeholder="Bank name, account name, account number, and payment instructions"></textarea></div>
                                    <div class="col-md-6"><label class="form-label">Remarks <span class="text-muted fw-normal">(optional)</span></label><textarea class="form-control quotation-compact-textarea" rows="2" name="quotations[0][remarks]" placeholder="Quotation notes"></textarea></div>
                                    <div class="col-12 quote-bulk-fields d-none">
                                        <div class="d-flex justify-content-between align-items-center mb-2"><strong>Quoted items</strong><button class="btn btn-sm btn-outline-primary" type="button" data-add-quoted-item>Add Item</button></div>
                                        <div data-quoted-items>
                                            <div class="row g-2 align-items-end quoted-item-row" data-quoted-item>
                                                <div class="col-md-5"><label class="form-label">Requested item <span class="text-danger">*</span></label><select class="form-select" name="quotations[0][items][0][item_id]" required disabled><option value="">Choose requested item</option>@foreach($purchaseRequest->items as $item)<option value="{{ $item->id }}">{{ $item->description }}</option>@endforeach</select></div>
                                                <div class="col-md-2"><label class="form-label">Quantity <span class="text-danger">*</span></label><input class="form-control bulk-item-quantity" type="number" min="0.01" step="0.01" value="1" name="quotations[0][items][0][quantity]" required disabled></div>
                                                <div class="col-md-3"><label class="form-label">Unit price <span class="text-danger">*</span></label><input class="form-control bulk-item-price" type="number" min="0" step="0.01" value="0" name="quotations[0][items][0][unit_price]" required disabled></div>
                                                <div class="col-md-2"><button class="btn btn-outline-secondary w-100" type="button" data-remove-quoted-item disabled aria-label="Clear requested item">Clear</button></div>
                                            </div>
                                        </div>
                                        <div class="small text-muted text-end mt-2">Bulk quotation total: ₱ <span data-bulk-total>0.00</span></div>
                                    </div>
                                    @unless($screeningQuotationEntry)
                                        <div class="col-12"><label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="quotations[0][recommended]" value="1" data-recommended-quotation> Submit this supplier as the recommendation</label></div>
                                    @endunless
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 d-none" id="quotation-empty-state">
                        <div class="alert alert-light mb-0">No quotation rows. Select <strong>Add quotation</strong> to create a new entry.</div>
                    </div>
                    <div class="col-12 quotation-submit-bar"><button class="btn btn-primary" type="submit" data-submit-quotations>{{ $screeningQuotationEntry ? 'Submit to Canvassing' : 'Submit Supplier Recommendation' }}</button></div>
                </form>
            </div>
        </div>
    @endif
@endcan
