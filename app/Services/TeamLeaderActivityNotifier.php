<?php

namespace App\Services;

use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Notifications\TeamLeaderRequestActivity;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class TeamLeaderActivityNotifier
{
    public function notify(PurchaseRequest $purchaseRequest, string $action): void
    {
        $activity = match ($action) {
            'SUBMITTED_FOR_SCREENING' => 'submitted',
            'RESUBMITTED_FOR_SCREENING' => 'resubmitted',
            'WITHDRAWAL_REQUESTED' => 'withdrawn',
            default => null,
        };

        if ($activity === null) {
            return;
        }

        foreach ($this->addresses($purchaseRequest) as $email) {
            Notification::route('mail', $email)->notify(new TeamLeaderRequestActivity($activity, route('dashboard')));
        }
    }

    /** @return list<string> */
    public function addresses(PurchaseRequest $purchaseRequest): array
    {
        // Some workflow queues load only the requestor's name. Read the actual
        // recipient fields rather than relying on a partially loaded relation.
        $owner = User::query()->with(['role:id,slug', 'roles:id,slug'])->find($purchaseRequest->requestor_id, [
            'id', 'role_id', 'om_notification_email', 'smt_notification_email',
        ]);
        // Recipient eligibility follows assigned roles, independent of the
        // account's primary role or currently selected workspace.
        if (! $owner || (! $owner->hasAssignedRole(RoleSlug::Requestor->value)
            && ! $owner->hasAssignedRole(RoleSlug::TeamLeader->value))) {
            return [];
        }

        // Use only the OM/SMT recipients explicitly assigned by Admin to this TL.
        $addresses = ['OM' => $owner->om_notification_email, 'SMT' => $owner->smt_notification_email];
        $recipients = collect($addresses)
            ->filter(fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->map(fn (string $email) => strtolower(trim($email)))
            ->unique()
            ->values();

        $missing = collect($addresses)
            ->filter(fn ($email) => ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL))
            ->keys()
            ->all();
        if ($missing !== []) {
            Log::warning('OM/SMT activity notification emails need to be assigned by Admin.', [
                'requestor_id' => $owner->id,
                'missing_recipients' => $missing,
            ]);
        }

        return $recipients->all();
    }
}
