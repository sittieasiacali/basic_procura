<?php

namespace App\Models;

use App\Enums\RoleSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class InventoryItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'inventory_code', 'sku', 'asset_tag', 'goods_receipt_item_id', 'purchase_request_id',
        'logged_by', 'description', 'quantity', 'unit', 'serial_number', 'location',
        'sticker_details', 'status', 'logged_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            // SKUs are identifiers issued by Procura. Ignore imported or manually
            // supplied values so every inventory source follows the same format.
            $item->sku = static::nextSku(static::prefixFor($item->purchase_request_id, $item->logged_by));
        });
        static::created(function (self $item): void {
            $item->activities()->create([
                'actor_id' => $item->logged_by,
                'action' => 'ADDED',
                'quantity' => $item->quantity,
                'occurred_at' => $item->logged_at ?? now(),
            ]);
        });
    }

    /** Next system-issued SKU in the team's sequence, e.g. IT-00001 or PREM-00001. */
    public static function nextSku(string $prefix = 'IT'): string
    {
        $prefix = ($prefix === 'PREM' ? 'PREM' : 'IT').'-';

        return $prefix.str_pad((string) static::nextSequence('sku', $prefix), 5, '0', STR_PAD_LEFT);
    }

    /** Highest number already issued after $prefix in $column, plus one. */
    private static function nextSequence(string $column, string $prefix): int
    {
        $highest = static::withTrashed()
            ->where($column, 'like', $prefix.'%')
            ->pluck($column)
            ->map(fn (string $value) => substr($value, strlen($prefix)))
            ->filter(fn (string $number) => ctype_digit($number))
            ->map(fn (string $number) => (int) $number)
            ->max();

        return ($highest ?? 0) + 1;
    }

    public static function prefixFor(?int $purchaseRequestId, ?int $loggedBy): string
    {
        $requestType = $purchaseRequestId
            ? PurchaseRequest::withTrashed()->whereKey($purchaseRequestId)->value('request_type')
            : null;

        if ($requestType === 'PREMISES') {
            return 'PREM';
        }

        if ($requestType === 'IT') {
            return 'IT';
        }

        $role = $loggedBy ? User::query()->with('role:id,slug')->find($loggedBy)?->role?->slug : null;

        return $role === RoleSlug::PremisesStaff->value ? 'PREM' : 'IT';
    }

    public static function prefixForUser(User $user): ?string
    {
        return match ($user->role?->slug) {
            RoleSlug::ItStaff->value => 'IT',
            RoleSlug::PremisesStaff->value => 'PREM',
            default => null,
        };
    }

    /** IT and Premises manage their own stock; central inventory roles see both. */
    public function scopeVisibleToTeam(Builder $query, User $user): Builder
    {
        $prefix = static::prefixForUser($user);

        return $prefix === null ? $query : $query->where('sku', 'like', $prefix.'-%');
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'logged_at' => 'datetime'];
    }

    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function logger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }

    public function endorsements(): HasMany
    {
        return $this->hasMany(Endorsement::class);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(InventoryRelease::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(InventoryActivity::class);
    }

    /** Next inventory code for today, numbered in order: INV-20261103-0001, INV-20261103-0002, ... */
    public static function nextCode(): string
    {
        $prefix = 'INV-'.now()->format('Ymd').'-';

        return $prefix.str_pad((string) static::nextSequence('inventory_code', $prefix), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Filters shared by the Inventory page and the Finance dashboard Inventory tab.
     *
     * @param  array{search?: ?string, stock?: ?string, date_from?: ?string, date_to?: ?string}  $filters
     */
    public function scopeRegisterFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(trim((string) ($filters['search'] ?? '')), function (Builder $query, string $search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $query) => $query
                    ->where('inventory_code', 'like', $term)
                    ->orWhere('sku', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('serial_number', 'like', $term)
                    ->orWhere('location', 'like', $term)
                    ->orWhereHas('purchaseRequest', fn (Builder $request) => $request->where('request_number', 'like', $term)));
            })
            ->when(($filters['stock'] ?? null) === 'in_stock', fn (Builder $query) => $query->where('quantity', '>', 0))
            ->when(($filters['stock'] ?? null) === 'released', fn (Builder $query) => $query->where('quantity', '<=', 0))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('logged_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('logged_at', '<=', $date))
            ->with(['purchaseRequest:id,request_number,title', 'logger:id,name'])
            ->withSum('releases as released_quantity', 'quantity')
            ->latest('logged_at')
            ->latest('id');
    }
}
