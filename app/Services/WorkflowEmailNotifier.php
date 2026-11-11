<?php

namespace App\Services;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Notifications\WorkflowHandoffEmail;
use App\Support\RequestWithdrawal;
use Illuminate\Support\Facades\Notification;

final class WorkflowEmailNotifier
{
    public function __construct(private TeamLeaderActivityNotifier $teamLeaderRecipients) {}

    public function requestMoved(PurchaseRequest $purchaseRequest, PurchaseRequestStatus $previousStatus, string $action): void
    {
        if ($purchaseRequest->status === $previousStatus || $purchaseRequest->status === PurchaseRequestStatus::Draft) {
            return;
        }

        $stage = match ($action) {
            'SUBMITTED_FOR_SCREENING' => 'Request submitted for IT / Premises screening',
            'RESUBMITTED_FOR_SCREENING' => 'Request resubmitted for IT / Premises screening',
            default => $this->requestStage($purchaseRequest->status),
        };
        $this->send($purchaseRequest, $this->teamLeaderRecipients->addresses($purchaseRequest), $stage, $action);
    }

    public function purchaseOrderMoved(PurchaseOrder $order, User $actor, ?PurchaseOrderApprovalStage $missingSignature = null): void
    {
        $purchaseRequest = $order->purchaseRequest;
        $addresses = $this->teamLeaderRecipients->addresses($purchaseRequest);

        $stage = $missingSignature !== null
            ? 'Purchase Order: '.match ($missingSignature) {
                PurchaseOrderApprovalStage::PreparedBy => 'Prepared By signature required',
                PurchaseOrderApprovalStage::CheckedBy => 'Checked By signature required',
                PurchaseOrderApprovalStage::NotedBy => 'Noted By signature required',
                PurchaseOrderApprovalStage::ApprovedBy => 'Approved By signature required',
            }
        : match ($order->status) {
            'FOR_CHECKING' => 'Purchase Order checking',
            'FOR_ACKNOWLEDGEMENT' => 'Purchase Order noting',
            'FOR_APPROVAL' => 'Purchase Order approval',
            'APPROVED', 'ISSUED' => 'Purchase Order approved; ready for Budget Request preparation',
            'REJECTED' => 'Purchase Order rejected',
            'CANCELLED' => 'Purchase Order cancelled',
            default => null,
        };
        if ($stage === null) {
            return;
        }

        // Creating a signed PO and confirming Move to Checker can reach the same
        // handoff. Existing PO row locks serialize these calls; queue it once.
        sort($addresses, SORT_STRING);
        $recipientHash = hash('sha256', implode('|', $addresses));
        $lastDispatch = $purchaseRequest->auditLogs()
            ->where('action', 'WORKFLOW_EMAIL_QUEUED')
            ->where('metadata->purchase_order_id', $order->id)
            ->latest('id')->first();
        if (($lastDispatch?->metadata['email_stage'] ?? null) === $stage
            && ($lastDispatch?->metadata['recipient_hash'] ?? null) === $recipientHash) {
            return;
        }

        $nextSigner = $missingSignature
            ? $order->approvals()->where('stage', $missingSignature->value)->value('assigned_user_id')
            : $order->approvals()->where('status', 'PENDING')->orderBy('id')->value('assigned_user_id');
        app(ProcurementNotifications::class)->send($purchaseRequest, 'PURCHASE_ORDER_'.$order->status, $stage, $nextSigner);
        $this->send($purchaseRequest, $addresses, $stage, 'PURCHASE_ORDER_'.$order->status);
        $purchaseRequest->auditLogs()->create([
            'user_id' => $actor->id,
            'action' => 'WORKFLOW_EMAIL_QUEUED',
            'previous_status' => $purchaseRequest->status->value,
            'new_status' => $purchaseRequest->status->value,
            'remarks' => $addresses === [] ? 'In-app workflow update recorded; no OM/SMT email recipients configured.' : 'Workflow update emails queued for the TL\'s assigned OM and SMT.',
            'metadata' => [
                'purchase_order_id' => $order->id,
                'email_stage' => $stage,
                'recipient_hash' => $recipientHash,
                'recipient_count' => count($addresses),
            ],
        ]);
    }

