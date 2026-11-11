<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequest;
use App\Models\Payment;
use App\Models\User;
use App\Services\GoodsReceiving;
use App\Services\PurchaseRequestWorkflow;
use App\Services\WorkflowEmailNotifier;
use App\Support\WorkflowSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function store(
        Request $request,
        PurchaseRequest $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
        GoodsReceiving $receiving,
        WorkflowEmailNotifier $workflowEmails,
    ): RedirectResponse {
        $this->authorize('processPayment', $purchaseRequest);
        $balance = self::balanceDue($purchaseRequest);
        // Goods can only be received with the final payment, so a partial one skips the receiving fields.
        if (is_numeric($request->input('amount')) && (float) $request->input('amount') < $balance - 0.005) {
            $request->merge(['receiving_status' => 'FOR_RECEIVING']);
        }
        $validated = $request->validate([
            ...self::paymentRules(),
            // A payment may settle part of the balance; the request stays For payment until it is cleared.
            'amount' => ['required', 'numeric', 'gt:0', 'max:'.$balance],
            // FOR_RECEIVING: goods still to arrive. RECEIVED: goods are in hand, so they go
            // straight into inventory with the receiving details below.
            'receiving_status' => ['nullable', Rule::in(['FOR_RECEIVING', 'RECEIVED'])],
            ...collect(GoodsReceiving::rules())
                ->map(fn (array $rules) => ['exclude_unless:receiving_status,RECEIVED', ...$rules])
                ->all(),
        ], [
            'amount.max' => 'The amount cannot be more than the balance due of ₱ '.number_format($balance, 2).'.',
        ]);
        $settlesBalance = (float) $validated['amount'] >= $balance - 0.005;
        $goodsReceived = $settlesBalance && ($validated['receiving_status'] ?? 'FOR_RECEIVING') === 'RECEIVED';

        DB::transaction(function () use ($request, $purchaseRequest, $workflow, $receiving, $workflowEmails, $validated, $goodsReceived, $settlesBalance) {
            $lockedRequest = $this->record($request, $purchaseRequest, $workflow, $validated, (float) $validated['amount']);

            if (! $settlesBalance) {
                $workflow->recordActivity($lockedRequest, $request->user(), 'PARTIAL_PAYMENT_POSTED',
                    'Partial payment recorded; the remaining balance is still awaiting Finance processing.');
                $workflowEmails->requestProcessed($lockedRequest, 'PARTIAL_PAYMENT_POSTED', 'Partial payment recorded');
            }

            if ($goodsReceived) {
                $this->authorize('receiveGoods', $lockedRequest);
                $receiving->receive($request, $lockedRequest, $request->user(), $validated);
            }
        });

        $remaining = max(0, $balance - (float) $validated['amount']);

        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->with('success', match (true) {
                ! $settlesBalance => 'Partial payment recorded. A balance of ₱ '.number_format($remaining, 2).' is still due; the request stays For payment until it is fully paid.',
                $goodsReceived => 'Payment recorded and goods received. The items were added to inventory and the request is complete.',
                default => 'Payment recorded successfully and the request has moved to receiving.',
            });
    }

    /** Record payment for several ticked requests at once: one set of payment details, each PO's own amount. */
    public function batchForm(Request $request): View
    {
        $validated = $request->validate(['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['integer']]);
        $purchaseRequests = $this->payableRequests($request, $validated['ids']);
        abort_if($purchaseRequests->isEmpty(), 404);

        return view('payments.batch', [
            'purchaseRequests' => $purchaseRequests,
            'paymentCustodians' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function batchStore(Request $request, PurchaseRequestWorkflow $workflow, GoodsReceiving $receiving): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer'],
            ...self::paymentRules(),
            // POs ticked "Already received" go straight into inventory; the rest wait For receiving.
            'received_ids' => ['nullable', 'array'],
            'received_ids.*' => ['integer', Rule::in($request->input('ids', []))],
            'receiving_remarks' => ['exclude_without:received_ids', 'nullable', 'string', 'max:2000'],
            // Inventory details per received PO: receiving[{request id}][quantities|units|serial_numbers|locations][{line}].
            'receiving' => ['nullable', 'array'],
            ...collect(GoodsReceiving::rules())
                ->only(['serial_numbers.*', 'quantities.*', 'units.*', 'locations.*'])
                ->mapWithKeys(fn (array $rules, string $field) => ['receiving.*.'.$field => $rules])
                ->all(),
            ...collect(WorkflowSignature::rules())
                ->map(fn (array $rules) => ['exclude_without:received_ids', ...$rules])
                ->all(),
        ]);
        $receivedIds = array_map('intval', $validated['received_ids'] ?? []);

        $purchaseRequests = $this->payableRequests($request, $validated['ids']);
        if ($purchaseRequests->count() !== count(array_unique($validated['ids']))) {
            throw ValidationException::withMessages(['ids' => 'One or more selected requests are no longer awaiting payment. Reload the list and try again.']);
        }

        DB::transaction(function () use ($request, $workflow, $receiving, $validated, $purchaseRequests, $receivedIds): void {
            foreach ($purchaseRequests as $purchaseRequest) {
                $lockedRequest = $this->record($request, $purchaseRequest, $workflow, $validated, self::balanceDue($purchaseRequest));

                if (in_array($purchaseRequest->id, $receivedIds, true)) {
                    $this->authorize('receiveGoods', $lockedRequest);
                    $lineDetails = $validated['receiving'][$purchaseRequest->id] ?? [];
                    $receiving->receive($request, $lockedRequest, $request->user(), [
                        'serial_numbers' => $lineDetails['serial_numbers'] ?? [],
                        'quantities' => $lineDetails['quantities'] ?? [],
                        'units' => $lineDetails['units'] ?? [],
                        'locations' => $lineDetails['locations'] ?? [],
                        'remarks' => $validated['receiving_remarks'] ?? null,
                        'signature_name' => $validated['signature_name'],
                        'signature_data' => $validated['signature_data'],
                    ]);
                }
            }
        });

        $count = $purchaseRequests->count();
        $receivedCount = count($receivedIds);
        $message = "Payment recorded for {$count} ".($count === 1 ? 'PO' : 'POs').'.'
            .($receivedCount > 0 ? " {$receivedCount} received and added to inventory." : '')
            .($count - $receivedCount > 0 ? ' '.($count - $receivedCount).' moved to For receiving.' : '');

        return ($request->boolean('_embedded') ? redirect()->away(\App\Support\WorkflowUrl::route('workflow.done')) : redirect()->route('dashboard'))
            ->with('success', $message);
    }

    /** The online / cashout deposit / check transaction sheet of the payment's Budget Request (Processed payment preview). */
    public function sheet(Request $request, Payment $payment): View
    {
        $budgetRequest = $payment->budgetRequest;
        abort_if($budgetRequest === null || $budgetRequest->purchaseRequest === null, 404);
        $this->authorize('view', $budgetRequest->purchaseRequest);

        $budgetRequest->load([
            'requester', 'maker', 'checker', 'campaign', 'payments', 'purchaseOrder.creator',
            'lines.requester', 'lines.campaign', 'lines.purchaseOrder.supplier',
            'purchaseRequest.requestor', 'purchaseRequest.approvals', 'purchaseRequest.purchaseOrder.creator',
        ]);

        // ?print=1 opens the sheet as its own printable page; otherwise it is a fragment for the preview tab.
        return view($request->boolean('print') ? 'payments.sheet-document' : 'budget-requests.partials.transaction-sheet', [
            'budget' => $budgetRequest,
            'purchaseRequest' => $budgetRequest->purchaseRequest,
            'payment' => $payment,
        ]);
    }

    /** The amount a request is paid: its Purchase Order total, or its Budget Request amount when the PO was skipped. */
    public static function amountDue(PurchaseRequest $purchaseRequest): float
    {
        return (float) ($purchaseRequest->purchaseOrder?->total_amount
            ?? $purchaseRequest->budgetRequest?->amount
            ?? $purchaseRequest->total_amount);
    }

    /** Sum of payments already recorded against the request. */
    public static function amountPaid(PurchaseRequest $purchaseRequest): float
    {
        return (float) Payment::query()->where('purchase_request_id', $purchaseRequest->id)->sum('amount');
    }

    /** What is left to pay after earlier partial payments. */
    public static function balanceDue(PurchaseRequest $purchaseRequest): float
    {
        return round(max(0, self::amountDue($purchaseRequest) - self::amountPaid($purchaseRequest)), 2);
    }

    /** @return array<string, array<int, mixed>> */
    private static function paymentRules(): array
    {
        return [
            // Type of transaction and the Budget Information are recorded here, at payment.
            'payment_method' => ['required', Rule::in(['ONLINE', 'CASH', 'CHECK', 'COD'])],
            'account_type' => ['required', Rule::in(['ACA', 'OTHER_PARTY', 'OWN_ACCOUNT'])],
            'fund_source' => ['required', Rule::in(['MAIN_FUND', 'CAMPAIGN_FUND', 'OTHER'])],
            'main_fund' => ['required', 'string', 'max:255'],
            'budget_custodian_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:2000'],
            'paid_at' => ['nullable', 'date'],
            // Receipt evidence: official receipt, deposit slip, transfer screenshot or check photo.
            'receipts' => ['required', 'array', 'min:1', 'max:5'],
            'receipts.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];
    }

    /** @param array<int, int> $ids */
    private function payableRequests(Request $request, array $ids): Collection
    {
        return PurchaseRequest::query()
            ->whereKey($ids)
            ->where('status', PurchaseRequestStatus::ForPayment->value)
            ->with(['purchaseOrder.supplier', 'purchaseOrder.items', 'items', 'budgetRequest'])
            ->orderBy('id')
            ->get()
            ->filter(fn (PurchaseRequest $purchaseRequest) => $purchaseRequest->budgetRequest
                && $request->user()->can('processPayment', $purchaseRequest))
            ->values();
    }

    /** Posts one payment and moves the request to receiving. Call inside a transaction. */
    private function record(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestWorkflow $workflow, array $validated, float $amount): PurchaseRequest
    {
        $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
        $this->authorize('processPayment', $lockedRequest);

        if ($lockedRequest->status !== PurchaseRequestStatus::ForPayment) {
            throw ValidationException::withMessages(['status' => 'Payment is not yet available. Purchasing final approval is required before payment can be processed.']);
        }

        // Every payment belongs to a Budget Request; say so instead of failing with a 404.
        $budgetRequest = $lockedRequest->budgetRequest()->first();
        if ($budgetRequest === null) {
            throw ValidationException::withMessages(['status' => 'This request has no Budget Request yet, so its payment cannot be recorded. Create or link a Budget Request first.']);
        }
        $settlesBalance = $amount >= self::balanceDue($lockedRequest) - 0.005;
        $payment = $lockedRequest->payments()->create([
            'budget_request_id' => $budgetRequest->id,
            'purchase_order_id' => $lockedRequest->purchaseOrder?->id,
            'recorded_by' => $request->user()->id,
            'payment_type' => 'PROCUREMENT',
            'payment_method' => strtoupper((string) $validated['payment_method']),
            'payment_channel' => strtoupper((string) $validated['payment_method']),
            'reference_number' => $validated['reference_number'] ?? null,
            'amount' => $amount,
            'paid_at' => $validated['paid_at'] ?? now()->toDateString(),
            'payment_date' => $validated['paid_at'] ?? now()->toDateString(),
            'status' => $settlesBalance ? 'PAID' : 'PARTIAL',
            'payment_status' => $settlesBalance ? 'PAID' : 'PARTIAL',
            'details' => $validated['details'] ?? null,
        ]);

        foreach ($request->file('receipts', []) as $file) {
            $payment->attachments()->create([
                'uploaded_by' => $request->user()->id,
                'category' => 'PAYMENT_RECEIPT',
                'original_name' => $file->getClientOriginalName(),
                'disk' => 'local',
                'path' => $file->store("purchase-requests/{$lockedRequest->id}/payments/{$payment->id}", 'local'),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
        }

        // The Budget Request is paid once every request on it has no balance left.
        $allocatedRequests = PurchaseRequest::query()
            ->whereIn('id', $budgetRequest->allocations()->select('purchase_request_id'))
            ->with('purchaseOrder:id,purchase_request_id,total_amount')
            ->get(['id', 'total_amount']);
        $budgetIsFullyPaid = $allocatedRequests->isNotEmpty()
            && $allocatedRequests->every(fn (PurchaseRequest $allocated) => self::balanceDue($allocated) <= 0.005);

        $budgetRequest->update([
            'payment_method' => strtoupper((string) $validated['payment_method']),
            'account_type' => $validated['account_type'],
            'fund_source' => $validated['fund_source'],
            'main_fund' => $validated['main_fund'],
            'budget_custodian_id' => array_key_exists('budget_custodian_id', $validated)
                ? $validated['budget_custodian_id'] : $budgetRequest->budget_custodian_id,
            'status' => $budgetIsFullyPaid ? 'PAID' : 'PARTIALLY_PAID',
            'released_at' => $budgetIsFullyPaid ? now()->toDateString() : null,
        ]);

        $lockedRequest->purchaseOrder?->update([
            'budget_account_type' => $validated['account_type'],
            'budget_transaction_type' => strtoupper((string) $validated['payment_method']),
            'budget_fund_source' => $validated['fund_source'],
            'main_fund' => $validated['main_fund'],
        ]);

        if (! $settlesBalance) {
            return $lockedRequest;
        }

        $workflow->transition(
            $lockedRequest,
            PurchaseRequestStatus::ForReceiving,
            $request->user(),
            'PAYMENT_POSTED',
            'Payment posted for receiving and inventory update.',
            'PAYMENT',
        );

        return $lockedRequest;
    }
}
