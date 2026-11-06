<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\BudgetRequest;
use App\Models\InventoryItem;
use App\Models\InventoryRelease;
use App\Models\Payment;
use App\Models\PurchaseRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderApproval;
use App\Models\User;
use App\Support\AccountMonitoring;
use App\Support\PurchaseOrderActionQueue;
use App\Support\RequestWithdrawal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as LengthAwarePaginatorConcrete;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public const PAYMENT_METHODS = ['ONLINE', 'CASH', 'CHECK', 'COD'];

    public function __invoke(Request $request): View
    {
        $user = $request->user()->loadMissing(['role', 'campaign']);

        if ($user->hasAnyRole([RoleSlug::TeamLeader->value, RoleSlug::Requestor->value])) {
            $doneStatuses = [
                PurchaseRequestStatus::Rejected->value,
                PurchaseRequestStatus::Cancelled->value,
                PurchaseRequestStatus::Completed->value,
                PurchaseRequestStatus::Fulfilled->value,
            ];
            $history = PurchaseRequest::query()
                ->where('requestor_id', $user->id)
                ->with(['requestor.campaign', 'responsibleRole', 'items', 'purchaseOrder:id,purchase_request_id,approved_at']);
            $history = $this->applyRequestFilters($history, $request);
            $completedFilters = $this->tableFilters($request, 'completed');
            $requestTab = in_array($request->query('request_tab'), ['returned', 'voided'], true) ? $request->query('request_tab') : 'completed';
            $returnedQuery = (clone $history)->where('status', PurchaseRequestStatus::Returned->value);
            $returnedRequests = $this->applyTableRequestFilters(
                clone $returnedQuery,
                $this->tableFilters($request, 'returned'),
                fn (Builder $query, string $status) => $query,
            )->with('latestReturnApproval.user.role');

            // Voided monitoring: withdrawals still waiting for acknowledgment, and voided requests.
            $voidStatuses = [PurchaseRequestStatus::WithdrawalPending->value, PurchaseRequestStatus::Voided->value];
            $voidedQuery = (clone $history)->whereIn('status', $voidStatuses);
            $voidedRequests = $this->applyTableRequestFilters(
                clone $voidedQuery,
                $this->tableFilters($request, 'voided'),
                fn (Builder $query, string $status) => in_array($status, $voidStatuses, true) ? $query->where('status', $status) : $query,
            )->with(['withdrawnBy:id,name', 'withdrawalAcknowledgedBy:id,name']);

            $doneRequests = $this->applyTableRequestFilters(
                (clone $history)->whereIn('status', $doneStatuses),
                $completedFilters,
                fn (Builder $query, string $status) => in_array($status, $doneStatuses, true) ? $query->where('status', $status) : $query,
            );

            return view('dashboard.team-leader', [
                'user' => $user,
                'myItemsCount' => InventoryRelease::query()->where('recipient_user_id', $user->id)->count(),
                'requestTab' => $requestTab,
                'returnedCount' => (clone $returnedQuery)->count(),
                'returnedRequests' => $returnedRequests->latest('updated_at')->paginate(10, ['*'], 'returned_page')->withQueryString(),
                'voidedCount' => (clone $voidedQuery)->count(),
                'voidPendingCount' => (clone $history)->where('status', PurchaseRequestStatus::WithdrawalPending->value)->count(),
                'voidedRequests' => $voidedRequests->latest('withdrawn_at')->paginate(10, ['*'], 'voided_page')->withQueryString(),
                // Paused withdrawals stay visible here until acknowledged; voided ones only in their tab.
                'pendingRequests' => (clone $history)->whereNotIn('status', [...$doneStatuses, PurchaseRequestStatus::Returned->value, PurchaseRequestStatus::Voided->value])->latest('updated_at')->get(),
                'doneRequests' => $doneRequests->latest('updated_at')->paginate(10, ['*'], 'completed_page')->withQueryString(),
            ]);
        }

        $purchaseOrderQueue = $this->purchaseOrderActionQueue($user);
        $creatorWorkflow = $user->hasRole(RoleSlug::PurchasingTeam->value)
            ? (in_array($request->query('workflow'), ['canvassing', 'purchase-order', 'budget-request'], true)
                ? $request->query('workflow')
                : 'canvassing')
            : null;
        $visibleRequests = $this->applyRequestFilters(PurchaseRequest::query()->visibleTo($user), $request);
        // Purchasing Team tabs deliberately use the same live eligibility rules as
        // their specialist queues. Do not start with a broad role queue here: a
        // status alone is not enough to make a PO or Budget Request actionable.
        $creatorQueue = fn (?string $workflow): ?Builder => match ($workflow) {
            'canvassing' => PurchaseRequest::query()
                ->visibleTo($user)
                ->whereIn('status', [
                    PurchaseRequestStatus::ForProcurement->value,
                    PurchaseRequestStatus::ForCanvassing->value,
                ]),
            'purchase-order' => PurchaseRequest::query()
                ->visibleTo($user)
                ->eligibleForPurchaseOrderPreparation(),
            'budget-request' => PurchaseRequest::query()
                ->visibleTo($user)
                ->readyForBudgetRequest(),
            default => null,
        };
        $creatorActionQueue = $creatorQueue($creatorWorkflow);
        // Waiting items per workflow tab, so the Purchasing Team sees every queue at a glance.
        $creatorWorkflowCounts = $creatorWorkflow === null ? [] : collect(['canvassing', 'purchase-order', 'budget-request'])
            ->mapWithKeys(fn (string $workflow) => [$workflow => $this->applyRequestFilters($creatorQueue($workflow), $request)->count()])
            ->all();
        $actionableRequests = $this->applyRequestFilters(
            $creatorActionQueue ?? $purchaseOrderQueue ?? $this->actionableRequests($user),
            $request,
        );
        // Purchasing Team tabs have no page-wide filter: each section filters itself ("action_*" here).
        $actionFilters = $this->tableFilters($request, 'action');
        if ($creatorWorkflow !== null) {
            $actionableRequests = $this->applyTableRequestFilters($actionableRequests, $actionFilters, fn (Builder $query) => $query);
        }
        $summary = $this->requestSummary($purchaseOrderQueue ?? $visibleRequests);
        $actionCount = (clone $actionableRequests)->count();

        $myActionsQuery = (clone $actionableRequests)
            ->with([
                'requestor:id,name,campaign_id',
                'items:id,purchase_request_id,description,quantity,unit',
                'requestor.campaign:id,name',
                'responsibleRole:id,name',
                'purchaseOrder:id,purchase_request_id,po_number,status,created_by,approved_at,issued_at,budget_campaign_id',
            ]);

        $isBudgetMaker = $user->hasRole(RoleSlug::BudgetMaker->value) || $creatorWorkflow === 'budget-request';
        $actionSearch = $creatorWorkflow !== null ? $actionFilters['search'] : trim($request->string('search')->toString());

        if ($isBudgetMaker) {
            $myActionsQuery->withMax('purchaseOrder as po_approved_at', 'approved_at')
                ->orderByDesc('po_approved_at');
        }

        // The President decides on supplier selections: show what is being approved,
        // for how much, and how long it has waited (oldest first).
        $isExecutiveQueue = $user->hasRole(RoleSlug::President->value) && $purchaseOrderQueue === null && $creatorWorkflow === null;
        $executiveSummary = null;
        $approvalOrder = $request->query('approval_order') === 'oldest' ? 'oldest' : 'newest';
        if ($isExecutiveQueue) {
            $recommendedQuotations = fn ($query) => $query->where('is_recommended', true)->with('supplier:id,name');
            $direction = $approvalOrder === 'oldest' ? 'asc' : 'desc';
            $myActionsQuery->with(['quotations' => $recommendedQuotations])->withCount('quotations')
                ->reorder()->orderBy('updated_at', $direction)->orderBy('id', $direction);
            $queued = (clone $actionableRequests)->with(['quotations' => $recommendedQuotations])->get(['purchase_requests.id', 'purchase_requests.total_amount', 'purchase_requests.updated_at']);
            $executiveSummary = [
                'value' => $queued->sum(fn (PurchaseRequest $purchaseRequest) => $this->decisionAmount($purchaseRequest)),
                'oldest' => $queued->min('updated_at'),
                'overdue' => $queued->filter(fn (PurchaseRequest $purchaseRequest) => $purchaseRequest->updated_at->lt(now()->subDays(3)))->count(),
            ];
        }

        $transactionType = in_array($request->query('transaction_type'), ['ONLINE', 'CASH', 'CHECK'], true)
            ? $request->query('transaction_type')
            : null;
        $budgetRegisterTab = $creatorWorkflow === null
            && in_array('approved', [$request->query('budget_register_tab'), $request->query('processed_tab')], true)
            ? 'approved' : 'created';
        // Budget Maker workflow: the processed list is split by stage (all / waiting for Finance / finalized).
        $budgetStage = $creatorWorkflow === 'budget-request' && in_array($request->query('processed_tab'), ['finance', 'finalized'], true)
            ? $request->query('processed_tab')
            : null;
        $loadsBudgetRegister = $isBudgetMaker;
        $budgetRequests = $loadsBudgetRegister
            ? BudgetRequest::query()
                ->with(['campaign', 'custodian', 'requester', 'lines.purchaseOrder.supplier', 'lines.purchaseOrder.purchaseRequest', 'lines.campaign', 'payments', 'purchaseRequest'])
                ->when($budgetRegisterTab === 'approved', fn (Builder $query) => $query->finallyApproved())
                ->when($creatorWorkflow === 'budget-request', fn (Builder $query) => $query->whereNotNull('maker_approved_at'))
                ->forStage($budgetStage)
                ->when($transactionType, fn (Builder $query, string $type) => $query->where('payment_method', $type))
                ->registerSearch($this->tableFilters($request, 'br')['search'])
                ->when($creatorWorkflow === null && $actionSearch !== '', function (Builder $query) use ($actionSearch): void {
                    $term = '%'.addcslashes($actionSearch, '%_\\').'%';
                    $query->where(fn (Builder $query) => $query
                        ->where('budget_request_number', 'like', $term)
                        ->orWhereHas('purchaseRequest', fn (Builder $request) => $request
                            ->where('request_number', 'like', $term)
                            ->orWhere('title', 'like', $term)
                            ->orWhereHas('requestor', fn (Builder $requestor) => $requestor->where('name', 'like', $term)))
                        ->orWhereHas('purchaseRequests', fn (Builder $request) => $request
                            ->where('request_number', 'like', $term)
                            ->orWhere('title', 'like', $term)
                            ->orWhereHas('requestor', fn (Builder $requestor) => $requestor->where('name', 'like', $term)))
                        ->orWhereHas('lines.purchaseOrder', fn (Builder $order) => $order->where('po_number', 'like', $term)));
                })
                ->when($request->filled('priority'), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->whereHas('purchaseRequest', fn (Builder $requestQuery) => $requestQuery->where('priority_level', $request->query('priority')))
                    ->orWhereHas('purchaseRequests', fn (Builder $requestQuery) => $requestQuery->where('priority_level', $request->query('priority')))))
                ->when($request->filled('date_from'), fn (Builder $query) => $query->whereDate('updated_at', '>=', $request->query('date_from')))
                ->when($request->filled('date_to'), fn (Builder $query) => $query->whereDate('updated_at', '<=', $request->query('date_to')))
                ->latest('created_at')
                ->paginate(10, ['*'], 'history_page')
                ->withQueryString()
            : null;
        $budgetCreatedCount = $loadsBudgetRegister ? BudgetRequest::query()->count() : null;
        $budgetApprovedCount = $loadsBudgetRegister ? BudgetRequest::query()->finallyApproved()->count() : null;

        // Every action queue is paginated. A fixed limit here used to hide the rest of
        // the queue (e.g. IT / Premises screening beyond the sixth request) while the
        // counters still included them.
        if (! $isExecutiveQueue) {
            $myActionsQuery->latest('updated_at')->latest('id');
        }
        $presidentActionsPagination = $myActionsQuery
            ->paginate($isBudgetMaker ? 20 : 12, ['*'], 'approval_page')
            ->withQueryString();
        $myActions = $presidentActionsPagination->getCollection()
            ->map(function (PurchaseRequest $purchaseRequest) use ($user, $isExecutiveQueue): ?array {
                $details = $this->actionDetails($user, $purchaseRequest);

                return $details === null
                    ? null
                    : [
                        'request' => $purchaseRequest,
                        'purchaseOrderPreviewUrl' => $purchaseRequest->purchaseOrder
                            ? route('purchase-orders.preview', $purchaseRequest->purchaseOrder)
                            : null,
                        'amount' => $isExecutiveQueue ? $this->decisionAmount($purchaseRequest) : null,
                        'supplier' => $isExecutiveQueue ? (fn ($names) => $names->count() > 1 ? $names->count().' suppliers' : $names->first())($purchaseRequest->quotations->pluck('supplier.name')->filter()->unique()) : null,
                        ...$details,
                    ];
            })
            ->filter()
            ->values();

        $recentRequests = (clone $visibleRequests)
            ->with([
                'requestor:id,name,campaign_id',
                'requestor.campaign:id,name',
                'responsibleRole:id,name',
            ])
            ->latest('updated_at')
            ->limit(7)
            ->get();

        $recentActivity = collect();
        $purchaseOrderReviewActions = $this->purchaseOrderReviewActions($user, $request);
        $userSummary = null;

        if (Gate::forUser($user)->allows('viewAny', AuditLog::class)) {
            $recentActivity = AuditLog::query()
                ->where('auditable_type', PurchaseRequest::class)
                ->whereIn('auditable_id', (clone $visibleRequests)->select('purchase_requests.id'))
                ->with(['user:id,name', 'auditable'])
                ->latest('created_at')
                ->latest('id')
                ->limit(8)
                ->get();

            $userSummary = User::query()
                ->selectRaw('COUNT(*) as total')
                ->selectRaw('COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) as active')
                ->first();
        }

        $isPurchaseOrderApprover = $purchaseOrderQueue !== null;
        // Workflow roles get the same focused dashboard as the PO signers:
        // what is pending for them, and what they have already processed.
        $isWorkflowDashboard = $creatorWorkflow !== null || (! $isPurchaseOrderApprover && $user->hasAnyRole([
            RoleSlug::Requestor->value,
            RoleSlug::TeamLeader->value,
            RoleSlug::Tl->value,
            RoleSlug::OmSmtsApprover->value,
            RoleSlug::Om->value,
            RoleSlug::ItStaff->value,
            RoleSlug::PremisesStaff->value,
            RoleSlug::CanvassingStaff->value,
            RoleSlug::President->value,
            RoleSlug::Purchasing->value,
            RoleSlug::PurchasingTeam->value,
            RoleSlug::ProcurementStaff->value,
        ]));
        $processedRequests = collect();
        $embedsBudgetRegister = $isWorkflowDashboard && $budgetRequests !== null;
        $showsProcessedByMe = $isWorkflowDashboard && $user->hasAnyRole([RoleSlug::ItStaff->value, RoleSlug::PremisesStaff->value]);
        $processedTabOptions = $creatorWorkflow !== null
            ? ($creatorWorkflow === 'budget-request' ? ['processed', 'finance', 'finalized'] : ['processed'])
            : array_keys(array_filter([
                'mine' => $showsProcessedByMe,
                'created' => $embedsBudgetRegister,
                'approved' => $embedsBudgetRegister,
            ]));
        $processedTab = in_array($request->query('processed_tab'), $processedTabOptions, true)
            ? $request->query('processed_tab')
            : ($processedTabOptions[0] ?? 'mine');
        $createdPoFilters = $this->tableFilters($request, 'created_po');
        $createdPurchaseOrders = $creatorWorkflow === 'purchase-order'
            ? PurchaseOrder::query()
                ->with(['purchaseRequest:id,request_number,title', 'supplier:id,name', 'creator:id,name'])
                ->when($createdPoFilters['search'] !== '', function (Builder $query) use ($createdPoFilters): void {
                    $term = $this->likeTerm($createdPoFilters['search']);
                    $query->where(fn (Builder $query) => $query
                        ->where('po_number', 'like', $term)
                        ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $term))
                        ->orWhereHas('purchaseRequest', fn (Builder $purchaseRequest) => $purchaseRequest
                            ->where('request_number', 'like', $term)
                            ->orWhere('title', 'like', $term)));
                })
                ->when(array_key_exists((string) $createdPoFilters['status'], self::PO_STATUSES), fn (Builder $query) => $query->where('status', $createdPoFilters['status']))
                ->when($createdPoFilters['from'], fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
                ->when($createdPoFilters['to'], fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))
                ->latest('created_at')->latest('id')
                ->paginate(10, ['*'], 'created_po_page')
                ->withQueryString()
            : null;
        $canvassingRecords = $creatorWorkflow === 'canvassing'
            ? $this->applyTableRequestFilters(PurchaseRequest::query(), $this->tableFilters($request, 'canvassing'), fn (Builder $query) => $query)
                ->canvassingProcessedBy($user)
                ->with(['quotations.supplier', 'requestor:id,name'])
                ->latest('updated_at')
                ->paginate(10, ['*'], 'canvassing_page')
                ->withQueryString()
            : null;
        $processedTabCounts = [];

        if ($isWorkflowDashboard && $creatorWorkflow === null) {
            $processedRequestIds = PurchaseRequest::query()->processedBy($user)->pluck('id');

            $processedQuery = fn (string $tab): Builder => $this->applyTableRequestFilters(
                $this->applyRequestFilters(PurchaseRequest::query()->whereKey($processedRequestIds), $request),
                $this->tableFilters($request, 'processed'),
                fn (Builder $query, string $group) => in_array($group, ['pending', 'approved', 'disapproved'], true)
                    ? $query->whereIn('status', PurchaseRequestStatus::valuesInSummaryGroup($group))
                    : $query,
            );

            $processedRequests = $processedQuery($processedTab)
                ->with(['requestor:id,name,campaign_id'])
                ->latest('updated_at')
                ->paginate(10, ['*'], 'processed_page')
                ->withQueryString();

            $processedTabCounts = array_intersect_key([
                'mine' => $showsProcessedByMe ? $processedQuery('mine')->count() : 0,
                'created' => $budgetCreatedCount ?? 0,
                'approved' => $budgetApprovedCount ?? 0,
            ], array_flip($processedTabOptions));
        }
        if ($creatorWorkflow !== null) {
            $processedTabCounts = match ($creatorWorkflow) {
                'canvassing' => [
                    'processed' => PurchaseRequest::query()->canvassingProcessedBy($user)->count(),
                ],
                'purchase-order' => [
                    'processed' => PurchaseOrder::query()->count(),
                ],
                // The Final Budget Request search covers every stage tab, so each count shows its matches.
                'budget-request' => (function () use ($request, $transactionType): array {
                    $matching = fn (): Builder => BudgetRequest::query()
                        ->whereNotNull('maker_approved_at')
                        ->when($transactionType, fn (Builder $query, string $type) => $query->where('payment_method', $type))
                        ->registerSearch($this->tableFilters($request, 'br')['search']);

                    return [
                        'processed' => $matching()->count(),
                        'finance' => $matching()->awaitingFinance()->count(),
                        'finalized' => $matching()->finallyApproved()->count(),
                    ];
                })(),
            };
        }

        $isPaymentDashboard = $user->hasRole(RoleSlug::Finance->value);

        if ($creatorWorkflow !== null) {
            $creatorWorkflowCounts['budget-request-processed'] = BudgetRequest::query()->whereNotNull('maker_approved_at')->count();
        }

        $profile = $this->profile($user);
        if ($creatorWorkflow !== null) {
            $profile['actionLabel'] = match ($creatorWorkflow) {
                'canvassing' => 'Canvassing actions',
                'purchase-order' => 'Purchase Orders for my action',
                'budget-request' => 'Budget Requests for my action',
            };
        }

        return view('dashboard', [
            'user' => $user,
            'isPurchaseOrderApprover' => $isPurchaseOrderApprover,
            'isWorkflowDashboard' => $isWorkflowDashboard,
            'profile' => $profile,
            'kpis' => $isWorkflowDashboard
                ? $this->workflowKpis($user, $actionCount, $request)
                : $this->kpis($user, $summary, $actionCount, $userSummary),
            'myActions' => $myActions,
            'actionCount' => $actionCount,
            'executiveSummary' => $executiveSummary,
            'approvalOrder' => $approvalOrder,
            'presidentActionsPagination' => $presidentActionsPagination,
            'actionSearch' => $actionSearch,
            'budgetRequests' => $budgetRequests,
            'budgetRegisterTab' => $budgetRegisterTab,
            'budgetCreatedCount' => $budgetCreatedCount,
            'budgetApprovedCount' => $budgetApprovedCount,
            'transactionType' => $transactionType,
            'processedRequests' => $processedRequests,
            'processedTab' => $processedTab,
            'processedTabCounts' => $processedTabCounts,
            'createdPurchaseOrders' => $createdPurchaseOrders,
            'canvassingRecords' => $canvassingRecords,
            'embedsBudgetRegister' => $embedsBudgetRegister,
            'isPaymentDashboard' => $isPaymentDashboard,
            'processedPayments' => $isPaymentDashboard ? $this->processedPayments($user, $request) : null,
            'forReceivingRequests' => $isPaymentDashboard ? $this->forReceivingRequests($user, $request) : null,
            'awaitingPaymentRequests' => $isPaymentDashboard ? $this->awaitingPaymentRequests($user, $request) : null,
            'partialPaymentRequests' => $isPaymentDashboard ? $this->awaitingPaymentRequests($user, $request, partial: true) : null,
            'paymentTab' => in_array($request->query('payment_tab'), ['partial', 'receiving', 'inventory'], true) ? $request->query('payment_tab') : 'awaiting',
            'inventoryItems' => $isPaymentDashboard ? $this->inventoryTabItems($request) : null,
            'myItemsCount' => InventoryRelease::query()->where('recipient_user_id', $user->id)->count(),
            'recentRequests' => $recentRequests,
            'recentActivity' => $recentActivity,
            'purchaseOrderReviewActions' => $purchaseOrderReviewActions,
            'purchaseOrderHistoryTab' => $this->purchaseOrderHistoryTab($request),
            'purchaseOrderHistoryCounts' => $isPurchaseOrderApprover ? $this->purchaseOrderHistoryCounts($user, $request) : [],
            'creatorWorkflow' => $creatorWorkflow,
            'creatorWorkflowCounts' => $creatorWorkflowCounts,
            // Paused requests whose withdrawal this user must acknowledge (stopped at their stage).
            // Purchasing uses the shared Voided Monitoring page across all workflow tabs.
            'withdrawalsToAcknowledge' => $user->hasRole(RoleSlug::PurchasingTeam->value) ? collect() : PurchaseRequest::query()
                ->where('status', PurchaseRequestStatus::WithdrawalPending->value)
                ->with(['requestor:id,name', 'withdrawnBy:id,name'])
                ->oldest('withdrawn_at')
                ->limit(50)
                ->get()
                ->filter(fn (PurchaseRequest $purchaseRequest) => $user->can('acknowledgeWithdrawal', $purchaseRequest))
                ->values(),
            'voidedMonitoring' => $this->voidedMonitoring($user, $request),
            'accountMonitoring' => AccountMonitoring::for($user, $request),
        ]);
    }

    /**
     * Dashboard monitoring for IT and Premises: requests withdrawn while at
     * a stage the team handles (or acknowledged by this user), waiting ones first.
     */
    private function voidedMonitoring(User $user, Request $request): ?LengthAwarePaginator
    {
        if (! $user->hasAnyRole([RoleSlug::ItStaff->value, RoleSlug::PremisesStaff->value])) {
            return null;
        }

        $filters = $this->tableFilters($request, 'voided');
        $statuses = [PurchaseRequestStatus::WithdrawalPending->value, PurchaseRequestStatus::Voided->value];
        $rows = PurchaseRequest::query()
            ->whereIn('status', in_array($filters['status'], $statuses, true) ? [$filters['status']] : $statuses)
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('request_number', 'like', $this->likeTerm($filters['search']))
                ->orWhere('title', 'like', $this->likeTerm($filters['search']))
                ->orWhere('withdrawal_reason', 'like', $this->likeTerm($filters['search']))))
            ->when($filters['from'], fn (Builder $query, string $date) => $query->whereDate('withdrawn_at', '>=', $date))
            ->when($filters['to'], fn (Builder $query, string $date) => $query->whereDate('withdrawn_at', '<=', $date))
            ->with(['requestor:id,name', 'withdrawnBy:id,name', 'withdrawalAcknowledgedBy:id,name'])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [PurchaseRequestStatus::WithdrawalPending->value])
            ->latest('withdrawn_at')
            ->limit(500)
            ->get()
            ->filter(fn (PurchaseRequest $purchaseRequest) => RequestWithdrawal::concerns($user, $purchaseRequest))
            ->values();

        $page = LengthAwarePaginatorConcrete::resolveCurrentPage('voided_page');

        return (new LengthAwarePaginatorConcrete($rows->forPage($page, 10)->values(), $rows->count(), 10, $page, [
            'path' => $request->url(),
            'pageName' => 'voided_page',
        ]))->withQueryString();
    }

    /**
     * KPIs for the focused workflow dashboard: they summarise exactly the two panels
     * below them — what is waiting on me, and what I have processed (and where it is now).
     *
     * @return list<array{label: string, value: int, tone: string, icon: string}>
     */
    private function workflowKpis(User $user, int $actionCount, Request $request): array
    {
        $processedIds = PurchaseRequest::query()->processedBy($user)->pluck('id');
        $processed = $this->applyRequestFilters(PurchaseRequest::query()->whereKey($processedIds), $request)->pluck('status');
        $countIn = fn (string $group) => $processed->filter(fn (PurchaseRequestStatus $status) => $status->summaryGroup() === $group)->count();

        return [
            [
                'label' => 'Awaiting my action',
                'value' => $actionCount,
                'tone' => 'warning',
                'icon' => 'hourglass',
            ],
            [
                'label' => 'Processed by me',
                'value' => $processed->count(),
                'tone' => 'primary',
                'icon' => 'file-text',
            ],
            [
                'label' => 'Processed · in progress',
                'value' => $countIn('pending'),
                'tone' => 'info',
                'icon' => 'exclamation-circle',
            ],
            [
                'label' => 'Processed · approved',
                'value' => $countIn('approved'),
                'tone' => 'success',
                'icon' => 'check-circle',
            ],
        ];
    }

    /** Read-only tabs of the PO review history, each listing one completed signing step. */
    public const PO_HISTORY_TABS = [
        'checked' => PurchaseOrderApprovalStage::CheckedBy,
        'noted' => PurchaseOrderApprovalStage::NotedBy,
        'approved' => PurchaseOrderApprovalStage::ApprovedBy,
    ];

    /** PO statuses offered by the Purchase Orders processed filter. */
    public const PO_STATUSES = [
        'FOR_CHECKING' => 'For checking',
        'FOR_ACKNOWLEDGEMENT' => 'For noting',
        'FOR_APPROVAL' => 'For approval',
        'APPROVED' => 'Approved',
        'ISSUED' => 'Issued',
        'REJECTED' => 'Rejected',
    ];

    private function purchaseOrderHistoryTab(Request $request): string
    {
        $tab = $request->query('po_history_tab');

        return is_string($tab) && array_key_exists($tab, self::PO_HISTORY_TABS) ? $tab : 'mine';
    }

    private function purchaseOrderReviewActions(User $user, Request $request): LengthAwarePaginator
    {
        return $this->purchaseOrderHistoryQuery($user, $request, $this->purchaseOrderHistoryTab($request))
            ->with(['purchaseOrder.purchaseRequest', 'actor:id,name', 'assignedUser:id,name'])
            ->latest('acted_at')
            ->latest('id')
            ->paginate(10, ['*'], 'po_history_page')
            ->withQueryString();
    }

    /** @return array<string, int> row count of every history tab, with the current filters applied */
    private function purchaseOrderHistoryCounts(User $user, Request $request): array
    {
        return collect(['mine', ...array_keys(self::PO_HISTORY_TABS)])
            ->mapWithKeys(fn (string $tab) => [$tab => $this->purchaseOrderHistoryQuery($user, $request, $tab)->count()])
            ->all();
    }

    private function purchaseOrderHistoryQuery(User $user, Request $request, string $tab): Builder
    {
        $stages = PurchaseOrderApprovalStage::cases();
        $filters = $this->tableFilters($request, 'po_history');
        $tabStage = self::PO_HISTORY_TABS[$tab] ?? null;

        $query = PurchaseOrderApproval::query()
            ->whereHas('purchaseOrder.purchaseRequest', fn (Builder $query) => $this->applyRequestFilters($query, $request));

        // The Purchasing Team prepares every PO as a team (PreparedBy is granted by
        // role, not per-user assignment), so its history covers all POs.
        $isPurchasingTeam = $user->hasRole(RoleSlug::PurchasingTeam->value);

        if ($tabStage !== null) {
            // A role stage is a live database register: show both its pending
            // assignments and completed sign-offs, but only when this user is the
            // assigned actor or actually performed the action.
            $query->where('stage', $tabStage->value)
                ->unless($isPurchasingTeam, fn (Builder $query) => $query->whereIn('purchase_order_id', PurchaseOrderApproval::query()
                    ->select('purchase_order_id')
                    ->where(fn (Builder $mine) => $mine->where('assigned_user_id', $user->id)->orWhere('acted_by', $user->id))));
        } elseif ($isPurchasingTeam) {
            $query->where('stage', PurchaseOrderApprovalStage::PreparedBy->value)
                ->where(fn ($completed) => PurchaseOrderActionQueue::whereCompletedStage($completed, ['APPROVED', 'REJECTED']));
        } else {
            // Only steps actually signed and submitted count as completed in the
            // personal handoff history.
            $query->where(fn ($completed) => PurchaseOrderActionQueue::whereCompletedStage($completed, ['APPROVED', 'REJECTED']));
            // A completed step stays in the signer's history only while the PO waits on
            // the next stage. Once the next signer acts, it belongs to their history.
            $query->where('acted_by', $user->id)->where(function ($query) use ($stages): void {
                foreach ($stages as $index => $stage) {
                    $next = $stages[$index + 1] ?? null;
                    $query->orWhere(function ($stageQuery) use ($stage, $next): void {
                        $stageQuery->where('stage', $stage->value);
                        if ($next !== null) {
                            $stageQuery->whereNotExists(fn ($nextStage) => $nextStage
                                ->selectRaw('1')
                                ->from('purchase_order_approvals as next_stage')
                                ->whereColumn('next_stage.purchase_order_id', 'purchase_order_approvals.purchase_order_id')
                                ->where('next_stage.stage', $next->value)
                                ->where(fn ($done) => $done->whereNotNull('next_stage.signed_at')->orWhere('next_stage.status', '!=', 'PENDING')));
                        }
                    });
                }
            })->when(PurchaseOrderApprovalStage::tryFrom((string) $filters['status']), fn (Builder $query, PurchaseOrderApprovalStage $stage) => $query->where('stage', $stage->value));
        }

        return $query
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $term = $this->likeTerm($filters['search']);
                $query->whereHas('purchaseOrder', fn (Builder $order) => $order
                    ->where('po_number', 'like', $term)
                    ->orWhereHas('purchaseRequest', fn (Builder $purchaseRequest) => $purchaseRequest
                        ->where('request_number', 'like', $term)
                        ->orWhere('title', 'like', $term)));
            })
            ->when($filters['from'], fn (Builder $query, string $date) => $query->whereDate('acted_at', '>=', $date))
            ->when($filters['to'], fn (Builder $query, string $date) => $query->whereDate('acted_at', '<=', $date));
    }

    /**
     * Awaiting payment tab: requests whose Budget Request passed final approval (the
     * Budget Approved register) and that have not been paid at all yet.
     * Partially paid tab ($partial): the same requests once part of the balance is paid;
     * they stay For payment until the last payment clears the balance.
     */
    private function awaitingPaymentRequests(User $user, Request $request, bool $partial = false): LengthAwarePaginator
    {
        return $this->applyTableRequestFilters(
            $this->applyRequestFilters(PurchaseRequest::query()
                ->visibleTo($user)
                ->where('status', PurchaseRequestStatus::ForPayment->value)
                ->whereHas('budgetRequest', fn (Builder $budget) => $budget->finallyApproved())
                ->when($partial, fn (Builder $query) => $query->whereHas('payments'), fn (Builder $query) => $query->whereDoesntHave('payments')), $request),
            $this->tableFilters($request, $partial ? 'partial' : 'awaiting'),
            fn (Builder $query) => $query,
        )
            ->with([
                'requestor:id,name',
                'purchaseOrder:id,purchase_request_id,po_number,total_amount',
                'budgetRequest',
                ...($partial ? ['payments' => fn ($payments) => $payments->latest('paid_at')->latest('id')->with('attachments')] : []),
            ])
            ->withSum('payments as amount_paid', 'amount')
            ->latest('updated_at')
            ->paginate(10, ['*'], $partial ? 'partial_page' : 'awaiting_page')
            ->withQueryString();
    }

    /** Inventory tab of the Finance dashboard: the same register as the Inventory page. */
    private function inventoryTabItems(Request $request): LengthAwarePaginator
    {
        $filters = $this->tableFilters($request, 'inventory');

        return InventoryItem::query()
            ->registerFilters([
                'search' => $filters['search'],
                'stock' => $filters['status'],
                'date_from' => $filters['from'],
                'date_to' => $filters['to'],
            ])
            ->paginate(10, ['*'], 'inventory_page')
            ->withQueryString();
    }

    /** Paid requests whose goods have not been received yet; Finance can mark them received. */
    private function forReceivingRequests(User $user, Request $request): LengthAwarePaginator
    {
        return $this->applyTableRequestFilters(
            PurchaseRequest::query()->visibleTo($user)->where('status', PurchaseRequestStatus::ForReceiving->value),
            $this->tableFilters($request, 'receiving'),
            fn (Builder $query) => $query,
        )
            ->with(['requestor:id,name', 'purchaseOrder:id,purchase_request_id,po_number', 'budgetRequest', 'payments' => fn ($payment) => $payment->latest('paid_at')])
            ->latest('updated_at')
            ->paginate(10, ['*'], 'receiving_page')
            ->withQueryString();
    }

    /** Payments the Finance user recorded, newest first, with their own table filters. */
    private function processedPayments(User $user, Request $request): LengthAwarePaginator
    {
        $filters = $this->tableFilters($request, 'payments');

        return Payment::query()
            ->where('recorded_by', $user->id)
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $term = $this->likeTerm($filters['search']);
                $query->where(function (Builder $query) use ($filters, $term): void {
                    if (preg_match('/^PAY-?0*(\d+)$/i', $filters['search'], $code)) {
                        $query->orWhere('payments.id', (int) $code[1]);
                    }
                    $query->orWhere('reference_number', 'like', $term)
                        ->orWhere('invoice_number', 'like', $term)
                        ->orWhereHas('purchaseOrder', fn (Builder $order) => $order->where('po_number', 'like', $term))
                        ->orWhereHas('budgetRequest', fn (Builder $budget) => $budget->where('budget_request_number', 'like', $term))
                        ->orWhereHas('purchaseRequest', fn (Builder $purchaseRequest) => $purchaseRequest
                            ->where('request_number', 'like', $term)
                            ->orWhere('title', 'like', $term));
                });
            })
            ->when(in_array($filters['status'], self::PAYMENT_METHODS, true), fn (Builder $query) => $query->where('payment_method', $filters['status']))
            ->when($filters['from'], fn (Builder $query, string $date) => $query->whereDate('paid_at', '>=', $date))
            ->when($filters['to'], fn (Builder $query, string $date) => $query->whereDate('paid_at', '<=', $date))
            ->with(['purchaseRequest:id,request_number,title', 'purchaseOrder:id,po_number', 'budgetRequest:id,budget_request_number', 'recorder:id,name', 'attachments'])
            ->latest('paid_at')
            ->latest('id')
            ->paginate(10, ['*'], 'payments_page')
            ->withQueryString();
    }

    /**
     * Each dashboard table has its own search, one select filter and a date range,
     * read from "{prefix}_search", "{prefix}_status", "{prefix}_from" and "{prefix}_to".
     *
     * @return array{search: string, status: ?string, from: ?string, to: ?string}
     */
    private function tableFilters(Request $request, string $prefix): array
    {
        $value = fn (string $key) => is_string($input = $request->query($prefix.'_'.$key)) ? trim($input) : '';
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value($key)) ? $value($key) : null;

        return [
            'search' => mb_substr($value('search'), 0, 200),
            'status' => $value('status') !== '' ? $value('status') : null,
            'from' => $date('from'),
            'to' => $date('to'),
        ];
    }

    /** @param  callable(Builder, string): Builder  $status */
    private function applyTableRequestFilters(Builder $query, array $filters, callable $status): Builder
    {
        return $query
            ->when($filters['search'] !== '', fn (Builder $query) => $this->searchRequests($query, $filters['search']))
            ->when($filters['status'], fn (Builder $query, string $value) => $status($query, $value))
            ->when($filters['from'], fn (Builder $query, string $date) => $query->whereDate('updated_at', '>=', $date))
            ->when($filters['to'], fn (Builder $query, string $date) => $query->whereDate('updated_at', '<=', $date));
    }

    private function likeTerm(string $search): string
    {
        return '%'.addcslashes($search, '%_\\').'%';
    }

    private function requestSummary(Builder $query): object
    {
        $terminal = [
            PurchaseRequestStatus::Rejected->value,
            PurchaseRequestStatus::Cancelled->value,
            PurchaseRequestStatus::Completed->value,
            PurchaseRequestStatus::Fulfilled->value,
        ];
        $approved = [
            PurchaseRequestStatus::Approved->value,
            PurchaseRequestStatus::PoCreated->value,
            PurchaseRequestStatus::ForBudgetProcessing->value,
            PurchaseRequestStatus::PaymentPending->value,
            PurchaseRequestStatus::PartiallyPaid->value,
            PurchaseRequestStatus::ForDelivery->value,
            PurchaseRequestStatus::PartiallyReceived->value,
            PurchaseRequestStatus::ReceivedWithInvoice->value,
            PurchaseRequestStatus::FullyPaid->value,
            PurchaseRequestStatus::ForInventoryLogging->value,
            PurchaseRequestStatus::Endorsed->value,
            PurchaseRequestStatus::Completed->value,
            PurchaseRequestStatus::Fulfilled->value,
        ];
        $rejected = [PurchaseRequestStatus::Rejected->value, PurchaseRequestStatus::Cancelled->value];
        $completed = [PurchaseRequestStatus::Completed->value, PurchaseRequestStatus::Fulfilled->value];

        return (clone $query)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status NOT IN ('.$this->placeholders($terminal).') THEN 1 ELSE 0 END), 0) as pending',
                $terminal,
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status IN ('.$this->placeholders($approved).') THEN 1 ELSE 0 END), 0) as approved',
                $approved,
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status IN ('.$this->placeholders($rejected).') THEN 1 ELSE 0 END), 0) as rejected',
                $rejected,
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status IN ('.$this->placeholders($completed).') THEN 1 ELSE 0 END), 0) as completed',
                $completed,
            )
            ->first();
    }

    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    private function purchaseOrderActionQueue(User $user): ?Builder
    {
        return PurchaseOrderActionQueue::for($user);
    }

    private function applyRequestFilters(Builder $query, Request $request): Builder
    {
        $search = trim($request->string('search')->toString());
        if ($search !== '') {
            $this->searchRequests($query, $search);
        }

        if ($request->filled('priority') && $request->user()?->canViewPriority()) {
            $query->where('priority_level', $request->string('priority')->toString());
        }

        if ($request->filled('date_from')) {
            $query->whereDate('updated_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('updated_at', '<=', $request->date('date_to'));
        }

        return $query;
    }

    private function searchRequests(Builder $query, string $search): Builder
    {
        $term = $this->likeTerm($search);

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('request_number', 'like', $term)
                ->orWhere('title', 'like', $term)
                ->orWhere('department_campaign', 'like', $term)
                ->orWhereHas('purchaseOrder', fn (Builder $order) => $order->where('po_number', 'like', $term))
                ->orWhereHas('budgetRequest', fn (Builder $budget) => $budget->where('budget_request_number', 'like', $term))
                ->orWhereHas('requestor', fn (Builder $requestor) => $requestor->where('name', 'like', $term));
        });
    }

    private function actionableRequests(User $user): Builder
    {
        if (($purchaseOrderQueue = $this->purchaseOrderActionQueue($user)) !== null) {
            return $purchaseOrderQueue;
        }

        $query = PurchaseRequest::query()->visibleTo($user);
        $role = $user->activeRole()?->slug;

        if ($role === RoleSlug::Admin->value) {
            return $query->whereIn('status', [
                PurchaseRequestStatus::Draft->value,
                PurchaseRequestStatus::Returned->value,
                PurchaseRequestStatus::ForOmApproval->value,
                PurchaseRequestStatus::ForTeamScreening->value,
                PurchaseRequestStatus::ForItScreening->value,
                PurchaseRequestStatus::ForPremisesScreening->value,
                PurchaseRequestStatus::ForProcurement->value,
                PurchaseRequestStatus::ForCanvassing->value,
                PurchaseRequestStatus::ForSupplierApproval->value,
                PurchaseRequestStatus::ForBudgetProcessing->value,
                PurchaseRequestStatus::ForBudgetMaker->value,
                PurchaseRequestStatus::ForBudgetChecker->value,
                PurchaseRequestStatus::ForBudgetProceeder->value,
                PurchaseRequestStatus::AwaitingPresidentFinalApproval->value,
                PurchaseRequestStatus::ForPayment->value,
                PurchaseRequestStatus::ForReceiving->value,
                PurchaseRequestStatus::ForInventoryRelease->value,
            ]);
        }

        if (in_array($role, [RoleSlug::Requestor->value, RoleSlug::TeamLeader->value, RoleSlug::Tl->value], true)) {
            return $query
                ->where('requestor_id', $user->id)
                ->whereIn('status', [PurchaseRequestStatus::Draft->value, PurchaseRequestStatus::Returned->value]);
        }

        if (in_array($role, [RoleSlug::OmSmtsApprover->value, RoleSlug::Om->value, RoleSlug::Smt->value], true)) {
            return $query->whereRaw('1 = 0');
        }

        if ($role === RoleSlug::ItStaff->value) {
            return $this->screeningActions($query, $user);
        }

        if ($role === RoleSlug::PremisesStaff->value) {
            return $this->screeningActions($query, $user);
        }

        if ($role === RoleSlug::CanvassingStaff->value) {
            return $query->where('status', PurchaseRequestStatus::ForCanvassing->value);
        }

        if ($role === RoleSlug::PurchasingTeam->value) {
            return $query->where(function (Builder $query): void {
                $query->whereIn('status', [PurchaseRequestStatus::ForProcurement->value, PurchaseRequestStatus::ForCanvassing->value])
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', PurchaseRequestStatus::ForBudgetProcessing->value)
                            ->whereDoesntHave('budgetRequest');
                    });
            });
        }

        if (in_array($role, [RoleSlug::ProcurementStaff->value, RoleSlug::Purchasing->value], true)) {
            return $query->where(function (Builder $query) {
                $query->whereIn('status', [
                    PurchaseRequestStatus::ForProcurement->value,
                    PurchaseRequestStatus::ForCanvassing->value,
                    PurchaseRequestStatus::ForBudgetMaker->value,
                    PurchaseRequestStatus::ForBudgetChecker->value,
                    PurchaseRequestStatus::ForBudgetProceeder->value,
                    PurchaseRequestStatus::AwaitingPresidentFinalApproval->value,
                    PurchaseRequestStatus::ForPayment->value,
                    PurchaseRequestStatus::ForReceiving->value,
                ])->orWhere(function (Builder $query) {
                    $query->where('status', PurchaseRequestStatus::ForBudgetProcessing->value)
                        ->whereDoesntHave('purchaseOrder');
                });
            });
        }

        if ($role === RoleSlug::President->value) {
            return $query->whereIn('status', [
                PurchaseRequestStatus::ForSupplierApproval->value,
            ]);
        }

        if ($role === RoleSlug::BudgetMaker->value) {
            return $query->readyForBudgetRequest();
        }

        if (in_array($role, [RoleSlug::BudgetChecker->value, RoleSlug::BudgetProceeder->value], true)) {
            return $query->whereRaw('1 = 0');
        }

        if ($role === RoleSlug::Finance->value) {
            return $query->where('status', PurchaseRequestStatus::ForPayment->value);
        }

        if (in_array($role, [RoleSlug::Inventory->value, RoleSlug::InventoryStaff->value], true)) {
            return $query->whereIn('status', [PurchaseRequestStatus::ForReceiving->value, PurchaseRequestStatus::ForInventoryRelease->value]);
        }

        return $query->whereRaw('1 = 0');
    }


    /**
     * Shared unclaimed requests and the current screener's unfinished work.
     */
    private function screeningActions(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user) {
            $teamStatus = $user->hasRole(RoleSlug::ItStaff->value)
                ? PurchaseRequestStatus::ForItScreening->value
                : PurchaseRequestStatus::ForPremisesScreening->value;
            $query->where(function (Builder $unclaimed) use ($teamStatus, $user): void {
                $unclaimed->whereNull('assigned_user_id')->whereIn('status', [
                    PurchaseRequestStatus::ForTeamScreening->value,
                    $teamStatus,
                ])->forScreeningTeam($user->hasRole(RoleSlug::ItStaff->value) ? 'IT' : 'PREMISES');
            })
                ->orWhere(function (Builder $owned) use ($user): void {
                    $owned->where('assigned_user_id', $user->id)
                        ->whereIn('status', [
                            PurchaseRequestStatus::ForItScreening->value,
                            PurchaseRequestStatus::ForPremisesScreening->value,
                            PurchaseRequestStatus::ForProcurement->value,
                        ])
                        ->whereNotIn('id', PurchaseRequest::query()->processedBy($user)->select('purchase_requests.id'));
                });
        });
    }

    /** Value of the recommended supplier quotation (VAT inclusive), else the requested total. */
    private function decisionAmount(PurchaseRequest $purchaseRequest): float
    {
        $quotations = $purchaseRequest->quotations;

        return $quotations->isNotEmpty()
            ? $quotations->sum(fn ($quotation) => $quotation->vatBreakdown()['total'])
            : (float) $purchaseRequest->total_amount;
    }

    /** @return array{label: string, url: string}|null */
    private function actionDetails(User $user, PurchaseRequest $purchaseRequest): ?array
    {
        if ($purchaseRequest->status === PurchaseRequestStatus::ForBudgetProcessing
            && $purchaseRequest->purchaseOrder?->isApproved()
            && $purchaseRequest->purchaseOrder?->budget_campaign_id !== null
            && ! $purchaseRequest->budgetRequest()->exists()
            && Gate::forUser($user)->allows('prepareBudgetRequest', $purchaseRequest)) {
            return [
                'label' => 'Prepare budget',
                'url' => route('budget.create', ['purchase_request_id' => $purchaseRequest->id]),
            ];
        }

        if ($purchaseRequest->status === PurchaseRequestStatus::ForBudgetProcessing
            && ! $purchaseRequest->purchaseOrder
            && Gate::forUser($user)->allows('prepare', PurchaseOrder::class)) {
            return [
                'label' => 'Create purchase order',
                'url' => route('purchase-requests.show', $purchaseRequest).'#purchase-order-actions',
            ];
        }

        $purchaseOrderAction = match ($purchaseRequest->purchaseOrder?->status) {
            'FOR_CHECKING' => ['check', 'Check purchase order', 'purchase-order-check-actions'],
            'FOR_ACKNOWLEDGEMENT' => ['note', 'Note purchase order', 'purchase-order-acknowledgement-actions'],
            'FOR_APPROVAL' => ['approve', 'Approve purchase order', 'purchase-order-approval-actions'],
            default => null,
        };

        if ($purchaseOrderAction !== null) {
            [$ability, $label, $anchor] = $purchaseOrderAction;

            if (Gate::forUser($user)->allows($ability, $purchaseRequest->purchaseOrder)) {
                return [
                    'label' => $label,
                    'url' => route('purchase-requests.show', $purchaseRequest).'#'.$anchor,
                ];
            }
        }
        [$ability, $label, $anchor, $route] = match ($purchaseRequest->status) {
            PurchaseRequestStatus::Draft => ['update', 'Complete draft', null, 'purchase-requests.edit'],
            PurchaseRequestStatus::Returned => ['update', 'Revise and resubmit', null, 'purchase-requests.edit'],
            PurchaseRequestStatus::ForOmApproval => ['reviewOm', 'Review request', 'om-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForTeamScreening => ['claim', 'Claim for screening', 'screening-claim-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForItScreening,
            PurchaseRequestStatus::ForPremisesScreening => $purchaseRequest->assigned_user_id === null
                && ! ($purchaseRequest->status === PurchaseRequestStatus::ForItScreening
                    && $user->hasRole(RoleSlug::ItStaff->value))
                ? ['claim', 'Claim for screening', 'screening-claim-actions', 'purchase-requests.show']
                : ['screen', 'Screen request', 'screening-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForProcurement => ['manageCanvassing', 'Add quotation', 'canvassing-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForCanvassing => ['manageCanvassing', 'Add quotation', 'canvassing-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForSupplierApproval => ['reviewPresident', 'Review supplier selection', 'supplier-approval-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForBudgetProcessing => $purchaseRequest->purchaseOrder
                ? ['manageBudget', 'Prepare budget request', 'budget-request-actions', 'purchase-requests.show']
                : ['manageCanvassing', 'Create purchase order', 'purchase-order-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForBudgetMaker,
            PurchaseRequestStatus::ForBudgetChecker,
            PurchaseRequestStatus::ForBudgetProceeder,
            PurchaseRequestStatus::AwaitingPresidentFinalApproval => ['reviewBudgetFinal', 'Final budget', 'final-approval-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForPayment => ['processPayment', 'Record payment', 'payment-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForReceiving => ['receiveGoods', 'Receive goods', 'receiving-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForInventoryRelease => ['releaseInventory', 'Release items', 'inventory-release-actions', 'purchase-requests.show'],
            default => abort(500, 'Unsupported dashboard action status.'),
        };

        if (! Gate::forUser($user)->allows($ability, $purchaseRequest)) {
            return null;
        }

        $url = route($route, $purchaseRequest);

        return [
            'label' => $label,
            'url' => $anchor ? $url.'#'.$anchor : $url,
        ];
    }

    /** @return array<string, string> */
    private function profile(User $user): array
    {
        return match ($user->activeRole()?->slug) {
            RoleSlug::PoWorkflow->value, RoleSlug::PoChecker->value, RoleSlug::PoNoted->value, RoleSlug::PoApprover->value => [
                'eyebrow' => 'Purchase Order workflow',
                'description' => 'Review only the Purchase Orders assigned to your configured workflow stage.',
                'actionLabel' => 'Purchase Orders for my action',
            ],
            RoleSlug::Requestor->value, RoleSlug::TeamLeader->value => [
                'eyebrow' => 'Request workspace',
                'description' => 'Track your requests and complete drafts or returned submissions that need your attention.',
                'actionLabel' => 'Requests for my action',
            ],
            RoleSlug::OmSmtsApprover->value => [
                'eyebrow' => 'Workflow updates',
                'description' => 'OM and SMT receive email updates. Requests go directly to IT / Premises screening.',
                'actionLabel' => 'Requests for my action',
            ],
            RoleSlug::ItStaff->value => [
                'eyebrow' => 'IT screening',
                'description' => 'Assess IT requests against available inventory and record screening outcomes.',
                'actionLabel' => 'Awaiting IT screening',
            ],
            RoleSlug::PremisesStaff->value => [
                'eyebrow' => 'Premises screening',
                'description' => 'Assess premises requests against available inventory and record screening outcomes.',
                'actionLabel' => 'Awaiting premises screening',
            ],
            RoleSlug::CanvassingStaff->value => [
                'eyebrow' => 'Canvassing operations',
                'description' => 'Compare supplier quotations and submit the recommended supplier for approval.',
                'actionLabel' => 'Awaiting canvassing',
            ],
            RoleSlug::ProcurementStaff->value, RoleSlug::Purchasing->value => [
                'eyebrow' => 'Procurement operations',
                'description' => 'Manage requests at authorized canvassing, supplier, purchase order, and downstream workflow stages.',
                'actionLabel' => 'Procurement actions',
            ],
            RoleSlug::PurchasingTeam->value => [
                'eyebrow' => 'Purchasing team',
                'description' => 'Create canvassing recommendations, purchase orders, and budget requests.',
                'actionLabel' => 'Creator actions',
            ],
            RoleSlug::BudgetMaker->value, RoleSlug::BudgetChecker->value, RoleSlug::BudgetProceeder->value => [
                'eyebrow' => 'Budget workflow',
                'description' => 'Process requests currently at an authorized budget checkpoint.',
                'actionLabel' => 'Budget actions',
            ],
            RoleSlug::Finance->value => [
                'eyebrow' => 'Finance operations',
                'description' => 'Process requests released for payment after final approval.',
                'actionLabel' => 'Awaiting payment',
            ],
            RoleSlug::Inventory->value, RoleSlug::InventoryStaff->value => [
                'eyebrow' => 'Inventory operations',
                'description' => 'Receive approved and paid purchases and complete their inventory records.',
                'actionLabel' => 'Awaiting receiving',
            ],
            RoleSlug::President->value => [
                'eyebrow' => 'Executive approvals',
                'description' => 'Review supplier selections and requests awaiting final approval.',
                'actionLabel' => 'Pending approvals',
            ],
            RoleSlug::Admin->value => [
                'eyebrow' => 'System overview',
                'description' => 'Monitor system-wide requests, workflow queues, accounts, and authorized audit activity.',
                'actionLabel' => 'Pending workflow actions',
            ],
            default => [
                'eyebrow' => 'Operational overview',
                'description' => 'View requests made available to your account by the current authorization rules.',
                'actionLabel' => 'Requests for my action',
            ],
        };
    }

    /** @return list<array{label: string, value: int, tone: string, icon: string}> */
    private function kpis(User $user, object $summary, int $actionCount, ?User $userSummary): array
    {
        $isAdmin = $user->hasRole(RoleSlug::Admin->value);
        $isOwnerRole = $user->hasAnyRole([
            RoleSlug::Requestor->value,
            RoleSlug::TeamLeader->value,
        ]);

        $kpis = [
            [
                'label' => $isAdmin ? 'Total Requests' : ($isOwnerRole ? 'My Requests' : 'Visible Requests'),
                'value' => (int) $summary->total,
                'tone' => 'primary',
                'icon' => 'file-text',
            ],
            [
                'label' => 'Pending Requests',
                'value' => (int) $summary->pending,
                'tone' => 'warning',
                'icon' => 'hourglass',
            ],
            [
                'label' => $this->profile($user)['actionLabel'],
                'value' => $actionCount,
                'tone' => 'info',
                'icon' => 'exclamation-circle',
            ],
            [
                'label' => 'Approved',
                'value' => (int) $summary->approved,
                'tone' => 'success',
                'icon' => 'check-circle',
            ],
            [
                'label' => 'Rejected / Cancelled',
                'value' => (int) $summary->rejected,
                'tone' => 'danger',
                'icon' => 'x-circle',
            ],
            [
                'label' => 'Completed',
                'value' => (int) $summary->completed,
                'tone' => 'success',
                'icon' => 'shield-check',
            ],
        ];

        if ($isAdmin && $userSummary) {
            $kpis[] = [
                'label' => 'Active Users',
                'value' => (int) $userSummary->active,
                'tone' => 'secondary',
                'icon' => 'users',
            ];
        }

        return $kpis;
    }
}
