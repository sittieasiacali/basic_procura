<?php

namespace App\Models;

use App\Enums\PurchaseOrderApprovalStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoRoleAssignment extends Model
{
    protected $fillable = ['stage', 'user_id'];

    protected function casts(): array
    {
        return ['stage' => PurchaseOrderApprovalStage::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}