<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uploaded_by', 'category', 'original_name', 'disk', 'path', 'mime_type', 'size',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Raster image types the app accepts for upload; SVG is never rendered inline. */
    public const INLINE_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function isImage(): bool
    {
        return in_array($this->mime_type, self::INLINE_IMAGE_TYPES, true);
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    /** Images and PDFs open in the in-page viewer; other files are downloaded. */
    public function isPreviewable(): bool
    {
        return $this->isImage() || $this->isPdf();
    }
}
