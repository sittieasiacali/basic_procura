<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;

final class NotificationInbox
{
    public static function forUser(User $user): MorphMany
    {
        $query = $user->notifications();

        if (ProcuraChatAccess::isCampaignRole($user)) {
            $query->whereIn('data->purchase_request_id', ProcuraChatAccess::visibleQuery($user)->select('purchase_requests.id'));
        }

        return $query;
    }

    public static function requestUrl(User $user, array $data): ?string
    {
        $requestId = $data['purchase_request_id'] ?? null;
        if (! $requestId) {
            return null;
        }

        return ProcuraChatAccess::isCampaignRole($user) || isset($data['chat_message_id'])
            ? route('chat.index', ['request' => $requestId])
            : route('purchase-requests.show', $requestId);
    }
}
