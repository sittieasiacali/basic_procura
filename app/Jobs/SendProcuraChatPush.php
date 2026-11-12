<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\User;
use App\Notifications\ProcurementUpdate;
use App\Services\ProcuraChat;
use App\Support\ProcuraChatAccess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class SendProcuraChatPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function __construct(public int $messageId) {}

    public function handle(ProcuraChat $chat): void
    {
        $message = ChatMessage::query()->with('purchaseRequest')->find($this->messageId);
        if (! $message) {
            return;
        }
        $recipients = $chat->recipients($message);
        foreach ($recipients as $recipient) {
            // These entries also notify SMT in the web application.
            if (! $recipient->notifications()->where('data->chat_message_id', $message->id)->exists()) {
                $recipient->notify(new ProcurementUpdate([
                    'title' => $message->purchaseRequest->request_number,
                    'message' => $message->kind === 'workflow' ? 'Workflow updated. Open PROCURA Chat.' : 'New message in PROCURA Chat.',
                    'purchase_request_id' => $message->purchase_request_id,
                    'chat_message_id' => $message->id, 'action' => 'PROCURA_CHAT_UPDATE', 'status' => $message->purchaseRequest->status->value, 'url' => route('chat.index', ['request' => $message->purchase_request_id]),
                ]));
            }
        }
        if (! config('procura_chat.push_enabled')) {
            return;
        }
        $devices = DB::table('mobile_devices')->whereIn('user_id', $recipients->modelKeys())->get();
        foreach ($devices as $device) {
            $token = PersonalAccessToken::find($device->personal_access_token_id);
            $user = User::find($device->user_id);
            if (! $token || ! $user || ! $token->can('procura-chat') || ($token->expires_at && $token->expires_at->isPast())) {
                continue;
            }
            $ability = collect($token->abilities)->first(fn ($item) => str_starts_with($item, 'role:'));
            $role = $user->assignedRoles()->firstWhere('id', (int) substr((string) $ability, 5));
            if (! $role) {
                continue;
            }
            $user->useWorkspaceRole($role);
            if (! ProcuraChatAccess::canView($user, $message->purchaseRequest)) {
                continue;
            }
            $http = Http::acceptJson()->timeout(15);
            if (config('procura_chat.expo_access_token')) {
                $http = $http->withToken(config('procura_chat.expo_access_token'));
            }
            // Keep request contents off the lock screen.
            $response = $http->post('https://exp.host/--/api/v2/push/send', [
                'to' => $device->push_token, 'title' => 'PROCURA Chat',
                'body' => $message->kind === 'workflow' ? 'A request has a workflow update. Open the app to review.' : 'You have a new request message.',
                'sound' => 'default', 'priority' => 'high', 'channelId' => 'procura-approvals',
                'data' => ['requestId' => $message->purchase_request_id],
            ])->throw();
            $ticket = $response->json('data');
            if (($ticket['status'] ?? null) === 'error') {
                if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    DB::table('mobile_devices')->where('id', $device->id)->delete();
                } else {
                    Log::warning('PROCURA push ticket rejected', ['device_id' => $device->id, 'error' => $ticket['details']['error'] ?? 'Unknown']);
                }
            }
            // Expo acceptance is not proof of delivery. Check its receipt asynchronously.
            if (isset($ticket['id'])) {
                CheckProcuraPushReceipt::dispatch($device->id, $ticket['id'])->delay(now()->addMinutes(15));
            }
        }
    }
}
