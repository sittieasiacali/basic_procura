<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestWorkflow;
use App\Support\RequestWithdrawal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Requestor withdrawal: Withdraw Request pauses processing (Withdrawal Pending
 * Acknowledgment); the staff handling the stopped stage acknowledge it, which voids
 * the whole request. Approvals, quotations and history stay on the record.
 */
class RequestWithdrawalController extends Controller
{
    public function store(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestWorkflow $workflow): RedirectResponse
    {
        $this->authorize('withdraw', $purchaseRequest);
        $validated = $request->validate(['withdrawal_reason' => ['required', 'string', 'min:5', 'max:2000']], [], ['withdrawal_reason' => 'reason']);

        DB::transaction(function () use ($request, $purchaseRequest, $workflow, $validated): void {
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            // Re-check under the lock: a PO may have been created since the page loaded.
            if ($request->user()->cannot('withdraw', $locked)) {
                throw ValidationException::withMessages(['withdrawal_reason' => 'This request can no longer be withdrawn. A Purchase Order may already exist; contact Purchasing to cancel it.']);
            }

            $stage = $locked->status;
            $locked->forceFill([
                'withdrawal_reason' => $validated['withdrawal_reason'],
                'withdrawn_by' => $request->user()->id,
                'withdrawn_at' => now(),
                'withdrawal_stage' => $stage->value,
                'withdrawal_role_id' => $locked->current_responsible_role_id,
                'withdrawal_acknowledged_by' => null,
                'withdrawal_acknowledged_at' => null,
                'withdrawal_acknowledgment_remarks' => null,
            ])->save();

            $workflow->transition(
                $locked,
                PurchaseRequestStatus::WithdrawalPending,
                $request->user(),
                'WITHDRAWAL_REQUESTED',
                $validated['withdrawal_reason'],
                metadata: [
                    'stopped_stage' => $stage->value,
                    'stopped_stage_label' => RequestWithdrawal::stageLabel($stage->value),
                    'request_owner_name' => $locked->requestor?->name,
                    'request_owner_email' => $locked->requestor?->email,
                    'handling_account_id' => $locked->assigned_user_id,
                    'handling_account_name' => $locked->assignedUser?->name,
                    'handling_account_email' => $locked->assignedUser?->email,
                    'handling_role' => $locked->assignedUser?->role?->name ?? $locked->responsibleRole?->name,
                ],
            );
        });

        // Back to where it was started (request page or the requestor's dashboard).
        return redirect()->back(fallback: route('purchase-requests.show', $purchaseRequest))
            ->with('success', "Void requested for {$purchaseRequest->request_number}. Processing is paused until the handling staff acknowledge it.");
    }

    public function acknowledge(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestWorkflow $workflow): RedirectResponse
    {
        $this->authorize('acknowledgeWithdrawal', $purchaseRequest);
        $validated = $request->validate([
            'acknowledgment_remarks' => ['nullable', 'string', 'max:2000'],
            'return_to_monitoring' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $purchaseRequest, $workflow, $validated): void {
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->authorize('acknowledgeWithdrawal', $locked);
            // Admin policy overrides must still respect the withdrawal lifecycle.
            abort_unless($locked->status === PurchaseRequestStatus::WithdrawalPending, 409, 'This withdrawal has already been acknowledged.');

            $locked->forceFill([
                'withdrawal_acknowledged_by' => $request->user()->id,
                'withdrawal_acknowledged_at' => now(),
                'withdrawal_acknowledgment_remarks' => $validated['acknowledgment_remarks'] ?? null,
                'assigned_user_id' => null,
            ])->save();

            $workflow->transition(
                $locked,
                PurchaseRequestStatus::Voided,
                $request->user(),
                'WITHDRAWAL_ACKNOWLEDGED',
                $validated['acknowledgment_remarks'] ?? 'Withdrawal acknowledged; request voided.',
                metadata: [
                    'stopped_stage' => $locked->withdrawal_stage,
                    'stopped_stage_label' => RequestWithdrawal::stageLabel($locked->withdrawal_stage),
                    'requestor_id' => $locked->requestor_id,
                    'acknowledged_by_name' => $request->user()->name,
                    'acknowledged_by_email' => $request->user()->email,
                    'acknowledged_by_role' => $request->user()->role?->name,
                ],
            );
        });

        $redirect = $request->boolean('return_to_monitoring')
            && $request->user()->hasAnyRole([RoleSlug::PurchasingTeam->value, RoleSlug::Admin->value])
                ? redirect()->route('voided-monitoring.index')
                : redirect()->route('purchase-requests.show', $purchaseRequest);

        return $redirect
            ->with('success', 'Withdrawal acknowledged. The request is now voided and kept in history.');
    }
}
