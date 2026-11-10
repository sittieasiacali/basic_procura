<?php

namespace App\Models;

use App\Enums\PurchaseOrderApprovalStage;
use App\Support\WorkflowSignature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderApproval extends Model
{
    protected $fillable = [
        'purchase_order_id', 'stage', 'assigned_user_id', 'acted_by',
        'status', 'decision', 'remarks', 'signature_name', 'signature_data', 'signed_at', 'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'stage' => PurchaseOrderApprovalStage::class,
            'signed_at' => 'datetime',
            'acted_at' => 'datetime',
        ];
    }

    public function hasValidSignature(): bool
    {
        if (blank($this->signature_name) || blank($this->signature_data) || $this->signed_at === null) {
            return false;
        }

        if ($this->acted_by === null || ($this->stage !== PurchaseOrderApprovalStage::NotedBy && (int) $this->acted_by !== (int) $this->assigned_user_id)) {
            return false;
        }

        return WorkflowSignature::isValidImage($this->signature_data);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }
}
