<?php

namespace App\Support;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ProcuraChatAccess
{
    public static function isCampaignRole(User $user): bool
    {
        return $user->hasAnyRole([RoleSlug::Om->value, RoleSlug::OmSmtsApprover->value, RoleSlug::Smt->value]);
    }

    public static function campaignQuery(Builder $query, User $user): Builder
    {
        if (! $user->campaign_id || ! $user->campaign?->is_active) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q->where('chat_campaign_id', $user->campaign_id)
            ->orWhere(fn (Builder $legacy) => $legacy->whereNull('chat_campaign_id')
                ->whereHas('requestor', fn (Builder $owner) => $owner->where('campaign_id', $user->campaign_id))));
    }

    public static function sameCampaign(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return self::campaignQuery(PurchaseRequest::query()->whereKey($purchaseRequest->id), $user)->exists();
    }

    public static function visibleQuery(User $user): Builder
    {
        $query = PurchaseRequest::query();
        if (self::isCampaignRole($user)) {
            self::campaignQuery($query, $user);
        } else {
            $query->where(fn (Builder $visible) => $visible
                ->whereIn('purchase_requests.id', PurchaseRequest::query()->visibleTo($user)->select('purchase_requests.id'))
                ->orWhere('requestor_id', $user->id)
                ->orWhereRaw('(SELECT user_id FROM audit_logs WHERE auditable_type = ? AND auditable_id = purchase_requests.id AND previous_status IS NOT NULL AND previous_status <> new_status ORDER BY id DESC LIMIT 1) = ?', [
                    (new PurchaseRequest)->getMorphClass(), $user->id,
                ]));
        }

        return $query->where(fn (Builder $q) => $q->where('status', '!=', PurchaseRequestStatus::Draft->value)
            ->orWhere('requestor_id', $user->id));
    }

    public static function canView(User $user, PurchaseRequest $purchaseRequest): bool
    {
        if (! $user->is_active || $user->must_change_password) {
            return false;
        }

        return self::visibleQuery($user)->whereKey($purchaseRequest->id)->exists();
    }

    public static function canSend(User $user, PurchaseRequest $purchaseRequest): bool
    {
        if (! self::canView($user, $purchaseRequest)
            || in_array($purchaseRequest->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Voided,
                PurchaseRequestStatus::Completed, PurchaseRequestStatus::Fulfilled, PurchaseRequestStatus::Cancelled], true)) {
            return false;
        }
        if ($user->hasRole(RoleSlug::Admin->value) || $purchaseRequest->requestor_id === $user->id) {
            return true;
        }
        // SMT observes and acknowledges; it does not become an approver or a chat handler.
        if ($user->hasRole(RoleSlug::Smt->value)) {
            return false;
        }
        if (self::isCurrentHandler($user, $purchaseRequest)) {
            return true;
        }

        // Only the actor who handed off the most recent stage can continue the discussion.
        $previous = $purchaseRequest->auditLogs()->whereNotNull('previous_status')
            ->whereColumn('previous_status', '!=', 'new_status')->latest('id')->first();

        return $previous && (int) $previous->user_id === $user->id;
    }

    public static function isCurrentHandler(User $user, PurchaseRequest $purchaseRequest): bool
    {
        if ($purchaseRequest->status === PurchaseRequestStatus::ForOmApproval) {
            return $user->hasAnyRole([RoleSlug::Om->value, RoleSlug::OmSmtsApprover->value]) && self::sameCampaign($user, $purchaseRequest);
        }
        if ($purchaseRequest->assigned_user_id) {
            return (int) $purchaseRequest->assigned_user_id === $user->id;
        }

        return ($purchaseRequest->current_responsible_role_id !== null
            && (int) $purchaseRequest->current_responsible_role_id === $user->activeRole()?->id)
            || WorkflowAction::canAct($user, $purchaseRequest);
    }
}
