<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Endorsement extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'purchase_request_id', 'inventory_item_id', 'requestor_id', 'endorsed_by',
        'endorsed_at', 'received_by_requestor_at', 'status', 'acknowledgment', 'remarks',
    ];

    protected function casts(): array
    {
        return ['endorsed_at' => 'datetime', 'received_by_requestor_at' => 'datetime'];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function requestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requestor_id');
    }

    public function endorser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'endorsed_by');
    }
}
