<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetRequestLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'budget_request_id', 'purchase_order_id', 'campaign_id', 'source_bank_account',
        'requested_by_user_id', 'details', 'amount', 'bank_details', 'item_allocations', 'funding_allocations',
        'priority_level', 'request_campaign', 'requested_payment_method', 'requesting_team', 'payable_on', 'remarks',
    ];

    protected function casts(): array
    {
        return ['funding_allocations' => 'array', 'item_allocations' => 'array', 'amount' => 'decimal:2', 'payable_on' => 'date'];
    }

    /** Saved preparation values stay with each PO when requests are combined. */
    public function preparationData(): array
    {
        $priority = $this->priority_level ?? $this->purchaseOrder?->purchaseRequest?->priority_level ?? 'NORMAL';
        $campaign = $this->request_campaign ?? $this->purchaseOrder?->purchaseRequest?->department_campaign;

        return [
            'priority' => $priority === 'NORMAL' ? 'Medium' : PurchaseRequest::priorityLabel($priority),
            'campaign' => $campaign ?: 'N/A',
            'details' => $this->details,
            'amount' => number_format((float) $this->amount, 2, '.', ''),
            'source_account' => $this->sourceAccountSummary(),
            'mode_of_payment' => $this->requested_payment_method ?: 'To be set by Finance',
            'bank_details' => $this->bank_details ?: 'N/A',
            'requesting_team' => ($this->requesting_team ?? $campaign) ?: 'N/A',
            'date_to_pay' => $this->payable_on?->format('M d, Y') ?? ($this->priority_level === null ? $this->budgetRequest?->payable_on?->format('M d, Y') : null) ?? 'N/A',
            'remarks' => $this->remarks ?? ($this->priority_level === null ? $this->budgetRequest?->remarks : null) ?? 'N/A',
        ];
    }

    public function sourceContributions(): array
    {
        return $this->funding_allocations ?: [['campaign_id' => $this->campaign_id,
            'campaign_name' => $this->campaign?->name ?? $this->budgetRequest?->campaign?->name,
            'account_number' => $this->source_bank_account ?: $this->budgetRequest?->campaign_account_number,
            'amount' => $this->amount]];
    }

    public function sourceAccountSummary(): string
    {
        $sources = $this->sourceContributions();

        return collect($sources)->map(fn ($source) => trim(($source['campaign_name'] ?? '').' '.($source['account_number'] ?? ''))
            .(count($sources) > 1 ? ' (PHP '.number_format((float) $source['amount'], 2).')' : ''))->join('; ') ?: 'N/A';
    }

    public function budgetRequest(): BelongsTo
    {
        return $this->belongsTo(BudgetRequest::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
