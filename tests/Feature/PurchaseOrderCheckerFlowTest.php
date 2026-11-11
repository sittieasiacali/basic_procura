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

    public function test_checker_role_can_check_a_po_assigned_to_another_account(): void
    {
        $order = $this->createOrder();
        $newChecker = $this->user(RoleSlug::PoChecker);
        PoRoleAssignment::where('stage', PurchaseOrderApprovalStage::CheckedBy->value)->update(['user_id' => $newChecker->id]);

        $this->actingAs($newChecker)->get(route('dashboard'))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->contains(fn ($action) => $action['request']->is($order->purchaseRequest)));

        $before = $order->approvals()->orderBy('id')->get()->toArray();
        $this->artisan('po:diagnose', ['numbers' => [$order->po_number]])
            ->expectsOutputToContain('Assigned checker: '.$this->checker->name)
            ->expectsOutputToContain('Saving workflow assignments only affects new POs.')
            ->expectsOutputToContain('Other PO Checker role account IDs: '.$newChecker->id)
            ->assertSuccessful();
        $this->assertSame($before, $order->approvals()->orderBy('id')->get()->toArray());
        $this->assertSame('FOR_CHECKING', $order->fresh()->status);
        $this->post(route('purchase-orders.check', $order->purchaseRequest), $this->workflowSignature($newChecker))->assertSessionHasNoErrors();
        $this->assertSame($newChecker->id, $order->fresh()->approvalFor(PurchaseOrderApprovalStage::CheckedBy)->acted_by);
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

    public function test_admin_can_share_accounts_across_stages(): void
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
        ]])->assertSessionHasNoErrors();
        $this->put(route('admin.po-workflow-assignments.update'), ['assignments' => [
            ...$assignments, PurchaseOrderApprovalStage::ApprovedBy->value => $this->noter->id,
        ]])->assertSessionHasNoErrors();
        $this->noter->update(['is_active' => false]);
        $this->put(route('admin.po-workflow-assignments.update'), ['assignments' => $assignments])
            ->assertSessionHasErrors('assignments.'.PurchaseOrderApprovalStage::NotedBy->value);
    }

    public function test_creator_with_roles_can_check_note_and_approve_own_po(): void
    {
        $order = $this->createOrder();
        $this->preparer->roles()->sync([$this->checker->role_id, $this->noter->role_id, $this->approver->role_id]);
        $this->actingAs($this->preparer)->withSession(['workspace_role_id' => $this->checker->role_id])
            ->get(route('dashboard'))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->contains(fn ($action) => $action['request']->is($order->purchaseRequest)));
        $this->post(route('purchase-orders.check', $order->purchaseRequest), $this->workflowSignature($this->preparer))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->preparer->id, $order->fresh()->approvalFor(PurchaseOrderApprovalStage::CheckedBy)->acted_by);
        $this->actingAs($this->preparer)->withSession(['workspace_role_id' => $this->noter->role_id])
            ->get(route('dashboard'))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->contains(fn ($action) => $action['request']->is($order->purchaseRequest)));
        $this->get(route('purchase-requests.show', $order->purchaseRequest))->assertOk()->assertSee('Noted By signature');
        $this->post(route('purchase-orders.acknowledge', $order->purchaseRequest), $this->workflowSignature($this->preparer))
            ->assertSessionHasNoErrors();
        $noted = $order->fresh()->approvalFor(PurchaseOrderApprovalStage::NotedBy);
        $this->assertSame($this->noter->id, $noted->assigned_user_id);
        $this->assertSame($this->preparer->id, $noted->acted_by);
        $this->assertTrue($noted->hasValidSignature());
        $this->assertSame('FOR_APPROVAL', $order->fresh()->status);
        $this->withSession(['workspace_role_id' => $this->approver->role_id])
            ->get(route('dashboard'))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->contains(fn ($action) => $action['request']->is($order->purchaseRequest)));
        $this->get(route('purchase-requests.show', $order->purchaseRequest))->assertOk()->assertSee('Approved By signature');
        $this->post(route('purchase-orders.approve', $order->purchaseRequest), [
            ...$this->workflowSignature($this->preparer), 'decision' => 'APPROVE',
        ])->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $order->fresh()->status);
        $approved = $order->fresh()->approvalFor(PurchaseOrderApprovalStage::ApprovedBy);
        $this->assertSame($this->approver->id, $approved->assigned_user_id);
        $this->assertSame($this->preparer->id, $approved->acted_by);
        $this->assertTrue($approved->hasValidSignature());
    }

    public function test_any_noter_can_act_but_cannot_use_another_noters_signature(): void
    {
        $order = $this->createOrder();
        $this->actingAs($this->checker)->post(route('purchase-orders.check', $order->purchaseRequest), $this->workflowSignature($this->checker))
            ->assertSessionHasNoErrors();
        $otherNoter = $this->user(RoleSlug::PoNoted);
        $this->actingAs($otherNoter)->get(route('dashboard'))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->contains(fn ($action) => $action['request']->is($order->purchaseRequest)));
        $this->get(route('purchase-requests.show', $order->purchaseRequest))->assertOk()->assertSee('Noted By signature');
        $this->post(route('purchase-orders.signature', [$order->purchaseRequest, PurchaseOrderApprovalStage::NotedBy->value]), $this->workflowSignature($otherNoter))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->noter)->post(route('purchase-orders.acknowledge', $order->purchaseRequest), [])->assertForbidden();
        $this->actingAs($otherNoter)->post(route('purchase-orders.acknowledge', $order->purchaseRequest), [])->assertSessionHasNoErrors();
        $this->get(route('dashboard'))->assertOk()->assertViewHas('myActions', fn ($actions) => $actions->isEmpty());
        $this->actingAs($this->approver)->get(route('dashboard'))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->contains(fn ($action) => $action['request']->is($order->purchaseRequest)));
    }

    public function test_role_based_checker_queue_paginates_multiple_orders(): void
    {
        $order = $this->createOrder();
        for ($i = 1; $i <= 12; $i++) {
            $request = $order->purchaseRequest->replicate();
            $request->request_number = 'ROLE-QUEUE-'.$i;
            $request->save();
            $copy = $order->replicate();
            $copy->po_number = 'ROLE-QUEUE-'.$i;
            $copy->purchase_request_id = $request->id;
            $copy->save();
            foreach ($order->approvals as $approval) {
                $copy->approvals()->create($approval->only([
                    'stage', 'assigned_user_id', 'acted_by', 'status', 'decision', 'remarks',
                    'signature_name', 'signature_data', 'signed_at', 'acted_at',
                ]));
            }
        }
        $checker = $this->user(RoleSlug::PoChecker);
        $this->actingAs($checker)->get(route('dashboard', ['po_diagnostics' => 1]))->assertOk()
            ->assertJsonPath('queued_count', 13)->assertJsonPath('action_row_count', 12);
        $this->get(route('dashboard', ['po_diagnostics' => 1, 'approval_page' => 2]))->assertOk()
            ->assertJsonPath('queued_count', 13)->assertJsonPath('action_row_count', 1);
    }

    public function test_role_based_approver_cannot_use_another_approvers_signature(): void
    {
        $order = $this->createOrder();
        $this->actingAs($this->checker)->post(route('purchase-orders.check', $order->purchaseRequest), $this->workflowSignature($this->checker))->assertSessionHasNoErrors();
        $this->actingAs($this->noter)->post(route('purchase-orders.acknowledge', $order->purchaseRequest), $this->workflowSignature($this->noter))->assertSessionHasNoErrors();
        $other = $this->user(RoleSlug::PoApprover);
        $this->actingAs($other)->get(route('dashboard', ['po_diagnostics' => 1]))->assertOk()
            ->assertJsonPath('orders.0.current_action', 'approve')
            ->assertJsonPath('orders.0.can_perform_current_action', true)
            ->assertJsonPath('orders.0.can_sign_current_stage', true);
        $this->post(route('purchase-orders.signature', [$order->purchaseRequest, PurchaseOrderApprovalStage::ApprovedBy->value]), $this->workflowSignature($other))->assertSessionHasNoErrors();
        $this->actingAs($this->approver)->post(route('purchase-orders.approve', $order->purchaseRequest), ['decision' => 'APPROVE'])->assertForbidden();
        $this->actingAs($other)->post(route('purchase-orders.approve', $order->purchaseRequest), ['decision' => 'APPROVE'])->assertSessionHasNoErrors();
        $this->assertSame($other->id, $order->fresh()->approvalFor(PurchaseOrderApprovalStage::ApprovedBy)->acted_by);
        $this->assertSame('APPROVED', $order->fresh()->status);
    }

    public function test_browser_diagnostics_require_login_and_only_include_the_users_own_queue(): void
    {
        $this->get(route('dashboard', ['po_diagnostics' => 1]))->assertRedirect(route('login'));
        $order = $this->createOrder();
        $this->get(route('dashboard', ['po_diagnostics' => 1]))->assertForbidden();
        $this->actingAs($this->checker)->get(route('dashboard', ['po_diagnostics' => 1]))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('orders.0.po_number', $order->po_number)
            ->assertJsonPath('orders.0.action_row_present', true)
            ->assertJsonMissingPath('orders.0.signature_data')
            ->assertDontSee($this->signatureImage(), false);
        $otherChecker = $this->user(RoleSlug::PoChecker);
        $this->actingAs($otherChecker)->get(route('dashboard', ['po_diagnostics' => 1]))->assertOk()
            ->assertJsonPath('orders.0.po_number', $order->po_number)->assertJsonPath('queued_count', 1);
    }

    public function test_president_only_reviews_suppliers_and_cannot_create_purchase_orders(): void
    {
        $president = $this->user(RoleSlug::President);
        $pending = $this->awaitingOrder();
        $review = $pending->replicate();
        $review->request_number = 'PRESIDENT-REVIEW-001';
        $review->status = PurchaseRequestStatus::ForSupplierApproval;
        $review->selected_supplier_id = null;
        $review->save();
        // Even an older preparer assignment cannot grant preparation in the President workspace.
        PoRoleAssignment::where('stage', PurchaseOrderApprovalStage::PreparedBy->value)->update(['user_id' => $president->id]);
        $this->assertFalse($president->hasPoRole(PurchaseOrderApprovalStage::PreparedBy));
        $this->assertFalse($president->can('prepare', PurchaseOrder::class));
        $this->actingAs($president)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Create purchase order')
            ->assertViewHas('myActions', fn ($actions) => $actions->count() === 1
                && $actions->first()['request']->is($review)
                && $actions->first()['label'] === 'Review supplier selection');
        $this->get(route('purchase-requests.show', $pending))->assertOk()->assertDontSee('data-po-create-form', false);
        $this->post(route('purchase-orders.store', $pending))->assertForbidden();
        $this->assertDatabaseCount('purchase_orders', 0);

        // A separate Purchasing assignment is usable only after switching workspace.
        $teamRole = Role::where('slug', RoleSlug::PurchasingTeam->value)->firstOrFail();
        $president->roles()->attach($teamRole);
        $this->withSession(['workspace_role_id' => $president->role_id])
            ->get(route('purchase-requests.show', $pending))->assertOk()->assertDontSee('data-po-create-form', false);
        $this->post(route('purchase-orders.store', $pending))->assertForbidden();
        $this->withSession(['workspace_role_id' => $teamRole->id])
            ->get(route('purchase-requests.show', $pending))->assertOk()->assertSee('data-po-create-form', false);
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role->value)->value('id')]);
    }

    private function awaitingOrder(): PurchaseRequest
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
        return $request;
    }

    private function createOrder(): PurchaseOrder
    {
        $request = $this->awaitingOrder();
        $supplier = $request->selectedSupplier;
        $actor = $this->preparer;
        $this->actingAs($actor)->get(route('purchase-requests.show', $request))->assertOk()
            ->assertSee('data-po-create-form', false);
        $this->post(route('purchase-orders.store', $request), [
            'expected_delivery_date' => now()->addWeek()->toDateString(),
            'payable_on' => now()->toDateString(), 'account_number' => 'SUPPLIER-001',
            'account_name' => $supplier->name, 'payment_terms' => 'On delivery',
            'payment_details' => 'Bank transfer', 'remarks' => 'Prepared for checking.',
            ...$this->workflowSignature($actor),
        ])->assertSessionHasNoErrors()->assertRedirect();

        return $request->purchaseOrder()->firstOrFail();
    }
}
