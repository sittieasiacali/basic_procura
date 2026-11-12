<?php

namespace App\Services;

use App\Jobs\SendProcuraChatPush;
use App\Models\ChatMessage;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Support\ProcuraChatAccess;

class ProcuraChat
{
    public function workflow(PurchaseRequest $purchaseRequest, User $actor, string $action, ?string $remarks): void
    {
        $label = str($purchaseRequest->status->value)->replace('_', ' ')->lower()->ucfirst();
        $message = ChatMessage::query()->create([
            'purchase_request_id' => $purchaseRequest->id, 'user_id' => $actor->id, 'kind' => 'workflow',
            'body' => $label.(filled($remarks) ? "\n".$remarks : ''),
            'metadata' => ['status' => $purchaseRequest->status->value, 'action' => $action],
        ]);
        $this->notify($message);
    }

    public function activity(PurchaseRequest $purchaseRequest, User $actor, string $action, string $remarks): void
    {
        $message = ChatMessage::query()->create([
            'purchase_request_id' => $purchaseRequest->id, 'user_id' => $actor->id, 'kind' => 'activity',
            'body' => str($action)->replace('_', ' ')->lower()->ucfirst()."\n".$remarks,
            'metadata' => ['status' => $purchaseRequest->status->value, 'action' => $action],
        ]);
        $this->notify($message);
    }

    public function notify(ChatMessage $message): void
    {
        SendProcuraChatPush::dispatch($message->id)->afterCommit();
    }

    public function recipients(ChatMessage $message)
    {
        $purchaseRequest = $message->purchaseRequest;

        // Delivery rechecks current account, role and campaign permissions.
        return User::query()->where('is_active', true)->where('must_change_password', false)
            ->where('id', '!=', $message->user_id)->with(['role', 'roles', 'campaign'])->get()
            ->filter(function (User $user) use ($purchaseRequest) {
                foreach ($user->assignedRoles() as $role) {
                    $user->useWorkspaceRole($role);
                    if (ProcuraChatAccess::canView($user, $purchaseRequest)
                        && (ProcuraChatAccess::canSend($user, $purchaseRequest)
                            || ProcuraChatAccess::isCampaignRole($user))) {
                        return true;
                    }
                }

                return false;
            });
    }
}
