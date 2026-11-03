<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryActivity extends Model
{
    protected $fillable = ['inventory_item_id', 'inventory_release_id', 'actor_id', 'action', 'quantity', 'occurred_at'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'occurred_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(InventoryRelease::class, 'inventory_release_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
