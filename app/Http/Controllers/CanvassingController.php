<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Services\PurchaseRequestWorkflow;
use App\Support\WorkflowSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CanvassingController extends Controller
{
    public function submitWithoutQuotation(
        Request $request,
        PurchaseRequest $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
    ): RedirectResponse {
        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $purchaseRequest, $workflow, $validated): void {
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->authorize('manageCanvassing', $locked);
            $screening = $request->user()->hasAnyRole([RoleSlug::ItStaff->value, RoleSlug::PremisesStaff->value])
                && $request->user()->can('recommendScreeningQuotation', $locked);
            if (! in_array($locked->status, [PurchaseRequestStatus::ForProcurement, PurchaseRequestStatus::ForCanvassing], true)) {
                throw ValidationException::withMessages(['status' => 'This request is no longer awaiting canvassing.']);
            }
            $count = $locked->quotations()->count();
            if (! $screening) {
                $locked->forceFill(['selected_supplier_id' => null])->save();
                $locked->quotations()->update(['is_approved' => false, 'approved_by' => null, 'approved_at' => null]);
            }
            $nextStatus = $screening ? PurchaseRequestStatus::ForCanvassing : PurchaseRequestStatus::ForSupplierApproval;
            $workflow->transition(
                $locked,
                $nextStatus,
                $request->user(),
                $screening ? 'SCREENING_SUBMITTED_WITHOUT_QUOTATION' : ($count ? 'CANVASSING_SUBMITTED_WITHOUT_ADDITIONAL_QUOTATION' : 'CANVASSING_SUBMITTED_WITHOUT_QUOTATION'),
                $validated['remarks'] ?? ($screening ? 'Screening submitted the request to Canvassing without adding a quotation.' : 'Purchasing submitted the request to the President without adding a quotation.'),
                $screening ? 'IT_PREMISES_QUOTATION' : 'PURCHASING',
                ['without_quotation' => $count === 0, 'quotation_count' => $count],
            );
        });

        // Preserve the browser's HTTPS origin even when a proxy forwards HTTP to PHP.
        return ($request->boolean('_embedded') ? redirect()->away(route('workflow.done', [], false)) : redirect()->route('purchase-requests.show', $purchaseRequest))
            ->with('success', $purchaseRequest->request_number.': submitted without adding a quotation.');
    }

    public function storeQuotations(
        Request $request,
        PurchaseRequest $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
    ): RedirectResponse {
        $request->validate(['quotations' => ['nullable', 'array', 'max:50'], 'quotations.*' => ['array']]);
        // The Add quotation button may leave an unused empty row in the form.
        // Ignore only rows with no quotation data; partially completed rows remain
        // subject to the normal validation rules below.
        $request->merge([
            'quotations' => collect($request->input('quotations', []))
                ->filter(function (array $quotation): bool {
                    return filled($quotation['supplier_name'] ?? null)
                        || filled($quotation['quoted_amount'] ?? null)
                        || filled($quotation['delivery_lead_time'] ?? null)
                        || filled($quotation['warranty'] ?? null)
                        || filled($quotation['quotation_url'] ?? null)
                        || filled($quotation['bank_details'] ?? null)
                        || filled($quotation['remarks'] ?? null)
                        || filter_var($quotation['recommended'] ?? false, FILTER_VALIDATE_BOOLEAN)
                        || collect($quotation['items'] ?? [])->contains(fn ($item) => ! is_array($item)
                            || filled($item['item_id'] ?? null)
                            || (filled($item['quantity'] ?? null) && (string) $item['quantity'] !== '1')
                            || (filled($item['unit_price'] ?? null) && (string) $item['unit_price'] !== '0'));
                })
                ->values()
                ->all(),
        ]);

        if ($request->input('quotations') === []) {
            $request->validate(['confirmed_without_quotation' => ['required', 'accepted']], [
                'confirmed_without_quotation.required' => 'Confirm that you want to proceed without adding a quotation.',
                'confirmed_without_quotation.accepted' => 'Confirm that you want to proceed without adding a quotation.',
            ]);

            return $this->submitWithoutQuotation($request, $purchaseRequest, $workflow);
        }

        $validated = $request->validate([
            'quotation_mode' => ['required', Rule::in(['manual', 'bulk'])],
            'quotations' => ['required', 'array', 'min:1'],
            'quotations.*.supplier_name' => ['required', 'string', 'max:255'],
            'quotations.*.quoted_amount' => [Rule::requiredIf($request->input('quotation_mode') === 'manual'), 'nullable', 'numeric', 'min:0'],
            'quotations.*.vat_treatment' => ['required', Rule::in(SupplierQuotation::VAT_TREATMENTS)],
            'quotations.*.delivery_lead_time' => ['required', 'string', 'max:255'],
            'quotations.*.delivery_location' => ['required', 'string', Rule::in(SupplierQuotation::DELIVERY_LOCATIONS)],
            'quotations.*.warranty' => ['required', 'string', 'max:255'],
            'quotations.*.quotation_url' => ['required', 'url', 'max:500'],
            'quotations.*.bank_details' => ['required', 'string', 'max:2000'],
            'quotations.*.remarks' => ['nullable', 'string', 'max:2000'],
            'quotations.*.recommended' => ['nullable', Rule::in(['0', '1', true, false, 'true', 'false'])],
            'quotations.*.items' => [Rule::requiredIf($request->input('quotation_mode') === 'bulk'), 'nullable', 'array', 'max:50'],
            'quotations.*.items.*.item_id' => ['required', 'integer'],
            'quotations.*.items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'quotations.*.items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ], [], [
            'quotations.*.supplier_name' => 'supplier',
            'quotations.*.quoted_amount' => 'amount',
            'quotations.*.vat_treatment' => 'VAT type',
            'quotations.*.delivery_lead_time' => 'lead time',
            'quotations.*.delivery_location' => 'delivery location',
            'quotations.*.warranty' => 'warranty',
            'quotations.*.quotation_url' => 'quotation URL',
            'quotations.*.bank_details' => 'supplier bank details',
            'quotations.*.remarks' => 'remarks',
            'quotations.*.items' => 'quoted items',
            'quotations.*.items.*.item_id' => 'requested item',
            'quotations.*.items.*.quantity' => 'quantity',
            'quotations.*.items.*.unit_price' => 'unit price',
        ]);
        $saveResult = DB::transaction(function () use ($request, $purchaseRequest, $workflow, $validated): array {
            $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->authorize('manageCanvassing', $lockedRequest);

            $isScreeningQuotation = $request->user()->hasAnyRole([RoleSlug::ItStaff->value, RoleSlug::PremisesStaff->value])
                && $request->user()->can('recommendScreeningQuotation', $lockedRequest);

            if (! in_array($lockedRequest->status, [
                PurchaseRequestStatus::ForProcurement,
                PurchaseRequestStatus::ForCanvassing,
                PurchaseRequestStatus::ForSupplierApproval,
            ], true)) {
                throw ValidationException::withMessages(['status' => 'This request is not awaiting quotations, canvassing, or supplier review.']);
            }

            if ($lockedRequest->status === PurchaseRequestStatus::ForProcurement
                && ! $isScreeningQuotation
                && ! $request->user()->hasRole(RoleSlug::PurchasingTeam->value)) {
                throw ValidationException::withMessages(['status' => 'Only the assigned screener or Purchasing Team may record quotations for this request.']);
            }

            if (! $isScreeningQuotation && in_array($lockedRequest->status, [PurchaseRequestStatus::ForCanvassing, PurchaseRequestStatus::ForSupplierApproval], true)) {
                // Revisions reuse the existing quotation rows. This keeps the
                // legacy schema idempotent and prevents repeated submissions
                // from doubling supplier totals.
                $lockedRequest->update(['selected_supplier_id' => null]);
                $lockedRequest->quotations()->update([
                    'is_recommended' => false,
                    'is_approved' => false,
                    'approved_by' => null,
                    'approved_at' => null,
                ]);
            }

            $submittedSupplierNames = collect($validated['quotations'])
                ->map(fn (array $quote) => mb_strtolower(trim((string) $quote['supplier_name'])));

            if ($submittedSupplierNames->count() !== $submittedSupplierNames->unique()->count()) {
                throw ValidationException::withMessages([
                    'quotations' => 'Submit one quotation per supplier. Use one bulk quotation for multiple requested-item lines from the same supplier.',
                ]);
            }

            $recommendedSupplierNames = collect($validated['quotations'])
                ->filter(fn (array $quote) => filter_var($quote['recommended'] ?? false, FILTER_VALIDATE_BOOLEAN))
                ->map(fn (array $quote) => mb_strtolower(trim((string) $quote['supplier_name'])))
                ->unique()
                ->values();

            if (! $isScreeningQuotation && $recommendedSupplierNames->count() !== 1) {
                throw ValidationException::withMessages([
                    'quotations' => 'Mark exactly one supplier as the recommendation before submitting for President approval.',
                ]);
            }

            $recommendedSupplierName = $isScreeningQuotation ? null : $recommendedSupplierNames->first();
            $recommendedSupplierId = null;
            $savedQuotationIds = [];

            foreach ($validated['quotations'] as $quote) {
                $supplierName = trim((string) ($quote['supplier_name'] ?? ''));
                if ($supplierName === '') {
                    throw ValidationException::withMessages(['quotations' => 'Each quotation must include a supplier name.']);
                }

                $supplier = Supplier::query()->firstOrCreate([
                    'name' => $supplierName,
                ], [
                    'is_active' => true,
                ]);

                $isRecommended = $recommendedSupplierName !== null
                    && mb_strtolower($supplierName) === $recommendedSupplierName;
                if ($isRecommended) {
                    $recommendedSupplierId = $supplier->id;
                }

                $common = [
                    'supplier_id' => $supplier->id,
                    'created_by' => $request->user()->id,
                    'brand_quality' => $quote['warranty'] ?? null,
                    'delivery_terms' => $quote['delivery_lead_time'] ?? null,
                    'remarks' => $quote['remarks'] ?? null,
                    'quotation_url' => $quote['quotation_url'] ?? null,
                    'bank_details' => $quote['bank_details'] ?? null,
                    'delivery_lead_time' => $quote['delivery_lead_time'] ?? null,
                    'delivery_location' => $quote['delivery_location'] ?? null,
                    'warranty' => $quote['warranty'] ?? null,
                    'is_recommended' => false,
                    'is_approved' => false,
                    'quoted_at' => now()->toDateString(),
                ];
                $bulkItems = $quote['items'] ?? [];

                if ($bulkItems !== []) {
                    $itemIds = collect($bulkItems)->pluck('item_id')->map(fn ($id) => (int) $id);
                    if ($itemIds->count() !== $itemIds->unique()->count()) {
                        throw ValidationException::withMessages(['quotations' => 'Each requested item may appear only once in a bulk supplier quotation.']);
                    }

                    $requestItems = $lockedRequest->items()->whereIn('id', $itemIds)->get()->keyBy('id');
                    if ($requestItems->count() !== $itemIds->count()) {
                        throw ValidationException::withMessages(['quotations' => 'Bulk quotations may only include items from this purchase request.']);
                    }

                    foreach ($bulkItems as $bulkItem) {
                        $requestedItem = $requestItems->get((int) $bulkItem['item_id']);
                        $lineTotal = round((float) $bulkItem['quantity'] * (float) $bulkItem['unit_price'], 2);
                        $quotation = $lockedRequest->quotations()->where('supplier_id', $supplier->id)->where('purchase_request_item_id', $requestedItem->id)->latest('id')->first()
                            ?? $lockedRequest->quotations()->where('supplier_id', $supplier->id)->whereNull('purchase_request_item_id')->latest('id')->first()
                            ?? $lockedRequest->quotations()->make();
                        $totals = $this->vatTotals($lineTotal, $quote['vat_treatment'] ?? 'EXCLUSIVE');
                        $quotation->fill([
                            ...$common,
                            'purchase_request_item_id' => $requestedItem->id,
                            'item_or_service' => $requestedItem->description,
                            'price' => $lineTotal,
                            ...$totals,
                        ])->save();
                        $savedQuotationIds[] = $quotation->id;
                    }
                } else {
                    if (! array_key_exists('quoted_amount', $quote) || $quote['quoted_amount'] === null) {
                        throw ValidationException::withMessages(['quotations' => 'Each manual quotation must include a quoted amount.']);
                    }

                    $quotation = $lockedRequest->quotations()->where('supplier_id', $supplier->id)->latest('id')->first() ?? $lockedRequest->quotations()->make();
                    $amount = (float) $quote['quoted_amount'];
                    $quotation->fill([
                        ...$common,
                        'item_or_service' => $lockedRequest->title,
                        'price' => $amount,
                        ...$this->vatTotals($amount, $quote['vat_treatment'] ?? 'EXCLUSIVE'),
                    ])->save();
                    $savedQuotationIds[] = $quotation->id;
                }
            }

            if ($recommendedSupplierId !== null) {
                $lockedRequest->quotations()->update([
                    'is_recommended' => false,
                    'recommended_by' => $request->user()->id,
                    'recommended_at' => now(),
                ]);
                $lockedRequest->quotations()->where('supplier_id', $recommendedSupplierId)->update([
                    'is_recommended' => true,
                    'recommended_by' => $request->user()->id,
                    'recommended_at' => now(),
                ]);
            }

            if ($isScreeningQuotation) {
                $quotationCount = count($validated['quotations']);
                $team = $lockedRequest->request_type === 'IT' ? 'IT' : 'Premises';
                $workflow->transition(
                    $lockedRequest,
                    PurchaseRequestStatus::ForCanvassing,
                    $request->user(),
                    'SCREENING_QUOTATIONS_RECORDED',
                    "{$team} completed {$quotationCount} supplier quotation(s) and submitted the request to canvassing.",
                    'IT_PREMISES_QUOTATION',
                    ['screening_team' => strtoupper($team), 'quotation_count' => $quotationCount],
                );

                return ['is_screening' => true, 'quotation_ids' => array_values(array_unique($savedQuotationIds))];
            }

            $workflow->transition(
                $lockedRequest,
                PurchaseRequestStatus::ForSupplierApproval,
                $request->user(),
                'CANVASSING_UPDATED',
                'Purchasing submitted supplier recommendation for President approval.',
                'PURCHASING',
            );

            return ['is_screening' => false, 'quotation_ids' => array_values(array_unique($savedQuotationIds))];
        });

        $quotationReferences = collect($saveResult['quotation_ids'])
            ->map(fn (int $id) => 'Q-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT))
            ->implode(', ');
        $screeningQuotationSaved = $saveResult['is_screening'];

        // Submitting the recommendation hands the request to the President; that page closes to a receipt.
        // Use a relative Location so HTTPS does not depend on proxy scheme detection.
        if (! $screeningQuotationSaved) {
            return ($request->boolean('_embedded') ? redirect()->away(route('workflow.done', [], false)) : redirect()->route('purchase-requests.show', $purchaseRequest))
                ->with('success', "Quotation {$quotationReferences} saved and submitted for President approval.");
        }

        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->withFragment('supplier-quotations')
            ->with('quotation_saved', true)
            ->with('success', $screeningQuotationSaved
                ? "Quotation {$quotationReferences} saved and submitted to canvassing."
                : "Quotation {$quotationReferences} saved and submitted for President approval.");
    }

    public function updateQuotation(Request $request, PurchaseRequest $purchaseRequest, SupplierQuotation $quotation): RedirectResponse
    {
        abort_unless($quotation->purchase_request_id === $purchaseRequest->id, 404);
        abort_unless($quotation->created_by === $request->user()->id, 403);
        abort_if($quotation->is_approved || ! in_array($purchaseRequest->status, [
            PurchaseRequestStatus::ForItScreening,
            PurchaseRequestStatus::ForPremisesScreening,
            PurchaseRequestStatus::ForProcurement,
            PurchaseRequestStatus::ForCanvassing,
        ], true), 403);

        try {
            $validated = $request->validate([
                'supplier_name' => ['required', 'string', 'max:255'],
                'quoted_amount' => ['required', 'numeric', 'min:0'],
                'vat_treatment' => ['nullable', Rule::in(SupplierQuotation::VAT_TREATMENTS)],
                'delivery_location' => ['nullable', 'string', Rule::in(SupplierQuotation::DELIVERY_LOCATIONS)],
                'delivery_lead_time' => ['nullable', 'string', 'max:255'],
                'warranty' => ['nullable', 'string', 'max:255'],
                'quotation_url' => ['nullable', 'url', 'max:2048'],
                'bank_details' => ['nullable', 'string', 'max:2000'],
                'remarks' => ['nullable', 'string', 'max:2000'],
            ]);
        } catch (ValidationException $exception) {
            return back()
                ->withFragment('supplier-quotations')
                ->withErrors($exception->errors())
                ->withInput()
                ->with('quotation_edit_failed', $quotation->id);
        }

        DB::transaction(function () use ($quotation, $validated): void {
            $supplier = Supplier::query()->firstOrCreate([
                'name' => trim($validated['supplier_name']),
            ], [
                'is_active' => true,
            ]);

            $amount = (float) $validated['quoted_amount'];
            $quotation->update([
                'supplier_id' => $supplier->id,
                'price' => $amount,
                ...$this->vatTotals($amount, $validated['vat_treatment'] ?? 'EXCLUSIVE'),
                ...collect($validated)->except(['supplier_name', 'quoted_amount', 'vat_treatment'])->all(),
            ]);
        });

        $reference = 'Q-'.str_pad((string) $quotation->id, 5, '0', STR_PAD_LEFT);

        return back()->withFragment('supplier-quotations')->with('success', "Quotation {$reference} was updated. The saved quotation count is unchanged.");
    }
    /** @return array{vat_treatment: string, base_amount: float, vat_rate: float, vat_amount: float, total_amount: float} */
    private function vatTotals(float $amount, string $treatment): array
    {
        $treatment = in_array($treatment, SupplierQuotation::VAT_TREATMENTS, true) ? $treatment : 'EXCLUSIVE';
        $amount = round($amount, 2);

        if ($treatment === 'INCLUSIVE') {
            $base = round($amount / (1 + SupplierQuotation::VAT_RATE / 100), 2);
            $vat = round($amount - $base, 2);

            return ['vat_treatment' => $treatment, 'base_amount' => $base, 'vat_rate' => SupplierQuotation::VAT_RATE, 'vat_amount' => $vat, 'total_amount' => $amount];
        }

        if ($treatment === 'EXEMPT') {
            return ['vat_treatment' => $treatment, 'base_amount' => $amount, 'vat_rate' => 0.0, 'vat_amount' => 0.0, 'total_amount' => $amount];
        }

        $vat = round($amount * (SupplierQuotation::VAT_RATE / 100), 2);

        return ['vat_treatment' => 'EXCLUSIVE', 'base_amount' => $amount, 'vat_rate' => SupplierQuotation::VAT_RATE, 'vat_amount' => $vat, 'total_amount' => round($amount + $vat, 2)];
    }
    public function recommendQuotation(
        Request $request,
        PurchaseRequest $purchaseRequest,
        SupplierQuotation $quotation,
        PurchaseRequestWorkflow $workflow,
    ): RedirectResponse {
        // Canvassing and the supplier recommendation belong to Purchasing.
        // IT / Premises contribute quotations only.
        abort_if(
            $request->user()->hasAnyRole([RoleSlug::ItStaff->value, RoleSlug::PremisesStaff->value])
                && ! $request->user()->hasAnyRole([RoleSlug::CanvassingStaff->value, RoleSlug::Purchasing->value, RoleSlug::PurchasingTeam->value, RoleSlug::ProcurementStaff->value, RoleSlug::Admin->value]),
            403,
            'The Purchasing team handles the supplier recommendation. You can add quotations only.',
        );

        DB::transaction(function () use ($request, $purchaseRequest, $quotation, $workflow): void {
            $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $quote = $lockedRequest->quotations()->whereKey($quotation->id)->with('supplier')->firstOrFail();

            $this->authorize('manageCanvassing', $lockedRequest);
            if (! $request->user()->hasAnyRole([RoleSlug::CanvassingStaff->value, RoleSlug::Purchasing->value, RoleSlug::PurchasingTeam->value, RoleSlug::ProcurementStaff->value, RoleSlug::Admin->value])
                || ! in_array($lockedRequest->status, [PurchaseRequestStatus::ForProcurement, PurchaseRequestStatus::ForCanvassing], true)) {
                abort(403);
            }

            $lockedRequest->quotations()->update(['is_recommended' => false, 'is_approved' => false, 'recommended_by' => $request->user()->id, 'recommended_at' => now()]);
            $lockedRequest->quotations()->where('supplier_id', $quote->supplier_id)->update(['is_recommended' => true, 'recommended_by' => $request->user()->id, 'recommended_at' => now()]);
            $workflow->transition(
                $lockedRequest,
                PurchaseRequestStatus::ForSupplierApproval,
                $request->user(),
                'CANVASSING_UPDATED',
                'Purchasing submitted supplier recommendation for President approval.',
                'PURCHASING',
            );
        });

        return redirect()->route('purchase-requests.show', $purchaseRequest);
    }

    public function approveSupplier(
        Request $request,
        PurchaseRequest $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
    ): RedirectResponse {
        // Kept as a backwards-compatible route, but supplier approval belongs to
        // the President. Purchasing may only submit a recommendation.
        abort_unless($request->user()?->hasAnyRole([RoleSlug::President->value, RoleSlug::Admin->value]), 403);

        $validated = $request->validate([
            'quotation_id' => ['required', 'integer'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $purchaseRequest, $workflow, $validated) {
            $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->authorize('reviewPresident', $lockedRequest);

            if ($lockedRequest->status !== PurchaseRequestStatus::ForSupplierApproval) {
                throw ValidationException::withMessages(['status' => 'This request is no longer waiting on President supplier approval.']);
            }

            $quotation = $lockedRequest->quotations()
                ->with('supplier')
                ->whereKey((int) $validated['quotation_id'])
                ->whereHas('supplier', fn ($query) => $query->where('is_active', true))
                ->first();

            if (! $quotation) {
                throw ValidationException::withMessages(['quotation_id' => 'The chosen quotation must belong to this request and an active supplier.']);
            }

            $lockedRequest->update([
                'selected_supplier_id' => $quotation->supplier_id,
            ]);
            $lockedRequest->quotations()->update([
                'is_approved' => false,
                'approved_by' => null,
                'approved_at' => null,
            ]);
            $lockedRequest->quotations()->where('supplier_id', $quotation->supplier_id)->update([
                'is_approved' => true,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            $workflow->transition(
                $lockedRequest,
                PurchaseRequestStatus::ForBudgetProcessing,
                $request->user(),
                'PRESIDENT_SUPPLIER_APPROVED',
                $validated['remarks'] ?? 'President approved the selected supplier.',
                'PRESIDENT_SUPPLIER_APPROVAL',
            );
        });

        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->with('success', 'President supplier approval recorded and the budget workflow has started.');
    }
}
