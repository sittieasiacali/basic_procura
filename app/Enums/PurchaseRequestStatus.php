<?php

namespace App\Enums;

enum PurchaseRequestStatus: string
{
    case Draft = 'DRAFT';
    case ForTlApproval = 'FOR_TL_APPROVAL';
    case ForOmApproval = 'FOR_OM_APPROVAL';
    case Returned = 'RETURNED';
    case Rejected = 'REJECTED';
    case ForTeamScreening = 'FOR_TEAM_SCREENING';
    case ForItScreening = 'FOR_IT_SCREENING';
    case ForPremisesScreening = 'FOR_PREMISES_SCREENING';
    case ForInventoryRelease = 'FOR_INVENTORY_RELEASE';
    case ForProcurement = 'FOR_PROCUREMENT';
    case ForCanvassing = 'FOR_CANVASSING';
    case ForSupplierApproval = 'FOR_SUPPLIER_APPROVAL';
    case SupplierApproved = 'SUPPLIER_APPROVED';
    case ForOmSmtsApproval = 'FOR_OM_SMTS_APPROVAL';
    case Cancelled = 'CANCELLED';
    case Approved = 'APPROVED';
    case PoCreated = 'PO_CREATED';
    case ForBudgetProcessing = 'FOR_BUDGET_PROCESSING';
    case ForBudgetMaker = 'FOR_BUDGET_MAKER';
    case ForBudgetChecker = 'FOR_BUDGET_CHECKER';
    case ForBudgetProceeder = 'FOR_BUDGET_PROCEEDER';
    case AwaitingPresidentFinalApproval = 'AWAITING_PRESIDENT_FINAL_APPROVAL';
    case ForPayment = 'FOR_PAYMENT';
    case PaymentPending = 'PAYMENT_PENDING';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case ForDelivery = 'FOR_DELIVERY';
    case PartiallyReceived = 'PARTIALLY_RECEIVED';
    case ReceivedWithInvoice = 'RECEIVED_WITH_INVOICE';
    case FullyPaid = 'FULLY_PAID';
    case ForReceiving = 'FOR_RECEIVING';
    case ForInventoryLogging = 'FOR_INVENTORY_LOGGING';
    case Endorsed = 'ENDORSED';
    case Completed = 'COMPLETED';
    case Fulfilled = 'FULFILLED';
    // Requestor asked to withdraw; processing is paused until the handling staff acknowledge it.
    case WithdrawalPending = 'WITHDRAWAL_PENDING';
    // Withdrawal acknowledged: the whole request is closed and kept for history.
    case Voided = 'VOIDED';