    public function requestProcessed(PurchaseRequest $purchaseRequest, string $action, string $stage): void
    {
        app(ProcurementNotifications::class)->send($purchaseRequest, $action, $stage);
        $this->send($purchaseRequest, $this->teamLeaderRecipients->addresses($purchaseRequest), $stage, $action);
    }

    /** @param list<string> $addresses */
    private function send(PurchaseRequest $purchaseRequest, array $addresses, string $stage, string $action): void
    {
        $purchaseRequest->load([
            'requestor', 'items', 'screening.screener', 'screening.assets',
            'quotations.supplier', 'purchaseOrder', 'budgetRequest',
            'payments.recorder', 'goodsReceipts.receiver', 'goodsReceipts.items',
        ]);
        $details = [
            'request_number' => $purchaseRequest->request_number,
            'requestor' => $purchaseRequest->requestor?->name ?? 'Not available',
            'campaign' => $purchaseRequest->department_campaign ?: 'Not specified',
            'title' => $purchaseRequest->title,
            'purpose' => $purchaseRequest->purpose ?: 'Not specified',
            'required_date' => $purchaseRequest->required_date?->format('M j, Y') ?? 'Not specified',
            'screening_team' => $purchaseRequest->requested_team ?: 'IT / Premises',
            'items' => $purchaseRequest->items->map(fn ($item): string => sprintf(
                '%s — %s %s',
                $item->description,
                rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.'),
                $item->unit
            ))->values()->all(),
            'process_details' => $this->processDetails($purchaseRequest, $action),
            'workflow_step' => $this->workflowStep($purchaseRequest->status, $action),
        ];

        foreach ($addresses as $email) {
            // Only the TL's explicitly assigned OM/SMT addresses receive email.
            // Staff account emails and the notification bell are not recipients.
            Notification::route('mail', $email)->notify(new WorkflowHandoffEmail(
                $stage,
                $details,
                route('purchase-requests.show', $purchaseRequest),
                $action === 'SUBMITTED_FOR_SCREENING',
            ));
        }
    }

