<?php

namespace App\Models;

use App\Enums\PurchaseRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    public const TEAMS = ['IT' => 'IT', 'PREMISES' => 'Premises', 'PURCHASING' => 'Purchasing Team'];

    protected $fillable = ['team', 'name', 'contact_person', 'email', 'phone', 'address', 'tax_id', 'bank_details', 'is_active'];

    public static function workspaceTeam(User $user): ?string
    {
        return match ($user->activeRole()?->slug) {
            'it-staff' => 'IT',
            'premises-staff' => 'PREMISES',
            'purchasing-team', 'purchasing', 'procurement-staff', 'canvassing-staff' => 'PURCHASING',
            default => null,
        };
    }

    public static function quotationTeam(User $user, PurchaseRequest $purchaseRequest): string
    {
        $screening = $user->isAdministrator()
            && $purchaseRequest->status === PurchaseRequestStatus::ForProcurement
            && $purchaseRequest->screening?->decision === 'UNAVAILABLE';

        return self::workspaceTeam($user) ?? ($screening ? $purchaseRequest->request_type : 'PURCHASING');
    }

    public function scopeForWorkspace(Builder $query, User $user): Builder
    {
        return $user->isAdministrator() ? $query : $query->where('team', self::workspaceTeam($user) ?? 'NONE');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    public function latestQuotation(): HasOne
    {
        return $this->hasOne(SupplierQuotation::class)->ofMany(['updated_at' => 'max', 'id' => 'max']);
    }

    public function quotationDefaults(?User $user = null): array
    {
        $defaults = $this->latestQuotation?->only([
            'bank_details', 'warranty', 'quotation_url', 'vat_treatment', 'shipping_fee', 'delivery_location',
        ]) ?? [];
        $defaults['bank_details'] ??= $this->bank_details;
        $quotation = $this->latestQuotation;
        if ($quotation?->purchaseRequest) {
            $lines = SupplierQuotation::deduplicateForReview($quotation->purchaseRequest->quotations->where('supplier_id', $this->id));
            $defaults['quoted_amount'] = round((float) $lines->sum('price'), 2);
            $defaults['shipping_fee'] = number_format((float) $lines->sum('shipping_fee'), 2, '.', '');
        }
        $defaults['attachments'] = [];
        if ($quotation?->purchaseRequest && $user && $user->can('view', $quotation->purchaseRequest)) {
            $defaults['attachments'] = $quotation->attachments
                ->where('category', 'supplier_quotation')->unique(fn ($attachment) => $attachment->disk.'|'.$attachment->path)
                ->filter(fn ($attachment) => $attachment->size <= 5 * 1024 * 1024 && Storage::disk($attachment->disk)->exists($attachment->path))
                ->take(10)->map(fn ($attachment) => [
                    'id' => $attachment->id, 'name' => $attachment->original_name,
                    'url' => route('attachments.view', $attachment),
                ])->values()->all();
        }

        return $defaults;
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(SupplierQuotation::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
