<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Notifications\ProcurementUpdate;
use App\Services\ProcuraChat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
            // PO handoffs already notify the owner and named signer synchronously.
            if (in_array((int) $recipient->id, $message->metadata['notified_user_ids'] ?? [], true)) {
                continue;
            }
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
    }
}
