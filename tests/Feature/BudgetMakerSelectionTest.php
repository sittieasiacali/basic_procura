<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\BudgetRequest;
use App\Models\Campaign;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\CampaignSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BudgetMakerSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, CampaignSeeder::class]);
        Storage::fake('local');
    }

    public function test_budget_maker_can_open_one_approved_po_at_a_time(): void
    {
        [$maker, $requests, $team, $source] = $this->fixture(8);
        $unapproved = $requests->last();
        $unapproved->purchaseOrder->update(['status' => 'FOR_APPROVAL']);

        $this->actingAs($maker)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-select-all-budgets', false)
            ->assertDontSee('data-budget-request-select', false)
            ->assertSee('Prepare Budget Request')
            ->assertSee($requests->first()->purchaseOrder->po_number)
            ->assertViewHas('myActions', fn ($actions) => $actions->count() === 7
                && ! $actions->contains(fn ($action) => $action['request']->is($unapproved)));

        $this->get(route('budget.create', ['purchase_request_id' => $requests->first()->id]))
            ->assertOk()
            ->assertSee('Campaign source account')
            ->assertSee('Request team based account')
            ->assertSee($team->account_number)
            ->assertSee($source->account_number)
            ->assertDontSee('Additional notes')
            ->assertDontSee('Additional Information')
            ->assertDontSee('Budget Maker signature (required)')
            ->assertDontSee('name="signature_data"', false)
            ->assertSee('name="purchase_request_id"', false)
            ->assertDontSee('name="purchase_request_ids[]"', false)
            ->assertViewHas('purchaseRequest', fn ($selected) => $selected->is($requests->first()));
        $this->get(route('budget.create', ['purchase_request_ids' => $requests->take(2)->pluck('id')->all()]))
            ->assertSessionHasErrors(['purchase_request_id', 'purchase_request_ids']);

        // The creation permission must not grant unrelated budget/PO edits.
        $this->get(route('budget.po-details.edit', $requests->first()))->assertForbidden();
    }

    public function test_procura_budget_queue_combines_search_priority_and_date_filters(): void
    {
        [$maker, $requests] = $this->fixture(2);
        $requests[0]->update(['priority_level' => 'HIGH']);
        $requests[1]->update(['priority_level' => 'LOW']);

        $this->actingAs($maker)->get(route('budget.index', [
            'search' => 'PO-SELECT-1',
            'priority' => 'HIGH',
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]))->assertOk()
            ->assertSee('Search PO, request, requestor')
            ->assertSee('PO-SELECT-1')
            ->assertDontSee('PO-SELECT-2');

        $workflowFilters = [
            'search' => 'PO-SELECT-1',
            'priority' => 'HIGH',
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ];
        $this->get(route('dashboard', $workflowFilters))
            ->assertOk()->assertSee('PO-SELECT-1')->assertDontSee('PO-SELECT-2');
        $this->get(route('request-flow.index', $workflowFilters))
            ->assertRedirect(route('dashboard', $workflowFilters));
        $this->get(route('request-flow.index', [...$workflowFilters, 'stage' => 'purchase-order']))
            ->assertRedirect(route('dashboard', $workflowFilters));
        $this->get(route('purchase-requests.index', $workflowFilters))
            ->assertOk()->assertSee('BR-SELECT-1')->assertDontSee('BR-SELECT-2');
    }

    public function test_one_po_creates_one_signed_budget_with_the_selected_source_and_preserves_team_account(): void
    {
        [$maker, $requests, $team, $source] = $this->fixture(2);
        $payload = $this->payload($maker, $requests->first()->id, $source);
        // A client cannot override the team accounts, total, or maker identity.
        $payload['amount'] = 1;
        $payload['source_bank_account'] = 'untrusted account';
        $payload['team_account'] = 'untrusted team';

        $this->actingAs($maker)->post(route('budget.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('purchase-requests.show', $requests->first()));

        $budget = BudgetRequest::query()->sole();
        $this->assertSame($source->id, $budget->campaign_id);
        $this->assertSame($source->account_number, $budget->source_bank_account);
        $this->assertSame($source->account_number, $budget->campaign_account_number);
        $this->assertSame($maker->id, $budget->maker_user_id);
        $this->assertNotNull($budget->maker_approved_at);
        $this->assertEquals(100, (float) $budget->amount);
        $this->assertCount(1, $budget->allocations);
        $this->assertCount(1, $budget->lines);
        // The BR code is only issued at the Final budget.
        $this->assertNull($budget->budget_request_number);
        $preview = $this->getJson(route('budget-requests.document', ['budgetRequest' => $budget, 'preview' => 1]))
            ->assertOk()
            ->assertJsonPath('number', 'Pending BR code');
        // The BR document shows the Budget Maker details; the online / cash sheet belongs to the payment.
        $preview->assertJsonPath('transaction_type', 'Budget Request');
        $this->assertStringContainsString('Budget Maker preparation details', $preview->json('transaction_html'));
        $this->assertStringNotContainsString('Online Transactions', $preview->json('transaction_html'));
        $this->assertStringContainsString($source->account_number, $preview->json('transaction_html'));
        $this->get(route('budget-requests.document', $budget))
            ->assertOk()->assertDontSee('Online Transactions')->assertSee($source->account_number);
        $purchaseRequest = $requests->first();
        $this->assertSame(PurchaseRequestStatus::AwaitingPresidentFinalApproval, $purchaseRequest->fresh()->status);
        $this->assertSame(PurchaseRequestStatus::ForBudgetProcessing, $requests->last()->fresh()->status);
        $order = $purchaseRequest->purchaseOrder->fresh();
        $this->assertSame($team->id, $order->budget_campaign_id);
        $this->assertSame($team->account_number, $order->budget_campaign_account_number);
        $signature = $purchaseRequest->approvals()->where('approval_level', 'BUDGET_REQUEST_PREPARATION')->sole();
        $this->assertSame($maker->name, $signature->signature_name);
        $this->assertSame($payload['signature_data'], $signature->signature_data);

        $this->get(route('purchase-requests.show', $requests->first()))
            ->assertOk()->assertSee('Budget Maker signature')->assertSee($maker->name);
        $this->get(route('dashboard'))->assertViewHas('myActions', fn ($actions) => $actions->count() === 1);
        $this->post(route('budget.store'), $payload)->assertSessionHasErrors('purchase_request_id');
        $this->assertDatabaseCount('budget_requests', 1);
    }

    public function test_single_po_can_create_a_signed_budget_request(): void
    {
        [$maker, $requests, $team, $source] = $this->fixture(1);
        $this->actingAs($maker)->post(route('budget.store'), $this->payload($maker, $requests->first()->id, $source))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertCount(1, BudgetRequest::query()->sole()->allocations);
    }

    public function test_budget_preparation_can_submit_without_the_temporarily_removed_fields(): void
    {
        [$maker, $requests, , $source] = $this->fixture(1);
        $purchaseRequest = $requests->first();

        $this->actingAs($maker)->post(route('budget.store'), [
            'purchase_request_id' => $purchaseRequest->id,
            'campaign_id' => $source->id,
            'budget_custodian_id' => $maker->id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('purchase-requests.show', $purchaseRequest));

        $budget = BudgetRequest::query()->sole();
        $this->assertSame($maker->id, $budget->maker_user_id);
        $this->assertNull($budget->budget_custodian_id);
        $this->assertNull($budget->remarks);
        $this->assertSame(PurchaseRequestStatus::AwaitingPresidentFinalApproval, $purchaseRequest->fresh()->status);
        $approval = $purchaseRequest->approvals()->where('approval_level', 'BUDGET_REQUEST_PREPARATION')->sole();
        $this->assertSame($maker->id, $approval->user_id);
        $this->assertNull($approval->signature_data);
        $this->get(route('budget-requests.document', $budget))->assertOk()->assertSee($maker->name);
    }

    public function test_budget_signature_can_be_restored_without_payment_only_fields(): void
    {
        config()->set('procurement.budget_preparation.additional_information', true);
        config()->set('procurement.budget_preparation.signature_required', true);
        [$maker, $requests, , $source] = $this->fixture(1);

        $this->actingAs($maker)->get(route('budget.create', ['purchase_request_id' => $requests->first()->id]))
            ->assertOk()->assertDontSee('Budget Custodian')->assertSee('Budget Maker signature (required)');
        $this->post(route('budget.store'), [
            'purchase_request_id' => $requests->first()->id,
            'campaign_id' => $source->id,
        ])->assertSessionHasErrors(['signature_name', 'signature_data']);
        $this->assertDatabaseCount('budget_requests', 0);
    }

    public function test_budget_information_is_left_for_the_payment_form(): void
    {
        [$maker, $requests, , $source] = $this->fixture(1);
        $purchaseRequest = $requests->first();

        $this->actingAs($maker)->get(route('budget.create', ['purchase_request_id' => $purchaseRequest->id]))
            ->assertOk()
            ->assertSee('Campaign Source Account')
            ->assertDontSee('Budget Information')
            ->assertSee('Cancel</a>', false)
            ->assertSee('<span data-submit-label>Submit Budget Request</span>', false);

        $this->post(route('budget.store'), [
            'purchase_request_id' => $purchaseRequest->id, 'campaign_id' => $source->id, ...$this->workflowSignature($maker),
        ])->assertSessionHasNoErrors();
        $budget = BudgetRequest::query()->sole();
        $this->assertNull($budget->payment_method);

        $purchasing = $this->user(RoleSlug::Purchasing);
        $this->actingAs($purchasing)->get(route('purchase-requests.show', $purchaseRequest))
            ->assertOk()
            ->assertSee('Prepared by Budget Maker')
            ->assertSee($source->account_number)
            ->assertSee('Budget test supplier')
            ->assertDontSee('name="account_type"', false)
            ->assertDontSee('Reject and return to Canvassing');
        $this->get(route('budget.finalize', ['ids' => [$budget->id]]))
            ->assertOk()->assertDontSee('Budget Information')->assertSee('name="signature_data"', false);
        $this->post(route('budget.finalize.store'), ['ids' => [$budget->id]])->assertSessionHasErrors('signature_data');
        $this->post(route('budget.finalize.store'), ['ids' => [$budget->id], 'priority_level' => 'NORMAL', ...$this->workflowSignature($purchasing)])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForPayment, $purchaseRequest->fresh()->status);

        $finance = $this->user(RoleSlug::Finance);
        $this->actingAs($finance)->get(route('purchase-requests.show', $purchaseRequest))
            ->assertOk()->assertSee('Budget Information')->assertSee('name="account_type"', false)->assertSee('Check Transaction')
            ->assertSee('name="budget_custodian_id"', false);
        $this->post(route('payments.store', $purchaseRequest), ['payment_method' => 'CHECK', 'amount' => 100])
            ->assertSessionHasErrors(['account_type', 'fund_source', 'main_fund']);
        $this->post(route('payments.store', $purchaseRequest), [
            'payment_method' => 'CHECK', 'amount' => 100, 'account_type' => 'ACA',
            'fund_source' => 'MAIN_FUND', 'main_fund' => 'Operations', 'receipts' => [UploadedFile::fake()->image('receipt.jpg')],
            'budget_custodian_id' => $maker->id,
        ])->assertSessionHasNoErrors();
        $budget->refresh();
        $this->assertSame('CHECK', $budget->payment_method);
        $this->assertSame('ACA', $budget->account_type);
        $this->assertSame('Operations', $budget->main_fund);
        $this->assertSame($maker->id, $budget->budget_custodian_id);
        $this->assertSame('CHECK', $purchaseRequest->purchaseOrder->fresh()->budget_transaction_type);
    }

    public function test_finance_records_one_payment_for_several_ticked_pos(): void
    {
        [$maker, $requests, , $source] = $this->fixture(3);
        $this->prepareAll($maker, $requests, $source);
        $purchasing = $this->user(RoleSlug::Purchasing);
        $this->actingAs($purchasing)->post(route('budget.finalize.store'), [
            'ids' => BudgetRequest::query()->pluck('id')->all(), 'priority_level' => 'HIGH', ...$this->workflowSignature($purchasing),
        ])->assertSessionHasNoErrors();
        $brNumber = BudgetRequest::query()->sole()->budget_request_number;

        $finance = $this->user(RoleSlug::Finance);
        $this->actingAs($finance)->get(route('dashboard'))->assertOk()
            ->assertSee('<th>BR Number</th>', false)->assertDontSee('<th>Request</th>', false)
            ->assertSee($brNumber)->assertSee('data-br-select-all', false)
            ->assertSee('data-base-label="Record payment"', false);

        $paid = [$requests[0]->id, $requests[1]->id];
        $this->get(route('payments.batch', ['ids' => $paid]))->assertOk()
            ->assertSee($requests[0]->purchaseOrder->po_number)->assertSee($requests[1]->purchaseOrder->po_number)
            ->assertDontSee($requests[2]->purchaseOrder->po_number)->assertSee('Record 2 payments');
        $this->post(route('payments.batch.store'), ['ids' => $paid, 'payment_method' => 'ONLINE'])
            ->assertSessionHasErrors(['account_type', 'fund_source', 'main_fund']);
        $this->post(route('payments.batch.store'), [
            'ids' => $paid, '_embedded' => 1, 'payment_method' => 'ONLINE', 'account_type' => 'ACA',
            'fund_source' => 'MAIN_FUND', 'main_fund' => 'Operations', 'receipts' => [UploadedFile::fake()->image('receipt.jpg')], 'reference_number' => 'REF-BATCH-1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('workflow.done'));

        foreach ([0, 1] as $index) {
            $this->assertSame(PurchaseRequestStatus::ForReceiving, $requests[$index]->fresh()->status);
            $payment = $requests[$index]->payments()->sole();
            $this->assertSame('REF-BATCH-1', $payment->reference_number);
            $this->assertEquals((float) $requests[$index]->purchaseOrder->total_amount, (float) $payment->amount);
        }
        $this->assertSame(PurchaseRequestStatus::ForPayment, $requests[2]->fresh()->status);
        $this->assertSame('PARTIALLY_PAID', BudgetRequest::query()->sole()->status);

        // A PO ticked "Already received" is paid and logged to inventory in the same step.
        $this->get(route('payments.batch', ['ids' => [$requests[2]->id]]))->assertOk()
            ->assertSee('name="received_ids[]"', false)->assertSee('data-receiving-fields', false);
        $this->post(route('payments.batch.store'), [
            'ids' => [$requests[2]->id], 'received_ids' => [$requests[2]->id], 'payment_method' => 'ONLINE', 'account_type' => 'ACA',
            'fund_source' => 'MAIN_FUND', 'main_fund' => 'Operations', 'receipts' => [UploadedFile::fake()->image('receipt.jpg')],
        ])->assertSessionHasErrors('signature_data');
        $lineKey = \App\Services\GoodsReceiving::lines($requests[2]->fresh(['purchaseOrder.items', 'items']))->keys()->first();
        $this->get(route('payments.batch', ['ids' => [$requests[2]->id]]))
            ->assertSee('name="receiving['.$requests[2]->id.'][quantities]['.$lineKey.']"', false)
            ->assertSee('name="receiving['.$requests[2]->id.'][locations]['.$lineKey.']"', false);
        $this->post(route('payments.batch.store'), [
            'ids' => [$requests[2]->id], 'received_ids' => [$requests[2]->id], 'payment_method' => 'ONLINE', 'account_type' => 'ACA',
            'fund_source' => 'MAIN_FUND', 'main_fund' => 'Operations', 'receipts' => [UploadedFile::fake()->image('receipt.jpg')], ...$this->workflowSignature($finance),
            'receiving' => [$requests[2]->id => ['quantities' => [$lineKey => 0]]],
        ])->assertSessionHasErrors('receiving.'.$requests[2]->id.'.quantities.'.$lineKey);
        $this->post(route('payments.batch.store'), [
            'ids' => [$requests[2]->id], 'received_ids' => [$requests[2]->id], 'payment_method' => 'ONLINE', 'account_type' => 'ACA',
            'fund_source' => 'MAIN_FUND', 'main_fund' => 'Operations', 'receipts' => [UploadedFile::fake()->image('receipt.jpg')], ...$this->workflowSignature($finance),
            'receiving' => [$requests[2]->id => [
                'quantities' => [$lineKey => 2], 'units' => [$lineKey => 'box'],
                'serial_numbers' => [$lineKey => 'SN-BATCH-1'], 'locations' => [$lineKey => 'Warehouse B'],
            ]],
        ])->assertSessionHasNoErrors()->assertSessionHas('success', fn ($message) => str_contains($message, '1 received and added to inventory'));
        $this->assertSame(PurchaseRequestStatus::Completed, $requests[2]->fresh()->status);
        $item = \App\Models\InventoryItem::query()->where('purchase_request_id', $requests[2]->id)->sole();
        $this->assertEquals(2, (float) $item->quantity);
        $this->assertSame(['box', 'SN-BATCH-1', 'Warehouse B'], [$item->unit, $item->serial_number, $item->location]);
        $this->assertSame('PAID', BudgetRequest::query()->sole()->status);

        // Already paid requests cannot be paid twice.
        $this->post(route('payments.batch.store'), [
            'ids' => [$requests[0]->id, $requests[1]->id], 'payment_method' => 'ONLINE', 'account_type' => 'ACA',
            'fund_source' => 'MAIN_FUND', 'main_fund' => 'Operations', 'receipts' => [UploadedFile::fake()->image('receipt.jpg')],
        ])->assertSessionHasErrors('ids');
        $this->actingAs($this->user(RoleSlug::Requestor))->get(route('payments.batch', ['ids' => [$requests[2]->id]]))->assertForbidden();
    }

    public function test_processed_payment_preview_shows_the_transaction_sheet_for_its_type(): void
    {
        [$maker, $requests, , $source] = $this->fixture(1);
        $this->actingAs($maker)->post(route('budget.store'), $this->payload($maker, $requests->first()->id, $source))
            ->assertSessionHasNoErrors();
        $budget = BudgetRequest::query()->sole();
        $payment = $requests->first()->payments()->create([
            'budget_request_id' => $budget->id, 'purchase_order_id' => $requests->first()->purchaseOrder->id, 'recorded_by' => $maker->id, 'payment_type' => 'PROCUREMENT',
            'payment_method' => 'ONLINE', 'amount' => 100, 'paid_at' => now(), 'status' => 'PAID', 'reference_number' => 'REF-SHEET',
        ]);
        $this->assertSame(route('payments.sheet', $payment), $payment->viewDetails()['sheet_url']);

        $finance = $this->user(RoleSlug::Finance);
        foreach (['ONLINE' => 'Online Transactions', 'CASH' => 'Cashout Deposit Transactions', 'CHECK' => 'Check Transactions'] as $type => $heading) {
            $budget->update(['payment_method' => $type]);
            $this->actingAs($finance)->get(route('payments.sheet', $payment))->assertOk()
                ->assertSee($heading)->assertSee('Type of Account:')->assertSee($source->account_number)->assertSee('Ref: REF-SHEET');
            // The BR document itself no longer carries the transaction sheet.
            $this->getJson(route('budget-requests.document', ['budgetRequest' => $budget, 'preview' => 1]))
                ->assertOk()->assertJsonPath('transaction_type', 'Budget Request');
        }
        // "Print transaction sheet" opens it as its own printable page.
        $this->actingAs($finance)->get(route('payments.sheet', ['payment' => $payment, 'print' => 1]))->assertOk()
            ->assertSee('<!doctype html>', false)->assertSee('Print / Save as PDF')->assertSee($payment->payment_code);
        $this->actingAs($this->user(RoleSlug::Requestor))->get(route('payments.sheet', $payment))->assertForbidden();
    }

    public function test_missing_signature_wrong_signer_invalid_source_and_unapproved_po_cannot_create_a_budget(): void
    {
        [$maker, $requests, $team, $source] = $this->fixture(1);
        $payload = $this->payload($maker, $requests->first()->id, $source);
        $missingSignature = $payload;
        unset($missingSignature['signature_data']);
        $this->actingAs($maker)->post(route('budget.store'), $missingSignature)->assertSessionHasErrors('signature_data');
        $this->post(route('budget.store'), [...$payload, 'signature_name' => 'Another user'])
            ->assertSessionHasErrors('signature_name');
        $source->update(['is_active' => false]);
        $this->post(route('budget.store'), $payload)->assertSessionHasErrors('campaign_id');
        $source->update(['is_active' => true]);
        $requests->first()->purchaseOrder->update(['status' => 'FOR_APPROVAL']);
        $this->post(route('budget.store'), $payload)->assertSessionHasErrors('purchase_request_id');
        $this->assertDatabaseCount('budget_requests', 0);
        $this->assertDatabaseCount('budget_request_purchase_orders', 0);

        $this->actingAs($this->user(RoleSlug::Requestor))->post(route('budget.store'), $payload)->assertForbidden();
    }

    public function test_register_export_treats_formula_like_supplier_names_as_text(): void
    {
        [$maker, $requests, , $source] = $this->fixture(1);
        $this->prepareAll($maker, $requests, $source);
        $requests[0]->purchaseOrder->supplier->update(['name' => '=1+1']);
        $csv = $this->actingAs($this->user(RoleSlug::PurchasingTeam))
            ->get(route('budget.export', ['stage' => 'finance', 'maker_only' => 1]))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
    }

    public function test_register_is_split_by_stage_and_exports_the_list(): void
    {
        [$maker, $requests, , $source] = $this->fixture(2);
        $this->prepareAll($maker, $requests, $source);
        [$waiting, $finalized] = BudgetRequest::query()->orderBy('id')->get()->all();
        $finalized->update(['final_approval_status' => 'APPROVED', 'status' => 'APPROVED', 'budget_request_number' => 'BR-2026-0099']);

        $team = $this->user(RoleSlug::PurchasingTeam);
        $this->actingAs($team)->get(route('dashboard', ['workflow' => 'budget-request', 'budget_view' => 'processed']))
            ->assertOk()
            ->assertSee('Waiting for Final BR')
            ->assertSee('Budget test supplier')
            ->assertSee('Export list (1)')
            ->assertViewHas('processedTab', 'finance')
            ->assertDontSee('po-history-tab--processed', false)
            ->assertDontSee('Budget Custodian')
            // Purchasing Team finalizes from this tab; only rows waiting for Finance get a tick box.
            ->assertSee('data-br-select-all', false)
            ->assertSee('value="'.$waiting->id.'" data-amount', false)
            ->assertDontSee('value="'.$finalized->id.'" data-amount', false)
            ->assertViewHas('processedTabCounts', fn ($counts) => $counts === ['processed' => 2, 'finance' => 1, 'finalized' => 1]);
        // One search above the stage tabs covers all of them: each count shows its matches.
        $this->get(route('dashboard', ['workflow' => 'budget-request', 'budget_view' => 'processed', 'br_search' => 'BR-2026-0099']))
            ->assertOk()
            ->assertSee('Search all Final Budget Requests')
            ->assertViewHas('processedTabCounts', fn ($counts) => $counts === ['processed' => 1, 'finance' => 0, 'finalized' => 1])
            ->assertViewHas('creatorWorkflowCounts', fn ($counts) => $counts['budget-request-processed'] === 2);
        $this->get(route('dashboard', ['workflow' => 'budget-request', 'budget_view' => 'processed', 'br_search' => 'Budget test supplier']))
            ->assertViewHas('processedTabCounts', fn ($counts) => $counts['processed'] === 2);
        $this->get(route('dashboard', ['workflow' => 'budget-request', 'budget_view' => 'processed', 'processed_tab' => 'finance']))
            ->assertOk()
            ->assertSee('PO-SELECT-1')
            ->assertDontSee($finalized->budget_request_number)
            ->assertSee('name="stage" value="finance"', false)
            // Remarks live in the dashboard's Live remarks dialog, not in the rows.
            ->assertDontSee('workflow-remarks-cell', false);

        $csv = $this->get(route('budget.export', ['stage' => 'finance', 'maker_only' => 1]))->assertOk()->streamedContent();
        $this->assertStringContainsString('PO-SELECT-1', $csv);
        $this->assertStringNotContainsString($finalized->budget_request_number, $csv);
        $this->assertStringContainsString('Budget test supplier', $csv);
        $this->assertStringContainsString($source->account_number, $csv);

        $this->actingAs($this->user(RoleSlug::Requestor))->get(route('budget.export'))->assertForbidden();
    }

    public function test_purchasing_team_tabs_filter_each_section_without_the_page_wide_filter(): void
    {
        [, $requests] = $this->fixture(2);
        [$first, $second] = [$requests[0]->purchaseOrder->po_number, $requests[1]->purchaseOrder->po_number];

        $this->actingAs($this->user(RoleSlug::PurchasingTeam));
        $this->get(route('dashboard', ['workflow' => 'budget-request']))->assertOk()
            ->assertDontSee('Filter requests')
            ->assertSee('name="action_search"', false)
            ->assertSee($first)->assertSee($second);
        $this->get(route('dashboard', ['workflow' => 'budget-request', 'action_search' => $first]))->assertOk()
            ->assertSee($first)->assertDontSee($second);

        // Processed sections have their own filters and leave the action queue alone.
        $this->get(route('dashboard', ['workflow' => 'purchase-order', 'created_po_search' => $first]))->assertOk()
            ->assertSee('name="created_po_search"', false)
            ->assertSee('name="created_po_status"', false)
            ->assertSee($first)->assertDontSee($second);
        $this->get(route('dashboard', ['workflow' => 'canvassing']))->assertOk()
            ->assertSee('name="canvassing_search"', false);
    }

    public function test_live_remarks_dialog_lists_the_newest_remarks_for_purchasing_team(): void
    {
        [$maker, $requests, , $source] = $this->fixture(2);
        $this->prepareAll($maker, $requests, $source);
        $requests[0]->update(['remarks' => 'Live remark for the first PO']);

        $team = $this->user(RoleSlug::PurchasingTeam);
        $this->actingAs($team)->get(route('workflow-tracker.index'))
            ->assertOk()
            ->assertSee('Live remarks')
            ->assertSee('data-live-remarks-url="'.route('workflow-remarks.feed').'"', false)
            ->assertDontSee('Refresh status')
            // PO and request codes open their previews in place.
            ->assertSee('data-po-preview-url="'.route('purchase-orders.preview', $requests[0]->purchaseOrder).'"', false)
            ->assertSee('data-workflow-request-preview', false);
        $this->getJson(route('purchase-orders.preview', $requests[0]->purchaseOrder))->assertOk();
        $this->get(route('purchase-requests.show', ['purchaseRequest' => $requests[0], 'preview' => 1]))->assertOk();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('data-live-remarks-button', false);
        // The queue under the step tabs is tinted and labelled with the active step.
        $this->get(route('dashboard', ['workflow' => 'canvassing']))->assertOk()
            ->assertSee('dashboard-step-theme--canvassing', false)
            ->assertSee('Step 1 · Canvassing');
        $this->get(route('workflow-remarks.feed'))
            ->assertOk()
            ->assertSee('Live remark for the first PO')
            ->assertSee($requests[0]->purchaseOrder->po_number);
        $this->get(route('workflow-remarks.feed', ['search' => $requests[1]->purchaseOrder->po_number]))
            ->assertOk()
            ->assertDontSee('Live remark for the first PO');
        // The button's badge counts remarks newer than the viewer's last visit, and the list marks them.
        $this->getJson(route('workflow-remarks.feed', ['count' => 1, 'since' => now()->subHour()->toIso8601String()]))
            ->assertOk()
            ->assertJson(fn ($json) => $json->where('new', fn ($count) => $count >= 1));
        $this->getJson(route('workflow-remarks.feed', ['count' => 1, 'since' => now()->addMinute()->toIso8601String()]))
            ->assertExactJson(['new' => 0]);
        $this->get(route('workflow-remarks.feed', ['since' => now()->subHour()->toIso8601String()]))
            ->assertSee('Today')
            ->assertSee('remarks-feed-new', false);

        $this->actingAs($this->user(RoleSlug::Requestor))->get(route('workflow-remarks.feed'))->assertForbidden();
    }

    public function test_final_budget_issues_br_codes_for_the_ticked_requests_and_moves_them_to_payment(): void
    {
        [$maker, $requests, , $source] = $this->fixture(3);
        $this->prepareAll($maker, $requests, $source);
        $budgets = BudgetRequest::query()->orderBy('id')->get();
        $this->assertTrue($budgets->every(fn ($budget) => $budget->budget_request_number === null));
        $team = $this->user(RoleSlug::PurchasingTeam);

        $this->actingAs($team)->get(route('dashboard', ['workflow' => 'budget-request', 'budget_view' => 'processed']))
            ->assertOk()
            ->assertSee('data-br-select', false)
            ->assertSee('Final budget')
            ->assertDontSee('>Final Approval</a>', false);

        $selected = $budgets->take(2)->pluck('id')->all();
        $this->get(route('budget.finalize', ['ids' => $selected]))
            ->assertOk()
            ->assertSee('PO-SELECT-1')
            ->assertSee('PO-SELECT-2')
            ->assertDontSee('PO-SELECT-3')
            ->assertSee('Budget test supplier')
            ->assertSee('&#8369; 300.00', false)
            ->assertDontSee('Budget Information')
            ->assertSee('name="signature_data"', false);

        $this->post(route('budget.finalize.store'), ['ids' => $selected, '_embedded' => 1, 'priority_level' => 'NORMAL', ...$this->workflowSignature($team)])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workflow.done'));

        // Same source account: the two POs share one BR code and one document.
        $year = now()->format('Y');
        $combined = $budgets[0]->fresh();
        $this->assertSame("BR-{$year}-0001", $combined->budget_request_number);
        $this->assertSame('APPROVED', $combined->final_approval_status);
        $this->assertEquals(300, (float) $combined->amount);
        $this->assertCount(2, $combined->lines);
        $this->assertSoftDeleted($budgets[1]);
        foreach ($requests->take(2) as $purchaseRequest) {
            $this->assertTrue($purchaseRequest->budgetRequest->is($combined));
            $this->assertSame(PurchaseRequestStatus::ForPayment, $purchaseRequest->fresh()->status);
        }
        $this->get(route('budget-requests.document', $combined))->assertOk()->assertSee('PO-SELECT-1')->assertSee('PO-SELECT-2');
        $this->assertNull($budgets[2]->fresh()->budget_request_number);

        // Already finalized rows cannot be finalized again.
        $this->post(route('budget.finalize.store'), ['ids' => [$budgets[0]->id, $budgets[2]->id], 'priority_level' => 'NORMAL', ...$this->workflowSignature($team)])
            ->assertSessionHasErrors('ids');
        $this->post(route('budget.finalize.store'), ['ids' => [$budgets[2]->id], 'priority_level' => 'NORMAL', ...$this->workflowSignature($team)])->assertSessionHasNoErrors();
        $this->assertSame("BR-{$year}-0002", $budgets[2]->fresh()->budget_request_number);

        $this->actingAs($this->user(RoleSlug::Requestor))->get(route('budget.finalize', ['ids' => [$budgets[2]->id]]))->assertForbidden();
    }

    public function test_submitting_inside_the_action_modal_closes_it_instead_of_opening_the_request_page(): void
    {
        [$maker, $requests, , $source] = $this->fixture(1);

        $this->actingAs($maker)->post(route('budget.store'), [
            'purchase_request_id' => $requests->first()->id, 'campaign_id' => $source->id, '_embedded' => 1, ...$this->workflowSignature($maker),
        ])->assertSessionHasNoErrors()->assertRedirect(route('workflow.done'));

        $this->get(route('workflow.done'))->assertOk()
            ->assertSee('Budget Request created. It is now waiting for Finance.')
            ->assertSee('asi-procura:flash');
    }

    public function test_final_budget_issues_one_group_br_even_across_source_accounts(): void
    {
        [$maker, $requests, , $source] = $this->fixture(3);
        $otherSource = Campaign::query()->where('is_active', true)
            ->whereIn('name', array_keys(Campaign::BUDGET_ACCOUNT_OPTIONS))
            ->whereKeyNot($source->id)->firstOrFail();
        $this->prepareAll($maker, $requests->take(2), $source);
        $this->prepareAll($maker, $requests->slice(2), $otherSource);
        $budgets = BudgetRequest::query()->orderBy('id')->get();
        $team = $this->user(RoleSlug::PurchasingTeam);

        $this->actingAs($team)->post(route('budget.finalize.store'), ['ids' => $budgets->pluck('id')->all(), 'priority_level' => 'NORMAL', ...$this->workflowSignature($team)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'issued for 3 POs'));

        $group = BudgetRequest::query()->sole();
        $this->assertCount(3, $group->lines);
        $this->assertEquals(600, (float) $group->amount);
        $this->assertSame([$source->account_number, $otherSource->account_number],
            $group->lines->pluck('source_bank_account')->unique()->values()->all());
        $this->assertTrue($requests->every(fn ($purchaseRequest) => $purchaseRequest->fresh()->status === PurchaseRequestStatus::ForPayment
            && $purchaseRequest->budgetRequest->is($group)));

        $this->get(route('budget-requests.document', $group))->assertOk()
            ->assertSee('PO-SELECT-1')->assertSee('PO-SELECT-2')->assertSee('PO-SELECT-3')
            ->assertSee($source->account_number)
            ->assertSee($otherSource->account_number);

        // The list shows the first PO and account; the rest open from "+N more".
        $this->actingAs($team)->get(route('dashboard', ['workflow' => 'budget-request', 'budget_view' => 'processed', 'processed_tab' => 'finalized']))->assertOk()
            ->assertSee('3 POs')
            ->assertSee('+2 more')
            ->assertSee($source->name)
            ->assertSee($otherSource->name)
            ->assertSee('PO-SELECT-3')->assertSee('Budget selection 3');
    }

    public function test_command_combines_budget_requests_already_finalized_together(): void
    {
        [$maker, $requests, , $source] = $this->fixture(2);
        $this->prepareAll($maker, $requests, $source);
        $finance = $this->user(RoleSlug::Purchasing);
        $finalizedAt = now()->startOfSecond();
        BudgetRequest::query()->orderBy('id')->get()->each(fn ($budget, $index) => $budget->update([
            'budget_request_number' => 'BR-2026-000'.($index + 1), 'final_approval_status' => 'APPROVED', 'status' => 'APPROVED',
            'final_approval_by' => $finance->id, 'final_approval_at' => $finalizedAt,
        ]));

        $this->artisan('budget:combine-finalized', ['--dry-run' => true])->expectsOutputToContain('BR-2026-0001  <=  BR-2026-0002')->assertSuccessful();
        $this->assertSame(2, BudgetRequest::query()->count());

        $this->artisan('budget:combine-finalized')->assertSuccessful();
        $combined = BudgetRequest::query()->sole();
        $this->assertSame('BR-2026-0001', $combined->budget_request_number);
        $this->assertCount(2, $combined->lines);
        $this->assertEquals(300, (float) $combined->amount);
        $this->artisan('budget:combine-finalized')->expectsOutput('Nothing to combine.')->assertSuccessful();
    }

    public function test_manual_preparation_details_survive_group_finalization_document_and_finalized_export(): void
    {
        [$maker, $requests, , $source] = $this->fixture(2);
        foreach ($requests as $index => $purchaseRequest) {
            $purchaseRequest->requestor()->update(['department' => 'Requesting Team '.($index + 1)]);
            $this->actingAs($maker)->post(route('budget.store'), [
                'purchase_request_id' => $purchaseRequest->id,
                'campaign_id' => $source->id,
                'request_campaign' => 'Manual Campaign '.($index + 1),
                'details' => 'Manual PO details '.($index + 1),
                'requested_payment_method' => $index === 0 ? 'ONLINE' : 'CHECK',
                'bank_details' => 'Deposit account '.($index + 1),
                'requesting_team' => 'Forged team',
                'payable_on' => $index === 0 ? '2026-11-05' : null,
                'remarks' => 'Maker remarks '.($index + 1),
                'amount' => 1,
            ])->assertSessionHasNoErrors();
        }
        $budgets = BudgetRequest::query()->orderBy('id')->get();
        $this->assertNull($budgets[0]->payment_method, 'Requested mode must not set Finance payment details.');
        $this->assertEquals(100, (float) $budgets[0]->amount);
        $this->assertSame('2026-11-05', $budgets[0]->lines->sole()->payable_on->format('Y-m-d'));
        $this->assertSame('Requesting Team 1', $budgets[0]->lines->sole()->requesting_team);
        $this->assertSame('Requesting Team 2', $budgets[1]->lines->sole()->requesting_team);

        $finalizer = $this->user(RoleSlug::PurchasingTeam);
        $ids = $budgets->modelKeys();
        $this->actingAs($finalizer)->get(route('budget.finalize', ['ids' => $ids]))
            ->assertOk()->assertSee('Manual PO details 1')->assertSee('Manual PO details 2')
            ->assertSee('Requesting Team 2')->assertSee('Deposit account 1')->assertSee('Maker remarks 2')
            // Priority Level is chosen on the Final budget form, not shown as a column there.
            ->assertSee('name="priority_level"', false)->assertDontSee('<th scope="col">Priority Level</th>', false);
        $this->post(route('budget.finalize.store'), ['ids' => $ids, ...$this->workflowSignature($finalizer)])
            ->assertSessionHasErrors('priority_level');
        $this->post(route('budget.finalize.store'), ['ids' => $ids, 'priority_level' => 'HIGH', ...$this->workflowSignature($finalizer)])
            ->assertSessionHasNoErrors();
        $this->assertSame(['HIGH', 'HIGH'], [$requests[0]->fresh()->priority_level, $requests[1]->fresh()->priority_level]);
        $this->assertSame('HIGH', BudgetRequest::query()->sole()->priorityLevel());
        // Finalized list: one row per BR number with its single Priority Level; differing PO values sit behind "+ more".
        $finalized = $this->get(route('dashboard', ['workflow' => 'budget-request', 'budget_view' => 'processed', 'processed_tab' => 'finalized']))
            ->assertOk()->assertSee('<th scope="col">BR Number</th>', false)
            ->assertSee('budget-priority-pill--high', false)->assertSee('View 2 POs')->assertSee('+1 more');
        $this->assertSame(1, substr_count($finalized->getContent(), 'budget-preparation-code'));

        $combined = BudgetRequest::query()->sole();
        $this->assertCount(2, $combined->lines);
        $this->assertEquals(300, (float) $combined->amount);
        // The second PO keeps its own blank date and remarks, rather than inheriting the first PO's values.
        $this->assertSame('N/A', $combined->lines->last()->preparationData()['date_to_pay']);
        $this->assertSame('Maker remarks 2', $combined->lines->last()->remarks);
        $document = $this->get(route('budget-requests.document', $combined))
            ->assertOk()->assertSee('Manual Campaign 1')->assertSee('Manual Campaign 2')
            ->assertSee('Manual PO details 2')->assertSee('Deposit account 2')
            ->assertSee('Requesting Team 1')->assertSee('Nov 05, 2026')->assertSee('Maker remarks 1')->assertSee('High');
        $this->assertSame(1, substr_count($document->getContent(), 'aria-label="Budget Maker preparation details"'));
        $this->get(route('dashboard', ['workflow' => 'budget-request', 'budget_view' => 'processed', 'processed_tab' => 'finalized']))
            ->assertOk()->assertSee('Priority Level')->assertSee('Campaign2')->assertSee('Mode of Payment')
            ->assertSee('Requesting Team')->assertSee('Date to Pay')->assertSee('Manual PO details 1')->assertSee('Maker remarks 2');
        $csv = $this->get(route('budget.export', ['stage' => 'finalized', 'maker_only' => 1]))->assertOk()->streamedContent();
        $csvStream = fopen('php://temp', 'r+');
        fwrite($csvStream, substr($csv, 3));
        rewind($csvStream);
        $rows = [];
        while (($row = fgetcsv($csvStream)) !== false) {
            $rows[] = $row;
        }
        fclose($csvStream);
        $this->assertSame(['Priority Level', 'Campaign', 'Details', 'Amount', 'Campaign2', 'Mode of Payment', 'Bank Details (for Deposit)', 'Requesting Team', 'Date to Pay', 'Remarks', 'BR Number'], $rows[0]);
        $this->assertCount(3, $rows);
        $this->assertSame('Manual Campaign 2', $rows[2][1]);
        $this->assertSame('200.00', $rows[2][3]);
        $this->assertSame('N/A', $rows[2][8]);
        $this->assertSame($combined->budget_request_number, $rows[2][10]);
    }

    public function test_preparation_defaults_and_manual_field_validation(): void
    {
        [$maker, $requests, , $source] = $this->fixture(1);
        $this->actingAs($maker)->get(route('budget.create', ['purchase_request_id' => $requests[0]->id]))
            // Priority Level is chosen at the Final budget, not when preparing.
            ->assertOk()->assertDontSee('name="priority_level"', false)
            ->assertSee('name="details"', false)->assertSee('name="bank_details"', false)
            ->assertSee('id="budget_requesting_team"', false)->assertDontSee('name="requesting_team"', false)
            ->assertSee('name="payable_on"', false);
        $this->post(route('budget.store'), [
            'purchase_request_id' => $requests[0]->id, 'campaign_id' => $source->id,
            'priority_level' => 'INVALID', 'details' => '', 'request_campaign' => '',
            'requested_payment_method' => 'INVALID', 'bank_details' => '',
            'requesting_team' => '', 'payable_on' => 'not-a-date',
        ])->assertSessionHasErrors(['priority_level', 'details', 'request_campaign', 'requested_payment_method', 'bank_details', 'payable_on']);
        $this->assertDatabaseCount('budget_requests', 0);
    }

    private function prepareAll(User $maker, $requests, Campaign $source): void
    {
        foreach ($requests as $purchaseRequest) {
            $this->actingAs($maker)->post(route('budget.store'), [
                'purchase_request_id' => $purchaseRequest->id, 'campaign_id' => $source->id, ...$this->workflowSignature($maker),
            ])->assertSessionHasNoErrors();
        }
    }

    private function fixture(int $count): array
    {
        $maker = $this->user(RoleSlug::BudgetMaker);
        $requestor = $this->user(RoleSlug::Requestor);
        $team = Campaign::query()->where('name', 'AC MOBILITY')->firstOrFail();
        $source = Campaign::query()->where('name', 'AIA 1')->firstOrFail();
        $requestor->update(['campaign_id' => $team->id]);
        $supplier = Supplier::query()->create(['name' => 'Budget test supplier', 'is_active' => true]);
        $requests = collect();
        for ($i = 1; $i <= $count; $i++) {
            $purchaseRequest = PurchaseRequest::query()->create([
                'request_number' => 'BR-SELECT-'.$i, 'title' => 'Budget selection '.$i,
                'requestor_id' => $requestor->id, 'department_campaign' => $team->name,
                'request_type' => 'OTHER', 'purpose' => 'Budget selection verification',
                'required_date' => now()->addWeek(), 'delivery_location' => 'Main office',
                'status' => PurchaseRequestStatus::ForBudgetProcessing, 'total_amount' => $i * 100,
            ]);
            $purchaseRequest->purchaseOrder()->create([
                'po_number' => 'PO-SELECT-'.$i, 'supplier_id' => $supplier->id, 'created_by' => $maker->id,
                'issued_at' => now()->toDateString(), 'status' => 'APPROVED', 'approved_at' => now()->addSeconds($i),
                'total_amount' => $i * 100, 'budget_campaign_id' => $team->id,
                'budget_campaign_account_number' => $team->account_number,
            ]);
            $requests->push($purchaseRequest);
        }

        return [$maker, $requests, $team, $source];
    }

    private function payload(User $maker, int $id, Campaign $source): array
    {
        return [
            'purchase_request_id' => $id, 'campaign_id' => $source->id, 'account_type' => 'ACA',
            'transaction_type' => 'ONLINE', 'fund_source' => 'CAMPAIGN_FUND', 'main_fund' => 'Operations',
            'remarks' => 'Prepare this approved PO.', ...$this->workflowSignature($maker),
        ];
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $role->value)->value('id')]);
    }
}
