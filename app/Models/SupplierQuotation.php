<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class SupplierQuotation extends Model
{
    use HasFactory, SoftDeletes;

    public const VAT_RATE = 12.00;

    public const VAT_TREATMENTS = ['EXCLUSIVE', 'INCLUSIVE', 'EXEMPT'];

    public const DELIVERY_LOCATIONS = [
        'Seneca Plaza — E. Rodriguez, Quezon City',
        'Seneca World — E. Rodriguez, Quezon City',
        'Seneca Prime — E. Rodriguez, Quezon City',
        'Triumph Square — Quezon Avenue',
        'Tomas Morato — Quezon City',
        'JMT Building — Ortigas',
    ];

    protected $fillable = [
        'purchase_request_id', 'supplier_id', 'purchase_request_item_id', 'created_by',
        'item_or_service', 'price', 'vat_treatment', 'base_amount', 'vat_rate', 'vat_amount', 'total_amount', 'brand_quality', 'delivery_terms', 'delivery_lead_time',
        'warranty', 'quotation_url', 'bank_details', 'remarks', 'delivery_location', 'is_recommended', 'is_approved',
        'recommended_by', 'recommended_at', 'approved_by', 'approved_at', 'quoted_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2', 'base_amount' => 'decimal:2', 'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'is_recommended' => 'boolean',
            'is_approved' => 'boolean', 'quoted_at' => 'date', 'recommended_at' => 'datetime', 'approved_at' => 'datetime',
        ];
    }

    /** @return array{treatment: string, base: float, rate: float, vat: float, total: float} */
    public function vatBreakdown(): array
    {
        // Rows saved before VAT support retain their recorded amount unchanged.
        if ($this->base_amount === null || $this->total_amount === null) {
            $amount = (float) $this->price;

            return ['treatment' => 'EXEMPT', 'base' => $amount, 'rate' => 0.0, 'vat' => 0.0, 'total' => $amount];
        }

        return [
            'treatment' => $this->vat_treatment ?: 'EXEMPT',
            'base' => (float) $this->base_amount,
            'rate' => (float) ($this->vat_rate ?? 0),
            'vat' => (float) ($this->vat_amount ?? 0),
            'total' => (float) $this->total_amount,
        ];
    }
    public function quotationDestination(): ?string
    {
        $value = trim((string) $this->quotation_url);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return url($value);
        }

        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host = strtolower(rtrim((string) parse_url($value, PHP_URL_HOST), '.'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $sampleHosts = ['example.com', 'example.net', 'example.org'];
        $isReservedSampleHost = in_array($host, $sampleHosts, true)
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.invalid')
            || str_ends_with($host, '.example');

        return $isReservedSampleHost ? null : $value;
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class, 'purchase_request_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function recommender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recommended_by');
    }

    public function recommendationStatus(): string
    {
        if ($this->recommended_at === null) {
            return 'Not Recorded';
        }

        return $this->is_recommended ? 'Recommended' : 'Not Recommended';
    }

    /**
     * Return one review choice for repeated identical supplier submissions.
     * Item-linked lines remain distinct. A legacy whole-request row is
     * consolidated when the same supplier, scope, and amount already has an
     * item-linked row, even if ancillary notes or document URLs differ.
     */
    public static function deduplicateForReview(Collection $quotations): Collection
    {
        $kept = collect();

        foreach ($quotations as $quotation) {
            $duplicate = $kept->first(function (self $existing) use ($quotation): bool {
                $sameLogicalScope = $existing->supplier_id === $quotation->supplier_id
                    && (float) $existing->price === (float) $quotation->price
                    && mb_strtolower(trim((string) $existing->item_or_service)) === mb_strtolower(trim((string) $quotation->item_or_service));
                $mixedLegacyLinkage = ($existing->purchase_request_item_id === null) !== ($quotation->purchase_request_item_id === null);

                if ($sameLogicalScope && $mixedLegacyLinkage) {
                    return true;
                }

                $sameDetails = $sameLogicalScope
                    && trim((string) $existing->delivery_lead_time) === trim((string) $quotation->delivery_lead_time)
                    && trim((string) $existing->warranty) === trim((string) $quotation->warranty)
                    && trim((string) $existing->quotation_url) === trim((string) $quotation->quotation_url)
                    && trim((string) $existing->remarks) === trim((string) $quotation->remarks);

                if (! $sameDetails) {
                    return false;
                }

                return $existing->purchase_request_item_id === null
                    || $quotation->purchase_request_item_id === null
                    || $existing->purchase_request_item_id === $quotation->purchase_request_item_id;
            });

            if (! $duplicate) {
                $kept->push($quotation);
            } elseif ($duplicate->purchase_request_item_id === null && $quotation->purchase_request_item_id !== null) {
                $kept = $kept->map(fn (self $item) => $item->is($duplicate) ? $quotation : $item);
            }
        }

        return $kept->values();
    }
}
