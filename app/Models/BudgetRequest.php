<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BudgetRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'budget_request_number', 'purchase_request_id', 'purchase_order_id', 'campaign_id',
        'campaign_account_number', 'account_type', 'requested_by', 'budget_custodian_id',
        'approved_by', 'maker_user_id', 'checker_user_id', 'proceeder_user_id', 'final_approval_by',
        'maker_approved_at', 'checker_approved_at', 'proceeder_approved_at', 'final_approval_at',
        'final_approval_status', 'rejection_reason', 'payment_method', 'fund_source',
        'source_bank_account', 'main_fund', 'amount', 'status', 'requested_at', 'payable_on',
        'released_at', 'remarks',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'requested_at' => 'date', 'payable_on' => 'date', 'released_at' => 'date', 'maker_approved_at' => 'datetime', 'checker_approved_at' => 'datetime', 'proceeder_approved_at' => 'datetime', 'final_approval_at' => 'datetime'];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'budget_custodian_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_user_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checker_user_id');
    }

    public function proceeder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proceeder_user_id');
    }

    public function finalApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'final_approval_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetRequestLine::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BudgetRequestPurchaseOrder::class);
    }

    public function purchaseOrders(): BelongsToMany
    {
        return $this->belongsToMany(PurchaseOrder::class, 'budget_request_purchase_orders')
            ->withPivot(['purchase_request_id', 'amount'])
            ->withTimestamps();
    }

    public function purchaseRequests(): BelongsToMany
    {
        return $this->belongsToMany(PurchaseRequest::class, 'budget_request_purchase_orders')
            ->withPivot(['purchase_order_id', 'amount'])
            ->withTimestamps();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Budget Requests that passed final approval, including those since paid. */
    public function scopeFinallyApproved(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('final_approval_status', 'APPROVED')
            ->orWhereIn('status', ['APPROVED', 'PARTIALLY_PAID', 'PAID']));
    }

    /** Next BR code for this year (BR-2026-0001). Call inside a transaction: it locks the year's codes. */
    public static function nextNumber(): string
    {
        $prefix = 'BR-'.now()->format('Y').'-';
        $last = static::withTrashed()
            ->where('budget_request_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('budget_request_number')
            ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Fold other Budget Requests into this one so one BR code lists all their POs: their
     * lines and PO allocations move here, the amount is summed, and they are soft-deleted.
     * Call inside a transaction. Budget Requests with payments are never merged.
     *
     * @param  iterable<self>  $others
     */
    public function absorb(iterable $others): void
    {
        foreach ($others as $other) {
            if ($other->is($this)) {
                continue;
            }
            if ($other->payments()->exists() || $this->payments()->exists()) {
                throw new \LogicException("Budget Request {$other->id} has payments and cannot be combined.");
            }

            $other->lines()->update(['budget_request_id' => $this->id]);
            $other->allocations()->update(['budget_request_id' => $this->id]);
            $this->amount = (float) $this->amount + (float) $other->amount;
            $other->delete();
        }

        $this->save();
        $this->unsetRelation('lines');
        $this->unsetRelation('allocations');
    }

    /** BR code once the Final budget is done; until then the PO number identifies it. */
    public function displayNumber(): string
    {
        return $this->budget_request_number
            ?? $this->lines->pluck('purchaseOrder.po_number')->filter()->first()
            ?? $this->purchaseOrder?->po_number
            ?? 'Pending BR #'.$this->id;
    }

    /**
     * One Priority Level per BR, chosen at the Final budget. BRs finalized before that rule
     * can hold mixed line priorities; the most urgent one stands for the BR.
     */
    public function priorityLevel(): string
    {
        $rank = array_flip(['LOW', 'NORMAL', 'HIGH', 'URGENT']);

        return $this->lines
            ->map(fn (BudgetRequestLine $line) => $line->priority_level ?? $line->purchaseOrder?->purchaseRequest?->priority_level ?? 'NORMAL')
            ->sortByDesc(fn (string $priority) => $rank[$priority] ?? 0)
            ->first() ?? 'NORMAL';
    }

    /** Prepared by the Budget Maker, still waiting for the Final budget. */
    public function scopeAwaitingFinance(Builder $query): Builder
    {
        // Spelled out rather than whereNot(finallyApproved) so NULL statuses still match.
        return $query->whereNotIn('status', ['APPROVED', 'PARTIALLY_PAID', 'PAID', 'REJECTED'])
            ->where(fn (Builder $query) => $query
                ->whereNull('final_approval_status')
                ->orWhereNotIn('final_approval_status', ['APPROVED', 'REJECTED']));
    }

    /** Register tab: "finance" (waiting for Finance), "finalized", or anything else for all. */
    public function scopeForStage(Builder $query, ?string $stage): Builder
    {
        return match ($stage) {
            'finance' => $query->awaitingFinance(),
            'finalized' => $query->finallyApproved(),
            default => $query,
        };
    }

    /** @return array{0: string, 1: string} Label and badge modifier for the register. */
    public function stage(): array
    {
        if ($this->final_approval_status === 'APPROVED' || in_array($this->status, ['APPROVED', 'PARTIALLY_PAID', 'PAID'], true)) {
            return ['Finalized', 'finalized'];
        }

        return $this->final_approval_status === 'REJECTED'
            ? ['Returned', 'returned']
            : ['Waiting for Final BR', 'finance'];
    }

    /** Search bar of the Created Budget Requests register. */
    public function scopeRegisterSearch(Builder $query, string $search): Builder
    {
        if ($search === '') {
            return $query;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        return $query->where(fn (Builder $query) => $query
            ->where('budget_request_number', 'like', $term)
            ->orWhere('campaign_account_number', 'like', $term)
            ->orWhereHas('campaign', fn (Builder $campaign) => $campaign->where('name', 'like', $term))
            ->orWhereHas('custodian', fn (Builder $custodian) => $custodian->where('name', 'like', $term))
            ->orWhereHas('lines', fn (Builder $line) => $line->where('details', 'like', $term)
                ->orWhere('funding_allocations', 'like', $term)
                ->orWhere('request_campaign', 'like', $term)
                ->orWhere('requesting_team', 'like', $term)
                ->orWhere('bank_details', 'like', $term)
                ->orWhere('remarks', 'like', $term))
            ->orWhereHas('lines.purchaseOrder', fn (Builder $order) => $order->where('po_number', 'like', $term)
                ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $term)))
            ->orWhereHas('payments', fn (Builder $payment) => $payment->where('reference_number', 'like', $term)));
    }
}
