<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryRelease extends Model
{
    protected $fillable = [
        'inventory_item_id', 'released_by', 'recipient_user_id', 'quantity', 'released_to', 'remarks',
        'released_at', 'signature_name', 'signature_data', 'claimed_by', 'claimed_at',
        'claim_remarks', 'claim_signature_name', 'claim_signature_data',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'released_at' => 'datetime', 'claimed_at' => 'datetime'];
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(InventoryClaimProof::class);
    }
}
