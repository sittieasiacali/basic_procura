@php
    $editing = isset($purchaseRequest);
    $blankItem = ['description' => '', 'brand_specifications' => '', 'reference_url' => ''];
    $formItems = old('items');
    if ($formItems === null) {
        $formItems = $editing
            ? $purchaseRequest->items->map(fn ($item) => $item->toArray())->all()
            : [$blankItem];
    }
    $selectedCampaign = old('department_campaign', $purchaseRequest->department_campaign ?? auth()->user()->campaign?->name ?? auth()->user()->department);
    $requestorName = isset($purchaseRequest) ? ($purchaseRequest->requestor?->name ?? auth()->user()->name) : auth()->user()->name;
    $purchaseMode = old('purchase_mode', 'manual');
    $selectedLocation = old('delivery_location', $purchaseRequest->delivery_location ?? '');
    $omReviewEdit = $editing
        && auth()->user()->hasRole(\App\Enums\RoleSlug::OmSmtsApprover->value)
        && $purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForOmApproval;
@endphp

<div class="card builder-section-card request-details-card mb-4">
    <div class="card-header">
        <div class="d-flex align-items-start gap-3">
            <span class="builder-section-number" aria-hidden="true">1</span>
            <div>
                <div class="section-kicker">Request Details</div>
                <h2 class="section-title d-flex align-items-center gap-2"><x-ui.icon name="request" size="19" />Request details</h2>
                <div class="small text-muted mt-1">What do you need, why, and when?</div>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="builder-identity-row" aria-label="Request ownership">
            <div><span>Requestor</span><strong>{{ $requestorName }}</strong></div>
            @if(! $editing && auth()->user()->hasAnyRole([\App\Enums\RoleSlug::Requestor->value, \App\Enums\RoleSlug::TeamLeader->value]))
                <div><span>Campaign</span><strong>{{ $selectedCampaign ?: 'Not assigned' }}</strong></div>
            @endif
        </div>
        <div class="row g-3">
            @php
                $selectedTeam = old('requested_team', $purchaseRequest->requested_team ?? '');
                $selectedCategory = old('request_category', $purchaseRequest->request_category ?? '');
                $categoryGroup = $selectedTeam;
                $legacyTitle = ($editing ?? false) && blank($purchaseRequest->request_category ?? null) ? ($purchaseRequest->title ?? null) : null;
            @endphp
            {{-- Two steps decide the request title and the screening queue: team → category. --}}
            <div class="col-12">
                <div class="request-classifier @if($errors->hasAny(['requested_team', 'request_category', 'title'])) has-error @endif" data-request-classifier>
                    <div class="request-classifier-steps">
                        <div class="request-classifier-step">
                            <label class="request-classifier-label" for="requested_team"><span class="request-classifier-number">1</span>Screening team<span class="request-classifier-required" aria-hidden="true">*</span></label>
                            <select class="form-select @error('requested_team') is-invalid @enderror" id="requested_team" name="requested_team" data-requested-team required>
                                <option value="" @selected($selectedTeam === '')>Select team</option>
                                @foreach(\App\Models\PurchaseRequest::REQUESTED_TEAMS as $teamValue => $teamLabel)
                                    <option value="{{ $teamValue }}" @selected($selectedTeam === $teamValue)>{{ $teamLabel }}</option>
                                @endforeach
                            </select>
                            @error('requested_team')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="request-classifier-step">
                            <label class="request-classifier-label" for="request_category"><span class="request-classifier-number">2</span>Request title category<span class="request-classifier-required" aria-hidden="true">*</span></label>
                            <select class="form-select @error('request_category') is-invalid @enderror @error('title') is-invalid @enderror" id="request_category" name="request_category" data-request-category required>
                                <option value="">{{ $selectedTeam === '' ? 'Select a screening team first' : 'Select category' }}</option>
                                @foreach(\App\Models\PurchaseRequest::REQUEST_CATEGORIES as $teamValue => $categories)
                                    <optgroup label="{{ \App\Models\PurchaseRequest::REQUESTED_TEAMS[$teamValue] }}" data-team="{{ $teamValue }}" @if($categoryGroup !== $teamValue) hidden disabled @endif>
                                        @foreach($categories as $categoryValue => $categoryLabel)
                                            <option value="{{ $categoryValue }}" @selected($categoryGroup === $teamValue && $selectedCategory === $categoryValue)>{{ $categoryLabel }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('request_category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    @if($legacyTitle)
                        <div class="request-classifier-summary">
                            <x-ui.icon name="file-text" size="15" />
                            <span>Current title: <strong>{{ $legacyTitle }}</strong> · choose a category to replace it</span>
                        </div>
                    @endif
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="required_date">Required date <span class="text-danger">*</span></label>
                <input class="form-control @error('required_date') is-invalid @enderror" id="required_date" name="required_date" type="date" value="{{ old('required_date', isset($purchaseRequest) ? $purchaseRequest->required_date->format('Y-m-d') : '') }}" required>
                @error('required_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            @if($editing || ! auth()->user()->hasAnyRole([\App\Enums\RoleSlug::Requestor->value, \App\Enums\RoleSlug::TeamLeader->value]))
            <div class="col-md-4">
                <label class="form-label" for="department_campaign">Campaign <span class="text-danger">*</span></label>
                    <select class="form-select @error('department_campaign') is-invalid @enderror" id="department_campaign" name="department_campaign" required>
                        <option value="">Select campaign</option>
                        @foreach($campaigns as $campaign)
                            <option value="{{ $campaign->name }}" @selected($selectedCampaign === $campaign->name)>{{ $campaign->name }}</option>
                        @endforeach
                        @if($selectedCampaign && ! $campaigns->contains('name', $selectedCampaign))
                            <option value="{{ $selectedCampaign }}" selected>{{ $selectedCampaign }}</option>
                        @endif
                    </select>
                @error('department_campaign')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            @endif
            <div class="col-md">
                <div class="d-flex justify-content-between align-items-baseline gap-2">
                    <label class="form-label" for="delivery_location">Delivery location <span class="text-danger">*</span></label>
                    @can('viewAny', \App\Models\DeliveryLocation::class)
                        <button class="btn btn-sm btn-link p-0 builder-inline-link" type="button" data-bs-toggle="modal" data-bs-target="#manageLocationsModal">Manage Locations</button>
                    @endcan
                </div>
                <select class="form-select @error('delivery_location') is-invalid @enderror" id="delivery_location" name="delivery_location" required>
                    <option value="">Select location</option>
                    @foreach($locations as $location)
                        <option value="{{ $location->name }}" @selected($selectedLocation === $location->name)>{{ $location->name }}</option>
                    @endforeach
                    @if($selectedLocation && ! $locations->contains('name', $selectedLocation))
                        <option value="{{ $selectedLocation }}" selected>{{ $selectedLocation }} (historical)</option>
                    @endif
                </select>
                @error('delivery_location')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <label class="form-label" for="purpose">Purpose or reason <span class="text-danger">*</span></label>
                <textarea class="form-control @error('purpose') is-invalid @enderror" id="purpose" name="purpose" rows="2" maxlength="5000" required placeholder="Explain the business need and intended use.">{{ old('purpose', $purchaseRequest->purpose ?? '') }}</textarea>
                @error('purpose')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <details class="builder-optional-disclosure" @if(old('remarks', $purchaseRequest->remarks ?? '') !== '' || $errors->has('remarks')) open @endif>
                    <summary>Remarks <span>(optional)</span></summary>
                    <div class="builder-optional-content">
                        <label class="visually-hidden" for="remarks">Remarks</label>
                        <textarea class="form-control @error('remarks') is-invalid @enderror" id="remarks" name="remarks" rows="2" maxlength="5000" placeholder="Add any supporting context for reviewers.">{{ old('remarks', $purchaseRequest->remarks ?? '') }}</textarea>
                        @error('remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </details>
            </div>
        </div>
    </div>
</div>

@if($editing)
    <div class="card mb-4">
        <div class="card-header bg-white py-3">
            <div class="section-kicker">Purchase Mode</div>
            <div class="section-title">How will the requested items be entered?</div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="purchase-mode-option h-100 d-flex gap-2 align-items-start border rounded p-3" for="purchase-mode-manual">
                        <input class="form-check-input mt-1" id="purchase-mode-manual" type="radio" name="purchase_mode" value="manual" @checked($purchaseMode === 'manual')>
                        <span><strong class="d-block">Manual Items</strong><small class="text-muted">Add and price each requested line item individually.</small></span>
                    </label>
                </div>
                <div class="col-md-6">
                    <label class="purchase-mode-option h-100 d-flex gap-2 align-items-start border rounded p-3" for="purchase-mode-bulk">
                        <input class="form-check-input mt-1" id="purchase-mode-bulk" type="radio" name="purchase_mode" value="bulk" @checked($purchaseMode === 'bulk')>
                        <span><strong class="d-block">Bulk Purchase</strong><small class="text-muted">Group multiple requested items that one supplier may provide later during canvassing.</small></span>
                    </label>
                </div>
            </div>
            <div class="alert alert-light border mt-3 mb-0 d-none" id="bulk-purchase-help">
                <strong>Bulk Purchase:</strong> use the Add Item button below to place multiple items under this request. Supplier details and item-level supplier prices are captured in Canvassing.
                <button class="btn btn-sm btn-outline-primary ms-2" type="button" data-trigger-add-item>Add Item</button>
            </div>
        </div>
    </div>
@else
    <input type="hidden" name="purchase_mode" value="manual">
@endif

<div class="row g-4 align-items-start builder-workspace">
<div class="col-xl-8">
<section class="card builder-section-card items-builder-card mb-4" aria-labelledby="requested-items-heading">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="d-flex align-items-start gap-3">
            <span class="builder-section-number" aria-hidden="true">2</span>
            <div>
                <span class="section-kicker d-block">Purchase contents</span>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <h2 class="section-title d-flex align-items-center gap-2" id="requested-items-heading"><x-ui.icon name="cart" size="19" />Requested Items</h2>
                    <span class="item-requirement-badge" id="item-count-label"><strong id="item-count">{{ count($formItems) }}</strong>&nbsp;<span id="item-count-noun">{{ count($formItems) === 1 ? 'item' : 'items' }}</span></span>
                </div>
                <div class="small text-muted mt-1">Describe each item. Specifications and supporting files can be added when helpful.</div>
            </div>
        </div>
        <button class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1" id="add-item" type="button"><x-ui.icon name="plus" size="16" />Add item</button>
    </div>
    <div class="card-body items-builder-body" id="items-container">
        @error('items')<div class="alert alert-danger py-2" id="items-server-error">{{ $message }}</div>@enderror
        <div class="item-requirement-message d-none" id="items-validity-feedback" role="status">
            <x-ui.icon name="warning" size="17" />
            <span>Complete the required item details to enable submission. You can still save this request as a draft.</span>
        </div>
        <div class="visually-hidden" id="item-action-announcement" aria-live="assertive"></div>
        @foreach($formItems as $index => $item)
            @php $existingItem = $editing && isset($item['id']) ? $purchaseRequest->items->firstWhere('id', $item['id']) : null; @endphp
            <article class="item-row" data-item-row>
                <div class="item-row-header">
                    <div class="item-row-heading"><span class="item-number-chip" data-item-number>{{ $loop->iteration }}</span><strong>Item <span class="visually-hidden" data-item-number-text>{{ $loop->iteration }}</span></strong></div>
                    <button class="btn btn-sm btn-link text-danger text-decoration-none d-inline-flex align-items-center gap-1" type="button" data-remove-item aria-label="Remove item {{ $loop->iteration }}"><span aria-hidden="true">&times;</span>Remove</button>
                </div>
                <div class="item-row-body">
                    @if(isset($item['id']))<input type="hidden" name="items[{{ $index }}][id]" value="{{ $item['id'] }}">@endif
                    @php
                        $emailApprovals = $existingItem?->attachments->where('category', \App\Models\PurchaseRequestItem::ATTACHMENT_EMAIL_APPROVAL) ?? collect();
                        $itemPictures = $existingItem?->attachments->where('category', \App\Models\PurchaseRequestItem::ATTACHMENT_PICTURE) ?? collect();
                    @endphp
                    <div class="row g-3 align-items-start">
                        <div class="col-lg-6">
                            <label class="form-label" for="item-{{ $index }}-description">Item description <span class="text-danger">*</span></label>
                            <input class="form-control @error("items.$index.description") is-invalid @enderror" id="item-{{ $index }}-description" name="items[{{ $index }}][description]" value="{{ $item['description'] ?? '' }}" maxlength="2000" placeholder="e.g. Wireless presentation speaker" required>
                            @error("items.$index.description")<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6 col-lg-3">
                            <label class="form-label" for="item-{{ $index }}-quantity">Quantity <span class="text-danger">*</span></label>
                            <input class="form-control item-quantity @error("items.$index.quantity") is-invalid @enderror" id="item-{{ $index }}-quantity" name="items[{{ $index }}][quantity]" type="number" min="0.01" step="0.01" inputmode="decimal" value="{{ $item['quantity'] ?? '' }}" data-submit-required>
                            @error("items.$index.quantity")<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6 col-lg-3">
                            <label class="form-label" for="item-{{ $index }}-unit">Unit</label>
                            @php ($currentUnit = (string) ($item['unit'] ?? '')); @endphp
                            <select class="form-select @error("items.$index.unit") is-invalid @enderror" id="item-{{ $index }}-unit" name="items[{{ $index }}][unit]">
                                <option value="">Select unit</option>
                                @foreach($units as $unitValue => $unitLabel)
                                    <option value="{{ $unitValue }}" @selected($currentUnit === $unitValue)>{{ $unitLabel }}</option>
                                @endforeach
                                @if($currentUnit !== '' && ! array_key_exists($currentUnit, $units))
                                    <option value="{{ $currentUnit }}" selected>{{ $currentUnit }} (legacy)</option>
                                @endif
                            </select>
                            @error("items.$index.unit")<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="item-{{ $index }}-specifications">Quotation <span class="text-danger">*</span></label>
                            <input class="form-control @error("items.$index.brand_specifications") is-invalid @enderror" id="item-{{ $index }}-specifications" name="items[{{ $index }}][brand_specifications]" value="{{ $item['brand_specifications'] ?? '' }}" maxlength="5000" placeholder="Quoted brand, model, price, or specifications" data-submit-required>
                            @error("items.$index.brand_specifications")<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label d-flex align-items-center gap-1" for="item-{{ $index }}-attachment"><x-ui.icon name="paperclip" size="16" />Email approval <span class="text-danger">*</span></label>
                            <input class="form-control form-control-sm @error("items.$index.attachment") is-invalid @enderror" id="item-{{ $index }}-attachment" name="items[{{ $index }}][attachment]" type="file" accept=".pdf,.jpg,.jpeg,.png" @if($emailApprovals->isEmpty()) data-submit-required @endif>
                            <div class="form-text">Screenshot or PDF of the approved email. PDF/JPG/PNG, up to 5 MB.</div>
                            <div class="attachment-preview mt-2" data-attachment-preview aria-live="polite"></div>
                            @error("items.$index.attachment")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            @if($emailApprovals->isNotEmpty())
                                <div class="form-text">On file: {{ $emailApprovals->pluck('original_name')->join(', ') }}. Upload again only to add another.</div>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label class="form-label d-flex align-items-center gap-1" for="item-{{ $index }}-picture"><x-ui.icon name="paperclip" size="16" />Item picture <span class="text-danger">*</span></label>
                            <input class="form-control form-control-sm @error("items.$index.picture") is-invalid @enderror" id="item-{{ $index }}-picture" name="items[{{ $index }}][picture]" type="file" accept=".jpg,.jpeg,.png,.webp" @if($itemPictures->isEmpty()) data-submit-required @endif data-file-label="item picture">
                            <div class="form-text">Photo of the item. JPG/PNG/WEBP, up to 5 MB.</div>
                            <div class="attachment-preview mt-2" data-attachment-preview aria-live="polite"></div>
                            @error("items.$index.picture")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            @if($itemPictures->isNotEmpty())
                                <div class="form-text">On file: {{ $itemPictures->pluck('original_name')->join(', ') }}</div>
                            @endif
                        </div>
                        @if($editing)
                        <div class="col-7 col-md-3">
                            <label class="form-label" for="item-{{ $index }}-price">Estimated unit price</label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input class="form-control item-price @error("items.$index.estimated_unit_price") is-invalid @enderror" id="item-{{ $index }}-price" name="items[{{ $index }}][estimated_unit_price]" type="number" min="0" step="0.01" inputmode="decimal" value="{{ $item['estimated_unit_price'] ?? '' }}">
                            </div>
                            @error("items.$index.estimated_unit_price")<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>
                        @endif
                        <div class="col-12">
                        <details class="builder-optional-disclosure item-details-disclosure" @if($errors->has("items.$index.reference_url") || filled($item['reference_url'] ?? null)) open @endif>
                            <summary>More item details <span>(optional)</span></summary>
                            <div class="builder-optional-content row g-3">
                        <div class="col-12">
                            <label class="form-label d-flex align-items-center gap-1" for="item-{{ $index }}-link"><x-ui.icon name="link" size="16" />Link <span class="text-muted">(optional)</span></label>
                            <input class="form-control @error("items.$index.reference_url") is-invalid @enderror" id="item-{{ $index }}-link" name="items[{{ $index }}][reference_url]" type="url" value="{{ $item['reference_url'] ?? '' }}" placeholder="https://">
                            @error("items.$index.reference_url")<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                            </div>
                        </details>
                        </div>
                    </div>
                </div>
            </article>
        @endforeach
    </div>
</section>
</div>

<aside class="col-xl-4 order-xl-last" aria-label="Request summary and procurement workflow">
    <div class="builder-side-rail">
        <section class="card builder-summary-card mb-4" aria-labelledby="request-summary-heading">
            <div class="card-header">
                <div class="section-kicker">At a glance</div>
                <h2 class="section-title d-flex align-items-center gap-2" id="request-summary-heading"><x-ui.icon name="clipboard-check" size="19" />Request Summary</h2>
            </div>
            <div class="card-body">
                <dl class="builder-summary-list mb-0">
                    <div><dt>Items</dt><dd><span id="item-count-summary">{{ count($formItems) }}</span></dd></div>
                    <div><dt>Status</dt><dd>@if($editing)<x-status-badge :status="$purchaseRequest->status" />@else<span class="status-badge status-draft">Draft</span>@endif</dd></div>
                </dl>
                <p class="builder-estimate-note mb-0">Quantities and costs are confirmed during purchasing and canvassing.</p>
            </div>
        </section>

        <details class="card builder-workflow-card builder-workflow-disclosure mb-4" aria-labelledby="procurement-workflow-heading">
            <summary class="card-header">
                <span><span class="section-kicker">What happens next</span><span class="section-title d-flex align-items-center gap-2" id="procurement-workflow-heading"><x-ui.icon name="flow" size="19" />Procurement Workflow</span></span>
                <span class="builder-disclosure-chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="card-body">
                <ol class="builder-workflow-list">
                    <li class="is-current"><span>1</span><div><strong>Request</strong><small>Provide the business need and items.</small></div></li>
                    <li><span>2</span><div><strong>Department screening</strong><small>Screening route is assigned by IT or Premises.</small></div></li>
                    <li><span>3</span><div><strong>Purchasing &amp; canvassing</strong><small>Purchasing obtains comparable quotations.</small></div></li>
                    <li><span>4</span><div><strong>Recommendation</strong><small>Purchasing records its recommendation.</small></div></li>
                    <li><span>5</span><div><strong>President approval</strong><small>The President approves or returns the supplier review.</small></div></li>
                </ol>
                <div class="builder-workflow-note" role="note">
                    <x-ui.icon name="supplier" size="18" />
                    <span><strong>You do not select a supplier here.</strong> Supplier selection happens during Canvassing.</span>
                </div>
            </div>
        </details>
    </div>
</aside>
</div>

@unless($omReviewEdit)
    <section class="card builder-section-card builder-signature-card mb-4" aria-labelledby="request-submission-signature-heading">
        <div class="card-header">
            <div class="d-flex align-items-start gap-3">
                <span class="builder-section-number" aria-hidden="true">3</span>
                <div>
                    <span class="section-kicker d-block">Confirmation</span>
                    <h2 class="section-title d-flex align-items-center gap-2" id="request-submission-signature-heading"><x-ui.icon name="clipboard-check" size="19" />Sign to submit</h2>
                    <div class="small text-muted mt-1">Your signature confirms the request is accurate and approved.</div>
                </div>
            </div>
        </div>
        <div class="card-body">
            <x-workflow-signature-pad
                id="request-submission-signature"
                label="Requestor signature"
                instruction="Draw your signature, then select Submit Request. Saving a draft does not need a signature."
                data-signature-required-for="submit"
            />
        </div>
    </section>
@endunless

<div class="builder-action-bar">
    <div class="builder-action-bar-inner d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="fw-semibold">{{ $omReviewEdit ? 'Ready to save your review?' : 'Ready to continue?' }}</div>
            <div class="small text-muted" id="submission-readiness">{{ $omReviewEdit ? 'Changes remain at the current review stage.' : 'Add item descriptions to submit, or save your progress as a draft.' }}</div>
        </div>
        <div class="builder-actions d-flex flex-wrap justify-content-end gap-2">
            <a class="btn btn-outline-secondary" href="{{ $editing ? route('purchase-requests.show', $purchaseRequest) : route('purchase-requests.index') }}">Cancel</a>
            <button class="btn {{ $omReviewEdit ? 'btn-primary' : 'btn-outline-primary' }}" type="submit" name="action" value="save_draft" data-submit-action>{{ $omReviewEdit ? 'Save Review Changes' : 'Save Draft' }}</button>
            @unless($omReviewEdit)
                <button class="btn btn-primary" type="submit" name="action" value="submit" data-submit-action aria-describedby="submission-readiness">Submit Request</button>
            @endunless
        </div>
    </div>
</div>

<template id="item-template">
    <article class="item-row item-row--new" data-item-row>
        <div class="item-row-header">
            <div class="item-row-heading"><span class="item-number-chip" data-item-number></span><strong>Item <span class="visually-hidden" data-item-number-text></span></strong></div>
            <button class="btn btn-sm btn-link text-danger text-decoration-none d-inline-flex align-items-center gap-1" type="button" data-remove-item><span aria-hidden="true">&times;</span>Remove</button>
        </div>
        <div class="item-row-body"><div class="row g-3 align-items-start">
            <div class="col-lg-6"><label class="form-label" for="item-__INDEX__-description">Item description <span class="text-danger">*</span></label><input class="form-control" id="item-__INDEX__-description" name="items[__INDEX__][description]" maxlength="2000" placeholder="e.g. Wireless presentation speaker" required></div>
            <div class="col-6 col-lg-3"><label class="form-label" for="item-__INDEX__-quantity">Quantity <span class="text-danger">*</span></label><input class="form-control item-quantity" id="item-__INDEX__-quantity" name="items[__INDEX__][quantity]" type="number" min="0.01" step="0.01" inputmode="decimal" data-submit-required></div>
            <div class="col-6 col-lg-3"><label class="form-label" for="item-__INDEX__-unit">Unit</label><select class="form-select" id="item-__INDEX__-unit" name="items[__INDEX__][unit]"><option value="">Select unit</option>@foreach($units as $unitValue => $unitLabel)<option value="{{ $unitValue }}">{{ $unitLabel }}</option>@endforeach</select></div>
            <div class="col-12"><label class="form-label" for="item-__INDEX__-specifications">Quotation <span class="text-danger">*</span></label><input class="form-control" id="item-__INDEX__-specifications" name="items[__INDEX__][brand_specifications]" maxlength="5000" placeholder="Quoted brand, model, price, or specifications" data-submit-required></div>
            <div class="col-md-6"><label class="form-label d-flex align-items-center gap-1" for="item-__INDEX__-attachment"><x-ui.icon name="paperclip" size="16" />Email approval <span class="text-danger">*</span></label><input class="form-control form-control-sm" id="item-__INDEX__-attachment" name="items[__INDEX__][attachment]" type="file" accept=".pdf,.jpg,.jpeg,.png" data-submit-required><div class="form-text">Screenshot or PDF of the approved email. PDF/JPG/PNG, up to 5 MB.</div><div class="attachment-preview mt-2" data-attachment-preview aria-live="polite"></div></div>
            <div class="col-md-6"><label class="form-label d-flex align-items-center gap-1" for="item-__INDEX__-picture"><x-ui.icon name="paperclip" size="16" />Item picture <span class="text-danger">*</span></label><input class="form-control form-control-sm" id="item-__INDEX__-picture" name="items[__INDEX__][picture]" type="file" accept=".jpg,.jpeg,.png,.webp" data-submit-required data-file-label="item picture"><div class="form-text">Photo of the item. JPG/PNG/WEBP, up to 5 MB.</div><div class="attachment-preview mt-2" data-attachment-preview aria-live="polite"></div></div>
            @if($editing)
            <div class="col-7 col-md-3"><label class="form-label" for="item-__INDEX__-price">Estimated unit price</label><div class="input-group"><span class="input-group-text">&#8369;</span><input class="form-control item-price" id="item-__INDEX__-price" name="items[__INDEX__][estimated_unit_price]" type="number" min="0" step="0.01" inputmode="decimal"></div></div>
            @endif
            <div class="col-12"><details class="builder-optional-disclosure item-details-disclosure"><summary>More item details <span>(optional)</span></summary><div class="builder-optional-content row g-3">
            <div class="col-12"><label class="form-label d-flex align-items-center gap-1" for="item-__INDEX__-link"><x-ui.icon name="link" size="16" />Link <span class="text-muted">(optional)</span></label><input class="form-control" id="item-__INDEX__-link" name="items[__INDEX__][reference_url]" type="url" placeholder="https://"></div>
            </div></details></div>
        </div></div>
    </article>
</template>

<div class="builder-undo-toast" id="item-undo-toast" role="status" aria-live="polite" hidden>
    <span>Item removed.</span>
    <button class="btn btn-sm btn-link" type="button" id="undo-remove-item">Undo</button>
</div>

@can('viewAny', \App\Models\DeliveryLocation::class)
    <div class="modal fade" id="manageLocationsModal" tabindex="-1" aria-labelledby="manage-locations-heading" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h2 class="modal-title fs-5" id="manage-locations-heading">Manage Locations</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body">
                    <div class="alert d-none py-2" id="location-manager-message" role="status"></div>
                    <div class="input-group mb-3">
                        <input class="form-control" id="new-location-name" maxlength="255" placeholder="New location name" aria-label="New location name">
                        <button class="btn btn-primary" id="add-location" type="button">Add</button>
                    </div>
                    <div class="list-group location-manager-list" id="location-manager-list"><div class="text-muted small py-3">Loading locations…</div></div>
                </div>
                <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Done</button></div>
            </div>
        </div>
    </div>
@endcan

@push('scripts')
<script>
    (() => {
        const container = document.getElementById('items-container');
        const template = document.getElementById('item-template');
        const form = container.closest('form');
        const itemsFeedback = document.getElementById('items-validity-feedback');
        const requirementLabel = document.getElementById('item-count-label');
        const submitButton = form.querySelector('button[name="action"][value="submit"]');
        const readiness = document.getElementById('submission-readiness');
        const announcement = document.getElementById('item-action-announcement');
        const undoToast = document.getElementById('item-undo-toast');
        const undoButton = document.getElementById('undo-remove-item');
        const closeButton = document.querySelector('[data-close-purchase-request]');
        let nextIndex = {{ $formItems === [] ? 0 : max(array_keys($formItems)) + 1 }};
        let removedItem = null;
        let undoTimer = null;
        let hasUnsavedChanges = false;

        form.addEventListener('input', () => { hasUnsavedChanges = true; });
        form.addEventListener('change', () => { hasUnsavedChanges = true; });
        form.addEventListener('submit', () => { hasUnsavedChanges = false; });

        closeButton?.addEventListener('click', () => {
            if (hasUnsavedChanges && ! window.confirm('Close this purchase request and discard your unsaved changes?')) {
                return;
            }

            window.location.assign(closeButton.dataset.closeDestination);
        });

        function rows() {
            return [...container.querySelectorAll('[data-item-row]')];
        }

        // Fields a draft may leave blank but a submission needs (quantity, quotation, email approval).
        function submitFieldIsFilled(field) {
            return field.type === 'file' ? field.files.length > 0 : field.value.trim() !== '';
        }

        function itemIsComplete(row) {
            const description = row.querySelector('[name$="[description]"]').value.trim();
            return description !== '' && [...row.querySelectorAll('[data-submit-required]')].every(submitFieldIsFilled);
        }

        function updateSubmissionState(currentRows) {
            const hasItems = currentRows.length > 0;
            const completeItems = currentRows.every(itemIsComplete);
            const canSubmit = hasItems && completeItems;

            requirementLabel.classList.toggle('is-ready', canSubmit);
            requirementLabel.classList.toggle('is-warning', ! canSubmit);
            itemsFeedback.classList.toggle('d-none', canSubmit);

            if (! hasItems) {
                itemsFeedback.querySelector('span').textContent = 'Add an item to submit this purchase request.';
                readiness.textContent = 'Add an item to submit, or save your progress as a draft.';
            } else if (! completeItems) {
                itemsFeedback.querySelector('span').textContent = 'Give every item a description, quantity, quotation, email approval, and picture to enable submission. You can still save a draft.';
                readiness.textContent = 'Complete each item\'s description, quantity, quotation, email approval, and picture to enable submission.';
            } else {
                readiness.textContent = 'Your item list is ready. Submit it to IT / Premises screening or save it as a draft.';
            }

            if (submitButton) {
                submitButton.disabled = ! canSubmit;
                submitButton.setAttribute('aria-disabled', String(! canSubmit));
            }
        }

        function recalculate() {
            const currentRows = rows();
            currentRows.forEach((row, index) => {
                row.querySelectorAll('[data-item-number], [data-item-number-text]').forEach((number) => number.textContent = index + 1);
                const removeButton = row.querySelector('[data-remove-item]');
                removeButton.disabled = currentRows.length === 1;
                removeButton.setAttribute('aria-label', `Remove item ${index + 1}`);
            });
            document.getElementById('item-count').textContent = currentRows.length;
            document.getElementById('item-count-noun').textContent = currentRows.length === 1 ? 'item' : 'items';
            document.getElementById('item-count-summary').textContent = currentRows.length;
            updateSubmissionState(currentRows);
        }

        document.getElementById('add-item').addEventListener('click', () => {
            container.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', nextIndex++));
            recalculate();
            const newRow = rows().at(-1);
            const description = newRow.querySelector('[name$="[description]"]');
            announcement.textContent = `Item ${rows().length} added.`;
            requestAnimationFrame(() => {
                newRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                description.focus({ preventScroll: true });
                window.setTimeout(() => newRow.classList.remove('item-row--new'), 700);
            });
        });

        document.querySelectorAll('input[name="purchase_mode"]').forEach((radio) => {
            radio.addEventListener('change', () => {
                document.getElementById('bulk-purchase-help')?.classList.toggle('d-none', radio.value !== 'bulk' || !radio.checked);
                document.querySelectorAll('.purchase-mode-option').forEach((option) => {
                    option.classList.toggle('is-selected', option.htmlFor === radio.id && radio.checked);
                });
            });
        });
        document.querySelector('[data-trigger-add-item]')?.addEventListener('click', () => document.getElementById('add-item')?.click());
        document.querySelector('input[name="purchase_mode"]:checked')?.dispatchEvent(new Event('change'));

        container.addEventListener('click', (event) => {
            const button = event.target.closest('[data-remove-item]');
            if (! button) return;
            if (rows().length === 1) return;
            const row = button.closest('[data-item-row]');
            removedItem = { row, next: row.nextElementSibling };
            row.remove();
            announcement.textContent = 'Item removed. Undo is available.';
            undoToast.hidden = false;
            window.clearTimeout(undoTimer);
            undoTimer = window.setTimeout(() => {
                undoToast.hidden = true;
                removedItem = null;
            }, 8000);
            recalculate();
        });

        undoButton.addEventListener('click', () => {
            if (! removedItem) return;
            if (removedItem.next?.isConnected) container.insertBefore(removedItem.row, removedItem.next);
            else container.appendChild(removedItem.row);
            undoToast.hidden = true;
            announcement.textContent = 'Removed item restored.';
            removedItem.row.querySelector('[name$="[description]"]').focus();
            removedItem = null;
            window.clearTimeout(undoTimer);
            recalculate();
        });

        function clearClientError(field) {
            field.setCustomValidity('');
            field.classList.remove('is-invalid');
            field.removeAttribute('aria-invalid');
            field.closest('[class*="col-"]')?.querySelector('.client-validation-message')?.remove();
        }

        function showClientError(field) {
            field.classList.add('is-invalid');
            field.setAttribute('aria-invalid', 'true');
            const wrapper = field.closest('[class*="col-"]') || field.parentElement;
            if (! wrapper.querySelector('.client-validation-message')) {
                const message = document.createElement('div');
                message.className = 'client-validation-message';
                message.textContent = field.validationMessage || 'Complete this required field.';
                wrapper.append(message);
            }
        }

        function validateBeforeSubmit(action) {
            let valid = true;
            const currentRows = rows();
            if (action === 'submit') {
                form.querySelectorAll('[data-submit-required]:not([disabled])').forEach((field) => {
                    clearClientError(field);
                    if (! submitFieldIsFilled(field)) {
                        field.setCustomValidity(field.type === 'file' ? `Attach the ${field.dataset.fileLabel ?? 'email approval'}.` : 'Complete this required field.');
                        showClientError(field);
                    }
                });
            }
            if (action === 'submit' && (currentRows.length < 1 || ! currentRows.every(itemIsComplete))) {
                itemsFeedback.classList.remove('d-none');
                (form.querySelector('[data-item-row] .is-invalid') ?? document.getElementById('requested-items-heading')).scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            form.querySelectorAll('input[required]:not([disabled]), select[required]:not([disabled]), textarea[required]:not([disabled])').forEach((field) => {
                clearClientError(field);
                if (field.matches('input[type="text"], textarea') && field.value.trim() === '') {
                    field.setCustomValidity(field.matches('[name$="[description]"]') ? 'Enter an item description.' : 'Complete this required field.');
                }
                if (! field.checkValidity()) {
                    showClientError(field);
                    valid = false;
                }
            });

            if (! valid) form.querySelector('.is-invalid')?.focus();
            return valid;
        }

        container.addEventListener('input', (event) => {
            clearClientError(event.target);
            recalculate();
        });
        container.addEventListener('change', (event) => {
            clearClientError(event.target);
            recalculate();
        });
        form.addEventListener('input', (event) => {
            if (! event.target.closest('[data-item-row]')) clearClientError(event.target);
        });

        form.addEventListener('submit', (event) => {
            const action = event.submitter?.value;
            if (! validateBeforeSubmit(action)) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }
            if (action === 'submit' && ! window.confirm('Submit this request for IT / Premises screening?')) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }
            if (form.classList.contains('is-submitting')) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = action;
            form.append(actionInput);
            form.classList.add('is-submitting');
            form.querySelectorAll('[data-submit-action]').forEach((button) => button.disabled = true);
            if (event.submitter) event.submitter.textContent = action === 'submit' ? 'Submitting request…' : 'Saving draft…';
        });

        recalculate();
    })();

    (() => {
        const modal = document.getElementById('manageLocationsModal');
        if (! modal) return;
        const endpoint = @js(route('delivery-locations.index'));
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const list = document.getElementById('location-manager-list');
        const message = document.getElementById('location-manager-message');
        const locationSelect = document.getElementById('delivery_location');
        const newName = document.getElementById('new-location-name');
        let locations = [];

        function showMessage(text, type = 'success') {
            message.textContent = text;
            message.className = `alert alert-${type} py-2`;
        }

        async function request(url, options = {}) {
            const response = await fetch(url, {
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                ...options,
            });
            const data = await response.json().catch(() => ({}));
            if (! response.ok) throw new Error(data.errors?.name?.[0] || data.message || 'Unable to update locations.');
            return data;
        }

        function refreshSelect(selectName = null) {
            const preserved = selectName ?? locationSelect.value;
            const activeNames = locations.filter((location) => location.is_active).map((location) => location.name);
            locationSelect.replaceChildren(new Option('Select location', ''));
            activeNames.forEach((name) => locationSelect.add(new Option(name, name)));
            if (preserved && ! activeNames.includes(preserved)) locationSelect.add(new Option(`${preserved} (historical)`, preserved));
            locationSelect.value = preserved;
        }

        function render() {
            list.replaceChildren();
            if (! locations.length) {
                const empty = document.createElement('div');
                empty.className = 'text-muted small py-3';
                empty.textContent = 'No locations have been added.';
                list.append(empty);
                return;
            }
            locations.forEach((location) => {
                const row = document.createElement('div');
                row.className = 'list-group-item d-flex justify-content-between align-items-center gap-3';
                const copy = document.createElement('div');
                copy.className = 'min-w-0';
                const name = document.createElement('div');
                name.className = 'fw-semibold text-break';
                name.textContent = location.name;
                const meta = document.createElement('div');
                meta.className = 'small text-muted';
                meta.textContent = `${location.is_active ? 'Active' : 'Archived'} · ${location.references} referenced request(s)`;
                copy.append(name, meta);
                const actions = document.createElement('div');
                actions.className = 'd-flex gap-1 flex-shrink-0';
                const edit = document.createElement('button');
                edit.type = 'button'; edit.className = 'btn btn-sm btn-outline-primary'; edit.textContent = 'Edit';
                edit.addEventListener('click', async () => {
                    const value = window.prompt('Location name', location.name);
                    if (value === null) return;
                    try {
                        const data = await request(`${endpoint}/${location.id}`, { method: 'PATCH', body: JSON.stringify({ name: value }) });
                        showMessage(data.message); await load();
                    } catch (error) { showMessage(error.message, 'danger'); }
                });
                const remove = document.createElement('button');
                remove.type = 'button'; remove.className = 'btn btn-sm btn-outline-danger'; remove.textContent = 'Delete';
                remove.addEventListener('click', async () => {
                    if (! window.confirm(`Delete ${location.name}? Referenced locations will be archived instead.`)) return;
                    try {
                        const data = await request(`${endpoint}/${location.id}`, { method: 'DELETE' });
                        showMessage(data.message, data.archived ? 'warning' : 'success'); await load();
                    } catch (error) { showMessage(error.message, 'danger'); }
                });
                actions.append(edit, remove); row.append(copy, actions); list.append(row);
            });
        }

        async function load(selectName = null) {
            const data = await request(endpoint);
            locations = data.locations;
            refreshSelect(selectName);
            render();
        }

        modal.addEventListener('show.bs.modal', () => load().catch((error) => showMessage(error.message, 'danger')));
        document.getElementById('add-location').addEventListener('click', async () => {
            try {
                const data = await request(endpoint, { method: 'POST', body: JSON.stringify({ name: newName.value }) });
                newName.value = '';
                showMessage(data.message);
                await load(data.location.name);
            } catch (error) { showMessage(error.message, 'danger'); }
        });
    })();
</script>
@endpush
