<?php

namespace App\Models;

use App\Enums\PurchaseRequestStatus;
use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\RoleSlug;
use App\Support\PurchaseOrderLinePricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseRequest extends Model
{
    use HasFactory, SoftDeletes;

    public const PRIORITY_LEVELS = [
        'LOW' => 'Low',
        'NORMAL' => 'Normal',
        'HIGH' => 'High',
        'URGENT' => 'Urgent',
    ];

    /** Screening teams a requestor can send a request to. */
    public const REQUESTED_TEAMS = [
        'IT' => 'IT',
        'PREMISES' => 'Premises',
    ];

    /** Request categories offered for each team on the request form. */
    public const REQUEST_CATEGORIES = [
        'IT' => [
            'COMPUTERS' => 'Computers and laptops',
            'PERIPHERALS' => 'Peripherals and accessories',
            'NETWORK' => 'Network equipment',
            'PRINTERS' => 'Printers and consumables',
            'SOFTWARE' => 'Software and licenses',
            'IT_REPAIR' => 'IT repair and parts',
        ],
        'PREMISES' => [
            'FURNITURE' => 'Furniture and fixtures',
            'OFFICE_SUPPLIES' => 'Office supplies',
            'PANTRY' => 'Pantry and cleaning supplies',
            'FACILITY_REPAIR' => 'Repair and maintenance',
            'ELECTRICAL' => 'Electrical and lighting',
            'SAFETY' => 'Safety and security',
        ],
    ];

    /** @return array<string, string> Categories offered for the chosen screening team. */
    public static function categoriesForTeam(?string $team): array
    {
        return self::REQUEST_CATEGORIES[$team ?? ''] ?? [];
    }

    public static function requestCategoryLabel(?string $team, ?string $category): ?string
    {
        return $category === null ? null : (self::categoriesForTeam($team)[$category] ?? $category);
    }

    /** Purchase type recorded on older requests (no longer asked); shown on their request page. */
    public const REQUEST_SCOPES = [
        'SET' => 'Set',
        'INDIVIDUAL' => 'Individual items',
    ];

    /** Request title taken from the chosen category, e.g. "Computers and laptops". */
    public static function titleFor(?string $team, ?string $category): ?string
    {
        return self::categoriesForTeam($team)[$category ?? ''] ?? null;
    }

    protected $fillable = [
        'request_number', 'title', 'requestor_id', 'team_leader_id', 'department_campaign',
        'request_type', 'requested_team', 'request_category', 'request_scope', 'priority_level', 'purpose', 'required_date', 'delivery_location', 'status',
        'current_responsible_role_id', 'selected_supplier_id', 'total_amount', 'remarks', 'submitted_at',
        'assigned_user_id', 'purchase_order_skipped_at', 'purchase_order_skipped_by',
    ];

    public static function priorityLabel(?string $priority): ?string
    {
        return $priority === null ? null : (self::PRIORITY_LEVELS[$priority] ?? $priority);
    }

    protected function casts(): array
    {
        return [
            'required_date' => 'date',
            'status' => PurchaseRequestStatus::class,
            'total_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'withdrawal_acknowledged_at' => 'datetime',
            'purchase_order_skipped_at' => 'datetime',
        ];
    }

    /**
     * Unclaimed screening work offered to a team: requests the requestor sent to that team,
     * plus older requests saved before the Screening team field existed (no team).
     */
    public function scopeForScreeningTeam(Builder $query, string $team): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('purchase_requests.requested_team', $team)
            ->orWhereNull('purchase_requests.requested_team'));
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $user->loadMissing('role');

        if ($user->hasAnyRole([RoleSlug::PoWorkflow->value, RoleSlug::PoChecker->value, RoleSlug::PoNoted->value, RoleSlug::PoApprover->value])) {
            return $query->where(function (Builder $query) use ($user) {
                if ($user->hasPoRole(PurchaseOrderApprovalStage::PreparedBy)) {
                    $query->where(fn (Builder $preparationQueue) => $preparationQueue->eligibleForPurchaseOrderPreparation());
                }

                $query->orWhereHas('purchaseOrder.approvals', fn (Builder $approval) => $approval
                    ->where(fn (Builder $assigned) => $assigned
                        ->where('assigned_user_id', $user->id)
                        ->orWhere('acted_by', $user->id)));
            });
        }

        if ($user->hasRole(RoleSlug::CanvassingStaff->value)) {
            return $query->whereIn('status', [
                PurchaseRequestStatus::ForProcurement->value,
                PurchaseRequestStatus::ForCanvassing->value,
            ]);
        }

        if ($user->hasAnyRole([RoleSlug::ItStaff->value, RoleSlug::PremisesStaff->value])) {
            $team = $user->hasRole(RoleSlug::ItStaff->value) ? 'IT' : 'PREMISES';
            $teamStatus = $team === 'IT'
                ? PurchaseRequestStatus::ForItScreening->value
                : PurchaseRequestStatus::ForPremisesScreening->value;

            return $query->where(function (Builder $teamRequests) use ($user, $team, $teamStatus): void {
                $teamRequests->where(fn (Builder $unclaimed) => $unclaimed
                    ->whereNull('assigned_user_id')
                    ->whereIn('status', [PurchaseRequestStatus::ForTeamScreening->value, $teamStatus])
                    ->forScreeningTeam($team))
                    ->orWhere(fn (Builder $owned) => $owned
                        ->where('assigned_user_id', $user->id)
                        ->where('request_type', $team))
                    ->orWhereHas('screening', fn (Builder $screening) => $screening->where('screened_by', $user->id));
            });
        }

        if ($user->hasAnyRole([
            RoleSlug::Admin->value,
            RoleSlug::Om->value,
            RoleSlug::OmSmtsApprover->value,
            RoleSlug::Smt->value,
            RoleSlug::President->value,
            RoleSlug::ProcurementStaff->value,
            RoleSlug::Purchasing->value,
            RoleSlug::BudgetMaker->value,
            RoleSlug::PurchasingTeam->value,
            RoleSlug::BudgetChecker->value,
            RoleSlug::BudgetProceeder->value,
            RoleSlug::Finance->value,
            RoleSlug::FinanceBudgetStaff->value,
            RoleSlug::Inventory->value,
            RoleSlug::InventoryStaff->value,
        ])) {
            return $query;
        }

        if ($user->hasAnyRole([
            RoleSlug::Requestor->value,
            RoleSlug::TeamLeader->value,
            RoleSlug::Tl->value,
        ])) {
            return $query->where('requestor_id', $user->id);
        }

        return $query->where(function (Builder $query) use ($user) {
            $query->where('current_responsible_role_id', $user->activeRole()?->id)
                ->orWhereHas('screening', fn (Builder $query) => $query->where('screened_by', $user->id));
        });
    }

    /** Only canvassing submissions actually handed off by this account. */
    public function scopeCanvassingProcessedBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('auditLogs', fn (Builder $log) => $log
            ->where('user_id', $user->id)
            ->whereIn('action', [
                'CANVASSING_UPDATED',
                'CANVASSING_SUBMITTED_WITHOUT_QUOTATION',
                'CANVASSING_SUBMITTED_WITHOUT_ADDITIONAL_QUOTATION',
            ]));
    }

    public function scopeProcessedBy(Builder $query, User $user): Builder
    {
        if ($user->hasAnyRole([RoleSlug::ItStaff->value, RoleSlug::PremisesStaff->value])) {
            return $query->where(function (Builder $completed) use ($user): void {
                $completed->whereHas('auditLogs', fn (Builder $log) => $log
                    ->where('user_id', $user->id)
                    ->whereIn('action', ['SCREENING_QUOTATIONS_RECORDED', 'SCREENING_QUOTATION_RECOMMENDED', 'SCREENING_SUBMITTED_WITHOUT_QUOTATION']))
                    ->orWhere(fn (Builder $released) => $released
                        ->whereHas('auditLogs', fn (Builder $log) => $log->where('user_id', $user->id)->where('action', 'INVENTORY_AVAILABLE'))
                        ->whereHas('auditLogs', fn (Builder $log) => $log->where('action', 'INVENTORY_RELEASED')));
            });
        }

        return $query->whereHas('auditLogs', fn (Builder $log) => $log
            ->where('user_id', $user->id)
            ->whereIn('action', array_keys(\App\Support\RequestStageReceipt::handoffLabels())));
    }

    public function requestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requestor_id');
    }

    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function withdrawalAcknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawal_acknowledged_by');
    }

    public function withdrawalRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'withdrawal_role_id');
    }

    /**
     * Requests which have completed supplier approval and can be prepared as a PO.
     */
    public function scopeEligibleForPurchaseOrderPreparation(Builder $query): Builder
    {
        return $query
            ->where('status', PurchaseRequestStatus::ForBudgetProcessing->value)
            ->whereNotNull('selected_supplier_id')
            ->whereDoesntHave('purchaseOrder')
            ->whereHas('quotations', fn (Builder $quotation) => $quotation
                ->where('is_approved', true)
                ->whereColumn('supplier_quotations.supplier_id', 'purchase_requests.selected_supplier_id'));
    }

    /** Requests with an approved Purchase Order waiting for budget preparation. */
    public function scopeReadyForBudgetRequest(Builder $query): Builder
    {
        return $query
            ->where('purchase_requests.status', PurchaseRequestStatus::ForBudgetProcessing->value)
            ->whereDoesntHave('budgetRequest')
            ->whereHas('purchaseOrder', fn (Builder $order) => $order->eligibleForBudgetRequest());
    }

    public function skippedPurchaseOrder(): bool
    {
        return $this->purchase_order_skipped_at !== null && ! $this->purchaseOrder;
    }

    /**
     * The PO a Budget Request is built from. When the PO step was skipped this is an
     * unsaved stand-in priced from the approved quotation, so budget screens read the
     * same fields either way. It has no id or PO number and must not be saved.
     */
    public function budgetPurchaseOrder(): PurchaseOrder
    {
        if ($this->purchaseOrder) {
            return $this->purchaseOrder;
        }

        $quotations = SupplierQuotation::deduplicateForReview(
            $this->quotations->where('supplier_id', $this->selected_supplier_id)->where('is_approved', true)->values()
        );
        $lineAmounts = PurchaseOrderLinePricing::amounts($this->items, $quotations);
        $campaign = $this->teamLeader?->campaign ?? $this->requestor?->campaign;

        $order = (new PurchaseOrder)->forceFill([
            'purchase_request_id' => $this->id,
            'supplier_id' => $this->selected_supplier_id,
            'subtotal' => round((float) $quotations->sum(fn (SupplierQuotation $quotation) => $quotation->vatBreakdown()['base']), 2),
            'tax_amount' => round((float) $quotations->sum(fn (SupplierQuotation $quotation) => $quotation->vatBreakdown()['vat']), 2),
            'total_amount' => round((float) $quotations->sum(fn (SupplierQuotation $quotation) => $quotation->vatBreakdown()['total']), 2),
            'budget_campaign_id' => $campaign?->id,
            'budget_campaign_account_number' => $campaign?->account_number,
            'status' => 'NOT_REQUIRED',
        ]);
        $order->setRelation('supplier', $this->selectedSupplier);
        $order->setRelation('budgetCampaign', $campaign);
        $order->setRelation('items', $this->items->map(fn (PurchaseRequestItem $item) => (new PurchaseOrderItem)->forceFill([
            'purchase_request_item_id' => $item->id,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'unit_price' => PurchaseOrderLinePricing::unitPrice($lineAmounts[$item->id] ?? 0, (float) ($item->quantity ?: 1)),
            'total_price' => $lineAmounts[$item->id] ?? 0,
            'specifications' => $item->brand_specifications,
        ])));

        return $order;
    }

    public function purchaseOrderSkipper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchase_order_skipped_by');
    }

    public function teamLeader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_leader_id');
    }

    public function responsibleRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'current_responsible_role_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function selectedSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'selected_supplier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(RequestApproval::class);
    }

    public function latestReturnApproval(): HasOne
    {
        return $this->hasOne(RequestApproval::class)->ofMany(
            ['acted_at' => 'max', 'id' => 'max'],
            fn (Builder $query) => $query->where('new_status', PurchaseRequestStatus::Returned->value),
        );
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(SupplierQuotation::class);
    }

    public function purchaseOrder(): HasOne
    {
        return $this->hasOne(PurchaseOrder::class);
    }

    public function budgetRequest(): HasOneThrough
    {
        return $this->hasOneThrough(
            BudgetRequest::class,
            BudgetRequestPurchaseOrder::class,
            'purchase_request_id',
            'id',
            'id',
            'budget_request_id',
        );
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function endorsements(): HasMany
    {
        return $this->hasMany(Endorsement::class);
    }

    public function screening(): HasOne
    {
        return $this->hasOne(RequestScreening::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    public function canvassingActor(): ?User
    {
        foreach (['CANVASS_COMPLETED', 'CANVASSING_UPDATED'] as $action) {
            $event = $this->auditLogs()
                ->with('user')
                ->where('action', $action)
                ->whereNotNull('user_id')
                ->latest('created_at')
                ->latest('id')
                ->first();

            if ($event?->user) {
                return $event->user;
            }
        }

        $quotation = $this->quotations()
            ->with('creator')
            ->when(
                $this->selected_supplier_id,
                fn (Builder $query, int $supplierId) => $query->where('supplier_id', $supplierId),
                fn (Builder $query) => $query->where('is_recommended', true),
            )
            ->whereNotNull('created_by')
            ->latest('created_at')
            ->latest('id')
            ->first();

        return $quotation?->creator;
    }

    /** @param list<string> $approvalLevels */
    public function approvalStatus(array $approvalLevels): string
    {
        $approval = $this->approvals
            ->whereIn('approval_level', $approvalLevels)
            ->sortByDesc(fn (RequestApproval $approval) => sprintf(
                '%s-%010d',
                $approval->acted_at?->format('YmdHis.u') ?? '',
                $approval->id,
            ))
            ->first();

        return $approval?->displayStatus() ?? 'Pending';
    }
}
