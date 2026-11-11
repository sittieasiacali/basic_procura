{{-- Quotation entry (Add quotation). Expects $purchaseRequest, $quotations and $teamCanRecommendQuotation. --}}
@can('manageCanvassing', $purchaseRequest)
    @if(in_array($purchaseRequest->status, [\App\Enums\PurchaseRequestStatus::ForProcurement, \App\Enums\PurchaseRequestStatus::ForCanvassing, \App\Enums\PurchaseRequestStatus::ForSupplierApproval], true))
        @php ($screeningQuotationEntry = auth()->user()->hasAnyRole([\App\Enums\RoleSlug::ItStaff->value, \App\Enums\RoleSlug::PremisesStaff->value]) && $teamCanRecommendQuotation); @endphp
        <div class="card action-panel mb-3 no-print quotation-entry-panel" id="canvassing-actions">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center gap-2">
                <span>@can('viewAny', \App\Models\Supplier::class)<a href="{{ route('suppliers.index') }}" class="small d-block">Manage suppliers</a>@endcan{{ $screeningQuotationEntry ? 'Quotations' : 'Canvassing & Quotations' }} <span class="badge text-bg-primary ms-1" aria-label="{{ $quotations->count() }} saved quotations">{{ $quotations->count() }}</span><small class="text-muted fw-normal d-block">{{ $screeningQuotationEntry ? 'Record quotations to complete this stage and submit the request to Canvassing.' : 'Send this request to President review, with or without supplier quotations.' }}</small></span>
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
                @if(! $screeningQuotationEntry && $quotations->isEmpty() && in_array($purchaseRequest->status, [\App\Enums\PurchaseRequestStatus::ForProcurement, \App\Enums\PurchaseRequestStatus::ForCanvassing], true))
                    <div class="border rounded p-3 mb-4 bg-light">
                        <h3 class="h6">Quotation is optional</h3>
                        <p class="small text-muted">You can send this request to the President now without supplier quotations, or add quotations below.</p>
                        <form method="POST" action="{{ route('canvassing.without-quotation', $purchaseRequest) }}" data-confirm-message="Request {{ $purchaseRequest->request_number }}: proceed to President review without adding a quotation?" onsubmit="return window.confirm(this.dataset.confirmMessage)">
                            @csrf
                            <input type="hidden" name="_validation_context" value="without-quotation">
                            @if(old('_validation_context') === 'without-quotation' && $errors->any())
                                <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
                            @endif
                            <label class="form-label small" for="no-quotation-remarks">Note to the President <span class="text-muted">(optional)</span></label>
                            <textarea class="form-control mb-3" id="no-quotation-remarks" name="remarks" rows="2" maxlength="2000" placeholder="Explain why no quotation is included">{{ old('_validation_context') === 'without-quotation' ? old('remarks') : '' }}</textarea>
                            <button class="btn btn-primary" type="submit">Proceed without quotation</button>
                        </form>
                    </div>
                @endif
                <form method="POST" action="{{ route('canvassing.quotations.store', $purchaseRequest) }}" enctype="multipart/form-data" class="row g-3" data-validation-context="canvassing" data-quotation-form data-request-code="{{ $purchaseRequest->request_number }}" data-next-stage="{{ $screeningQuotationEntry ? 'Canvassing' : 'President review' }}" novalidate>
                    @csrf
                    <input type="hidden" name="_validation_context" value="canvassing">
                    @if($screeningQuotationEntry)
                        <div class="col-md-4">
                            <label class="form-label" for="quotation-priority">Priority level <span class="text-danger">*</span></label>
                            <select class="form-select @error('priority_level') is-invalid @enderror" id="quotation-priority" name="priority_level" required>
                                <option value="">Select priority</option>
                                @foreach(\App\Models\PurchaseRequest::PRIORITY_LEVELS as $value => $label)
                                    <option value="{{ $value }}" @selected(old('priority_level', $purchaseRequest->priority_level) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Required before passing this request to the PO team.</div>
                            @error('priority_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endif
                    <input type="hidden" name="confirmed_without_quotation" value="0" data-confirm-without-quotation>
                    <div class="col-12"><p class="small text-muted mb-0">Request <strong>{{ $purchaseRequest->request_number }}</strong> · Quotations are optional. Leave the quotation rows empty and submit to proceed to {{ $screeningQuotationEntry ? 'Canvassing' : 'President review' }} after confirmation. Complete any quotation you choose to enter.</p></div>
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
                    @php
                        $supplierDirectory = \App\Models\Supplier::query()
                            ->where('team', \App\Models\Supplier::quotationTeam(auth()->user(), $purchaseRequest))
                            ->where('is_active', true)->orderBy('name')
                            ->with(['latestQuotation.attachments', 'latestQuotation.purchaseRequest.quotations'])->get(['id', 'name', 'bank_details'])
                            ->map(fn ($supplier) => ['id' => $supplier->id, 'name' => $supplier->name, ...$supplier->quotationDefaults(auth()->user())]);
                    @endphp
                    <script type="application/json" data-supplier-directory>@json($supplierDirectory)</script>
                    <div id="quotation-rows">
                        <div class="col-12 quote-row" data-quotation-row data-quote-index="0">
                            <div class="quotation-entry-card">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <strong>Quotation <span data-quotation-number>1</span></strong>
                                    <button class="btn btn-sm btn-outline-danger" type="button" data-remove-quotation aria-label="Remove quotation">Remove</button>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-3">
                                        <div class="supplier-picker" data-supplier-picker>
                                            <label class="form-label" data-supplier-label>Supplier <span class="text-danger">*</span></label>
                                            <div class="supplier-picker-control">
                                                <input class="form-control" type="text" name="quotations[0][supplier_name]" data-supplier-search autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" placeholder="Search or choose supplier" maxlength="255" required>
                                                <input type="hidden" name="quotations[0][supplier_id]" data-supplier-id>
                                                <button class="supplier-picker-toggle" type="button" data-supplier-toggle aria-label="Show saved suppliers"><x-ui.icon name="chevron-down" size="16" /></button>
                                            </div>
                                            <div class="supplier-picker-menu" data-supplier-options role="listbox" aria-label="Saved suppliers" hidden></div>
                                            <div class="form-text" data-supplier-status aria-live="polite">Search your team's suppliers or enter a new name.</div>
                                        </div>
                                    </div>
                                    <div class="col-md-2 quote-manual-fields"><label class="form-label">Amount <span class="text-danger">*</span></label><input class="form-control" type="number" step="0.01" min="0" name="quotations[0][quoted_amount]" required></div><div class="col-md-2"><label class="form-label">VAT TYPE <span class="text-danger">*</span></label><select class="form-select" name="quotations[0][vat_treatment]" required><option value="EXEMPT">Non-Vat</option><option value="EXCLUSIVE">VAT (12%)</option><option value="INCLUSIVE">VAT inclusive (12%)</option></select></div>
                                    <div class="col-md-2"><label class="form-label">Shipping fee</label><input class="form-control" type="number" step="0.01" min="0" name="quotations[0][shipping_fee]" placeholder="0.00"><div class="form-text">Added after VAT, once per supplier.</div></div><div class="col-md-3"><label class="form-label">Delivery Location <span class="text-danger">*</span></label><select class="form-select" name="quotations[0][delivery_location]" required><option value="">Select delivery location</option>@foreach(\App\Models\SupplierQuotation::DELIVERY_LOCATIONS as $location)<option value="{{ $location }}" @selected($purchaseRequest->delivery_location === $location)>{{ $location }}</option>@endforeach</select></div>
                                    <div class="col-md-2"><label class="form-label">Warranty <span class="text-danger">*</span></label><input class="form-control" type="text" name="quotations[0][warranty]" required></div>
                                    <div class="col-md-3"><label class="form-label">Quotation URL (optional)</label><input class="form-control" type="url" name="quotations[0][quotation_url]"></div>
                                    <div class="col-md-6"><label class="form-label">Quotation attachment (optional)</label><input class="form-control" type="file" multiple name="quotations[0][attachment][]" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"><div data-reused-attachments></div><div class="form-text">PDF, image, Word or Excel, up to 5 MB.</div><div data-attachment-preview aria-live="polite"></div></div>
                                    @if($screeningQuotationEntry)
                                        <div class="col-md-6"><label class="form-label">Email evidence attachments (optional)</label><input class="form-control" type="file" multiple name="quotations[0][email_evidence][]" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.eml,.msg"><div class="form-text">Supporting emails or screenshots. Up to 10 files, 5 MB each.</div></div>
                                    @endif
                                    <div class="col-md-6"><label class="form-label">Supplier Bank Details <span class="text-danger">*</span></label><textarea class="form-control quotation-compact-textarea" rows="2" maxlength="2000" name="quotations[0][bank_details]" required placeholder="Bank name, account name, account number, and payment instructions"></textarea></div>
                                    <div class="col-md-6"><label class="form-label">Remarks <span class="text-muted fw-normal">(optional)</span></label><textarea class="form-control quotation-compact-textarea" rows="2" name="quotations[0][remarks]" placeholder="Quotation notes"></textarea></div>
                                    <div class="col-12 quote-bulk-fields d-none">
                                        <div class="d-flex justify-content-between align-items-center mb-2"><strong>Quoted items</strong><button class="btn btn-sm btn-outline-primary" type="button" data-add-quoted-item>Add Item</button></div>
                                        <div data-quoted-items>
                                            <div class="row g-2 align-items-end quoted-item-row" data-quoted-item>
                                                <div class="col-md-4"><label class="form-label">Requested item <span class="text-danger">*</span></label><select class="form-select" name="quotations[0][items][0][item_id]" required disabled><option value="">Choose requested item</option>@foreach($purchaseRequest->items as $item)<option value="{{ $item->id }}">{{ $item->description }}</option>@endforeach</select></div>
                                                <div class="col-md-2"><label class="form-label">Quantity <span class="text-danger">*</span></label><input class="form-control bulk-item-quantity" type="number" min="0.01" step="0.01" value="1" name="quotations[0][items][0][quantity]" required disabled></div>
                                                <div class="col-md-2"><label class="form-label">Unit price <span class="text-danger">*</span></label><input class="form-control bulk-item-price" type="number" min="0" step="0.01" value="0" name="quotations[0][items][0][unit_price]" required disabled></div>
                                                <div class="col-md-2"><label class="form-label">Item total</label><output class="form-control-plaintext text-end" data-item-total>0.00</output></div>
                                                <div class="col-md-2"><button class="btn btn-outline-secondary w-100" type="button" data-remove-quoted-item disabled aria-label="Clear requested item">Clear</button></div>
                                            </div>
                                        </div>
                                        <div class="small text-muted text-end mt-2">Bulk quotation total: ₱ <span data-bulk-total>0.00</span></div>
                                    </div>
                                    <div class="col-12">
                                        <dl class="d-flex flex-wrap justify-content-end gap-4 border-top pt-3 mb-0" aria-live="polite" data-quotation-totals>
                                            <div><dt class="small text-muted">Item amount</dt><dd class="mb-0">&#8369; <span data-quote-items-total>0.00</span></dd></div>
                                            <div><dt class="small text-muted">VAT</dt><dd class="mb-0">&#8369; <span data-quote-vat>0.00</span></dd></div>
                                            <div><dt class="small text-muted">Shipping fee</dt><dd class="mb-0">&#8369; <span data-quote-shipping>0.00</span></dd></div>
                                            <div><dt>Total amount</dt><dd class="mb-0 fw-bold">&#8369; <span data-quote-total>0.00</span></dd></div>
                                        </dl>
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
                    <div class="col-12 quotation-submit-bar"><button class="btn btn-primary" type="submit" data-submit-quotations>{{ $screeningQuotationEntry ? 'Proceed to Canvassing' : 'Proceed to President review' }}</button></div>
                </form>
            </div>
        </div>
    @endif
@endcan
