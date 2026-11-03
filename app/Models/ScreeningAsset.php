<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreeningAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_screening_id', 'inventory_item_id', 'asset_tag', 'item_code', 'description', 'quantity', 'serial_number',
        'location', 'source', 'release_date', 'released_by', 'recipient',
    ];

    protected function casts(): array
    {
        return ['release_date' => 'date', 'quantity' => 'decimal:2'];
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(RequestScreening::class, 'request_screening_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
