<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Payment;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\RequestScreening;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function download(Attachment $attachment): StreamedResponse
    {
        $this->authorizeAttachment($attachment);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    /**
     * Opens an image or PDF in the browser (inline) so it can be previewed and shown
     * full screen. Any other file type is downloaded instead of rendered.
     */
    public function view(Attachment $attachment): StreamedResponse
    {
        $this->authorizeAttachment($attachment);

        if (! $attachment->isPreviewable()) {
            return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
        }

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=600',
        ], 'inline');
    }

    private function authorizeAttachment(Attachment $attachment): void
    {
        $attachment->load('attachable');

        $purchaseRequest = match (true) {
            $attachment->attachable instanceof PurchaseRequest => $attachment->attachable,
            $attachment->attachable instanceof PurchaseRequestItem => $attachment->attachable->purchaseRequest,
            $attachment->attachable instanceof RequestScreening => $attachment->attachable->purchaseRequest,
            $attachment->attachable instanceof Payment => $attachment->attachable->purchaseRequest,
            default => null,
        };

        abort_unless($purchaseRequest, 404);
        $this->authorize('view', $purchaseRequest);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);
    }
}
