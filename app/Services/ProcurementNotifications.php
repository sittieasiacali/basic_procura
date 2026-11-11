<?php

namespace App\Services;

use App\Models\PurchaseRequest;
use App\Models\User;
use App\Notifications\ProcurementUpdate;
use Illuminate\Support\Facades\Gate;

class ProcurementNotifications
{
    public function send(PurchaseRequest $purchaseRequest, string $action, string $message, ?int $assignedUserId = null): void
    {
        $recipients = User::query()->where('is_active', true)->where(function ($query) use ($purchaseRequest, $assignedUserId) {
            $query->whereKey($purchaseRequest->requestor_id);
            if ($assignedUserId ?? $purchaseRequest->assigned_user_id) {
                $query->orWhere('id', $assignedUserId ?? $purchaseRequest->assigned_user_id);
            }
            if ($purchaseRequest->current_responsible_role_id && ! $assignedUserId) {
                $query->orWhere(fn ($roles) => $roles->withAssignedRole($purchaseRequest->current_responsible_role_id));
            }
        })->get();

        foreach ($recipients as $recipient) {
            if (! Gate::forUser($recipient)->allows('view', $purchaseRequest)) {
                continue;
            }
            $recipient->notify(new ProcurementUpdate([
                'title' => $purchaseRequest->request_number,
                'message' => $message,
                'action' => $action,
                'status' => $purchaseRequest->status->value,
                'purchase_request_id' => $purchaseRequest->id,
                'url' => \App\Support\WorkflowUrl::route('purchase-requests.show', $purchaseRequest),
            ]));
        }
    }
}