    /** @return list<string> */
    private function processDetails(PurchaseRequest $purchaseRequest, string $action): array
    {
        $details = [];
        $audit = $purchaseRequest->auditLogs()->with('user')->where('action', $action)->latest('id')->first();

        if ($audit?->user) {
            $details[] = 'Processed by: '.$audit->user->name;
        }
        if (filled($audit?->remarks)) {
            $details[] = 'Remarks: '.$audit->remarks;
        }

        if (in_array($action, ['INVENTORY_AVAILABLE', 'INVENTORY_UNAVAILABLE', 'SCREENING_QUOTATIONS_RECORDED', 'INVENTORY_RELEASED'], true)
            && $purchaseRequest->screening) {
            $screening = $purchaseRequest->screening;
            $details[] = 'Screening decision: '.str_replace('_', ' ', $screening->decision);
            $details[] = 'Screened by: '.($screening->screener?->name ?? 'Not available');
            if (filled($screening->evidence)) {
                $details[] = 'Screening notes: '.$screening->evidence;
            }
            foreach ($screening->assets as $asset) {
                $assetDetail = sprintf('Inventory: %s - %s unit(s)', $asset->description,
                    rtrim(rtrim(number_format((float) $asset->quantity, 2, '.', ''), '0'), '.'));
                if (filled($asset->recipient)) {
                    $assetDetail .= '; released to '.$asset->recipient;
                }
                if (filled($asset->location)) {
                    $assetDetail .= '; location '.$asset->location;
                }
                $details[] = $assetDetail;
            }
        }

        if (in_array($action, ['SCREENING_QUOTATIONS_RECORDED', 'CANVASSING_UPDATED', 'PRESIDENT_SUPPLIER_APPROVED'], true)) {
            foreach ($purchaseRequest->quotations as $quotation) {
                $vat = $quotation->vatBreakdown();
                $quote = sprintf('Quotation: %s - PHP %s total (VAT %s)',
                    $quotation->supplier?->name ?? 'Unknown supplier', number_format($vat['total'], 2), strtolower($vat['treatment']));
                if ($quotation->is_recommended) {
                    $quote .= '; recommended by Purchasing';
                }
                if ($quotation->is_approved) {
                    $quote .= '; approved';
                }
                if (filled($quotation->delivery_lead_time)) {
                    $quote .= '; lead time '.$quotation->delivery_lead_time;
                }
                $details[] = $quote;
            }
        }

        if (str_starts_with($action, 'PURCHASE_ORDER_') && $purchaseRequest->purchaseOrder) {
            $details[] = 'Purchase Order: '.($purchaseRequest->purchaseOrder->po_number ?? 'Number pending');
            $details[] = 'PO status: '.str_replace('_', ' ', $purchaseRequest->purchaseOrder->status);
        }

        if (in_array($action, ['BUDGET_REQUEST_SUBMITTED', 'PURCHASING_BUDGET_APPROVED', 'PAYMENT_POSTED', 'PARTIAL_PAYMENT_POSTED'], true)
            && $purchaseRequest->budgetRequest) {
            $budget = $purchaseRequest->budgetRequest;
            $details[] = 'Budget Request: '.($budget->budget_request_number ?? 'Number pending');
            $details[] = 'Budget status: '.str_replace('_', ' ', $budget->status);
            $details[] = 'Budget amount: PHP '.number_format((float) $budget->amount, 2);
            if (filled($budget->payment_method)) {
                $details[] = 'Payment method: '.str_replace('_', ' ', $budget->payment_method);
            }
            if (filled($budget->fund_source)) {
                $details[] = 'Fund source: '.str_replace('_', ' ', $budget->fund_source);
            }
        }

        if (in_array($action, ['PAYMENT_POSTED', 'PARTIAL_PAYMENT_POSTED'], true)) {
            foreach ($purchaseRequest->payments->sortByDesc('id')->take(3) as $payment) {
                $paymentDetail = sprintf('Finance payment: %s - PHP %s via %s; status %s',
                    $payment->payment_code,
                    number_format((float) $payment->amount, 2),
                    str_replace('_', ' ', $payment->payment_method ?: 'Not recorded'),
                    str_replace('_', ' ', $payment->payment_status ?: $payment->status),
                );
                if (filled($payment->reference_number)) {
                    $paymentDetail .= '; reference '.$payment->reference_number;
                }
                if ($payment->recorder) {
                    $paymentDetail .= '; recorded by '.$payment->recorder->name;
                }
                if (filled($payment->details)) {
                    $paymentDetail .= '; details '.$payment->details;
                }
                $details[] = $paymentDetail;
            }
        }

        if ($action === 'RECEIVED_AND_INVENTORY_UPDATED') {
            foreach ($purchaseRequest->goodsReceipts->sortByDesc('id')->take(1) as $receipt) {
                $details[] = 'Goods receipt: '.$receipt->receipt_number.'; received by '.($receipt->receiver?->name ?? 'Not available');
                if (filled($receipt->invoice_number)) {
                    $details[] = 'Invoice: '.$receipt->invoice_number.' - PHP '.number_format((float) $receipt->invoice_amount, 2);
                }
                foreach ($receipt->items as $item) {
                    $details[] = sprintf('Received item: %s - %s %s', $item->description,
                        rtrim(rtrim(number_format((float) $item->quantity_received, 2, '.', ''), '0'), '.'), $item->unit);
                }
            }
        }

        return array_values(array_unique($details));
    }

