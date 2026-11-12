<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\BudgetRequest;
use App\Models\Campaign;
use App\Models\PoRoleAssignment;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseRequestWorkflow;
use App\Support\ItemBudgetAllocation;
use Database\Seeders\CampaignSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BudgetRevisionRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, CampaignSeeder::class]);
    }

    public function test_saved_item_allocation_history_is_preserved_without_the_removed_section(): void
    {
        [$owner, $creator, $request, $items] = $this->fixture();
        $payload = $this->poPayload($creator, $request);
        $payload['item_allocations'] = [$items[0]->id => '20.00', $items[1]->id => '80.00'];
        $this->actingAs($creator)->get(route('purchase-requests.show', $request))->assertOk()->assertDontSee('Budget allocation per item');
        $this->post(route('purchase-orders.store', $request), $payload)->assertSessionHasNoErrors();
        $order = $request->purchaseOrder()->sole();
        $this->assertSame(['20.00', '80.00'], array_column($order->item_budget_allocations, 'amount'));
        $this->assertSame(['0.01', '0.02'], array_column($order->item_budget_allocations, 'shipping_fee'));
        $this->assertSame(['20.01', '80.02'], array_column($order->item_budget_allocations, 'total'));
        $this->assertEquals(100.03, $order->total_amount);

        $order->update(['status' => 'APPROVED']);
        $this->post(route('budget.store'), $this->budgetPayload($request))->assertSessionHasNoErrors();
        $budget = BudgetRequest::query()->sole();
        $this->assertSame($order->item_budget_allocations, $budget->lines()->sole()->item_allocations);
        $this->get(route('budget.finalize', ['ids' => [$budget->id]]))->assertOk()->assertDontSee('Budget allocation per item');
        $this->get(route('budget-requests.document', $budget))->assertOk()->assertDontSee('Budget allocation per item');
    }

    public function test_invalid_or_foreign_item_allocations_cannot_create_a_po(): void
    {
        [, $creator, $request, $items] = $this->fixture();
        foreach ([[$items[0]->id => 99, $items[1]->id => 0], [$items[0]->id => 100], [$items[0]->id => 50, 999999 => 50]] as $amounts) {
            $this->actingAs($creator)->post(route('purchase-orders.store', $request), [
                ...$this->poPayload($creator, $request), 'item_allocations' => $amounts,
            ])->assertSessionHasErrors('item_allocations');
            $this->assertDatabaseCount('purchase_orders', 0);
        }
    }

    public function test_only_po_creator_can_reallocate_during_budget_preparation(): void
    {
        [, $creator, $request, $items] = $this->fixture();
        $this->actingAs($creator)->post(route('purchase-orders.store', $request), $this->poPayload($creator, $request))->assertSessionHasNoErrors();
        $order = $request->purchaseOrder()->sole();
        $order->update(['status' => 'APPROVED']);
        $other = $this->user(RoleSlug::PurchasingTeam);
        $this->actingAs($other)->get(route('budget.create', ['purchase_request_id' => $request->id]))->assertOk()
            ->assertDontSee('Budget allocation per item')->assertDontSee('name="item_allocations[', false);
        $this->post(route('budget.store'), [...$this->budgetPayload($request), 'item_allocations' => [$items[0]->id => 20, $items[1]->id => 80]])->assertForbidden();
        $this->assertDatabaseCount('budget_requests', 0);
        $this->actingAs($creator)->post(route('budget.store'), [...$this->budgetPayload($request), 'item_allocations' => [$items[0]->id => 30, $items[1]->id => 70]])->assertSessionHasNoErrors();
        $this->assertSame(['30.00', '70.00'], array_column(BudgetRequest::query()->sole()->lines()->sole()->item_allocations, 'amount'));
    }

    public function test_pending_void_stops_po_actions_and_budget_finalization(): void
    {
        [$owner, $creator, $request] = $this->fixture();
        $this->actingAs($creator)->post(route('purchase-orders.store', $request), $this->poPayload($creator, $request))->assertSessionHasNoErrors();
        $order = $request->purchaseOrder()->sole();
        $order->update(['status' => 'APPROVED']);
        $this->post(route('budget.store'), $this->budgetPayload($request))->assertSessionHasNoErrors();
        $budget = BudgetRequest::query()->sole();
        $this->actingAs($owner)->post(route('purchase-requests.withdraw', $request), ['withdrawal_reason' => 'Items are no longer needed.'])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::WithdrawalPending, $request->fresh()->status);
        $this->actingAs($creator)->post(route('budget.finalize.store'), ['ids' => [$budget->id], 'priority_level' => 'NORMAL', ...$this->workflowSignature($creator)])->assertSessionHasErrors('ids');
        $this->assertNull($budget->fresh()->final_approval_at);
        $this->assertNull($budget->budget_request_number);
        $order->update(['status' => 'FOR_CHECKING']);
        $checker = User::where('role_id', Role::where('slug', RoleSlug::PoChecker->value)->value('id'))->firstOrFail();
        $this->actingAs($checker)->post(route('purchase-orders.check', $request), $this->workflowSignature($checker))->assertForbidden();
        $this->assertSame('FOR_CHECKING', $order->fresh()->status);
        $this->actingAs($creator)->post(route('purchase-requests.withdrawal.acknowledge', $request))->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::Voided, $request->fresh()->status);
    }

    public function test_final_budget_notifies_requestor_and_rejects_stale_void_forms(): void
    {
        [$owner, $creator, $request] = $this->fixture();
        $this->actingAs($creator)->post(route('purchase-orders.store', $request), $this->poPayload($creator, $request))->assertSessionHasNoErrors();
        $order = $request->purchaseOrder()->sole();
        $order->update(['status' => 'APPROVED']);
        $this->post(route('budget.store'), $this->budgetPayload($request))->assertSessionHasNoErrors();
        $budget = BudgetRequest::query()->sole();
        $this->assertTrue($owner->can('withdraw', $request->fresh()));
        $this->post(route('budget.finalize.store'), ['ids' => [$budget->id], 'priority_level' => 'NORMAL', ...$this->workflowSignature($creator)])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForPayment, $request->fresh()->status);
        $this->assertFalse($owner->can('withdraw', $request->fresh()));
        $notice = $owner->notifications()->get()->first(fn ($notice) => $notice->data['action'] === 'PURCHASING_BUDGET_APPROVED');
        $this->assertNotNull($notice);
        $this->assertStringContainsString('Void requests are no longer allowed', $notice->data['message']);
        $this->actingAs($owner)->get(route('purchase-requests.show', $request))->assertOk()->assertSee('Void requests are no longer allowed')->assertDontSee('data-bs-target="#withdraw-request-modal"', false);
        $this->post(route('purchase-requests.withdraw', $request), ['withdrawal_reason' => 'Stale browser tab request.'])->assertSessionHas('error');
        $this->assertSame(PurchaseRequestStatus::ForPayment, $request->fresh()->status);
        // A historical final approval also blocks voiding if a status is later changed.
        $request->update(['status' => PurchaseRequestStatus::ForBudgetProcessing]);
        $this->assertFalse($owner->can('withdraw', $request->fresh()));
    }

    public function test_return_for_correction_requires_and_records_a_reason(): void
    {
        [, , $request] = $this->fixture();
        $president = $this->user(RoleSlug::President);
        $request->update(['status' => PurchaseRequestStatus::ForSupplierApproval]);
        foreach (['', '   '] as $reason) {
            $this->actingAs($president)->post(route('president-approvals.supplier.decision', $request), [
                'decision' => 'REJECT', 'remarks' => $reason, ...$this->workflowSignature($president),
            ])->assertSessionHasErrors('remarks');
            $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->fresh()->status);
        }
        $this->post(route('president-approvals.supplier.decision', $request), [
            'decision' => 'REJECT', 'remarks' => 'Correct the supplier bank account.', ...$this->workflowSignature($president),
        ])->assertSessionHasNoErrors();
        $this->assertSame('Correct the supplier bank account.', $request->auditLogs()->where('action', 'PRESIDENT_SUPPLIER_APPROVAL_REJECTED')->sole()->remarks);
        $this->assertSame(PurchaseRequestStatus::ForCanvassing, $request->fresh()->status);
        $purchasingUser = User::where('role_id', Role::where('slug', RoleSlug::PurchasingTeam->value)->value('id'))->firstOrFail();
        $this->assertTrue($purchasingUser->notifications()->get()->contains(fn ($notice) => str_contains($notice->data['message'], 'Correct the supplier bank account.')));
    }

    public function test_transition_service_rejects_a_return_without_remarks(): void
    {
        [, $creator, $request] = $this->fixture();
        $request->update(['status' => PurchaseRequestStatus::ForOmApproval]);
        $this->expectException(ValidationException::class);
        app(PurchaseRequestWorkflow::class)->transition($request, PurchaseRequestStatus::Returned, $creator, 'OM_RETURNED', '   ');
    }

    public function test_rounding_never_loses_shipping_cents(): void
    {
        $items = collect([1, 2, 3])->map(fn ($id) => (object) ['id' => $id, 'description' => 'Item '.$id, 'estimated_total' => 1]);
        $rows = ItemBudgetAllocation::allocate($items, 3.01, .01, [1 => '1.00', 2 => '1.00', 3 => '1.00']);
        $this->assertSame(['0.01', '0.00', '0.00'], array_column($rows, 'shipping_fee'));
        $this->assertEquals(3.01, array_sum(array_column($rows, 'total')));
        $zero = ItemBudgetAllocation::allocate($items, .01, .01, [1 => 0, 2 => 0, 3 => 0]);
        $this->assertEquals(.01, array_sum(array_column($zero, 'total')));
    }

    public function test_group_finalization_keeps_item_allocations_and_closes_voiding_for_every_request(): void
    {
        [$owner, $creator, $request] = $this->fixture();
        $second = $this->copyRequest($request);
        foreach ([$request, $second] as $entry) {
            $this->actingAs($creator)->post(route('purchase-orders.store', $entry), $this->poPayload($creator, $entry))->assertSessionHasNoErrors();
            $entry->purchaseOrder()->update(['status' => 'APPROVED']);
            $this->post(route('budget.store'), $this->budgetPayload($entry))->assertSessionHasNoErrors();
        }
        $ids = BudgetRequest::query()->pluck('id')->all();
        $this->post(route('budget.finalize.store'), ['ids' => $ids, 'priority_level' => 'NORMAL', ...$this->workflowSignature($creator)])->assertSessionHasNoErrors();
        $budget = BudgetRequest::query()->sole();
        $this->assertCount(2, $budget->lines);
        foreach ([$request, $second] as $entry) {
            $this->assertSame(PurchaseRequestStatus::ForPayment, $entry->fresh()->status);
            $this->assertFalse($owner->can('withdraw', $entry->fresh()));
            $this->assertCount(2, $budget->lines->firstWhere('purchase_order_id', $entry->purchaseOrder->id)->item_allocations);
        }
        $this->assertSame(2, $owner->notifications()->get()->filter(fn ($notice) => $notice->data['action'] === 'PURCHASING_BUDGET_APPROVED')->count());
    }

    public function test_batch_with_one_pending_void_does_not_finalize_any_selected_budget(): void
    {
        [$owner, $creator, $request] = $this->fixture();
        $second = $this->copyRequest($request);
        foreach ([$request, $second] as $entry) {
            $this->actingAs($creator)->post(route('purchase-orders.store', $entry), $this->poPayload($creator, $entry))->assertSessionHasNoErrors();
            $entry->purchaseOrder()->update(['status' => 'APPROVED']);
            $this->post(route('budget.store'), $this->budgetPayload($entry))->assertSessionHasNoErrors();
        }
        $ids = BudgetRequest::query()->pluck('id')->all();
        $this->actingAs($owner)->post(route('purchase-requests.withdraw', $second), ['withdrawal_reason' => 'Second request no longer needed.'])->assertSessionHasNoErrors();
        $this->actingAs($creator)->post(route('budget.finalize.store'), ['ids' => $ids, 'priority_level' => 'NORMAL', ...$this->workflowSignature($creator)])->assertSessionHasErrors('ids');
        $this->assertSame(2, BudgetRequest::query()->count());
        $this->assertSame(0, BudgetRequest::query()->whereNotNull('budget_request_number')->count());
        $this->assertSame(PurchaseRequestStatus::AwaitingPresidentFinalApproval, $request->fresh()->status);
        $this->assertSame(PurchaseRequestStatus::WithdrawalPending, $second->fresh()->status);
    }

    public function test_po_preparer_returns_to_requestor_with_required_reason_and_fresh_screening(): void
    {
        [$owner, $creator, $request] = $this->fixture();
        $screener = $this->user(RoleSlug::ItStaff);
        $request->update(['request_type' => 'IT', 'requested_team' => 'IT', 'assigned_user_id' => $screener->id]);
        $oldScreening = $request->screening()->create(['screened_by' => $screener->id, 'team' => 'IT', 'decision' => 'UNAVAILABLE', 'evidence' => 'Original evidence', 'decided_at' => now()]);
        $this->actingAs($creator)->get(route('purchase-requests.show', $request))->assertOk()->assertSee('Return to Requestor')->assertSee('id="po-return-requestor-modal"', false);
        $this->post(route('purchase-orders.return-to-requestor', $request), ['correction_reason' => '   '])->assertSessionHasErrors('correction_reason');
        $this->assertSame(PurchaseRequestStatus::ForBudgetProcessing, $request->fresh()->status);
        $this->post(route('purchase-orders.return-to-requestor', $request), ['correction_reason' => 'Correct the item specifications.'])->assertRedirect()->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::Returned, $request->status);
        $this->assertNull($request->selected_supplier_id);
        $this->assertNull($request->screening);
        $this->assertNotNull($oldScreening->fresh()->superseded_at);
        $this->assertSame('Original evidence', $oldScreening->fresh()->evidence);
        $this->assertFalse($request->quotations()->sole()->is_approved);
        $this->assertSame('Correct the item specifications.', $request->latestReturnApproval->remarks);
        $this->assertTrue($owner->can('update', $request));
        $this->actingAs($owner)->get(route('purchase-requests.show', $request))->assertOk()->assertSee('Correct the item specifications.');
        $this->assertTrue($owner->notifications()->get()->contains(fn ($notice) => str_contains($notice->data['message'], 'Correct the item specifications.')));
        $this->actingAs($creator)->post(route('purchase-orders.return-to-requestor', $request), ['correction_reason' => 'Repeated request.'])->assertForbidden();
        $this->actingAs($owner);
        app(PurchaseRequestWorkflow::class)->submitForScreening($request, $owner, 'Corrected specifications.', $owner->name, $this->signatureImage());
        $this->assertSame(PurchaseRequestStatus::ForOmApproval, $request->fresh()->status);
        $om = User::factory()->create(['role_id' => Role::where('slug', RoleSlug::Om->value)->value('id'), 'campaign_id' => $owner->campaign_id]);
        $this->actingAs($om)->post(route('om-approvals.approve', $request), $this->workflowSignature($om))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForItScreening, $request->fresh()->status);
        $this->actingAs($screener)->post(route('request-flow.screening.store', $request), ['decision' => 'UNAVAILABLE', 'evidence' => 'Fresh evidence', ...$this->workflowSignature($screener)])->assertSessionHasNoErrors();
        $this->assertSame('Fresh evidence', $request->fresh()->screening->evidence);
        $this->assertNotSame($oldScreening->id, $request->fresh()->screening->id);
        $this->assertDatabaseCount('request_screenings', 2);
    }

    public function test_return_to_requestor_is_not_allowed_for_other_roles_or_after_po_creation(): void
    {
        [$owner, $creator, $request] = $this->fixture();
        foreach ([$owner, $this->user(RoleSlug::President), $this->user(RoleSlug::ItStaff)] as $actor) {
            $this->actingAs($actor)->post(route('purchase-orders.return-to-requestor', $request), ['correction_reason' => 'Not authorized.'])->assertForbidden();
        }
        $this->actingAs($creator)->post(route('purchase-orders.store', $request), $this->poPayload($creator, $request))->assertSessionHasNoErrors();
        $this->post(route('purchase-orders.return-to-requestor', $request), ['correction_reason' => 'A PO already exists.'])->assertForbidden();
        $this->assertSame(PurchaseRequestStatus::ForBudgetProcessing, $request->fresh()->status);
    }

    public function test_return_monitoring_and_owner_edit_resubmit_follow_the_live_workflow(): void
    {
        [$owner, $creator, $request, $items] = $this->fixture();
        $request->update(['request_type' => 'IT', 'requested_team' => 'IT']);
        $this->actingAs($creator)->post(route('purchase-orders.return-to-requestor', $request), ['correction_reason' => 'Add the required laptop specification.'])->assertRedirect();
        foreach (['canvassing', 'purchase-order', 'budget-request'] as $workflow) {
            $this->get(route('dashboard', ['workflow' => $workflow]))->assertOk()->assertSee('Return Monitoring')->assertSee(route('returned-monitoring.index'), false);
        }
        $this->get(route('returned-monitoring.index'))->assertOk()->assertSee($request->request_number)->assertSee('Add the required laptop specification.')
            ->assertViewHas('returnedMonitoring', fn ($rows) => $rows->total() === 1);
        $this->get(route('workflow-tracker.index', ['stage' => 'returned']))->assertOk()->assertSee($request->request_number)
            ->assertViewHas('stageCounts', fn ($counts) => $counts['returned'] === 1);
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Needs correction')->assertSee('Edit and resubmit')->assertSee('Add the required laptop specification.');
        $this->get(route('purchase-requests.index'))->assertOk()->assertSee('Edit and resubmit')->assertSee('Add the required laptop specification.');
        $this->get(route('purchase-requests.edit', $request))->assertOk()->assertSee('Returned for correction:')->assertSee('Add the required laptop specification.');
        foreach ($items as $item) {
            $item->attachments()->create(['uploaded_by' => $owner->id, 'category' => PurchaseRequestItem::ATTACHMENT_EMAIL_APPROVAL,
                'original_name' => 'approval.pdf', 'disk' => 'local', 'path' => 'fixtures/approval.pdf', 'mime_type' => 'application/pdf', 'size' => 20]);
        }
        $payload = ['action' => 'submit', 'title' => 'Corrected request', 'requested_team' => 'IT', 'department_campaign' => $owner->campaign->name,
            'purpose' => 'Corrected specifications', 'delivery_location' => 'Main Office', ...$this->workflowSignature($owner),
            'items' => array_map(fn ($item) => ['id' => $item->id, 'description' => $item->description.' revised', 'quantity' => 1, 'unit' => 'pc', 'brand_specifications' => '16 GB RAM'], $items)];
        $this->put(route('purchase-requests.update', $request), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForOmApproval, $request->fresh()->status);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('id="needs-correction-heading"', false)->assertSee('Corrected request');
        $this->assertFalse($owner->can('update', $request->fresh()));
        $this->actingAs($creator)->get(route('returned-monitoring.index', ['returned_status' => 'RESUBMITTED']))->assertOk()
            ->assertSee('Corrected request')->assertViewHas('returnedMonitoring', fn ($rows) => $rows->total() === 1);
        $this->get(route('returned-monitoring.index', ['returned_status' => 'RETURNED']))->assertOk()->assertViewHas('returnedMonitoring', fn ($rows) => $rows->total() === 0);
        $this->get(route('workflow-tracker.index', ['stage' => 'resubmitted']))->assertOk()->assertSee('Corrected request')
            ->assertViewHas('stageCounts', fn ($counts) => $counts['returned'] === 0 && $counts['resubmitted'] === 1);
        $this->get(route('returned-monitoring.index', ['returned_search' => 'laptop specification', 'returned_from' => now()->toDateString(), 'returned_to' => now()->toDateString()]))->assertOk()
            ->assertViewHas('returnedMonitoring', fn ($rows) => $rows->total() === 1);
        $this->get(route('returned-monitoring.index', ['returned_search' => 'unmatched reason']))->assertOk()->assertViewHas('returnedMonitoring', fn ($rows) => $rows->total() === 0);
    }

    public function test_monitor_permissions_owner_privacy_and_tracker_handoff_to_created_po(): void
    {
        [$owner, $creator, $request] = $this->fixture();
        $this->actingAs($creator)->post(route('purchase-orders.return-to-requestor', $request), ['correction_reason' => 'Private correction reason.'])->assertRedirect();
        $other = $this->user(RoleSlug::Requestor);
        $this->actingAs($other)->get(route('purchase-requests.index'))->assertOk()->assertDontSee('Private correction reason.')->assertViewHas('purchaseRequests', fn ($rows) => $rows->total() === 0);
        $this->get(route('purchase-requests.edit', $request))->assertForbidden();
        foreach ([$other, $owner, $this->user(RoleSlug::President)] as $actor) {
            $this->actingAs($actor)->get(route('returned-monitoring.index'))->assertForbidden();
        }
        // After a fresh approval, the new PO replaces the pre-PO correction entry in the tracker.
        $request->refresh();
        $request->update(['status' => PurchaseRequestStatus::ForBudgetProcessing, 'selected_supplier_id' => $request->quotations()->sole()->supplier_id]);
        $request->quotations()->update(['is_approved' => true, 'approved_by' => $creator->id, 'approved_at' => now()]);
        $this->actingAs($creator)->post(route('purchase-orders.store', $request), $this->poPayload($creator, $request->fresh()))->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('workflow-tracker.index'))->assertOk()->assertSee($request->purchaseOrder()->sole()->po_number)->assertSee('Awaiting PO checking')
            ->assertViewHas('returnedRequests', fn ($rows) => $rows->total() === 0);
        $this->actingAs($owner)->post(route('purchase-requests.withdraw', $request), ['withdrawal_reason' => 'No longer needed after approval.'])->assertRedirect();
        $this->actingAs($creator)->get(route('workflow-tracker.index', ['stage' => 'withdrawal']))->assertOk()->assertSee('Withdrawal pending acknowledgment')
            ->assertViewHas('stageCounts', fn ($counts) => $counts['withdrawal'] === 1 && $counts['checking'] === 0);
    }

    public function test_multiple_campaign_accounts_contribute_to_one_po_and_survive_finalization(): void
    {
        [, $creator, $request] = $this->fixture();
        $this->actingAs($creator)->post(route('purchase-orders.store', $request), $this->poPayload($creator, $request))->assertRedirect();
        $request->purchaseOrder()->update(['status' => 'APPROVED']);
        $first = Campaign::where('name', 'AIA 1')->firstOrFail();
        $second = Campaign::where('name', 'AIA 2')->firstOrFail();
        $this->get(route('budget.create', ['purchase_request_id' => $request->id]))->assertOk()->assertSee('Add source account')
            ->assertSee('name="source_accounts[0][campaign_id]"', false)->assertSee('name="source_accounts[0][amount]"', false)
            ->assertDontSee('Budget allocation per item')->assertDontSee('name="item_allocations[', false);
        $this->post(route('budget.store'), ['purchase_request_id' => $request->id,
            'amount' => 1, 'source_accounts' => [['campaign_id' => $first->id, 'amount' => '60.00'], ['campaign_id' => $second->id, 'amount' => '40.03']]])->assertRedirect()->assertSessionHasNoErrors();
        $budget = BudgetRequest::query()->sole();
        $line = $budget->lines()->sole();
        $this->assertSame(['60.00', '40.03'], array_column($line->funding_allocations, 'amount'));
        $this->assertSame([$first->account_number, $second->account_number], array_column($line->funding_allocations, 'account_number'));
        $this->assertEquals(100.03, $budget->amount);
        $this->assertSame($first->id, $budget->campaign_id);
        $this->get(route('budget.finalize', ['ids' => [$budget->id]]))->assertOk()->assertSee('Campaign source contributions')->assertSee($second->account_number)->assertSee('40.03');
        $this->post(route('budget.finalize.store'), ['ids' => [$budget->id], 'priority_level' => 'NORMAL', ...$this->workflowSignature($creator)])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('budget-requests.document', $budget))->assertOk()->assertSee('60.00')->assertSee('40.03')->assertSee($second->account_number);
        $this->assertStringContainsString($second->account_number, $line->fresh()->preparationData()['source_account']);
        $paymentSheet = view('budget-requests.partials.transaction-sheet', ['budget' => $budget->fresh(), 'purchaseRequest' => $request])->render();
        $this->assertStringContainsString('Multiple source accounts', $paymentSheet);
        $this->assertStringContainsString($second->account_number, $paymentSheet);
        $this->assertTrue(BudgetRequest::query()->registerSearch($second->account_number)->whereKey($budget->id)->exists());
        // Saved source-account details stay unchanged when the directory is edited.
        $second->update(['account_number' => 'NEW-ACCOUNT']);
        $this->assertSame($line->funding_allocations, $line->fresh()->funding_allocations);
    }

    public function test_contributions_reject_wrong_totals_duplicates_inactive_and_unknown_accounts(): void
    {
        [, $creator, $request] = $this->fixture();
        $this->actingAs($creator)->post(route('purchase-orders.store', $request), $this->poPayload($creator, $request))->assertRedirect();
        $request->purchaseOrder()->update(['status' => 'APPROVED']);
        $first = Campaign::where('name', 'AIA 1')->firstOrFail();
        $second = Campaign::where('name', 'AIA 2')->firstOrFail();
        foreach ([['60', '40'], ['60', '41']] as [$a, $b]) {
            $this->post(route('budget.store'), ['purchase_request_id' => $request->id, 'source_accounts' => [
                ['campaign_id' => $first->id, 'amount' => $a], ['campaign_id' => $second->id, 'amount' => $b]]])->assertSessionHasErrors('source_accounts');
        }
        $this->post(route('budget.store'), ['purchase_request_id' => $request->id, 'source_accounts' => [
            ['campaign_id' => $first->id, 'amount' => 60], ['campaign_id' => $first->id, 'amount' => 40.03]]])->assertSessionHasErrors('source_accounts.0.campaign_id');
        foreach ([['campaign_id' => 999999, 'amount' => 100.03], ['campaign_id' => $first->id, 'amount' => -1], ['campaign_id' => $first->id, 'amount' => '100.031']] as $invalid) {
            $this->post(route('budget.store'), ['purchase_request_id' => $request->id, 'source_accounts' => [$invalid]])->assertSessionHasErrors();
        }
        $second->update(['is_active' => false]);
        $this->post(route('budget.store'), ['purchase_request_id' => $request->id, 'source_accounts' => [['campaign_id' => $second->id, 'amount' => 100.03]]])->assertSessionHasErrors('source_accounts.0.campaign_id');
        $this->assertDatabaseCount('budget_requests', 0);
        $this->assertSame(PurchaseRequestStatus::ForBudgetProcessing, $request->fresh()->status);
    }

    public function test_combined_final_budget_preserves_each_pos_account_contributions(): void
    {
        [, $creator, $request] = $this->fixture();
        $secondRequest = $this->copyRequest($request);
        $first = Campaign::where('name', 'AIA 1')->firstOrFail();
        $second = Campaign::where('name', 'AIA 2')->firstOrFail();
        foreach ([$request, $secondRequest] as $entry) {
            $this->actingAs($creator)->post(route('purchase-orders.store', $entry), $this->poPayload($creator, $entry))->assertRedirect();
            $entry->purchaseOrder()->update(['status' => 'APPROVED']);
            $this->post(route('budget.store'), ['purchase_request_id' => $entry->id, 'source_accounts' => [
                ['campaign_id' => $first->id, 'amount' => 60], ['campaign_id' => $second->id, 'amount' => 40.03]]])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->post(route('budget.finalize.store'), ['ids' => BudgetRequest::query()->pluck('id')->all(), 'priority_level' => 'NORMAL', ...$this->workflowSignature($creator)])->assertRedirect()->assertSessionHasNoErrors();
        $combined = BudgetRequest::query()->sole();
        $this->assertEquals(200.06, $combined->amount);
        $this->assertCount(2, $combined->lines);
        foreach ($combined->lines as $line) {
            $this->assertSame(['60.00', '40.03'], array_column($line->funding_allocations, 'amount'));
        }
        $this->get(route('budget-requests.document', $combined))->assertOk()->assertSee($first->account_number)->assertSee($second->account_number);
    }

    private function copyRequest(PurchaseRequest $original): PurchaseRequest
    {
        $second = $original->replicate();
        $second->request_number = 'BR-REV-002';
        $second->save();
        foreach ($original->items as $item) {
            $copy = $item->replicate();
            $copy->purchase_request_id = $second->id;
            $copy->save();
        }
        $quote = $original->quotations()->sole()->replicate();
        $quote->purchase_request_id = $second->id;
        $quote->save();

        return $second;
    }

    private function fixture(): array
    {
        $creator = $this->user(RoleSlug::PurchasingTeam);
        $owner = $this->user(RoleSlug::Requestor);
        $campaign = Campaign::where('name', 'AC MOBILITY')->firstOrFail();
        $owner->update(['campaign_id' => $campaign->id]);
        foreach ([PurchaseOrderApprovalStage::PreparedBy->value => $creator,
            PurchaseOrderApprovalStage::CheckedBy->value => $this->user(RoleSlug::PoChecker),
            PurchaseOrderApprovalStage::NotedBy->value => $this->user(RoleSlug::PoNoted),
            PurchaseOrderApprovalStage::ApprovedBy->value => $this->user(RoleSlug::PoApprover)] as $stage => $actor) {
            PoRoleAssignment::create(['stage' => $stage, 'user_id' => $actor->id]);
        }
        $supplier = Supplier::create(['name' => 'Budget revision supplier', 'is_active' => true]);
        $request = PurchaseRequest::create(['request_number' => 'BR-REV-001', 'title' => 'Item allocation revision',
            'requestor_id' => $owner->id, 'department_campaign' => $campaign->name, 'request_type' => 'OTHER',
            'purpose' => 'Budget revisions test', 'delivery_location' => 'Main Office', 'status' => PurchaseRequestStatus::ForBudgetProcessing,
            'selected_supplier_id' => $supplier->id, 'total_amount' => 100.03]);
        foreach ([60, 40] as $index => $amount) {
            $items[] = $request->items()->create(['description' => 'Office item '.($index + 1), 'quantity' => 1, 'unit' => 'pc', 'estimated_unit_price' => $amount, 'estimated_total' => $amount]);
        }
        $request->quotations()->create(['supplier_id' => $supplier->id, 'created_by' => $creator->id, 'price' => 100,
            'shipping_fee' => .03, 'is_approved' => true, 'approved_at' => now(), 'item_or_service' => 'Office items']);

        return [$owner, $creator, $request, $items];
    }

    private function poPayload(User $actor, PurchaseRequest $request): array
    {
        return ['expected_delivery_date' => now()->addWeek()->toDateString(), 'payable_on' => now()->toDateString(),
            'account_number' => 'BANK-001', 'account_name' => $request->selectedSupplier->name, 'payment_terms' => 'On delivery',
            'payment_details' => 'Bank transfer', ...$this->workflowSignature($actor)];
    }

    private function budgetPayload(PurchaseRequest $request): array
    {
        return ['purchase_request_id' => $request->id, 'campaign_id' => Campaign::where('name', 'AIA 1')->value('id')];
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role->value)->value('id')]);
    }
}
