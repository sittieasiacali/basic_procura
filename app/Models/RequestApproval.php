<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_request_id', 'user_id', 'approval_level', 'action',
        'previous_status', 'new_status', 'remarks', 'signature_name', 'signature_data', 'acted_at',
    ];

    protected function casts(): array
    {
        return ['acted_at' => 'datetime'];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function displayStatus(): string
    {
        $action = strtoupper($this->action);

        return match (true) {
            str_contains($action, 'RETURN') => 'Returned',
            str_contains($action, 'APPROV') => 'Approved',
            str_contains($action, 'PROCEED') => 'Approved',
            str_contains($action, 'SUBMIT') => 'Submitted',
            str_contains($action, 'RECOMMEND') => 'Recommended',
            $action === 'INVENTORY_UNAVAILABLE' => 'Awaiting quotations',
            $action === 'SCREENING_QUOTATIONS_RECORDED' => 'Submitted to canvassing',
            $action === 'INVENTORY_AVAILABLE' => 'Inventory available',
            $action === 'INVENTORY_RELEASED' => 'Released',
            str_contains($action, 'REJECT'),
            str_contains($action, 'DECLIN'),
            str_contains($action, 'CANCEL') => 'Disapproved',
            default => 'Pending',
        };
    }
}
