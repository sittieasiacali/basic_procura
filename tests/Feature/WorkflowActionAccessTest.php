<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderApprovalStage as PoStage;
use App\Enums\PurchaseRequestStatus as Status;
use App\Enums\RoleSlug;
use App\Models\Campaign;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Support\RequestStageReceipt;
use App\Support\WorkflowAction;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowActionAccessTest extends TestCase
{
    use RefreshDatabase;

    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        foreach ([RoleSlug::Requestor, RoleSlug::ItStaff, RoleSlug::PremisesStaff, RoleSlug::CanvassingStaff,
            RoleSlug::PurchasingTeam, RoleSlug::Purchasing, RoleSlug::ProcurementStaff, RoleSlug::President,
            RoleSlug::BudgetMaker, RoleSlug::Finance, RoleSlug::InventoryStaff, RoleSlug::PoChecker,
            RoleSlug::PoNoted, RoleSlug::PoApprover, RoleSlug::Admin] as $role) {
            $this->users[$role->value] = User::factory()->create(['role_id' => Role::where('slug', $role->value)->value('id')]);
        }
    }

    public function test_each_workflow_stage_exposes_actions_only_to_its_authorized_active_roles(): void
    {
        $purchasing = ['purchasing-team', 'purchasing', 'procurement-staff'];
        $cases = [
            [Status::Draft, null, ['requestor'], 'Complete draft'],
            [Status::Returned, null, ['requestor'], 'Revise and resubmit'],
            [Status::ForTeamScreening, null, ['it-staff', 'premises-staff'], 'Claim for screening'],
            [Status::ForItScreening, null, ['it-staff'], 'Screen request'],
            [Status::ForPremisesScreening, null, ['premises-staff'], 'Screen request'],
            [Status::ForProcurement, null, ['it-staff', 'purchasing-team'], 'Add quotation'],
            [Status::ForCanvassing, null, [...$purchasing, 'canvassing-staff'], 'Add quotation'],
            [Status::ForSupplierApproval, null, ['president'], 'Review supplier selection'],
            [Status::ForBudgetProcessing, null, ['purchasing-team'], 'Create purchase order'],
            [Status::ForBudgetProcessing, 'FOR_CHECKING', ['po-checker'], 'Check purchase order'],
            [Status::ForBudgetProcessing, 'FOR_ACKNOWLEDGEMENT', ['po-noted'], 'Note purchase order'],
            [Status::ForBudgetProcessing, 'FOR_APPROVAL', ['po-approver'], 'Approve purchase order'],
            [Status::ForBudgetProcessing, 'APPROVED', ['budget-maker', 'purchasing-team', 'purchasing'], 'Prepare budget'],
            [Status::ForBudgetMaker, null, $purchasing, 'Final budget'],
            [Status::ForBudgetChecker, null, $purchasing, 'Final budget'],
            [Status::ForBudgetProceeder, null, $purchasing, 'Final budget'],
            [Status::AwaitingPresidentFinalApproval, null, $purchasing, 'Final budget'],
            [Status::ForPayment, null, ['finance', 'purchasing'], 'Record payment'],
            [Status::ForReceiving, null, ['finance', 'inventory-staff'], 'Receive goods'],
            [Status::ForInventoryRelease, null, ['inventory-staff'], 'Release items'],
            [Status::WithdrawalPending, null, [...$purchasing, 'canvassing-staff'], 'Acknowledge withdrawal'],
            [Status::Completed, null, [], null],
            [Status::Voided, null, [], null],
        ];
        foreach ($cases as [$status, $poStatus, $allowed, $label]) {
            $request = $this->fixture($status, $poStatus);
            foreach ($this->users as $role => $user) {
                $action = WorkflowAction::for($user, $request);
                $expected = in_array($role, $allowed, true) || ($role === 'admin' && $label !== null);
                $this->assertSame($expected, $action !== null, $status->value.' / '.$poStatus.' / '.$role);
                if ($expected) {
                    $this->assertSame($label, $action['label'], $status->value.' / '.$role);
                    $this->assertTrue(WorkflowAction::canAct($user, $request));
                }
            }
        }
    }

    public function test_a_previous_handoff_never_closes_the_current_roles_task(): void
    {
        foreach ([
            [Status::ForItScreening, 'it-staff', 'SUBMITTED_FOR_SCREENING'],
            [Status::ForPremisesScreening, 'premises-staff', 'SUBMITTED_FOR_SCREENING'],
            [Status::ForCanvassing, 'purchasing-team', 'SCREENING_QUOTATIONS_RECORDED'],
            [Status::ForSupplierApproval, 'president', 'CANVASSING_UPDATED'],
        ] as [$status, $role, $event]) {
            $request = $this->fixture($status);
            $user = $this->users[$role];
            $request->auditLogs()->create(['user_id' => $user->id, 'action' => $event,
                'previous_status' => $status->value, 'new_status' => $status->value]);
            $this->assertNotNull(WorkflowAction::for($user, $request));
            $this->assertNull(RequestStageReceipt::for($request, $user));
            $response = $this->actingAs($user)->get(route('purchase-requests.show', $request))->assertOk();
            $this->assertStringNotContainsString('data-po-handoff-confirmation', $response->getContent());
        }
    }

    private function fixture(Status $status, ?string $poStatus = null): PurchaseRequest
    {
        $campaign = Campaign::firstOrCreate(['name' => 'Workflow action campaign'], ['account_number' => 'ACTION-001', 'is_active' => true]);
        $supplier = Supplier::firstOrCreate(['name' => 'Workflow action supplier'], ['team' => 'PURCHASING', 'is_active' => true]);
        $requestor = $this->users['requestor'];
        $requestor->update(['campaign_id' => $campaign->id]);
        $team = $status === Status::ForPremisesScreening ? 'PREMISES' : 'IT';
        $request = PurchaseRequest::create([
            'request_number' => 'ACTION-'.(PurchaseRequest::count() + 1), 'title' => 'Workflow actions',
            'requestor_id' => $requestor->id, 'department_campaign' => $campaign->name,
            'request_type' => $team, 'purpose' => 'Verify role and stage access',
            'delivery_location' => 'Main Office', 'status' => $status,
            'selected_supplier_id' => $supplier->id, 'priority_level' => 'NORMAL',
            'assigned_user_id' => in_array($status, [Status::ForItScreening, Status::ForPremisesScreening, Status::ForProcurement], true)
                ? $this->users[$team === 'IT' ? 'it-staff' : 'premises-staff']->id : null,
        ]);
        if ($status === Status::WithdrawalPending) {
            $request->forceFill(['withdrawal_stage' => Status::ForCanvassing->value,
                'withdrawn_by' => $requestor->id, 'withdrawn_at' => now()])->save();
        }
        $request->items()->create(['description' => 'Workstation', 'quantity' => 1, 'unit' => 'pc', 'estimated_unit_price' => 100, 'estimated_total' => 100]);
        if ($status === Status::ForProcurement) {
            $request->screening()->create(['screened_by' => $request->assigned_user_id, 'team' => $team, 'decision' => 'UNAVAILABLE', 'decided_at' => now()]);
        }
        $request->quotations()->create(['supplier_id' => $supplier->id, 'created_by' => $this->users['purchasing-team']->id,
            'item_or_service' => 'Workstation', 'price' => 100, 'is_approved' => true]);
        if ($poStatus) {
            $order = $request->purchaseOrder()->create(['po_number' => 'PO-'.$request->id, 'supplier_id' => $supplier->id,
                'created_by' => $this->users['purchasing-team']->id, 'status' => $poStatus,
                'issued_at' => now(), 'budget_campaign_id' => $campaign->id, 'subtotal' => 100, 'tax_amount' => 0, 'total_amount' => 100]);
            $current = match ($poStatus) {
                'FOR_CHECKING' => 1, 'FOR_ACKNOWLEDGEMENT' => 2, 'FOR_APPROVAL' => 3, default => 4
            };
            foreach (PoStage::cases() as $index => $stage) {
                $actor = $this->users[['purchasing-team', 'po-checker', 'po-noted', 'po-approver'][$index]];
                $done = $index < $current;
                $order->approvals()->create(['stage' => $stage, 'assigned_user_id' => $actor->id,
                    'status' => $done ? 'APPROVED' : 'PENDING', 'acted_by' => $done ? $actor->id : null,
                    'signature_name' => $done ? $actor->name : null,
                    'signature_data' => $done ? $this->workflowSignature($actor)['signature_data'] : null,
                    'signed_at' => $done ? now() : null, 'acted_at' => $done ? now() : null]);
            }
        }

        return $request->fresh();
    }
}
