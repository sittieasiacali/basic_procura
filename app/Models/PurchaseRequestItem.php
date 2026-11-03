<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseRequestItem extends Model
{
    use HasFactory, SoftDeletes;

    /** Required approval email. Kept as 'request_item' so earlier supporting attachments still count. */
    public const ATTACHMENT_EMAIL_APPROVAL = 'request_item';

    /** Optional photo of the requested item. */
    public const ATTACHMENT_PICTURE = 'item_picture';

    protected $fillable = [
        'purchase_request_id', 'description', 'quantity', 'unit',
        'estimated_unit_price', 'estimated_total', 'brand_specifications', 'reference_url',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'estimated_unit_price' => 'decimal:2', 'estimated_total' => 'decimal:2'];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** @return array<string, string> */
    public static function submissionMessages(): array
    {
        return [
            'items.*.brand_specifications.required_if' => 'Enter the quotation for every item before submitting.',
            'items.*.quantity.required_if' => 'Enter the quantity for every item before submitting.',
            'items.*.attachment.required_if' => 'Attach the email approval for every item before submitting.',
            'items.*.attachment.required' => 'Attach the email approval for every item before submitting.',
            'items.*.picture.required_if' => 'Attach a picture of every item before submitting.',
            'items.*.picture.required' => 'Attach a picture of every item before submitting.',
        ];
    }

    public function hasEmailApproval(): bool
    {
        return $this->attachments->contains('category', self::ATTACHMENT_EMAIL_APPROVAL);
    }

    public function hasPicture(): bool
    {
        return $this->attachments->contains('category', self::ATTACHMENT_PICTURE);
    }

    /** What still blocks this item from being submitted; an empty list means it is complete. */
    public function missingSubmissionDetails(): array
    {
        return array_keys(array_filter([
            'quotation' => blank($this->brand_specifications),
            'quantity' => $this->quantity === null || (float) $this->quantity <= 0,
            'email approval' => ! $this->hasEmailApproval(),
            'item picture' => ! $this->hasPicture(),
        ]));
    }
}
