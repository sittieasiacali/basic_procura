<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'budget_request_id', 'purchase_request_id', 'purchase_order_id', 'recorded_by',
        'payment_type', 'payment_method', 'payment_channel', 'reference_number', 'amount',
        'paid_at', 'payment_date', 'status', 'payment_status', 'invoice_number', 'details',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'date', 'payment_date' => 'date'];
    }

    /** Display code derived from the primary key, e.g. PAY-000042. */
    public function getPaymentCodeAttribute(): string
    {
        return 'PAY-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Display values for the Processed payment view modal.
     *
     * @return array<string, string>
     */
    public function viewDetails(): array
    {
        $label = fn (?string $value) => str($value ?: 'Not recorded')->replace('_', ' ')->title()->toString();

        return [
            'code' => $this->payment_code,
            'status' => $label($this->payment_status ?: $this->status),
            'amount' => '₱ '.number_format((float) $this->amount, 2),
            'method' => $label($this->payment_method),
            'channel' => $label($this->payment_channel),
            'type' => $label($this->payment_type),
            'reference' => $this->reference_number ?: 'Not recorded',
            'invoice' => $this->invoice_number ?: 'Not recorded',
            'paid_at' => ($this->paid_at ?? $this->payment_date)?->format('M j, Y') ?? 'Not recorded',
            'request' => $this->purchaseRequest ? $this->purchaseRequest->request_number.' — '.$this->purchaseRequest->title : 'Not linked',
            'purchase_order' => $this->purchaseOrder?->po_number ?? 'Not linked',
            'budget_request' => $this->budgetRequest?->budget_request_number ?? 'Not linked',
            'recorded_by' => $this->recorder?->name ?? 'Not recorded',
            'recorded_at' => $this->created_at?->format('M j, Y g:i A') ?? 'Not recorded',
            'details' => $this->details ?: 'No additional details',
            'receipts' => $this->attachments->map(fn (Attachment $attachment) => [
                'name' => $attachment->original_name,
                'url' => route('attachments.download', $attachment),
            ])->values()->all(),
            // The online / cash transaction sheet is loaded into the preview from here.
            'sheet_url' => $this->budget_request_id ? route('payments.sheet', $this) : null,
        ];
    }

    public function budgetRequest(): BelongsTo
    {
        return $this->belongsTo(BudgetRequest::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** Receipt evidence (photos or PDFs) uploaded when the payment was recorded. */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
