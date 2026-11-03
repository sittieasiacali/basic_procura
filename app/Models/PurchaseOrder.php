<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\PurchaseOrderApprovalStage;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'po_number', 'purchase_request_id', 'supplier_id', 'created_by', 'issued_at',
        'expected_delivery_date', 'status', 'subtotal', 'tax_amount', 'total_amount',
        'payment_terms', 'delivery_terms', 'remarks',
        'budget_campaign_id', 'budget_campaign_account_number', 'budget_account_type',
        'budget_transaction_type', 'budget_payable_on', 'budget_fund_source',
        'budget_source_bank_account', 'main_fund', 'requested_by', 'account_number', 'account_name', 'payment_details',
        'checked_by', 'checked_at', 'checker_remarks', 'acknowledged_by', 'acknowledged_at',
        'acknowledgement_remarks', 'approved_by', 'approved_at', 'approval_remarks', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date', 'expected_delivery_date' => 'date', 'budget_payable_on' => 'date',
            'checked_at' => 'datetime', 'acknowledged_at' => 'datetime', 'approved_at' => 'datetime',
            'subtotal' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total_amount' => 'decimal:2',
        ];
    }

    /** Limit a query to POs that can be prepared as a Budget Request. */
    public function scopeEligibleForBudgetRequest(Builder $query): Builder
    {
        return $query
            ->whereIn('status', ['APPROVED', 'ISSUED'])
            ->whereNotNull('issued_at')
            ->whereNotNull('budget_campaign_id');
    }

    /** A processed PO completed final approval, or is a valid legacy issuance. */
    public function scopeProcessed(Builder $query): Builder
    {
        return $query
            ->whereIn('status', ['APPROVED', 'ISSUED'])
            ->whereNotNull('issued_at');
    }

    public function isProcessed(): bool
    {
        return $this->isApproved();
    }
    public function isIssued(): bool
    {
        return $this->isApproved();
    }

    /** Legacy ISSUED purchase orders remain valid; new orders require final PO approval. */
    public function isApproved(): bool
    {
        return in_array($this->status, ['APPROVED', 'ISSUED'], true) && $this->issued_at !== null;
    }

    /** Completed PO tasks stay editable until the PO is pulled into a Budget Request. */
    public function isLockedForTaskEdits(): bool
    {
        return $this->budgetRequest()->exists()
            || ($this->purchaseRequest?->budgetRequest()->exists() ?? false);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PurchaseOrderApproval::class);
    }

    public function approvalFor(PurchaseOrderApprovalStage $stage): ?PurchaseOrderApproval
    {
        return $this->relationLoaded('approvals')
            ? $this->approvals->firstWhere('stage', $stage)
            : $this->approvals()->where('stage', $stage->value)->first();
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function budgetCampaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'budget_campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checker(): BelongsTo { return $this->belongsTo(User::class, 'checked_by'); }
    public function acknowledger(): BelongsTo { return $this->belongsTo(User::class, 'acknowledged_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function budgetRequest(): HasOneThrough
    {
        return $this->hasOneThrough(
            BudgetRequest::class,
            BudgetRequestPurchaseOrder::class,
            'purchase_order_id',
            'id',
            'id',
            'budget_request_id',
        );
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }
}
