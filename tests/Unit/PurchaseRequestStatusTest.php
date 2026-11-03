<?php

namespace Tests\Unit;

use App\Enums\PurchaseRequestStatus;
use PHPUnit\Framework\TestCase;

class PurchaseRequestStatusTest extends TestCase
{
    public function test_workflow_allows_expected_initial_transitions(): void
    {
        $this->assertFalse(PurchaseRequestStatus::Draft->canTransitionTo(PurchaseRequestStatus::ForOmApproval));
        $this->assertTrue(PurchaseRequestStatus::Draft->canTransitionTo(PurchaseRequestStatus::ForTeamScreening));
        $this->assertTrue(PurchaseRequestStatus::Draft->canTransitionTo(PurchaseRequestStatus::ForItScreening));
        $this->assertTrue(PurchaseRequestStatus::Returned->canTransitionTo(PurchaseRequestStatus::ForPremisesScreening));
        $this->assertTrue(PurchaseRequestStatus::ForOmApproval->canTransitionTo(PurchaseRequestStatus::Returned));
        $this->assertTrue(PurchaseRequestStatus::ForOmApproval->canTransitionTo(PurchaseRequestStatus::ForTeamScreening));
        $this->assertTrue(PurchaseRequestStatus::ForOmApproval->canTransitionTo(PurchaseRequestStatus::Rejected));
        $this->assertTrue(PurchaseRequestStatus::ForItScreening->canTransitionTo(PurchaseRequestStatus::ForInventoryRelease));
        $this->assertTrue(PurchaseRequestStatus::ForPremisesScreening->canTransitionTo(PurchaseRequestStatus::ForProcurement));
        $this->assertTrue(PurchaseRequestStatus::ForTeamScreening->canTransitionTo(PurchaseRequestStatus::ForItScreening));
        $this->assertTrue(PurchaseRequestStatus::ForTeamScreening->canTransitionTo(PurchaseRequestStatus::ForPremisesScreening));
        $this->assertTrue(PurchaseRequestStatus::ForInventoryRelease->canTransitionTo(PurchaseRequestStatus::Fulfilled));
    }

    public function test_terminal_statuses_have_no_transitions(): void
    {
        $this->assertSame([], PurchaseRequestStatus::Rejected->allowedTransitions());
        $this->assertSame([], PurchaseRequestStatus::Cancelled->allowedTransitions());
        $this->assertSame([], PurchaseRequestStatus::Completed->allowedTransitions());
        $this->assertSame([], PurchaseRequestStatus::Fulfilled->allowedTransitions());
    }
}
