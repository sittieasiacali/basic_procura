<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Http\Requests\OmDecisionRequest;
use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestWorkflow;
use App\Support\ScreeningRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OmApprovalController extends Controller
{
    public function approve(
        OmDecisionRequest $request,
        PurchaseRequest $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
    ): RedirectResponse {
        return $this->decide(
            $request,
            $purchaseRequest,
            $workflow,
            PurchaseRequestStatus::ForTeamScreening,
            'OM_APPROVED_FOR_SCREENING',
            'Request approved and routed to IT/Premises classification.',
        );
    }

    public function return(
        OmDecisionRequest $request,
        PurchaseRequest $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
    ): RedirectResponse {
        return $this->decide($request, $purchaseRequest, $workflow, PurchaseRequestStatus::Returned, 'OM_RETURNED', 'Request returned for correction.');
    }

    private function decide(
        OmDecisionRequest $request,
        PurchaseRequest $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
        PurchaseRequestStatus $newStatus,
        string $action,
        string $message,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $purchaseRequest, $workflow, $newStatus, $action) {
            $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->authorize('reviewOm', $lockedRequest);

            if ($lockedRequest->status !== PurchaseRequestStatus::ForOmApproval) {
                throw ValidationException::withMessages(['status' => 'This request is no longer awaiting OM approval.']);
            }

            $metadata = [];
            if ($newStatus === PurchaseRequestStatus::ForTeamScreening) {
                // The requestor's chosen team wins; otherwise requests made only of IT
                // equipment skip the shared queue by keyword. Anything else waits there.
                $team = $lockedRequest->requested_team;
                if ($team === null && ($itMatches = app(ScreeningRouter::class)->itMatches($lockedRequest)) !== []) {
                    $team = 'IT';
                    $metadata = ['auto_routed' => 'IT', 'matched_keywords' => $itMatches];
                } elseif ($team !== null) {
                    $metadata = ['routed_by' => 'requestor', 'requested_team' => $team, 'request_category' => $lockedRequest->request_category];
                }
                if ($team !== null) {
                    $newStatus = $team === 'PREMISES' ? PurchaseRequestStatus::ForPremisesScreening : PurchaseRequestStatus::ForItScreening;
                    $action = $team === 'PREMISES' ? 'OM_APPROVED_FOR_PREMISES_SCREENING' : 'OM_APPROVED_FOR_IT_SCREENING';
                }
                $lockedRequest->forceFill([
                    'request_type' => $team ?? 'UNCLASSIFIED',
                    'assigned_user_id' => null,
                ])->save();
            }

            $workflow->transition(
                $lockedRequest,
                $newStatus,
                $request->user(),
                $action,
                $request->validated('remarks'),
                'OM_REVIEW',
                $metadata,
                $request->user()->name,
                $request->validated('signature_data'),
            );
        });

        // The OM's view closes to a receipt naming the next stage, so no flash message.
        return redirect()->route('purchase-requests.show', $purchaseRequest);
    }
}
