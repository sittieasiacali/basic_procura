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

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }
}