    /**
     * Stages a requestor may withdraw from before the budget is finalized.
     * The policy also checks the budget record for historical final approval.
     *
     * @return list<self>
     */
    public static function withdrawable(): array
    {
        return [
            self::ForTlApproval, self::ForOmApproval, self::Returned, self::ForOmSmtsApproval,
            self::ForTeamScreening, self::ForItScreening, self::ForPremisesScreening,
            self::ForInventoryRelease, self::ForProcurement, self::ForCanvassing,
            self::ForSupplierApproval, self::SupplierApproved, self::Approved, self::PoCreated, self::ForBudgetProcessing,
            self::ForBudgetMaker, self::ForBudgetChecker, self::ForBudgetProceeder, self::AwaitingPresidentFinalApproval,
        ];
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Still in the requestor's hands: a void closes it at once, with no staff acknowledgment.
            self::Draft, self::Returned => [self::ForOmApproval, self::ForTeamScreening, self::ForItScreening, self::ForPremisesScreening, self::Voided],
            self::ForOmApproval => [
                self::Returned,
                self::Rejected,
                self::ForTeamScreening,
                self::ForItScreening,
                self::ForPremisesScreening,
                self::ForProcurement,
            ],
            self::ForTeamScreening => [self::ForItScreening, self::ForPremisesScreening],
            self::ForItScreening => [
                self::ForPremisesScreening,
                self::ForInventoryRelease,
                self::Fulfilled,
                self::ForProcurement,
            ],
            self::ForPremisesScreening => [
                self::ForItScreening,
                self::ForInventoryRelease,
                self::Fulfilled,
                self::ForProcurement,
            ],
            self::ForInventoryRelease => [self::ForInventoryLogging, self::Fulfilled],
            // IT / Premises records quotations after unavailable inventory screening.
            // Completing that quotation step hands the request to Canvassing.
            // Undoing the unavailable decision returns it to the team's screening.
            self::ForProcurement => [self::ForCanvassing, self::ForSupplierApproval, self::ForItScreening, self::ForPremisesScreening],
            self::ForTlApproval => [self::Returned, self::Rejected, self::ForCanvassing],
            self::ForCanvassing => [self::ForSupplierApproval],
            self::ForSupplierApproval => [self::SupplierApproved, self::ForBudgetProcessing, self::ForCanvassing],
            self::SupplierApproved => [self::ForBudgetProcessing],
            self::ForOmSmtsApproval => [
                self::Cancelled,
                self::Rejected,
                self::Approved,
                self::ForBudgetProcessing,
                self::ForTeamScreening,
                self::ForItScreening,
                self::ForPremisesScreening,
                self::ForProcurement,
            ],
            self::Approved => [self::PoCreated],
            self::PoCreated => [self::ForBudgetProcessing],
            self::ForBudgetProcessing => [self::AwaitingPresidentFinalApproval, self::Returned],
            self::ForBudgetMaker => [self::AwaitingPresidentFinalApproval, self::ForPayment, self::ForCanvassing],
            // Current controller exposes the Proceeder action from this queue.
            // Keep the explicit FOR_BUDGET_PROCEEDER state supported for legacy data,
            // while allowing the live Maker → Checker → Proceeder path to continue.
            self::ForBudgetChecker => [self::AwaitingPresidentFinalApproval, self::ForPayment, self::ForCanvassing],
            self::ForBudgetProceeder => [self::AwaitingPresidentFinalApproval, self::ForPayment, self::ForCanvassing],
            self::AwaitingPresidentFinalApproval => [self::ForPayment, self::ForCanvassing],
            self::ForPayment => [self::PaymentPending, self::FullyPaid, self::ForReceiving],
            self::PaymentPending => [self::PartiallyPaid, self::FullyPaid, self::ForDelivery, self::ForReceiving],
            self::PartiallyPaid => [self::ForDelivery, self::FullyPaid],
            self::ForDelivery => [self::PartiallyReceived, self::ReceivedWithInvoice],
            self::PartiallyReceived => [self::PartiallyReceived, self::ReceivedWithInvoice],
            self::ReceivedWithInvoice => [self::FullyPaid, self::ForInventoryLogging],
            self::FullyPaid => [self::ForInventoryLogging, self::ForReceiving],
            self::ForReceiving => [self::Completed],
            self::ForInventoryLogging => [self::Endorsed],
            self::Endorsed => [self::Completed, self::Fulfilled],
            self::WithdrawalPending => [self::Voided],
            self::Rejected, self::Cancelled, self::Completed, self::Fulfilled, self::Voided => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        if ($next === self::WithdrawalPending) {
            return in_array($this, self::withdrawable(), true);
        }

        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * Bucket for the request list's summary cards. A request is "approved" once its
     * supplier (or inventory release) is approved, and stays so through PO, budget,
     * payment, receiving and fulfilment. Every status belongs to exactly one bucket.
     */
    public function summaryGroup(): string
    {
        return match ($this) {
            self::Draft, self::ForTlApproval, self::ForOmApproval, self::Returned,
            self::ForTeamScreening, self::ForItScreening, self::ForPremisesScreening,
            self::ForProcurement, self::ForCanvassing, self::ForSupplierApproval,
            self::ForOmSmtsApproval, self::WithdrawalPending => 'pending',
            self::Rejected, self::Cancelled, self::Voided => 'disapproved',
            self::ForInventoryRelease, self::SupplierApproved, self::Approved, self::PoCreated,
            self::ForBudgetProcessing, self::ForBudgetMaker, self::ForBudgetChecker,
            self::ForBudgetProceeder, self::AwaitingPresidentFinalApproval, self::ForPayment,
            self::PaymentPending, self::PartiallyPaid, self::ForDelivery, self::PartiallyReceived,
            self::ReceivedWithInvoice, self::FullyPaid, self::ForReceiving, self::ForInventoryLogging,
            self::Endorsed, self::Completed, self::Fulfilled => 'approved',
        };
    }

    /** @return list<string> status values in the given summary bucket */
    public static function valuesInSummaryGroup(string $group): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->summaryGroup() === $group),
        ));
    }
}