    private function requestStage(PurchaseRequestStatus $status): string
    {
        return match ($status) {
            PurchaseRequestStatus::ForTeamScreening => 'IT / Premises screening',
            PurchaseRequestStatus::ForItScreening => 'IT screening',
            PurchaseRequestStatus::ForPremisesScreening => 'Premises screening',
            PurchaseRequestStatus::ForInventoryRelease => 'Inventory release',
            PurchaseRequestStatus::ForProcurement => 'IT / Premises quotation preparation',
            PurchaseRequestStatus::ForCanvassing => 'Purchasing canvassing',
            PurchaseRequestStatus::ForSupplierApproval => 'President supplier approval',
            PurchaseRequestStatus::SupplierApproved => 'Supplier approved',
            PurchaseRequestStatus::Approved, PurchaseRequestStatus::ForBudgetProcessing => 'Purchase Order preparation',
            PurchaseRequestStatus::PoCreated => 'Purchase Order created',
            PurchaseRequestStatus::ForBudgetMaker => 'Budget Request preparation',
            PurchaseRequestStatus::ForBudgetChecker => 'Budget Request checking',
            PurchaseRequestStatus::ForBudgetProceeder => 'Budget Request finalization',
            PurchaseRequestStatus::AwaitingPresidentFinalApproval => 'Final Budget Request approval',
            PurchaseRequestStatus::ForPayment, PurchaseRequestStatus::PaymentPending => 'Finance payment processing',
            PurchaseRequestStatus::PartiallyPaid => 'Partial payment recorded',
            PurchaseRequestStatus::FullyPaid => 'Full payment recorded',
            PurchaseRequestStatus::ForDelivery => 'Delivery',
            PurchaseRequestStatus::PartiallyReceived => 'Partial delivery received',
            PurchaseRequestStatus::ReceivedWithInvoice => 'Goods and invoice received',
            PurchaseRequestStatus::ForReceiving => 'Receiving and inventory update',
            PurchaseRequestStatus::ForInventoryLogging => 'Inventory logging',
            PurchaseRequestStatus::Endorsed => 'Endorsed to the requestor',
            PurchaseRequestStatus::Completed => 'Request completed',
            PurchaseRequestStatus::Fulfilled => 'Request fulfilled',
            PurchaseRequestStatus::Returned => 'Returned to the requestor for correction',
            PurchaseRequestStatus::Rejected => 'Request rejected',
            PurchaseRequestStatus::Cancelled => 'Request cancelled',
            PurchaseRequestStatus::WithdrawalPending => 'Withdrawal awaiting staff acknowledgment',
            PurchaseRequestStatus::Voided => 'Request voided; withdrawal acknowledged',
            default => RequestWithdrawal::stageLabel($status->value),
        };
    }

    /**
     * Position on the 7-step tracker drawn in the email (8 = every step done).
     * Null hides the tracker for closed or paused requests.
     */
    private function workflowStep(PurchaseRequestStatus $status, string $action): ?int
    {
        if (str_starts_with($action, 'PURCHASE_ORDER_')) {
            return 5;
        }

        return match ($status) {
            PurchaseRequestStatus::ForTlApproval, PurchaseRequestStatus::ForOmApproval, PurchaseRequestStatus::Returned => 1,
            PurchaseRequestStatus::ForTeamScreening, PurchaseRequestStatus::ForItScreening,
            PurchaseRequestStatus::ForPremisesScreening, PurchaseRequestStatus::ForInventoryRelease => 2,
            PurchaseRequestStatus::ForProcurement, PurchaseRequestStatus::ForCanvassing => 3,
            PurchaseRequestStatus::ForSupplierApproval, PurchaseRequestStatus::SupplierApproved,
            PurchaseRequestStatus::ForOmSmtsApproval => 4,
            PurchaseRequestStatus::Approved, PurchaseRequestStatus::ForBudgetProcessing, PurchaseRequestStatus::PoCreated => 5,
            PurchaseRequestStatus::ForBudgetMaker,
            PurchaseRequestStatus::ForBudgetChecker, PurchaseRequestStatus::ForBudgetProceeder,
            PurchaseRequestStatus::AwaitingPresidentFinalApproval, PurchaseRequestStatus::ForPayment,
            PurchaseRequestStatus::PaymentPending, PurchaseRequestStatus::PartiallyPaid, PurchaseRequestStatus::FullyPaid => 6,
            PurchaseRequestStatus::ForDelivery, PurchaseRequestStatus::PartiallyReceived,
            PurchaseRequestStatus::ReceivedWithInvoice, PurchaseRequestStatus::ForReceiving,
            PurchaseRequestStatus::ForInventoryLogging, PurchaseRequestStatus::Endorsed => 7,
            PurchaseRequestStatus::Completed, PurchaseRequestStatus::Fulfilled => 8,
            default => null,
        };
    }
}
