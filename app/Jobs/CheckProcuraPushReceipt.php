<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckProcuraPushReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $deviceId, public string $receiptId) {}

    public function handle(): void
    {
        if (! config('procura_chat.push_enabled')) {
            return;
        }
        $http = Http::acceptJson()->timeout(15);
        if (config('procura_chat.expo_access_token')) {
            $http = $http->withToken(config('procura_chat.expo_access_token'));
        }
        $receipt = $http->post('https://exp.host/--/api/v2/push/getReceipts', ['ids' => [$this->receiptId]])
            ->throw()->json('data.'.$this->receiptId);
        if (($receipt['status'] ?? null) === 'error') {
            if (($receipt['details']['error'] ?? null) === 'DeviceNotRegistered') {
                DB::table('mobile_devices')->where('id', $this->deviceId)->delete();
            }
            Log::warning('PROCURA push delivery failed', ['device_id' => $this->deviceId, 'error' => $receipt['details']['error'] ?? 'Unknown']);
        }
    }
}
