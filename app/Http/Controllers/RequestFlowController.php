<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RequestFlowController extends Controller
{
    /** Keep bookmarked workflow URLs pointed at the current dashboard. */
    public function __invoke(Request $request): RedirectResponse
    {
        $filters = $request->only(['search', 'priority', 'date_from', 'date_to']);

        if ($request->user()->hasRole(RoleSlug::PurchasingTeam->value)) {
            $workflow = match ($request->query('stage')) {
                'canvassing' => 'canvassing',
                'purchase-order' => 'purchase-order',
                'budget-request' => 'budget-request',
                default => null,
            };

            if ($workflow !== null) {
                $filters['workflow'] = $workflow;
            }
        }

        return redirect()->route('dashboard', array_filter($filters, fn ($value) => is_string($value) && $value !== ''));
    }

    public function claim(
        Request $request,
        PurchaseRequest $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
    ): RedirectResponse {
        $this->authorize('claim', $purchaseRequest);

        DB::transaction(function () use ($request, $purchaseRequest, $workflow): void {
            $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->authorize('claim', $lockedRequest);

            if (in_array($lockedRequest->status, [
                PurchaseRequestStatus::ForTeamScreening,
                PurchaseRequestStatus::ForItScreening,
                PurchaseRequestStatus::ForPremisesScreening,
            ], true)) {
                [$requestType, $screeningStatus] = match ($request->user()->activeRole()?->slug) {
                    RoleSlug::ItStaff->value => ['IT', PurchaseRequestStatus::ForItScreening],
                    RoleSlug::PremisesStaff->value => ['PREMISES', PurchaseRequestStatus::ForPremisesScreening],
                    default => abort(403),
                };

                $previousRequestType = $lockedRequest->request_type;

                $lockedRequest->forceFill([
                    'request_type' => $requestType,
                    'assigned_user_id' => $request->user()->id,
                ])->save();

                if ($lockedRequest->status !== $screeningStatus) {
                    $workflow->transition(
                        $lockedRequest,
                        $screeningStatus,
                        $request->user(),
                        'SCREENING_REQUEST_CLASSIFIED_AND_CLAIMED',
                        "Request classified and claimed for {$requestType} screening.",
                        metadata: ['screening_team' => $requestType, 'previous_request_type' => $previousRequestType],
                    );
                } else {
                    $lockedRequest->auditLogs()->create([
                        'user_id' => $request->user()->id,
                        'action' => 'SCREENING_REQUEST_CLASSIFIED_AND_CLAIMED',
                        'previous_status' => $lockedRequest->status->value,
                        'new_status' => $lockedRequest->status->value,
                        'remarks' => "Request classified and claimed for {$requestType} screening.",
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'metadata' => ['role' => $request->user()->activeRole()?->slug, 'screening_team' => $requestType, 'previous_request_type' => $previousRequestType],
                    ]);
                }
            }
        });

        return redirect()
            ->route('purchase-requests.show', $purchaseRequest)
            ->with('success', 'Request claimed. Complete the screening decision below.');
    }

    public function unclaim(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        DB::transaction(function () use ($request, $purchaseRequest): void {
            $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->authorize('unclaim', $lockedRequest);

            $claim = $lockedRequest->auditLogs()
                ->where('action', 'SCREENING_REQUEST_CLASSIFIED_AND_CLAIMED')
                ->latest('id')->first();
            // A request the requestor sent to a team stays in that team's queue. An older request
            // (no team chosen) that was routed to IT by keyword goes back to the shared queue instead,
            // so Premises can take it if the keyword guess was wrong.
            $wasAutoRouted = $lockedRequest->requested_team === null
                && $lockedRequest->status === PurchaseRequestStatus::ForItScreening
                && $lockedRequest->auditLogs()->where(fn ($query) => $query
                    ->where('action', 'OM_APPROVED_FOR_IT_SCREENING')
                    ->orWhere(fn ($submission) => $submission
                        ->whereIn('action', ['SUBMITTED_FOR_SCREENING', 'RESUBMITTED_FOR_SCREENING'])
                        ->where('metadata->auto_routed', 'IT')))->exists();
            $previousStatus = $claim?->previous_status === PurchaseRequestStatus::ForTeamScreening->value || $wasAutoRouted
                ? PurchaseRequestStatus::ForTeamScreening
                : $lockedRequest->status;
            $previousType = match ($previousStatus) {
                PurchaseRequestStatus::ForTeamScreening => $wasAutoRouted ? 'UNCLASSIFIED' : ($claim?->metadata['previous_request_type'] ?? 'UNCLASSIFIED'),
                PurchaseRequestStatus::ForPremisesScreening => 'PREMISES',
                default => 'IT',
            };
            $currentStatus = $lockedRequest->status;
            $lockedRequest->forceFill([
                'assigned_user_id' => null,
                'request_type' => $previousType,
                'status' => $previousStatus,
                'current_responsible_role_id' => $previousStatus === PurchaseRequestStatus::ForTeamScreening
                    ? null
                    : $lockedRequest->current_responsible_role_id,
            ])->save();
            $lockedRequest->auditLogs()->create([
                'user_id' => $request->user()->id,
                'action' => 'SCREENING_REQUEST_UNCLAIMED',
                'previous_status' => $currentStatus->value,
                'new_status' => $previousStatus->value,
                'remarks' => 'Screening claim released back to the team queue.',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => ['role' => $request->user()->activeRole()?->slug],
            ]);
        });

        return redirect()->route('dashboard')->with('success', 'Request unclaimed and returned to the screening queue.');
    }
}
