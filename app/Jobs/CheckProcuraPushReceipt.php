<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Compatibility handler so previously queued native-app receipts can finish safely. */
class CheckProcuraPushReceipt implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $deviceId, public string $receiptId) {}

    public function handle(): void
    {
        // Native push delivery has been retired. Old queued jobs perform no network requests.
    }
}
