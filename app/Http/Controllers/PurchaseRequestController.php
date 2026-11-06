<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Http\Requests\StorePurchaseRequestRequest;
use App\Http\Requests\UpdatePurchaseRequestRequest;
use App\Models\Campaign;
use App\Models\AuditLog;
use App\Models\DeliveryLocation;
use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Role;
use App\Models\SupplierQuotation;
use App\Models\User;
use App\Services\PurchaseRequestWorkflow;
use App\Support\WorkflowSignature;
use App\Support\RequestStageReceipt;
use App\Support\PurchaseOrderActionQueue;
use App\Support\PurchaseOrderDocumentData;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseRequestController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', PurchaseRequest::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(array_map(fn (PurchaseRequestStatus $status) => $status->value, PurchaseRequestStatus::cases()))],
            'summary' => ['nullable', Rule::in(['pending', 'approved', 'disapproved'])],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')],
            'priority' => ['nullable', Rule::in(array_keys(PurchaseRequest::PRIORITY_LEVELS))],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $visibleQuery = PurchaseRequest::query()->visibleTo($request->user());
        // A draft is private to its requestor until submitted; staff lists and counts skip it.
        if (! $request->user()->hasRole(RoleSlug::Admin->value)) {
            $visibleQuery->where(fn ($query) => $query
                ->where('status', '!=', PurchaseRequestStatus::Draft->value)
                ->orWhere('requestor_id', $request->user()->id));
        }
        $pendingStatuses = PurchaseRequestStatus::valuesInSummaryGroup('pending');
        $approvedStatuses = PurchaseRequestStatus::valuesInSummaryGroup('approved');
        $disapprovedStatuses = PurchaseRequestStatus::valuesInSummaryGroup('disapproved');
        $summaryCounts = [
            'all' => (clone $visibleQuery)->count(),
            'pending' => (clone $visibleQuery)->whereIn('status', $pendingStatuses)->count(),
            'approved' => (clone $visibleQuery)->whereIn('status', $approvedStatuses)->count(),
            'disapproved' => (clone $visibleQuery)->whereIn('status', $disapprovedStatuses)->count(),
        ];

        $selectedCampaign = isset($filters['campaign_id'])
            ? Campaign::query()->find($filters['campaign_id'])
            : null;
        $query = (clone $visibleQuery)->with([
            'requestor.campaign',
            'responsibleRole',
            'items',
            'approvals' => fn ($query) => $query->latest('acted_at')->latest('id'),
        ]);

        $query
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('request_number', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhereHas('purchaseOrder', fn ($order) => $order->where('po_number', 'like', "%{$search}%"))
                        ->orWhereHas('requestor', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('items', fn ($query) => $query->where('description', 'like', "%{$search}%"));
                });
            })
            ->when($filters['status'] ?? null, function ($query, string $status) {
                if (PurchaseRequestStatus::tryFrom($status)) {
                    $query->where('status', $status);
                }
            })
            ->when($filters['summary'] ?? null, function ($query, string $summary) use ($pendingStatuses, $approvedStatuses, $disapprovedStatuses) {
                match ($summary) {
                    'approved' => $query->whereIn('status', $approvedStatuses),
                    'disapproved' => $query->whereIn('status', $disapprovedStatuses),
                    default => $query->whereIn('status', $pendingStatuses),
                };
            })
            ->when($selectedCampaign, function ($query, Campaign $campaign) {
                $query->where(function ($query) use ($campaign) {
                    $query->whereHas('requestor', fn ($query) => $query->where('campaign_id', $campaign->id))
                        ->orWhere('department_campaign', $campaign->name);
                });
            })
            ->when($filters['priority'] ?? null, fn ($query, string $priority) => $query->where('priority_level', $priority))
            ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('updated_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('updated_at', '<=', $date));

        return view('purchase-requests.index', [
            'purchaseRequests' => $query->latest()->paginate(15)->withQueryString(),
            'statuses' => PurchaseRequestStatus::cases(),
            'campaigns' => Campaign::query()->orderBy('name')->get(),
            'summaryCounts' => $summaryCounts,
            'processedRequests' => $this->processedRequests($request->user(), $query),
            'showProcessedRequests' => $this->isWorkflowUser($request->user()),
        ]);
    }

    private function isWorkflowUser(User $user): bool
    {
        return PurchaseOrderActionQueue::for($user) === null && $user->hasAnyRole([
            RoleSlug::Requestor->value,
            RoleSlug::TeamLeader->value,
            RoleSlug::Tl->value,
            RoleSlug::OmSmtsApprover->value,
            RoleSlug::Om->value,
            RoleSlug::ItStaff->value,
            RoleSlug::PremisesStaff->value,
            RoleSlug::President->value,
            RoleSlug::Purchasing->value,
            RoleSlug::PurchasingTeam->value,
            RoleSlug::ProcurementStaff->value,
        ]);
    }

    /** Requests the current user completed and handed to the next workflow stage. */
    private function processedRequests(User $user, \Illuminate\Database\Eloquent\Builder $filteredRequests): Collection
    {
        if (! $this->isWorkflowUser($user)) {
            return new Collection();
        }

        $labels = RequestStageReceipt::handoffLabels();

        return AuditLog::query()
            ->where('auditable_type', PurchaseRequest::class)
            ->where('user_id', $user->id)
            ->whereIn('action', array_keys($labels))
            ->whereIn('auditable_id', (clone $filteredRequests)->select('purchase_requests.id'))
            ->whereIn('auditable_id', PurchaseRequest::query()->processedBy($user)->select('purchase_requests.id'))
            ->with('auditable')
            ->latest('created_at')
            ->latest('id')
            ->limit(60)
            ->get()
            ->filter(fn (AuditLog $log) => $log->auditable !== null)
            ->unique('auditable_id')
            ->take(10)
            ->map(fn (AuditLog $log) => [
                'request' => $log->auditable,
                'step' => $labels[$log->action],
                'processed_at' => $log->created_at,
            ])
            ->values();
    }

    public function create(): View
    {
        $this->authorize('create', PurchaseRequest::class);

        return view('purchase-requests.create', $this->formOptions());
    }

    public function store(
        StorePurchaseRequestRequest $request,
        PurchaseRequestWorkflow $workflow,
    ): RedirectResponse {
        $validated = $request->validated();
        $action = $validated['action'];
        $items = $validated['items'];
        $signature = $this->submissionSignature($request, $validated, $action);
        unset($validated['action'], $validated['items'], $validated['purchase_mode'], $validated['signature_name'], $validated['signature_data']);

        $purchaseRequest = DB::transaction(function () use ($request, $validated, $items, $action, $workflow, $signature) {
            $purchaseRequest = PurchaseRequest::query()->create([
                ...$validated,
                'request_number' => $this->generateRequestNumber(),
                'requestor_id' => $request->user()->id,
                'request_type' => 'UNCLASSIFIED',
                'status' => PurchaseRequestStatus::Draft,
                'current_responsible_role_id' => $request->user()->activeRole()?->id,
                'total_amount' => null,
            ]);

            $this->syncItems($purchaseRequest, $items, $request->user());
            $workflow->recordCreated($purchaseRequest, $request->user());

            if ($action === 'submit') {
                $workflow->submitForScreening(
                    $purchaseRequest,
                    $request->user(),
                    $validated['remarks'] ?? null,
                    $signature['signature_name'],
                    $signature['signature_data'],
                );
            }

            return $purchaseRequest;
        });

        // A submitted request closes to its screening handoff receipt.
        if ($action === 'submit') {
            return $request->boolean('_embedded')
                ? redirect()->away(route('workflow.done', [], false))
                : redirect()->route('purchase-requests.show', $purchaseRequest);
        }

        return redirect()->route('purchase-requests.show', $purchaseRequest)->with('success', 'Purchase request saved as draft.');
    }

    /** Read-only request summary for the dashboard's processed-request preview. */
    public function preview(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorize('view', $purchaseRequest);
        $this->loadForDocument($purchaseRequest);

        return response()->json([
            'request_number' => $purchaseRequest->request_number,
            'status' => $purchaseRequest->status->value,
            'html' => view('purchase-requests.partials.request-document', ['purchaseRequest' => $purchaseRequest])->render(),
            'print_url' => route('purchase-requests.document', ['purchaseRequest' => $purchaseRequest, 'mode' => 'print']),
        ]);
    }

    /** Printable request summary (opens the print dialog with ?mode=print). */
    public function document(Request $request, PurchaseRequest $purchaseRequest): View
    {
        $this->authorize('view', $purchaseRequest);
        $this->loadForDocument($purchaseRequest);

        return view('purchase-requests.document', [
            'purchaseRequest' => $purchaseRequest,
            'mode' => $request->string('mode')->toString(),
        ]);
    }

    private function loadForDocument(PurchaseRequest $purchaseRequest): void
    {
        $purchaseRequest->load([
            'requestor',
            'teamLeader',
            'items.attachments',
            'selectedSupplier',
            'approvals' => fn ($query) => $query->orderBy('acted_at')->orderBy('id'),
            'approvals.user.role',
            'auditLogs.user',
        ]);
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest): View
    {
        $this->authorize('view', $purchaseRequest);

        $purchaseRequest->load([
            'requestor.role',
            'assignedUser.role',
            'teamLeader',
            'responsibleRole',
            'selectedSupplier',
            'items.attachments.uploader',
            'attachments.uploader',
            'approvals.user',
            'auditLogs.user',
            'quotations.supplier',
            'quotations.attachments',
            'quotations.creator.role',
            'quotations.item',
            'screening.screener',
            'screening.assets.releaser',
            'screening.attachments',
            'purchaseOrder.supplier',
            'purchaseOrder.creator.role',
            'purchaseOrder.checker.role',
            'purchaseOrder.acknowledger.role',
            'purchaseOrder.approver.role',
            'purchaseOrder.budgetCampaign',
            'purchaseOrder.items.requestItem',
            'budgetRequest.requester',
            'budgetRequest.maker',
            'budgetRequest.lines.requester',
            'budgetRequest.lines.purchaseOrder.supplier',
            'budgetRequest.lines.purchaseOrder.purchaseRequest',
            'budgetRequest.lines.campaign',
            'budgetRequest.checker',
              'budgetRequest.allocations.purchaseOrder',
              'budgetRequest.finalApprover',
              'budgetRequest.campaign',
              'budgetRequest.custodian',
              'budgetRequest.payments',
            'payments.recorder',
            'goodsReceipts.receiver',
            'inventoryItems.logger',
        ]);

        $quotationSummary = $this->quotationSummary($purchaseRequest);
        $purchaseOrderDocument = $purchaseRequest->purchaseOrder
            ? PurchaseOrderDocumentData::from($purchaseRequest)
            : null;
        // The stock picker is only rendered while this user can screen stock.
        // Quotation entry and later stages do not need the inventory catalogue.
        $inventoryItems = $request->user()->can('screen', $purchaseRequest)
            ? InventoryItem::query()
                ->visibleToTeam($request->user())
                ->where('status', 'LOGGED')
                ->where('quantity', '>', 0)
                ->orderBy('description')->get()
            : collect();

        $processedPurchaseOrders = $purchaseRequest->purchaseOrder?->isProcessed()
            ? collect([$purchaseRequest->purchaseOrder])
            : collect();

        $paymentCustodians = $request->user()->can('processPayment', $purchaseRequest)
            ? User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('purchase-requests.show', compact('purchaseRequest', 'purchaseOrderDocument', 'quotationSummary', 'inventoryItems', 'processedPurchaseOrders', 'paymentCustodians'));
    }

    public function edit(PurchaseRequest $purchaseRequest): View
    {
        $this->authorize('update', $purchaseRequest);
        $purchaseRequest->load('items.attachments');

        return view('purchase-requests.edit', [
            'purchaseRequest' => $purchaseRequest,
            ...$this->formOptions($purchaseRequest),
        ]);
    }

    public function update(
        UpdatePurchaseRequestRequest $request,
        PurchaseRequest $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
    ): RedirectResponse {
        $validated = $request->validated();
        $action = $validated['action'];
        $items = $validated['items'];
        $signature = $this->submissionSignature($request, $validated, $action);
        unset($validated['action'], $validated['items'], $validated['purchase_mode'], $validated['signature_name'], $validated['signature_data']);

        DB::transaction(function () use ($request, $purchaseRequest, $validated, $items, $action, $workflow, $signature) {
            $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->authorize('update', $lockedRequest);

            $lockedRequest->update($validated);
            $this->syncItems($lockedRequest, $items, $request->user());
            $workflow->recordUpdated($lockedRequest, $request->user());

            if ($action === 'submit') {
                $this->authorize('submit', $lockedRequest);
                $workflow->submitForScreening(
                    $lockedRequest,
                    $request->user(),
                    $validated['remarks'] ?? null,
                    $signature['signature_name'],
                    $signature['signature_data'],
                );
            }
        });

        if ($action === 'submit') {
            return $request->boolean('_embedded')
                ? redirect()->away(route('workflow.done', [], false))
                : redirect()->route('purchase-requests.show', $purchaseRequest);
        }

        return redirect()->route('purchase-requests.show', $purchaseRequest)->with('success', 'Purchase request updated.');
    }

    public function submit(
        Request $request,
        PurchaseRequest $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
    ): RedirectResponse {
        $this->authorize('submit', $purchaseRequest);
        $validated = $request->validate([
            'submission_remarks' => ['nullable', 'string', 'max:5000'],
            'priority_level' => ['missing'],
            ...WorkflowSignature::rules(),
        ]);
        WorkflowSignature::assertValid($request, $validated);

        DB::transaction(function () use ($request, $purchaseRequest, $workflow, $validated) {
            $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->authorize('submit', $lockedRequest);

            $items = $lockedRequest->items()->with('attachments')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Add at least one item to the purchase request.',
                ]);
            }
            foreach ($items as $item) {
                if (($missing = $item->missingSubmissionDetails()) !== []) {
                    throw ValidationException::withMessages([
                        'items' => "Edit the request and add the ".Arr::join($missing, ', ', ' and ')." for \"{$item->description}\" before submitting.",
                    ]);
                }
            }

            $workflow->submitForScreening(
                $lockedRequest,
                $request->user(),
                $validated['submission_remarks'] ?? null,
                $validated['signature_name'],
                $validated['signature_data'],
            );
        });

        return $request->boolean('_embedded')
            ? redirect()->away(route('workflow.done', [], false))
            : redirect()->route('purchase-requests.show', $purchaseRequest);
    }

    /**
     * Submitting for screening is signed like every other workflow hand-off;
     * saving a draft is not.
     *
     * @param  array<string, mixed>  $validated
     * @return array{signature_name: ?string, signature_data: ?string}
     */
    private function submissionSignature(Request $request, array $validated, string $action): array
    {
        if ($action !== 'submit') {
            return ['signature_name' => null, 'signature_data' => null];
        }

        WorkflowSignature::assertValid($request, $validated);

        return ['signature_name' => $validated['signature_name'], 'signature_data' => $validated['signature_data']];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function syncItems(PurchaseRequest $purchaseRequest, array $items, User $user): void
    {
        $existingItems = $purchaseRequest->items()->get()->keyBy('id');
        $keptItemIds = [];
        $requestTotal = 0.0;
        $hasUnpricedItems = false;

        foreach ($items as $itemData) {
            $attachment = $itemData['attachment'] ?? null;
            $picture = $itemData['picture'] ?? null;
            $quotationFile = $itemData['quotation_file'] ?? null;
            $itemId = $itemData['id'] ?? null;
            unset($itemData['attachment'], $itemData['picture'], $itemData['quotation_file'], $itemData['id']);

            $item = $itemId ? $existingItems->get((int) $itemId) : new PurchaseRequestItem;

            if (! $item) {
                throw ValidationException::withMessages(['items' => 'One of the selected request items is invalid.']);
            }

            $item->fill($itemData);
            $itemTotal = $item->quantity !== null && filled($item->unit) && $item->estimated_unit_price !== null
                ? round((float) $item->quantity * (float) $item->estimated_unit_price, 2)
                : null;
            $item->estimated_total = $itemTotal;
            $purchaseRequest->items()->save($item);
            $keptItemIds[] = $item->id;
            if ($itemTotal === null) {
                $hasUnpricedItems = true;
            } else {
                $requestTotal += $itemTotal;
            }

            if ($attachment instanceof UploadedFile) {
                $this->storeItemAttachment($item, $attachment, $user, PurchaseRequestItem::ATTACHMENT_EMAIL_APPROVAL);
            }
            if ($picture instanceof UploadedFile) {
                $this->storeItemAttachment($item, $picture, $user, PurchaseRequestItem::ATTACHMENT_PICTURE);
            }
            if ($quotationFile instanceof UploadedFile) {
                $this->storeItemAttachment($item, $quotationFile, $user, PurchaseRequestItem::ATTACHMENT_QUOTATION);
            }
        }

        $purchaseRequest->items()->whereNotIn('id', $keptItemIds)->delete();
        $purchaseRequest->forceFill(['total_amount' => $hasUnpricedItems ? null : round($requestTotal, 2)])->save();
    }

    private function storeItemAttachment(PurchaseRequestItem $item, UploadedFile $file, User $user, string $category): void
    {
        $path = $file->store("purchase-requests/{$item->purchase_request_id}/items/{$item->id}", 'local');

        $item->attachments()->create([
            'uploaded_by' => $user->id,
            'category' => $category,
            'original_name' => $file->getClientOriginalName(),
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
    }

    private function generateRequestNumber(): string
    {
        $nextId = (int) PurchaseRequest::withTrashed()->max('id') + 1;
        do {
            $number = 'REQ'.str_pad((string) $nextId++, 5, '0', STR_PAD_LEFT);
        } while (PurchaseRequest::withTrashed()->where('request_number', $number)->exists());

        return $number;
    }

    /**
     * Build a read-only quotation comparison using only like-for-like scopes.
     * Manual quotations are comparable when they describe the same request;
     * itemized quotations must cover the complete requested-item set.
     *
     * @return array{entries: \Illuminate\Support\Collection, lowest: ?array, lowest_tie: bool, recommended: ?array}
     */
    private function quotationSummary(PurchaseRequest $purchaseRequest): array
    {
        $quotations = SupplierQuotation::deduplicateForReview($purchaseRequest->quotations);
        $requestItemIds = $purchaseRequest->items->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $entries = $quotations->groupBy('supplier_id')->map(function ($supplierQuotations) use ($requestItemIds, $purchaseRequest): array {
            $itemIds = $supplierQuotations->pluck('purchase_request_item_id')
                ->filter(fn ($id) => $id !== null)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all();
            $isFullyItemized = $supplierQuotations->isNotEmpty()
                && $supplierQuotations->every(fn (SupplierQuotation $quotation) => $quotation->purchase_request_item_id !== null)
                && $itemIds === $requestItemIds;
            $isWholeRequestQuote = $supplierQuotations->count() === 1
                && $supplierQuotations->first()->purchase_request_item_id === null
                && trim((string) $supplierQuotations->first()->item_or_service) !== '';
            $scopeKey = $isFullyItemized
                ? 'items:'.implode(',', $itemIds)
                : ($isWholeRequestQuote ? 'request:'.mb_strtolower(trim((string) $supplierQuotations->first()->item_or_service)) : null);
            $recommendedLines = $supplierQuotations->where('is_recommended', true);
            $reason = $recommendedLines->pluck('remarks')->first(fn ($remarks) => trim((string) $remarks) !== '');
            $creatorAccounts = $supplierQuotations
                ->map(fn (SupplierQuotation $quotation) => $quotation->creator)
                ->filter()
                ->unique('id')
                ->map(fn ($creator) => [
                    'name' => $creator->name,
                    'email' => $creator->email,
                    'role' => $creator->role?->name,
                ])
                ->values()
                ->all();

            return [
                'supplier_id' => (int) $supplierQuotations->first()->supplier_id,
                'supplier_name' => $supplierQuotations->first()->supplier?->name ?? 'Unavailable supplier',
                'amount' => (float) $supplierQuotations->sum(fn (SupplierQuotation $quotation) => $quotation->vatBreakdown()['total']),
                'base_amount' => (float) $supplierQuotations->sum(fn (SupplierQuotation $quotation) => $quotation->vatBreakdown()['base']),
                'vat_amount' => (float) $supplierQuotations->sum(fn (SupplierQuotation $quotation) => $quotation->vatBreakdown()['vat']),
                'scope_key' => $scopeKey,
                'is_recommended' => $recommendedLines->isNotEmpty(),
                'recommendation_reason' => $reason ? trim((string) $reason) : null,
                'is_selected' => (int) $purchaseRequest->selected_supplier_id === (int) $supplierQuotations->first()->supplier_id,
                'is_approved' => $supplierQuotations->where('is_approved', true)->isNotEmpty(),
                'creator_accounts' => $creatorAccounts,
            ];
        })->values();

        $comparableCohort = $entries->whereNotNull('scope_key')
            ->groupBy('scope_key')
            ->filter(fn ($cohort) => $cohort->count() >= 2)
            ->sortByDesc(fn ($cohort) => $cohort->count())
            ->first();
        $lowest = null;
        $lowestTie = false;

        if ($comparableCohort) {
            $lowestAmount = (float) $comparableCohort->min('amount');
            $lowestEntries = $comparableCohort->filter(fn (array $entry) => abs($entry['amount'] - $lowestAmount) < 0.01);
            $lowestTie = $lowestEntries->count() > 1;
            $lowest = $lowestTie ? null : $lowestEntries->first();
        }

        return [
            'entries' => $entries,
            'lowest' => $lowest,
            'lowest_tie' => $lowestTie,
            'recommended' => $entries->firstWhere('is_recommended', true),
        ];
    }

    private function roleId(RoleSlug $role): int
    {
        return (int) Role::query()->where('slug', $role->value)->firstOrFail()->id;
    }

    /** @return array{campaigns: Collection, locations: Collection, units: array<string, string>} */
    private function formOptions(?PurchaseRequest $purchaseRequest = null): array
    {
        $currentCampaignName = $purchaseRequest?->department_campaign;
        $user = request()->user();
        $ownsCampaign = $user?->hasAnyRole([RoleSlug::Requestor->value, RoleSlug::TeamLeader->value]);

        return [
            'campaigns' => Campaign::query()
                ->where(function ($query) use ($currentCampaignName) {
                    $query->where('is_active', true);

                    if ($currentCampaignName) {
                        $query->orWhere('name', $currentCampaignName);
                    }
                })
                ->when($ownsCampaign, fn ($query) => $query->where('id', $user->campaign_id))
                ->orderBy('name')
                ->get(),
            'locations' => DeliveryLocation::query()->where('is_active', true)->orderBy('name')->get(),
            'units' => config('procurement.units'),
        ];
    }
}
