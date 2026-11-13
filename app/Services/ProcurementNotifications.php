<?php

namespace App\Services;

use App\Models\PurchaseRequest;
use App\Models\User;
use App\Notifications\ProcurementUpdate;
use App\Support\WorkflowUrl;
use Illuminate\Support\Facades\Gate;

class ProcurementNotifications
{
    public function send(PurchaseRequest $purchaseRequest, string $action, string $message, ?int $assignedUserId = null): array
    {
        $recipients = User::query()->where('is_active', true)->where(function ($query) use ($purchaseRequest, $assignedUserId, $action) {
            $query->whereKey($purchaseRequest->requestor_id);
            if ($assignedUserId ?? $purchaseRequest->assigned_user_id) {
                $query->orWhere('id', $assignedUserId ?? $purchaseRequest->assigned_user_id);
            }
            if ($action === 'PRESIDENT_SUPPLIER_APPROVAL_REJECTED') {
                $query->orWhereHas('role', fn ($role) => $role->whereIn('slug', ['purchasing-team', 'purchasing', 'procurement-staff', 'canvassing-staff']))
                    ->orWhereHas('roles', fn ($role) => $role->whereIn('slug', ['purchasing-team', 'purchasing', 'procurement-staff', 'canvassing-staff']));
            }
            if ($purchaseRequest->current_responsible_role_id && ! $assignedUserId) {
                $query->orWhere(fn ($roles) => $roles->withAssignedRole($purchaseRequest->current_responsible_role_id));
            }
        })->get();

        $notified = [];
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
                'url' => WorkflowUrl::route('purchase-requests.show', $purchaseRequest),
            ]));
            $notified[] = (int) $recipient->id;
        }

        return $notified;
    }
}
