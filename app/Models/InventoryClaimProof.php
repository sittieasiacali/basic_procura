<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryClaimProof extends Model
{
    protected $fillable = ['inventory_release_id', 'disk', 'path', 'original_name', 'mime_type'];

    public function release(): BelongsTo
    {
        return $this->belongsTo(InventoryRelease::class, 'inventory_release_id');
    }
}
