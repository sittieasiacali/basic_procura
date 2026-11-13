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
        if ($user->relationLoaded('campaign') && (int) $user->campaign?->id !== (int) $user->campaign_id) {
            $user->unsetRelation('campaign');
        }
        if (! $user->campaign_id || ! $user->campaign?->is_active) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scope) use ($user) {
            self::originCampaignQuery($scope, $user);
            if ($team = self::screeningTeamFor($user)) {
                $scope->orWhere(fn (Builder $routed) => self::screeningQuery($routed, $team));
            }
        });
    }

    public static function originCampaignQuery(Builder $query, User $user): Builder
    {
        $campaignName = mb_strtolower(trim((string) $user->campaign?->name));

        return $query->where(fn (Builder $origin) => $origin->where('chat_campaign_id', $user->campaign_id)
            ->orWhere(fn (Builder $legacy) => $legacy->whereNull('chat_campaign_id')
                // A later account reassignment must not move historical requests.
                ->where(fn (Builder $saved) => $saved->whereRaw('LOWER(TRIM(department_campaign)) = ?', [$campaignName])
                    ->orWhere(fn (Builder $missing) => $missing
                        ->where(fn (Builder $blank) => $blank->whereNull('department_campaign')->orWhereRaw("TRIM(department_campaign) = ''"))
                        ->whereHas('requestor', fn (Builder $owner) => $owner->where('campaign_id', $user->campaign_id))))));
    }

    public static function screeningQuery(Builder $query, string $team): Builder
    {
        $status = $team === 'IT' ? PurchaseRequestStatus::ForItScreening->value : PurchaseRequestStatus::ForPremisesScreening->value;

        return $query->where(fn (Builder $routed) => $routed->where('requested_team', $team)
            ->orWhere('request_type', $team)
            ->orWhere('status', $status)
            ->orWhereHas('auditLogs', fn (Builder $history) => $history
                ->where('previous_status', $status)->orWhere('new_status', $status)));
    }

    /** Department OM/SMT also monitor work routed to their screening team. */
    public static function screeningTeamFor(User $user): ?string
    {
        return match (mb_strtoupper(trim((string) $user->campaign?->name))) {
            'IT DEPARTMENT' => 'IT',
            'PREMISES TEAM' => 'PREMISES',
            default => null,
        };
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
                // Prior processors retain the same history after later handoffs.
                ->orWhereHas('auditLogs', fn (Builder $history) => $history->where('user_id', $user->id)
                    ->whereNotNull('previous_status')->whereColumn('previous_status', '!=', 'new_status')));
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
                PurchaseRequestStatus::Completed, PurchaseRequestStatus::Fulfilled, PurchaseRequestStatus::Cancelled, PurchaseRequestStatus::Rejected], true)) {
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
        if (WorkflowAction::canAct($user, $purchaseRequest)) {
            return true;
        }

        // A screening claim belongs to screening and quotation preparation only.
        // Older records can retain that claim after handing off to another role.
        if (in_array($purchaseRequest->status, [PurchaseRequestStatus::ForTeamScreening, PurchaseRequestStatus::ForItScreening,
            PurchaseRequestStatus::ForPremisesScreening, PurchaseRequestStatus::ForProcurement], true)
            && $purchaseRequest->assigned_user_id) {
            return (int) $purchaseRequest->assigned_user_id === $user->id
                && $user->hasRole($purchaseRequest->request_type === 'PREMISES' ? RoleSlug::PremisesStaff->value : RoleSlug::ItStaff->value);
        }

        return $purchaseRequest->current_responsible_role_id !== null
            && (int) $purchaseRequest->current_responsible_role_id === $user->activeRole()?->id;
    }
}
