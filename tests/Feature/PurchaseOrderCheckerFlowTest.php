<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\Campaign;
use App\Models\PoRoleAssignment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderCheckerFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $preparer;

    private User $checker;

    private User $noter;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->preparer = $this->user(RoleSlug::PurchasingTeam);
        $this->checker = $this->user(RoleSlug::PoChecker);
        $this->noter = $this->user(RoleSlug::PoNoted);
        $this->approver = $this->user(RoleSlug::PoApprover);

        foreach ([
            [PurchaseOrderApprovalStage::PreparedBy, $this->preparer],
            [PurchaseOrderApprovalStage::CheckedBy, $this->checker],
            [PurchaseOrderApprovalStage::NotedBy, $this->noter],
            [PurchaseOrderApprovalStage::ApprovedBy, $this->approver],
        ] as [$stage, $user]) {
            PoRoleAssignment::create(['stage' => $stage, 'user_id' => $user->id]);
        }
    }

    public function test_created_po_reaches_assigned_checker_for_signature_and_remarks_then_advances_in_order(): void
    {
        $order = $this->createOrder();
        $request = $order->purchaseRequest;

        $this->actingAs($this->checker)->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->contains(
                fn ($action) => $action['request']->is($request) && $action['label'] === 'Check purchase order'
            ));
        $this->get(route('purchase-requests.show', $request))->assertOk()
            ->assertSee('Checker remarks')->assertSee('Checked By signature')->assertSee('data-signature-canvas', false);

        $this->actingAs($this->noter)->get(route('dashboard'))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->isEmpty());
        $this->actingAs($this->checker)->post(route('purchase-orders.check', $request), ['remarks' => 'Missing signature'])
            ->assertSessionHasErrors('signature_data');
        $this->assertSame('FOR_CHECKING', $order->fresh()->status);

        $this->post(route('purchase-orders.check', $request), [
            ...$this->workflowSignature($this->checker), 'remarks' => 'Checked specifications and totals.',
        ])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('FOR_ACKNOWLEDGEMENT', $order->status);
        $this->assertSame('Checked specifications and totals.', $order->checker_remarks);
        $checked = $order->approvalFor(PurchaseOrderApprovalStage::CheckedBy);
        $this->assertTrue($checked->hasValidSignature());
        $this->assertSame($this->checker->id, $checked->acted_by);
        $this->assertSame('Checked specifications and totals.', $checked->remarks);

        $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->isEmpty());
        $this->post(route('purchase-orders.check', $request), $this->workflowSignature($this->checker))->assertForbidden();

        $this->actingAs($this->noter)->get(route('dashboard'))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->contains(fn ($action) => $action['request']->is($request)));
        $this->post(route('purchase-orders.acknowledge', $request), [
            ...$this->workflowSignature($this->noter), 'remarks' => 'Noted for approval.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('FOR_APPROVAL', $order->fresh()->status);

        $this->actingAs($this->approver)->post(route('purchase-orders.approve', $request), [
            ...$this->workflowSignature($this->approver), 'decision' => 'APPROVE', 'remarks' => 'Approved for purchase.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $order->fresh()->status);
    }

    public function test_checker_role_alone_does_not_grant_another_accounts_assigned_po_and_diagnosis_explains_it(): void
    {
        $order = $this->createOrder();
        $newChecker = $this->user(RoleSlug::PoChecker);
        PoRoleAssignment::where('stage', PurchaseOrderApprovalStage::CheckedBy->value)->update(['user_id' => $newChecker->id]);

        $this->actingAs($newChecker)->get(route('dashboard'))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->isEmpty());
        $this->post(route('purchase-orders.check', $order->purchaseRequest), $this->workflowSignature($newChecker))->assertForbidden();

        $before = $order->approvals()->orderBy('id')->get()->toArray();
        $this->artisan('po:diagnose', ['numbers' => [$order->po_number]])
            ->expectsOutputToContain('Assigned checker: '.$this->checker->name)
            ->expectsOutputToContain('Saving workflow assignments only affects new POs.')
            ->expectsOutputToContain('Other PO Checker role account IDs: '.$newChecker->id)
            ->assertSuccessful();
        $this->assertSame($before, $order->approvals()->orderBy('id')->get()->toArray());
        $this->assertSame('FOR_CHECKING', $order->fresh()->status);
    }

    public function test_diagnosis_reports_a_missing_preparer_signature_and_missing_po_numbers(): void
    {
        $order = $this->createOrder();
        $order->approvals()->where('stage', PurchaseOrderApprovalStage::PreparedBy->value)->update(['signature_data' => null]);
        $this->artisan('po:diagnose', ['numbers' => [$order->po_number, 'ASI-NOT-FOUND']])
            ->expectsOutputToContain('Prepared By signature is missing or invalid')
            ->expectsOutputToContain("ASI-NOT-FOUND: not found in this server's configured database.")
            ->assertFailed();
    }

    public function test_multi_role_checker_must_switch_to_the_checker_workspace(): void
    {
        $checkerRoleId = $this->checker->role_id;
        $this->checker->roles()->sync([$checkerRoleId]);
        $this->checker->update(['role_id' => $this->preparer->role_id]);
        $order = $this->createOrder();

        $this->actingAs($this->checker)->get(route('dashboard'))->assertOk()
            ->assertViewHas('creatorWorkflow', 'canvassing');
        $this->post(route('purchase-orders.check', $order->purchaseRequest), $this->workflowSignature($this->checker))
            ->assertForbidden();

        $this->withSession(['workspace_role_id' => $checkerRoleId])->get(route('dashboard'))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->contains(
                fn ($action) => $action['request']->is($order->purchaseRequest)
            ));
        $this->get(route('purchase-requests.show', $order->purchaseRequest))->assertOk()
            ->assertSee('Checker remarks')->assertSee('Checked By signature');
        $this->post(route('purchase-orders.check', $order->purchaseRequest), [
            ...$this->workflowSignature($this->checker), 'remarks' => 'Checked in the correct workspace.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('FOR_ACKNOWLEDGEMENT', $order->fresh()->status);
    }

    public function test_preparer_can_check_own_po_in_assigned_checker_workspace_with_a_separate_signature(): void
    {
        $checkerRoleId = $this->checker->role_id;
        $this->preparer->roles()->sync(Role::whereIn('slug', [
            RoleSlug::Requestor->value, RoleSlug::ItStaff->value, RoleSlug::Finance->value,
            RoleSlug::PurchasingTeam->value, RoleSlug::PoChecker->value, RoleSlug::PoNoted->value, RoleSlug::PoApprover->value,
        ])->pluck('id'));
        PoRoleAssignment::where('stage', PurchaseOrderApprovalStage::CheckedBy->value)->update(['user_id' => $this->preparer->id]);
        $order = $this->createOrder();
        $preparedBefore = $order->approvalFor(PurchaseOrderApprovalStage::PreparedBy)->toArray();

        $this->post(route('purchase-orders.check', $order->purchaseRequest), $this->workflowSignature($this->preparer))
            ->assertForbidden();
        $this->withSession(['workspace_role_id' => $checkerRoleId])->get(route('dashboard'))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->contains(
                fn ($action) => $action['request']->is($order->purchaseRequest) && $action['label'] === 'Check purchase order'
            ));
        $this->get(route('purchase-requests.show', $order->purchaseRequest))->assertOk()
            ->assertSee('Checker remarks')->assertSee('Checked By signature');
        $this->get(route('dashboard'))->assertOk()->assertSee('Check purchase order')
            ->assertDontSee('No requests require your action');
        $this->get(route('dashboard', ['po_diagnostics' => 1]))->assertOk()
            ->assertJsonPath('checker_policy_version', 'self-check-v1')
            ->assertJsonPath('account_id', $this->preparer->id)
            ->assertJsonPath('workspace', 'po-checker')
            ->assertJsonPath('queued_count', 1)->assertJsonPath('action_row_count', 1)
            ->assertJsonPath('orders.0.loaded_can_check', true)
            ->assertJsonPath('orders.0.fresh_can_check', true)
            ->assertJsonPath('orders.0.fresh_can_sign', true);
        $this->post(route('purchase-orders.check', $order->purchaseRequest), ['remarks' => 'No signature'])
            ->assertSessionHasErrors('signature_data');
        $embeddedUrl = route('purchase-requests.show', [$order->purchaseRequest, '_embedded' => 1]);
        $this->from($embeddedUrl)->post(route('purchase-orders.check', $order->purchaseRequest), [
            ...$this->workflowSignature($this->preparer), 'remarks' => 'Prepared and checked by the assigned user.', '_embedded' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect($embeddedUrl)
            ->assertSessionHas('workflow_submission_notice', fn ($notice) => $notice['user_id'] === $this->preparer->id
                && $notice['stage'] === 'Next: Purchase Order notation');
        $order->refresh();
        $checked = $order->approvalFor(PurchaseOrderApprovalStage::CheckedBy);
        $this->assertSame('FOR_ACKNOWLEDGEMENT', $order->status);
        $this->assertTrue($checked->hasValidSignature());
        $this->assertSame($this->preparer->id, $checked->acted_by);
        $this->assertSame('Prepared and checked by the assigned user.', $checked->remarks);
        $this->assertSame($preparedBefore, $order->approvalFor(PurchaseOrderApprovalStage::PreparedBy)->toArray());
        $this->post(route('purchase-orders.check', $order->purchaseRequest), $this->workflowSignature($this->preparer))
            ->assertForbidden();

        $this->withSession(['workspace_role_id' => null])->actingAs($this->noter)->get(route('dashboard'))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->contains(fn ($action) => $action['request']->is($order->purchaseRequest)));
    }

    public function test_transfer_previews_then_audits_only_unsigned_checker_and_optionally_future_routing(): void
    {
        $order = $this->createOrder();
        $this->preparer->roles()->sync([$this->checker->role_id]);
        $before = $order->approvals()->orderBy('id')->get()->toArray();
        $arguments = ['numbers' => [$order->po_number], '--user' => $this->preparer->id,
            '--from-user' => $this->checker->id, '--update-routing' => true];
        $this->artisan('po:assign-checker', $arguments)->expectsOutputToContain('Preview only.')->assertSuccessful();
        $this->assertSame($before, $order->approvals()->orderBy('id')->get()->toArray());
        $this->assertSame($this->checker->id, (int) PoRoleAssignment::where('stage', PurchaseOrderApprovalStage::CheckedBy->value)->value('user_id'));

        $this->artisan('po:assign-checker', [...$arguments, '--apply' => true])->assertSuccessful();
        $order->refresh();
        $this->assertSame($this->preparer->id, (int) $order->approvalFor(PurchaseOrderApprovalStage::CheckedBy)->assigned_user_id);
        $this->assertSame($this->preparer->id, (int) PoRoleAssignment::where('stage', PurchaseOrderApprovalStage::CheckedBy->value)->value('user_id'));
        $this->assertSame($before[0], $order->approvals()->orderBy('id')->first()->toArray());
        $this->assertSame('FOR_CHECKING', $order->status);
        $logs = $order->purchaseRequest->auditLogs()->where('action', 'PURCHASE_ORDER_CHECKER_REASSIGNED');
        $this->assertSame(1, (clone $logs)->count());
        $this->assertSame($this->checker->id, (clone $logs)->first()->metadata['from_user_id']);
        $this->assertSame($this->preparer->id, (clone $logs)->first()->metadata['to_user_id']);
        $this->artisan('po:assign-checker', [...$arguments, '--apply' => true])->assertSuccessful();
        $this->assertSame(1, (clone $logs)->count());

        $this->actingAs($this->preparer)->withSession(['workspace_role_id' => $this->checker->role_id])
            ->get(route('dashboard'))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->contains(fn ($action) => $action['request']->is($order->purchaseRequest)));
        $this->post(route('purchase-orders.check', $order->purchaseRequest), [
            ...$this->workflowSignature($this->preparer), 'remarks' => 'Checked after transfer.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('FOR_ACKNOWLEDGEMENT', $order->fresh()->status);
    }

    public function test_transfer_refuses_signed_checks_and_leaves_every_requested_po_and_routing_unchanged(): void
    {
        $order = $this->createOrder();
        $newChecker = $this->user(RoleSlug::PoChecker);
        $otherRequest = $order->purchaseRequest->replicate();
        $otherRequest->request_number = 'CHECKER-FLOW-SECOND';
        $otherRequest->save();
        $other = $order->replicate();
        $other->po_number = 'CHECKER-FLOW-SECOND';
        $other->purchase_request_id = $otherRequest->id;
        $other->save();
        $other->approvals()->create([
            'stage' => PurchaseOrderApprovalStage::CheckedBy, 'assigned_user_id' => $this->checker->id,
            'status' => 'PENDING', 'signature_data' => $this->signatureImage(),
        ]);
        $this->artisan('po:assign-checker', ['numbers' => [$order->po_number, $other->po_number],
            '--user' => $newChecker->id, '--from-user' => $this->checker->id,
            '--update-routing' => true, '--apply' => true])
            ->expectsOutputToContain('only pending, unsigned checking steps')->assertFailed();
        $this->assertSame($this->checker->id, (int) $order->fresh()->approvalFor(PurchaseOrderApprovalStage::CheckedBy)->assigned_user_id);
        $this->assertSame($this->checker->id, (int) PoRoleAssignment::where('stage', PurchaseOrderApprovalStage::CheckedBy->value)->value('user_id'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'PURCHASE_ORDER_CHECKER_REASSIGNED']);
    }

    public function test_transfer_refuses_missing_numbers_unexpected_assignees_and_invalid_receiving_accounts(): void
    {
        $order = $this->createOrder();
        $newChecker = $this->user(RoleSlug::PoChecker);
        $arguments = ['numbers' => [$order->po_number], '--user' => $newChecker->id,
            '--from-user' => $this->checker->id, '--apply' => true];
        $this->artisan('po:assign-checker', [...$arguments, 'numbers' => [$order->po_number, 'MISSING']])->assertFailed();
        $this->artisan('po:assign-checker', [...$arguments, '--from-user' => $this->noter->id])->assertFailed();
        $this->artisan('po:assign-checker', [...$arguments, '--user' => $this->noter->id])->assertFailed();
        $newChecker->update(['is_active' => false]);
        $this->artisan('po:assign-checker', $arguments)->assertFailed();
        $this->assertSame($this->checker->id, (int) $order->fresh()->approvalFor(PurchaseOrderApprovalStage::CheckedBy)->assigned_user_id);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'PURCHASE_ORDER_CHECKER_REASSIGNED']);
    }

    public function test_admin_can_share_preparer_and_checker_but_other_stages_stay_separate(): void
    {
        $this->actingAs($this->user(RoleSlug::Admin));
        $assignments = [
            PurchaseOrderApprovalStage::PreparedBy->value => $this->preparer->id,
            PurchaseOrderApprovalStage::CheckedBy->value => $this->preparer->id,
            PurchaseOrderApprovalStage::NotedBy->value => $this->noter->id,
            PurchaseOrderApprovalStage::ApprovedBy->value => $this->approver->id,
        ];
        $this->put(route('admin.po-workflow-assignments.update'), ['assignments' => $assignments])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->preparer->id, (int) PoRoleAssignment::where('stage', PurchaseOrderApprovalStage::CheckedBy->value)->value('user_id'));
        $this->put(route('admin.po-workflow-assignments.update'), ['assignments' => [
            ...$assignments, PurchaseOrderApprovalStage::NotedBy->value => $this->preparer->id,
        ]])->assertSessionHasErrors('assignments.'.PurchaseOrderApprovalStage::NotedBy->value);
        $this->put(route('admin.po-workflow-assignments.update'), ['assignments' => [
            ...$assignments, PurchaseOrderApprovalStage::ApprovedBy->value => $this->noter->id,
        ]])->assertSessionHasErrors();
        $this->noter->update(['is_active' => false]);
        $this->put(route('admin.po-workflow-assignments.update'), ['assignments' => $assignments])
            ->assertSessionHasErrors('assignments.'.PurchaseOrderApprovalStage::NotedBy->value);
    }

    public function test_creator_still_cannot_note_or_approve_own_po(): void
    {
        $order = $this->createOrder();
        $this->actingAs($this->checker)->post(route('purchase-orders.check', $order->purchaseRequest), $this->workflowSignature($this->checker))
            ->assertSessionHasNoErrors();
        $this->preparer->roles()->sync([$this->noter->role_id, $this->approver->role_id]);
        $order->approvals()->where('stage', PurchaseOrderApprovalStage::NotedBy->value)->update(['assigned_user_id' => $this->preparer->id]);
        $this->actingAs($this->preparer)->withSession(['workspace_role_id' => $this->noter->role_id])
            ->post(route('purchase-orders.acknowledge', $order->purchaseRequest), $this->workflowSignature($this->preparer))
            ->assertForbidden();
        $this->post(route('purchase-orders.signature', [$order->purchaseRequest, PurchaseOrderApprovalStage::NotedBy->value]), $this->workflowSignature($this->preparer))
            ->assertForbidden();

        $order->approvals()->where('stage', PurchaseOrderApprovalStage::NotedBy->value)->update([
            'status' => 'APPROVED', 'acted_by' => $this->preparer->id,
            'signature_name' => $this->preparer->name, 'signature_data' => $this->signatureImage(),
            'signed_at' => now(), 'acted_at' => now(),
        ]);
        $order->approvals()->where('stage', PurchaseOrderApprovalStage::ApprovedBy->value)->update(['assigned_user_id' => $this->preparer->id]);
        $order->update(['status' => 'FOR_APPROVAL']);
        $this->withSession(['workspace_role_id' => $this->approver->role_id])
            ->post(route('purchase-orders.approve', $order->purchaseRequest), [
                ...$this->workflowSignature($this->preparer), 'decision' => 'APPROVE',
            ])->assertForbidden();
        $this->post(route('purchase-orders.signature', [$order->purchaseRequest, PurchaseOrderApprovalStage::ApprovedBy->value]), $this->workflowSignature($this->preparer))
            ->assertForbidden();
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role->value)->value('id')]);
    }

    private function createOrder(): PurchaseOrder
    {
        $campaign = Campaign::create(['name' => 'Checker flow campaign', 'account_number' => 'FLOW-001', 'is_active' => true]);
        $requestor = $this->user(RoleSlug::Requestor);
        $requestor->update(['campaign_id' => $campaign->id]);
        $supplier = Supplier::create(['name' => 'Checker flow supplier', 'is_active' => true]);
        $request = PurchaseRequest::create([
            'request_number' => 'CHECKER-FLOW-001', 'title' => 'Checker flow request',
            'requestor_id' => $requestor->id, 'department_campaign' => $campaign->name,
            'request_type' => 'OTHER', 'purpose' => 'Verify PO checker handoff',
            'required_date' => now()->addWeek(), 'delivery_location' => 'Main Office',
            'status' => PurchaseRequestStatus::ForBudgetProcessing,
            'current_responsible_role_id' => $this->preparer->role_id,
            'selected_supplier_id' => $supplier->id, 'total_amount' => 100,
        ]);
        $item = $request->items()->create([
            'description' => 'Office supplies', 'quantity' => 1, 'unit' => 'box',
            'estimated_unit_price' => 100, 'estimated_total' => 100,
        ]);
        $request->quotations()->create([
            'supplier_id' => $supplier->id, 'created_by' => $this->preparer->id,
            'purchase_request_item_id' => $item->id, 'item_or_service' => $item->description,
            'price' => 100, 'is_approved' => true, 'approved_at' => now(),
        ]);
        $this->actingAs($this->preparer)->post(route('purchase-orders.store', $request), [
            'expected_delivery_date' => now()->addWeek()->toDateString(),
            'payable_on' => now()->toDateString(), 'account_number' => 'SUPPLIER-001',
            'account_name' => $supplier->name, 'payment_terms' => 'On delivery',
            'payment_details' => 'Bank transfer', 'remarks' => 'Prepared for checking.',
            ...$this->workflowSignature($this->preparer),
        ])->assertSessionHasNoErrors()->assertRedirect();

        return $request->purchaseOrder()->firstOrFail();
    }
}
