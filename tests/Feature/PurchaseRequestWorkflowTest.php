<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\BudgetRequest;
use App\Models\Campaign;
use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Models\PoRoleAssignment;
use App\Models\RequestApproval;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\BudgetRequestDemoSeeder;
use Database\Seeders\CampaignSeeder;
use Database\Seeders\ProcurementWorkflowDemoSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SampleUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurchaseRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, CampaignSeeder::class]);
        Storage::fake('local');
    }

    public function test_purchase_order_workflow_page_lists_processed_orders_and_loads_authorized_previews(): void
    {
        $owner = $this->user(RoleSlug::TeamLeader);
        $otherOwner = $this->user(RoleSlug::TeamLeader);
        $purchasing = $this->user(RoleSlug::Purchasing);
        PoRoleAssignment::query()->create(['stage' => PurchaseOrderApprovalStage::PreparedBy, 'user_id' => $purchasing->id]);
        $supplier = Supplier::query()->create(['name' => 'Saved PO supplier', 'is_active' => true]);
        $orders = [];
        foreach (['APPROVED', 'ISSUED', 'FOR_CHECKING', 'FOR_ACKNOWLEDGEMENT', 'FOR_APPROVAL', 'REJECTED', 'CANCELLED'] as $status) {
            $request = $this->createRequest($owner, true);
            $request->forceFill([
                'status' => PurchaseRequestStatus::ForPayment,
                'selected_supplier_id' => $supplier->id,
            ])->save();
            $orders[$status] = $request->purchaseOrder()->create([
                'po_number' => 'TEST-WORKFLOW-'.$status,
                'supplier_id' => $supplier->id,
                'created_by' => $purchasing->id,
                'status' => $status,
                'issued_at' => now(),
                'approved_at' => $status === 'APPROVED' ? now() : null,
                'budget_transaction_type' => 'ONLINE',
                'budget_campaign_account_number' => 'Saved campaign account',
                'total_amount' => 9876.54,
            ]);
            $orders[$status]->items()->create([
                'description' => '<script>alert("unsafe")</script> Saved PO item',
                'quantity' => 1, 'unit' => 'unit', 'unit_price' => 9876.54, 'total_price' => 9876.54,
            ]);
        }
        $foreignRequest = $this->createRequest($otherOwner, true);
        $foreignOrder = $foreignRequest->purchaseOrder()->create([
            'po_number' => 'TEST-WORKFLOW-OTHER-OWNER',
            'supplier_id' => $supplier->id,
            'created_by' => $purchasing->id,
            'status' => 'APPROVED', 'issued_at' => now(),
            'budget_transaction_type' => 'CASH', 'budget_campaign_account_number' => 'Other account',
            'total_amount' => 100,
        ]);
        $ready = $this->createRequest($owner, true);
        $ready->forceFill([
            'status' => PurchaseRequestStatus::ForBudgetProcessing,
            'selected_supplier_id' => $supplier->id,
        ])->save();

        $ready->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $purchasing->id,
            'item_or_service' => $ready->title,
            'price' => $ready->total_amount,
            'is_approved' => true,
            'approved_at' => now(),
        ]);
        $response = $this->actingAs($purchasing)->get(route('request-flow.index', ['stage' => 'purchase-order']));
        $response->assertOk()->assertViewIs('request-flow.index')
            ->assertSeeInOrder(['Create Purchase Order', 'Create PO', 'Processed Purchase Orders'])
            ->assertSee($ready->request_number)
            ->assertSee('TEST-WORKFLOW-APPROVED')->assertSee('TEST-WORKFLOW-ISSUED')
            ->assertSee('Saved campaign account')->assertSee('9,876.54')
            ->assertSee(route('purchase-orders.preview', $orders['APPROVED']), false)
            ->assertSee('data-po-preview-document', false)->assertSee('Print selected template');
        foreach (['FOR_CHECKING', 'FOR_ACKNOWLEDGEMENT', 'FOR_APPROVAL', 'REJECTED', 'CANCELLED'] as $status) {
            $response->assertDontSee('TEST-WORKFLOW-'.$status);
        }

        foreach (['ONLINE', 'CASH', 'CHECK'] as $transaction) {
            $orders['APPROVED']->update(['budget_transaction_type' => $transaction]);
            $this->getJson(route('purchase-orders.preview', $orders['APPROVED']))
                ->assertOk()->assertJsonPath('po_number', 'TEST-WORKFLOW-APPROVED')
                ->assertJsonPath('is_processed', true)
                ->assertJsonPath('template', 'official-purchase-order')
                ->assertJsonPath('html', fn ($html) => str_contains($html, 'PURCHASE ORDER')
                    && str_contains($html, '9,876.54') && !str_contains($html, '<script>alert'));
        }
        $orders['APPROVED']->update(['budget_transaction_type' => 'UNKNOWN']);
        $this->getJson(route('purchase-orders.preview', $orders['APPROVED']))
            ->assertOk()->assertJsonPath('template', 'official-purchase-order')->assertJsonPath('html', fn ($html) => str_contains($html, 'PURCHASE ORDER'))
            ->assertJsonPath('template_error', null);
        $this->getJson(route('purchase-orders.preview', 999999999))->assertNotFound();
        $this->actingAs($owner)->getJson(route('purchase-orders.preview', $foreignOrder))->assertForbidden();
        $this->actingAs($owner)->get(route('request-flow.index', ['stage' => 'purchase-order']))
            ->assertOk()->assertSee('TEST-WORKFLOW-APPROVED')->assertDontSee('TEST-WORKFLOW-OTHER-OWNER');
        PoRoleAssignment::query()->where('stage', PurchaseOrderApprovalStage::PreparedBy->value)->delete();
        $this->actingAs($purchasing)->get(route('request-flow.index', ['stage' => 'canvassing']))
            ->assertOk()->assertDontSee('Processed Purchase Orders');
    }

    public function test_purchase_order_workflow_page_has_an_empty_processed_section(): void
    {
        $this->actingAs($this->user(RoleSlug::Purchasing))
            ->get(route('request-flow.index', ['stage' => 'purchase-order']))
            ->assertOk()->assertSee('Create Purchase Order')
            ->assertSee('Processed Purchase Orders')
            ->assertSee('No processed purchase orders yet.')
            ->assertSee('id="purchase-order-review-modal"', false);
    }
    public function test_po_account_classification_and_campaign_bank_account_are_independent(): void
    {
        $owner = $this->user(RoleSlug::TeamLeader);
        $purchasing = $this->user(RoleSlug::Purchasing);
        $request = $this->createRequest($owner, true);
        $campaign = Campaign::query()->create(['name' => 'Independent campaign', 'account_number' => 'CAMPAIGN-NEW-002', 'is_active' => true]);
        $supplier = Supplier::query()->create(['name' => 'Independent supplier', 'is_active' => true]);
        $order = $request->purchaseOrder()->create([
            'po_number' => 'TEST-INDEPENDENT-PO', 'supplier_id' => $supplier->id, 'created_by' => $purchasing->id,
            'status' => 'APPROVED', 'issued_at' => now(), 'total_amount' => 125,
            'budget_campaign_id' => $campaign->id, 'budget_campaign_account_number' => 'CAMPAIGN-SAVED-001',
            'budget_account_type' => 'ACA', 'budget_transaction_type' => 'ONLINE',
            'account_number' => 'UNRELATED-ACCOUNT', 'budget_source_bank_account' => 'OTHER-SOURCE',
        ]);
        $order->items()->create(['description' => 'Saved item', 'quantity' => 1, 'unit' => 'unit', 'unit_price' => 125, 'total_price' => 125]);
        $budget = BudgetRequest::query()->create([
            'budget_request_number' => 'TEST-INDEPENDENT-BR', 'purchase_request_id' => $request->id,
            'purchase_order_id' => $order->id, 'requested_by' => $owner->id, 'account_type' => 'OTHER_PARTY',
            'main_fund' => 'Manually saved BR fund', 'payment_method' => 'ONLINE',
            'amount' => 125, 'status' => 'PENDING_FINAL_APPROVAL', 'requested_at' => now(),
        ]);
        $budget->allocations()->create(['purchase_request_id' => $request->id, 'purchase_order_id' => $order->id, 'amount' => 125]);
        $this->actingAs($purchasing)->getJson(route('purchase-orders.preview', $order))->assertOk()
            ->assertJsonPath('account_classification.value', 'OTHER_PARTY')
            ->assertJsonPath('account_classification.label', 'Other Party')
            ->assertJsonPath('account_classification.mainFund', 'Manually saved BR fund')
            ->assertJsonPath('account_classification.isLegacy', false)
            ->assertJsonPath('campaign_bank_account.accountNumber', 'CAMPAIGN-SAVED-001')
            ->assertJsonPath('campaign_bank_account.configuredAccountNumber', 'CAMPAIGN-NEW-002')
            ->assertJsonPath('account_type_html', fn ($html) => str_contains($html, 'Other Party') && !str_contains($html, 'CAMPAIGN-SAVED-001'))
            ->assertJsonPath('campaign_bank_account_html', fn ($html) => str_contains($html, 'CAMPAIGN-SAVED-001') && !str_contains($html, 'Other Party') && !str_contains($html, 'UNRELATED-ACCOUNT'))
            ->assertJsonPath('html', fn ($html) => str_contains($html, 'PURCHASE ORDER') && str_contains($html, 'Saved item') && str_contains($html, '125.00') && ! str_contains($html, 'ACCOUNT TYPE'));

        $html = $this->getJson(route('purchase-orders.preview', $order))->json('account_type_html');
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//tbody/tr')->length);
        $this->assertSame(6, $xpath->query('//tbody/tr[1]/td')->length);
        $this->assertSame(1, $xpath->query('//tbody/tr/td[contains(@class,"po-sheet-amount")]')->length);
        $this->assertSame(2, (int) $xpath->query('//thead/tr[1]/th[1]')->item(0)->getAttribute('rowspan'));
        $this->assertSame(5, (int) $xpath->query('//thead/tr[1]/th[2]')->item(0)->getAttribute('colspan'));
        $headers = [];
        foreach ($xpath->query('//thead/tr[2]/th') as $cell) {
            $headers[] = trim($cell->textContent);
        }
        $this->assertSame(['DETAILS', 'REQUESTED BY', 'ACCOUNT TYPE', 'AMOUNT', 'Bank Details (for Deposit)'], $headers);
        $this->assertSame('Other Party', trim($xpath->query('//tbody/tr[1]/td[4]')->item(0)->textContent));
        $this->assertSame('TOTAL', trim($xpath->query('//tfoot/tr/th')->item(0)->textContent));
        $this->assertSame(6, $xpath->query('//tfoot/tr/*')->length);
        $labels = [];
        foreach ($xpath->query('//div[contains(@class,"po-sheet-signatory")]/h3') as $label) {
            $labels[] = trim($label->textContent);
        }
        $this->assertSame(['Requested by:', 'Prepared by:', 'Checked by:', 'Noted by:', 'APPROVED BY:'], $labels);
        $campaignHtml = $this->getJson(route('purchase-orders.preview', $order))->json('campaign_bank_account_html');
        $campaignDom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $campaignDom->loadHTML($campaignHtml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $campaignXpath = new \DOMXPath($campaignDom);
        $this->assertSame('campaign-bank', $campaignXpath->query('//section[contains(@class,"po-sheet")]')->item(0)->getAttribute('data-po-sheet-variant'));
        $this->assertSame(0, $campaignXpath->query('//*[contains(@class,"po-sheet-line-heading")]')->length);
        $this->assertSame(1, $campaignXpath->query('//tbody/tr')->length);
        $this->assertSame(5, $campaignXpath->query('//tbody/tr[1]/td')->length);
        $this->assertSame(5, (int) $campaignXpath->query('//thead/tr[1]/th[1]')->item(0)->getAttribute('colspan'));
        $this->assertSame(5, $campaignXpath->query('//tfoot/tr/*')->length);
        $this->assertSame(0, $campaignXpath->query('//*[contains(@class,"po-sheet-signatures")]')->length);
        $this->assertSame(0, $campaignXpath->query('//*[contains(@class,"po-sheet-transaction")]')->length);
        $campaignHeaders = [];
        foreach ($campaignXpath->query('//thead/tr[2]/th') as $cell) {
            $campaignHeaders[] = trim($cell->textContent);
        }
        $this->assertSame(['DETAILS', 'REQUESTED BY', 'ACCOUNT TYPE', 'AMOUNT', 'Bank Details (for Deposit)'], $campaignHeaders);
        $this->assertSame('', trim($campaignXpath->query('//tbody/tr[1]/td[3]')->item(0)->textContent));
        $this->assertStringNotContainsString('po-sheet-logo', $html);
        $document = \App\Support\PurchaseOrderDocumentData::from($request->fresh());
        $blank = [
            'line_number' => 2, 'details' => '', 'specifications' => null, 'requested_by' => '',
            'account_type' => '', 'quantity' => null, 'unit' => '', 'unit_price' => null,
            'amount' => null, 'item_code' => '', 'campaign' => '', 'bank_details' => '',
        ];
        $document['items'] = collect([$document['items']->first(), $blank]);
        $extended = view('purchase-requests.partials.purchase-order-document', ['purchaseOrderDocument' => $document])->render();
        $official = view('purchase-requests.partials.purchase-order-official', ['purchaseOrderDocument' => $document])->render();
        $this->assertSame(1, substr_count($extended, 'class="po-sheet-detail-row"'));
        $this->assertSame(1, substr_count($extended, 'class="po-sheet-total"'));
        $this->assertStringContainsString('&#8369; 125.00', $extended);
        $this->assertSame(1, substr_count($official, '<tbody>'));
        $this->assertStringNotContainsString('<td></td>', $official);
        $budget->forceFill(['account_type' => 'OWN_ACCOUNT'])->save();
        $this->getJson(route('purchase-orders.preview', $order))->assertOk()
            ->assertJsonPath('account_classification.value', 'OWN_ACCOUNT')
            ->assertJsonPath('campaign_bank_account.accountNumber', 'CAMPAIGN-SAVED-001');
        $order->update(['budget_campaign_account_number' => null]);
        $this->getJson(route('purchase-orders.preview', $order))->assertOk()
            ->assertJsonPath('campaign_bank_account.accountNumber', 'CAMPAIGN-NEW-002');
        $campaign->update(['account_number' => null]);
        $this->getJson(route('purchase-orders.preview', $order))->assertOk()
            ->assertJsonPath('campaign_bank_account.accountNumber', null)
            ->assertJsonPath('campaign_bank_account_html', fn ($html) => str_contains($html, 'Campaign Bank Account') && !str_contains($html, 'OTHER-SOURCE') && !str_contains($html, 'UNRELATED-ACCOUNT'))
            ->assertJsonPath('account_classification.value', 'OWN_ACCOUNT')
            ->assertJsonPath('template', 'official-purchase-order')
            ->assertJsonPath('html', fn ($html) => str_contains($html, 'PURCHASE ORDER')); 
        $this->assertSame('APPROVED', $order->fresh()->status);
        $this->assertSame('ACA', $order->fresh()->budget_account_type);
        $budget->delete();
        $this->getJson(route('purchase-orders.preview', $order))->assertOk()
            ->assertJsonPath('account_classification.value', 'ACA')
            ->assertJsonPath('account_classification.isLegacy', true);
        $order->update(['budget_account_type' => null]);
        $this->getJson(route('purchase-orders.preview', $order))->assertOk()
            ->assertJsonPath('account_classification.value', null)
            ->assertJsonPath('account_type_html', fn ($html) => str_contains($html, 'ACCOUNT TYPE') && !str_contains($html, '>ACA<') && !str_contains($html, 'OPERATIONS-001'));
    }
    public function test_requestor_creates_a_draft_without_selecting_a_supplier(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $payload = $this->payload();
        $payload['items'][] = [
            'description' => 'Laptop bag',
            'quantity' => 3,
            'unit' => 'piece',
            'estimated_unit_price' => 10,
            'brand_specifications' => 'Padded',
            'reference_url' => 'https://example.test/bag',
        ];

        $response = $this->actingAs($requestor)->post(route('purchase-requests.store'), $payload);

        $purchaseRequest = PurchaseRequest::query()->firstOrFail();
        $response->assertRedirect(route('purchase-requests.show', $purchaseRequest));
        $this->assertSame(PurchaseRequestStatus::Draft, $purchaseRequest->status);
        $this->assertNull($purchaseRequest->priority_level);
        $this->assertNull($purchaseRequest->team_leader_id);
        $this->assertSame('530.00', $purchaseRequest->total_amount);
        $this->assertSame(2, $purchaseRequest->items()->count());
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $purchaseRequest->id,
            'action' => 'CREATED_DRAFT',
            'new_status' => PurchaseRequestStatus::Draft->value,
        ]);
    }

    public function test_every_submitted_request_goes_to_om_first(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);

        foreach (['IT', 'PREMISES', 'OTHER'] as $type) {
            $request = $this->createRequest($requestor, true, ['request_type' => $type]);

            $this->assertSame(PurchaseRequestStatus::ForOmApproval, $request->status);
            $this->assertSame($this->roleId(RoleSlug::OmSmtsApprover), $request->current_responsible_role_id);
            $this->assertDatabaseHas('audit_logs', [
                'auditable_id' => $request->id,
                'action' => 'SUBMITTED_FOR_OM_APPROVAL',
            ]);
        }
    }

    public function test_team_leader_is_request_owner_and_cannot_approve_their_own_request(): void
    {
        $teamLeader = $this->user(RoleSlug::TeamLeader);
        $request = $this->createRequest($teamLeader, true);

        $this->assertSame($teamLeader->id, $request->requestor_id);
        $this->assertSame($teamLeader->campaign->name, $request->department_campaign);
        $this->assertSame(PurchaseRequestStatus::ForOmApproval, $request->status);

        $this->actingAs($teamLeader)->get(route('purchase-requests.index'))
            ->assertOk()
            ->assertSee($request->request_number)
            ->assertSee('Create Request')
            ->assertSee('My Requests')
            ->assertDontSee('Assigned Requests')
            ->assertDontSee('Request History');
        $this->post(route('om-approvals.approve', $request))->assertForbidden();
        $this->get(route('purchase-requests.edit', $request))->assertForbidden();
    }

    public function test_requestor_can_only_view_and_edit_their_own_editable_requests(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $other = $this->user(RoleSlug::Requestor);
        $own = $this->createRequest($owner, false, ['title' => 'Own Request']);
        $private = $this->createRequest($other, false, ['title' => 'Private Request']);

        $this->actingAs($owner)->get(route('purchase-requests.index'))
            ->assertOk()
            ->assertSee($own->request_number)
            ->assertDontSee($private->request_number);

        $this->get(route('purchase-requests.edit', $private))->assertForbidden();
        $this->put(route('purchase-requests.update', $private), $this->payload())->assertForbidden();
    }

    public function test_request_item_attachment_is_stored_and_authorized_for_download(): void
    {
        Storage::fake('local');
        $requestor = $this->user(RoleSlug::Requestor);
        $payload = $this->payload();
        $payload['items'][0]['attachment'] = UploadedFile::fake()->create('specification.pdf', 100, 'application/pdf');

        $this->actingAs($requestor)->post(route('purchase-requests.store'), $payload)->assertSessionHasNoErrors();

        $purchaseRequest = PurchaseRequest::query()->firstOrFail();
        $attachment = $purchaseRequest->items()->firstOrFail()->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);
        $this->get(route('attachments.download', $attachment))->assertOk();
    }

    public function test_request_control_center_is_role_scoped_and_shows_required_queue_information(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $om = $this->user(RoleSlug::OmSmtsApprover);
        $request = $this->createRequest($requestor, true);

        $this->actingAs($om)->get(route('request-flow.index'))
            ->assertOk()
            ->assertSee('Request Control Center')
            ->assertSee('Awaiting OM')
            ->assertSee('Canvassing')
            ->assertSee('President Supplier Approval')
            ->assertSee('Purchase Order')
            ->assertDontSee('>Budget Maker</a>', false)
            ->assertDontSee('>Budget Checker</a>', false)
            ->assertDontSee('Budget Proceed')
            ->assertDontSee('Purchasing Final Approval')
            ->assertSee('Payment')
            ->assertSee('Receiving')
            ->assertSee('Inventory / Fulfilled')
            ->assertSee($request->request_number)
            ->assertSee('Business laptop')
            ->assertSee('Operations Manager');

        $otherRequestor = $this->user(RoleSlug::Requestor);
        $this->actingAs($otherRequestor)->get(route('request-flow.index'))
            ->assertOk()
            ->assertDontSee($request->request_number);
    }

    public function test_request_control_center_exposes_each_downstream_stage_filter(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), false);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForBudgetMaker,
            'current_responsible_role_id' => $this->roleId(RoleSlug::BudgetChecker),
        ])->save();

        $this->actingAs($admin)->get(route('request-flow.index'))
            ->assertOk()
            ->assertSee('Canvassing')
            ->assertSee('President Supplier Approval')
            ->assertSee('Purchase Order')
            ->assertSee('Budget Request')
            ->assertDontSee('>Budget Maker</a>', false)
            ->assertDontSee('>Budget Checker</a>', false)
            ->assertDontSee('Budget Proceed')
            ->assertSee('Final Approval')
            ->assertSee('Payment')
            ->assertSee('Receiving')
            ->assertSee('Inventory / Fulfilled');

        $this->get(route('request-flow.index', ['stage' => 'final-approval']))
            ->assertOk()
            ->assertSee($request->request_number);

        $this->get(route('request-flow.index', ['stage' => 'budget-maker']))->assertSessionHasErrors();
        $this->get(route('request-flow.index', ['stage' => 'budget-checker']))->assertSessionHasErrors();
    }

    public function test_it_and_premises_accounts_have_a_quotation_section_showing_recorded_quotes(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $quotedRequest = $this->createRequest($requestor, false, ['title' => 'Quoted equipment']);
        $unquotedRequest = $this->createRequest($requestor, false, ['title' => 'No quotation yet']);
        $supplier = Supplier::query()->create(['name' => 'Quotation Queue Supplier', 'is_active' => true]);

        $quotedRequest->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $this->user(RoleSlug::Purchasing)->id,
            'item_or_service' => 'Quoted equipment',
            'price' => 12500,
            'quoted_at' => now()->toDateString(),
        ]);

        foreach ([RoleSlug::ItStaff, RoleSlug::PremisesStaff] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('request-flow.index', ['stage' => 'quotation']))
                ->assertOk()
                ->assertSee('Quotations')
                ->assertSee($quotedRequest->request_number)
                ->assertDontSee($unquotedRequest->request_number);
        }

        $this->actingAs($requestor)
            ->get(route('request-flow.index', ['stage' => 'quotation']))
            ->assertForbidden();
    }

    public function test_request_control_center_shows_only_the_authorized_action(): void
    {
        $purchasing = $this->user(RoleSlug::Purchasing);
        $request = $this->createRequest($this->user(RoleSlug::Requestor), false);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForCanvassing,
            'current_responsible_role_id' => $this->roleId(RoleSlug::ProcurementStaff),
        ])->save();

        $this->actingAs($purchasing)->get(route('request-flow.index'))
            ->assertOk()
            ->assertSee('Canvassing')
            ->assertDontSee('>View</a>', false);

        $president = $this->user(RoleSlug::President);
        $presidentRequest = $this->createRequest($this->user(RoleSlug::Requestor), false);
        $presidentRequest->forceFill([
            'status' => PurchaseRequestStatus::ForSupplierApproval,
            'current_responsible_role_id' => $this->roleId(RoleSlug::President),
        ])->save();

        $this->actingAs($president)->get(route('request-flow.index', ['stage' => 'supplier-approval']))
            ->assertOk()
            ->assertSee('Supplier Approval')
            ->assertDontSee('>View</a>', false);
    }

    public function test_purchasing_owns_budget_final_approval_instead_of_president(): void
    {
        $purchasing = $this->user(RoleSlug::Purchasing);
        $request = $this->createRequest($this->user(RoleSlug::Requestor), false);
        $request->forceFill([
            'status' => PurchaseRequestStatus::AwaitingPresidentFinalApproval,
            'current_responsible_role_id' => $this->roleId(RoleSlug::Purchasing),
        ])->save();

        $this->actingAs($purchasing)
            ->get(route('request-flow.index', ['stage' => 'final-approval']))
            ->assertOk()
            ->assertSee($request->request_number)
            ->assertSee('href="'.route('purchase-requests.show', [
                'purchaseRequest' => $request,
                'from_stage' => 'final-approval',
            ]).'#final-approval-actions"', false);

        $this->get(route('purchase-requests.show', [
            'purchaseRequest' => $request,
            'from_stage' => 'final-approval',
        ]))->assertOk()
            ->assertSee('Purchasing Final Approval')
            ->assertSee('id="final-approval-actions"', false);

        $this->actingAs($this->user(RoleSlug::ProcurementStaff))
            ->get(route('request-flow.index', ['stage' => 'final-approval']))
            ->assertOk()
            ->assertSee($request->request_number)
            ->assertSee('>Final Approval</a>', false);
        $this->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('id="final-approval-actions"', false);

        $this->actingAs($this->user(RoleSlug::President))->post(route('budget.finalize.store'), [
            'ids' => [1],
        ])->assertForbidden();
        $this->get(route('request-flow.index', ['stage' => 'final-approval']))->assertForbidden();
        $this->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertDontSee('id="final-approval-actions"', false);
    }

    public function test_every_workflow_stage_filter_keeps_a_persistent_active_state(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $stages = [
            'approval', 'it', 'premises', 'inventory', 'canvassing', 'supplier-approval',
            'purchase-order', 'budget-request',
            'final-approval', 'payment', 'receiving', 'fulfilled', 'done',
        ];

        foreach ($stages as $stage) {
            $url = route('request-flow.index', ['stage' => $stage]);
            if ($stage === 'budget-request') {
                $this->actingAs($admin)->get($url)->assertRedirect(route('budget.index'));
                $this->get(route('budget.index'))
                    ->assertOk()
                    ->assertSee('Budget Request Preparation')
                    ->assertSee('class="dropdown-item workflow-dropdown-item active" href="'.route('budget.index').'"', false);
                continue;
            }
            $this->actingAs($admin)->get($url)
                ->assertOk()
                ->assertSee('class="btn btn-sm btn-primary" href="'.$url.'"', false);
        }
    }

    public function test_om_review_routes_every_request_type_to_team_classification(): void
    {
        foreach (['IT', 'PREMISES', 'OTHER'] as $type) {
            [$request, $om] = $this->submittedRequest($type);

            $this->actingAs($om)->post(route('om-approvals.approve', $request), [
                'remarks' => 'Approved for screening.',
                'signature_name' => mb_strtolower($om->name),
                'signature_data' => $this->signatureData(),
            ])->assertSessionHasNoErrors();

            $request->refresh();
            $this->assertSame(PurchaseRequestStatus::ForTeamScreening, $request->status);
            $this->assertNull($request->current_responsible_role_id);
            $this->assertSame('UNCLASSIFIED', $request->request_type);
            $this->assertDatabaseHas('request_approvals', [
                'purchase_request_id' => $request->id,
                'approval_level' => 'OM_REVIEW',
                'action' => 'OM_APPROVED_FOR_SCREENING',
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'auditable_id' => $request->id,
                'action' => 'OM_APPROVED_FOR_SCREENING',
                'new_status' => PurchaseRequestStatus::ForTeamScreening->value,
            ]);
        }
    }

    public function test_om_must_draw_a_signature_before_approval(): void
    {
        [$request, $om] = $this->submittedRequest('IT');

        $this->actingAs($om)->post(route('om-approvals.approve', $request), [
            'signature_name' => $om->name,
        ])->assertSessionHasErrors('signature_data');

        $this->assertSame(PurchaseRequestStatus::ForOmApproval, $request->fresh()->status);
    }
    public function test_om_signature_must_match_the_authenticated_account(): void
    {
        [$request, $om] = $this->submittedRequest('IT');

        $this->actingAs($om)->post(route('om-approvals.approve', $request), [
            'signature_name' => 'Someone Else',
                'signature_data' => $this->signatureData(),
        ])->assertSessionHasErrors('signature_name');

        $this->assertSame(PurchaseRequestStatus::ForOmApproval, $request->fresh()->status);
    }
    public function test_om_request_view_hides_the_quotation_summary_without_hiding_it_from_purchasing(): void
    {
        [$request, $om] = $this->submittedRequest('IT');

        $this->actingAs($om)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertDontSee('Quotation Summary')
            ->assertDontSee('Supplier response');

        $this->actingAs($this->user(RoleSlug::Purchasing))->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('Quotation Summary')
            ->assertSee('Supplier response');
    }
    public function test_om_can_edit_a_submitted_request_without_submitting_for_the_team_leader(): void
    {
        [$request, $om, $teamLeader] = $this->submittedRequest('IT');
        $payload = $this->payload([
            'action' => 'save_draft',
            'title' => 'OM Clarified Request',
            'items' => $request->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'estimated_unit_price' => $item->estimated_unit_price,
            ])->all(),
        ]);

        $this->actingAs($om)->get(route('purchase-requests.edit', $request))
            ->assertOk()
            ->assertSee('Save Review Changes')
            ->assertDontSee('Submit Request');
        $this->put(route('purchase-requests.update', $request), $payload)->assertSessionHasNoErrors();
        $this->assertSame('OM Clarified Request', $request->fresh()->title);
        $this->assertSame(PurchaseRequestStatus::ForOmApproval, $request->fresh()->status);

        $this->actingAs($teamLeader)->get(route('purchase-requests.edit', $request))->assertForbidden();
    }

    public function test_om_return_remarks_are_optional(): void
    {
        [$returned, $om] = $this->submittedRequest('IT');

        $this->actingAs($om)->post(route('om-approvals.return', $returned), ['remarks' => '', 'signature_name' => $om->name, 'signature_data' => $this->signatureData()])
            ->assertSessionHasNoErrors();

        $returned->refresh();
        $this->assertSame(PurchaseRequestStatus::Returned, $returned->status);
        $this->assertSame($returned->requestor->role_id, $returned->current_responsible_role_id);

    }

    public function test_it_and_premises_classify_and_claim_requests_from_the_shared_screening_queue(): void
    {
        $itRequest = $this->approvedForTeamClassification();
        $premisesRequest = $this->approvedForTeamClassification();
        $it = $this->user(RoleSlug::ItStaff);
        $premises = $this->user(RoleSlug::PremisesStaff);

        $this->actingAs($it)->get(route('request-flow.index', ['stage' => 'it']))
            ->assertOk()
            ->assertSee($itRequest->request_number)
            ->assertSee('Claim as IT');
        $this->actingAs($premises)->get(route('request-flow.index', ['stage' => 'premises']))
            ->assertOk()
            ->assertSee($premisesRequest->request_number)
            ->assertSee('Claim as Premises');

        $originalItRequestor = $itRequest->requestor_id;
        $this->actingAs($it)->post(route('request-flow.claim', $itRequest))->assertRedirect();
        $itRequest->refresh();
        $this->assertSame('IT', $itRequest->request_type);
        $this->assertSame(PurchaseRequestStatus::ForItScreening, $itRequest->status);
        $this->assertSame($it->id, $itRequest->assigned_user_id);
        $this->assertSame($originalItRequestor, $itRequest->requestor_id);

        $originalPremisesRequestor = $premisesRequest->requestor_id;
        $this->actingAs($premises)->post(route('request-flow.claim', $premisesRequest))->assertRedirect();
        $premisesRequest->refresh();
        $this->assertSame('PREMISES', $premisesRequest->request_type);
        $this->assertSame(PurchaseRequestStatus::ForPremisesScreening, $premisesRequest->status);
        $this->assertSame($premises->id, $premisesRequest->assigned_user_id);
        $this->assertSame($originalPremisesRequestor, $premisesRequest->requestor_id);

        $this->actingAs($premises)->post(route('request-flow.claim', $itRequest))->assertForbidden();
        $this->actingAs($it)->post(route('request-flow.claim', $premisesRequest))->assertForbidden();
    }

    public function test_it_and_premises_can_record_quotations_for_their_claimed_screening_requests(): void
    {
        foreach ([
            [RoleSlug::ItStaff, PurchaseRequestStatus::ForItScreening, 'IT Screening Supplier'],
            [RoleSlug::PremisesStaff, PurchaseRequestStatus::ForPremisesScreening, 'Premises Screening Supplier'],
        ] as [$role, $expectedStatus, $supplierName]) {
            $purchaseRequest = $this->approvedForTeamClassification();
            $screeningUser = $this->user($role);

            $this->actingAs($screeningUser)
                ->post(route('request-flow.claim', $purchaseRequest))
                ->assertSessionHasNoErrors();

            $this->get(route('request-flow.index', ['stage' => 'quotation']))
                ->assertOk()
                ->assertSee($purchaseRequest->request_number)
                ->assertSee('Add Quotation');

            $this->get(route('purchase-requests.show', $purchaseRequest))
                ->assertOk()
                ->assertSee('Screening Quotations')
                ->assertSee('Save Quotation')
                ->assertSee('data-remove-quotation', false)
                ->assertSee('aria-label="Clear requested item">Clear</button>', false)
                ->assertSee('id="quotation-empty-state"', false)
                ->assertSee('data-submit-quotations', false)
                ->assertDontSee('name="quotations[0][recommended]"', false);

            $this->post(route('canvassing.quotations.store', $purchaseRequest), [
                'quotations' => [['supplier_name' => '', 'quoted_amount' => 18500]],
            ])->assertSessionHasErrors('quotations.0.supplier_name')
                ->assertSessionMissing('quotation_saved');

            $this->get(route('purchase-requests.show', $purchaseRequest))
                ->assertOk()
                ->assertSee('id="quotation-entry-body"', false)
                ->assertDontSee('class="card-body d-none" id="quotation-entry-body"', false);

            $this->post(route('canvassing.quotations.store', $purchaseRequest), [
                'quotations' => [[
                    'supplier_name' => $supplierName,
                    'quoted_amount' => 18500,
                    'delivery_lead_time' => '5 business days',
                    'warranty' => 'One year',
                    'bank_details' => 'Sample Bank · Account 123456789 · '.$supplierName,
                    'remarks' => 'Recorded during team screening.',
                ]],
            ])->assertSessionHasNoErrors()
                ->assertSessionHas('quotation_saved', true)
                ->assertSessionHas('success', fn (string $message) => str_starts_with($message, 'Quotation Q-')
                    && str_contains($message, 'saved. The quotation list and counts have been refreshed.'));

            $purchaseRequest->refresh();
            $this->assertSame($expectedStatus, $purchaseRequest->status);
            $this->assertSame($screeningUser->id, $purchaseRequest->assigned_user_id);
            $this->assertDatabaseHas('supplier_quotations', [
                'purchase_request_id' => $purchaseRequest->id,
                'created_by' => $screeningUser->id,
                'price' => 18500,
                'is_recommended' => false,
                'bank_details' => 'Sample Bank · Account 123456789 · '.$supplierName,
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'auditable_id' => $purchaseRequest->id,
                'action' => 'SCREENING_QUOTATIONS_RECORDED',
                'previous_status' => $expectedStatus->value,
                'new_status' => $expectedStatus->value,
            ]);

            $this->get(route('purchase-requests.show', $purchaseRequest))
                ->assertOk()
                ->assertSee('id="supplier-quotations-panel"  open >', false)
                ->assertSee('aria-label="1 saved quotations">1</span>', false)
                ->assertSee('id="quotation-entry-body"', false)
                ->assertSee('card-body d-none', false);
        }
    }

    public function test_screening_staff_cannot_record_quotations_for_another_teams_claimed_request(): void
    {
        $purchaseRequest = $this->approvedForTeamClassification();
        $this->actingAs($this->user(RoleSlug::ItStaff))
            ->post(route('request-flow.claim', $purchaseRequest))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->user(RoleSlug::PremisesStaff))
            ->post(route('canvassing.quotations.store', $purchaseRequest), [
                'quotations' => [[
                    'supplier_name' => 'Unauthorized Supplier',
                    'quoted_amount' => 100,
                ]],
            ])->assertForbidden();

        $this->assertDatabaseMissing('suppliers', ['name' => 'Unauthorized Supplier']);
    }

    public function test_each_request_stage_signs_and_closes_to_a_receipt_naming_the_next_stage(): void
    {
        [$request, $om, $requestor] = $this->submittedRequest('IT');

        // Requestor: signed submission is recorded, then the page closes.
        $this->assertDatabaseHas('request_approvals', [
            'purchase_request_id' => $request->id,
            'approval_level' => 'REQUESTOR_SUBMISSION',
            'user_id' => $requestor->id,
            'signature_name' => $requestor->name,
        ]);
        $this->actingAs($requestor)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('Submitted to Operations Manager.')
            ->assertSee('move to the Operations Manager');

        // OM still has the full page to act on, then closes after approving.
        $this->actingAs($om)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('Operations Manager Review')
            ->assertDontSee('Submitted to Operations Manager.');
        $this->actingAs($om)->post(route('om-approvals.approve', $request), ['remarks' => 'Approved.', ...$this->workflowSignature($om)])
            ->assertRedirect(route('purchase-requests.show', $request));
        $this->actingAs($om)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('Submitted to IT / Premises.')
            ->assertSee('move to IT / Premises');

        // Once the request has moved past the requestor's hand-off, their full page returns.
        $this->actingAs($requestor)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertDontSee('Submitted to Operations Manager.');
    }

    public function test_processed_requests_open_as_a_printable_preview(): void
    {
        [$request, $om, $requestor] = $this->submittedRequest('IT');
        $this->actingAs($om)->post(route('om-approvals.approve', $request), ['remarks' => 'Approved for screening.', ...$this->workflowSignature($om)]);

        // The dashboard's processed list opens the preview instead of the closed request page,
        // and the KPI cards summarise exactly the pending and processed panels.
        $this->actingAs($om)->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('kpis', fn ($kpis) => collect($kpis)->pluck('value', 'label')->all() === [
                'Awaiting my action' => 0,
                'Processed by me' => 1,
                'Processed · in progress' => 1,
                'Processed · approved' => 0,
            ])
            ->assertDontSee('Visible Requests')
            ->assertSee('My processed requests')
            ->assertSee('data-request-preview-url="'.route('purchase-requests.preview', $request).'"', false)
            ->assertSee('request-preview-modal', false);

        $this->getJson(route('purchase-requests.preview', $request))
            ->assertOk()
            ->assertJsonPath('request_number', $request->request_number)
            ->assertJsonPath('print_url', route('purchase-requests.document', ['purchaseRequest' => $request, 'mode' => 'print']))
            ->assertJson(fn ($json) => $json->where('html', fn ($html) => str_contains($html, 'Approval trail')
                && str_contains($html, 'Requestor submission')
                && str_contains($html, 'Operations Manager review')
                && str_contains($html, 'Approved for screening.')
                && str_contains($html, $requestor->name)
                // Signed steps show the drawn signature.
                && substr_count($html, 'src="data:image/png;base64,') === 2
                && ! str_contains($html, 'Signature not recorded'))
                ->etc());

        // A hand-off completed before signatures were captured still appears, clearly labelled.
        $request->auditLogs()->create(['user_id' => $om->id, 'action' => 'OM_RETURNED', 'previous_status' => 'FOR_OM_APPROVAL', 'new_status' => 'RETURNED']);
        $this->getJson(route('purchase-requests.preview', $request))
            ->assertOk()
            ->assertJson(fn ($json) => $json->where('html', fn ($html) => str_contains($html, 'Returned to requestor') && str_contains($html, 'Signature not recorded'))->etc());
        $this->get(route('purchase-requests.document', ['purchaseRequest' => $request, 'mode' => 'print']))
            ->assertOk()
            ->assertSee('Print / Save as PDF')
            ->assertSee('window.print()', false)
            ->assertSee($request->request_number);

        // Same access rules as the request itself.
        $this->actingAs($this->user(RoleSlug::Requestor))
            ->getJson(route('purchase-requests.preview', $request))
            ->assertForbidden();
    }

    public function test_submitting_for_om_approval_requires_a_signature_but_saving_a_draft_does_not(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $this->actingAs($requestor)->post(route('purchase-requests.store'), [...$this->payload(), 'action' => 'save_draft'])
            ->assertSessionHasNoErrors();
        $this->actingAs($requestor)->post(route('purchase-requests.store'), [...$this->payload(), 'action' => 'submit'])
            ->assertSessionHasErrors(['signature_name', 'signature_data']);
        $draft = PurchaseRequest::query()->latest('id')->firstOrFail();
        $this->post(route('purchase-requests.submit', $draft))->assertSessionHasErrors('signature_data');
        $this->assertSame(PurchaseRequestStatus::Draft, $draft->fresh()->status);
    }

    public function test_inventory_unavailable_modal_submission_returns_json(): void
    {
        $request = $this->screeningRequest('IT');
        $staff = $this->user(RoleSlug::ItStaff);
        $this->actingAs($staff)->post(route('request-flow.claim', $request))->assertRedirect();

        // The Inventory Unavailable modal submits in the background and expects JSON back.
        $this->postJson(route('request-flow.screening.store', $request), [
            ...$this->workflowSignature(auth()->user()),
            'decision' => 'UNAVAILABLE',
            'evidence' => 'No suitable inventory is available.',
        ])->assertOk()->assertJsonStructure(['success']);

        $this->assertSame(PurchaseRequestStatus::ForProcurement, $request->fresh()->status);
    }

    public function test_screening_staff_can_undo_an_unavailable_decision_before_quotations_are_saved(): void
    {
        foreach ([[RoleSlug::ItStaff, 'IT', PurchaseRequestStatus::ForItScreening], [RoleSlug::PremisesStaff, 'PREMISES', PurchaseRequestStatus::ForPremisesScreening]] as [$role, $type, $screeningStatus]) {
            $request = $this->screeningRequest($type);
            $staff = $this->user($role);
            $this->actingAs($staff)->post(route('request-flow.claim', $request))->assertRedirect();
            $this->post(route('request-flow.screening.store', $request), [
                ...$this->workflowSignature($staff),
                'decision' => 'UNAVAILABLE',
                'evidence' => 'Marked unavailable by mistake.',
            ])->assertRedirect();

            $this->actingAs($this->user($role))
                ->delete(route('request-flow.screening.undo', $request))
                ->assertForbidden();

            $this->actingAs($staff)->get(route('purchase-requests.show', $request))->assertSee('Undo decision');
            $this->delete(route('request-flow.screening.undo', $request))
                ->assertRedirect(route('purchase-requests.show', $request));

            $request->refresh();
            $this->assertSame($screeningStatus, $request->status);
            $this->assertNull($request->screening);
            $this->assertSame($staff->id, $request->assigned_user_id);
            $this->assertDatabaseHas('request_approvals', [
                'purchase_request_id' => $request->id,
                'action' => 'SCREENING_UNDONE',
            ]);
            $this->get(route('purchase-requests.show', $request))->assertSee('id="screening-actions"', false);
        }
    }

    public function test_unavailable_it_and_premises_requests_move_to_purchasing_canvassing_and_close(): void
    {
        foreach ([RoleSlug::ItStaff, RoleSlug::PremisesStaff] as $role) {
            $request = $this->screeningRequest($role === RoleSlug::ItStaff ? 'IT' : 'PREMISES');
            $staff = $this->user($role);
            $this->actingAs($staff)->post(route('request-flow.claim', $request))->assertRedirect();

            // The screening decision must be signed.
            $this->post(route('request-flow.screening.store', $request), [
                'decision' => 'UNAVAILABLE',
                'evidence' => 'No suitable inventory is available.',
            ])->assertSessionHasErrors(['signature_name', 'signature_data']);
            $this->assertNotSame(PurchaseRequestStatus::ForProcurement, $request->fresh()->status);

            // Inventory Unavailable moves the request to Purchasing's canvassing; the page shows only the message.
            $this->post(route('request-flow.screening.store', $request), [
                ...$this->workflowSignature(auth()->user()),
                'decision' => 'UNAVAILABLE',
                'evidence' => 'No suitable inventory is available.',
            ])->assertRedirect(route('purchase-requests.show', $request));
            $this->assertSame(PurchaseRequestStatus::ForProcurement, $request->fresh()->status);
            $this->assertDatabaseHas('request_approvals', [
                'purchase_request_id' => $request->id,
                'approval_level' => 'IT_PREMISES_SCREENING',
                'user_id' => $staff->id,
                'signature_name' => $staff->name,
            ]);
            $this->get(route('purchase-requests.show', $request))
                ->assertOk()
                ->assertSee('Moved to Canvassing.')
                ->assertSee('moved to canvassing with the Purchasing team')
                ->assertDontSee('Add quotation')
                ->assertDontSee('Your quotations')
                ->assertDontSee('Requested Items')
                ->assertDontSee('Recommend to Purchasing');
            $this->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee('Add quotation'); // the dashboard lists only requests to claim
            $this->get(route('request-flow.index', ['stage' => 'quotation']))
                ->assertOk()
                ->assertSee($request->request_number)
                ->assertDontSee('Add Quotation')
                ->assertDontSee('Recommend Quotation');

            // Purchasing canvasses and submits the recommendation; its page closes to the hand-off message too.
            $purchasing = $this->user(RoleSlug::Purchasing);
            $this->actingAs($purchasing)->post(route('canvassing.quotations.store', $request), [
                'quotations' => [[
                    'supplier_name' => 'Canvassed Supplier '.$role->value,
                    'quoted_amount' => 1500,
                    'recommended' => 1,
                ]],
            ])->assertRedirect(route('purchase-requests.show', $request));
            $quote = $request->quotations()->firstOrFail();
            $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->fresh()->status);
            $this->assertTrue($quote->fresh()->is_recommended);
            $this->get(route('purchase-requests.show', $request))
                ->assertOk()
                ->assertSee('Submitted to President.')
                ->assertSee('move to the President for supplier approval')
                ->assertDontSee('Requested Items');

            // The supplier recommendation belongs to Purchasing, never IT / Premises.
            $this->actingAs($staff)->post(route('canvassing.quotations.recommend', [$request, $quote]))->assertForbidden();
        }
    }

    public function test_purchasing_can_submit_a_supplier_recommendation_from_procurement(): void
    {
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), false, ['request_type' => 'OTHER']);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForProcurement,
            'current_responsible_role_id' => $this->roleId(RoleSlug::ProcurementStaff),
        ])->save();
        $purchasing = $this->user(RoleSlug::Purchasing);

        $this->actingAs($purchasing)->post(route('canvassing.quotations.store', $request), [
            'quotations' => [
                ['supplier_name' => 'ABC Office Supplies', 'quoted_amount' => 2500, 'recommended' => '1', 'delivery_lead_time' => '5 days'],
                ['supplier_name' => 'XYZ Office Supplies', 'quoted_amount' => 2700, 'recommended' => '0'],
            ],
        ])->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->status);
        $this->assertNull($request->selected_supplier_id);
        $this->assertDatabaseHas('supplier_quotations', [
            'purchase_request_id' => $request->id,
            'is_recommended' => true,
            'is_approved' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $request->id,
            'action' => 'CANVASSING_UPDATED',
            'remarks' => 'Purchasing submitted supplier recommendation for President approval.',
        ]);
    }

    public function test_manual_single_quotation_is_valid_without_hidden_bulk_item_fields(): void
    {
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), false, ['request_type' => 'OTHER']);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForProcurement,
            'current_responsible_role_id' => $this->roleId(RoleSlug::ProcurementStaff),
        ])->save();
        $purchasing = $this->user(RoleSlug::Purchasing);

        $this->actingAs($purchasing)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('name="quotations[0][items][0][item_id]" disabled', false);

        $this->actingAs($purchasing)->post(route('canvassing.quotations.store', $request), [
            'quotations' => [[
                'supplier_name' => 'Makati Office Supplies',
                'quoted_amount' => 1200,
                'delivery_lead_time' => '7 days',
                'warranty' => '1 year',
                'quotation_url' => 'https://quotes.example.test/asi-req-1.pdf',
                'recommended' => '1',
            ]],
        ])->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->status);
        $this->assertDatabaseHas('supplier_quotations', [
            'purchase_request_id' => $request->id,
            'supplier_id' => Supplier::query()->where('name', 'Makati Office Supplies')->value('id'),
            'is_recommended' => true,
        ]);
    }

    public function test_canvassing_accepts_more_than_three_supplier_quotations(): void
    {
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), false, ['request_type' => 'OTHER']);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForProcurement,
            'current_responsible_role_id' => $this->roleId(RoleSlug::ProcurementStaff),
        ])->save();
        $purchasing = $this->user(RoleSlug::Purchasing);
        $quotations = collect(range(1, 5))->map(fn (int $number): array => [
            'supplier_name' => "Unlimited Quote Supplier {$number}",
            'quoted_amount' => 1000 + $number,
            'delivery_lead_time' => "{$number} days",
            'warranty' => '1 year',
            'recommended' => $number === 4 ? '1' : '0',
        ])->all();

        $this->actingAs($purchasing)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('id="add-quotation"', false)
            ->assertSee('let nextQuoteIndex', false)
            ->assertDontSee('const maxRows', false);

        $this->actingAs($purchasing)
            ->post(route('canvassing.quotations.store', $request), compact('quotations'))
            ->assertSessionHasNoErrors();

        $this->assertSame(5, $request->quotations()->count());
        $this->assertSame(5, $request->quotations()->pluck('supplier_id')->unique()->count());
        $this->assertSame(1, $request->quotations()->where('is_recommended', true)->count());
        $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->fresh()->status);
    }

    public function test_bulk_quotation_requires_a_real_requested_item_id_when_items_are_present(): void
    {
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), true, ['request_type' => 'OTHER', 'purchase_mode' => 'bulk']);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForProcurement,
            'current_responsible_role_id' => $this->roleId(RoleSlug::ProcurementStaff),
        ])->save();
        $purchasing = $this->user(RoleSlug::Purchasing);

        $this->actingAs($purchasing)->post(route('canvassing.quotations.store', $request), [
            'quotation_mode' => 'bulk',
            'quotations' => [[
                'supplier_name' => 'Alpha Supply Co.',
                'recommended' => '1',
                'items' => [[
                    'item_id' => '',
                    'quantity' => 2,
                    'unit_price' => 150,
                ]],
            ]],
        ])->assertSessionHasErrors(['quotations.0.items.0.item_id']);
    }

    public function test_valid_external_quotation_url_opens_in_a_new_tab(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $request = $this->createRequest($requestor);
        $supplier = Supplier::query()->create(['name' => 'External Link Supplier', 'is_active' => true]);
        $url = 'https://quotes.vendor.ph/proposals/asi-1001.pdf';

        $request->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $requestor->id,
            'item_or_service' => $request->title,
            'price' => 2500,
            'quotation_url' => $url,
        ]);

        $this->actingAs($requestor)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('href="'.$url.'" target="_blank" rel="noopener noreferrer"', false);
    }

    public function test_invalid_and_sample_quotation_urls_do_not_render_broken_open_links(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $request = $this->createRequest($requestor);
        $supplier = Supplier::query()->create(['name' => 'Invalid Link Supplier', 'is_active' => true]);

        foreach (['not a valid URL', 'https://example.test/demo-quotation.pdf'] as $url) {
            $request->quotations()->create([
                'supplier_id' => $supplier->id,
                'created_by' => $requestor->id,
                'item_or_service' => $request->title,
                'price' => 2500,
                'quotation_url' => $url,
            ]);
        }

        $this->actingAs($requestor)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('No quotation document provided.', false)
            ->assertDontSee('href="https://example.test/demo-quotation.pdf"', false)
            ->assertDontSee('href="not a valid URL"', false);
    }

    public function test_existing_uploaded_quotation_file_uses_the_authorized_attachment_route(): void
    {
        Storage::fake('local');
        $requestor = $this->user(RoleSlug::Requestor);
        $request = $this->createRequest($requestor);
        $supplier = Supplier::query()->create(['name' => 'Uploaded File Supplier', 'is_active' => true]);
        $path = "purchase-requests/{$request->id}/quotations/vendor-quote.pdf";
        Storage::disk('local')->put($path, 'quotation contents');
        $attachment = $request->attachments()->create([
            'uploaded_by' => $requestor->id,
            'category' => 'supplier_quotation',
            'original_name' => 'vendor-quote.pdf',
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => strlen('quotation contents'),
        ]);
        $relativeDownloadUrl = route('attachments.download', $attachment, false);

        $quotation = $request->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $requestor->id,
            'item_or_service' => $request->title,
            'price' => 2500,
            'quotation_url' => $relativeDownloadUrl,
        ]);

        $this->actingAs($requestor)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('href="'.url($relativeDownloadUrl).'" target="_blank" rel="noopener noreferrer"', false);
        $this->get(route('attachments.download', $attachment))->assertOk();
    }

    public function test_bulk_canvassing_links_one_supplier_to_multiple_requested_items_and_totals_lines(): void
    {
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), true, ['request_type' => 'OTHER', 'purchase_mode' => 'bulk']);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForProcurement,
            'current_responsible_role_id' => $this->roleId(RoleSlug::ProcurementStaff),
        ])->save();
        $purchasing = $this->user(RoleSlug::Purchasing);
        $items = $request->items()->get();

        $this->actingAs($purchasing)->post(route('canvassing.quotations.store', $request), [
            'quotation_mode' => 'bulk',
            'quotations' => [[
                'supplier_name' => 'ABC Office Supplies',
                'recommended' => '1',
                'items' => [
                    ['item_id' => $items[0]->id, 'quantity' => 10, 'unit_price' => 999],
                    ['item_id' => $items[1]->id, 'quantity' => 10, 'unit_price' => 599],
                    ['item_id' => $items[2]->id, 'quantity' => 10, 'unit_price' => 350],
                ],
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, $request->quotations()->count());
        $this->assertEquals(19480, $request->quotations()->sum('price'));
        $this->assertSame(3, $request->quotations()->where('supplier_id', $request->quotations()->first()->supplier_id)->whereNotNull('purchase_request_item_id')->count());
        $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->fresh()->status);
    }

    public function test_president_approves_or_returns_purchasing_recommendation(): void
    {
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), false, ['request_type' => 'OTHER']);
        $supplier = Supplier::query()->create(['name' => 'Recommended Supplier', 'is_active' => true]);
        $purchasing = $this->user(RoleSlug::Purchasing);
        $request->forceFill(['status' => PurchaseRequestStatus::ForSupplierApproval])->save();
        $quotation = $request->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $purchasing->id,
            'item_or_service' => $request->title,
            'price' => 2500,
            'is_recommended' => true,
        ]);
        $president = $this->user(RoleSlug::President);

        $this->actingAs($president)->post(route('president-approvals.supplier.decision', $request), [
            'decision' => 'APPROVE',
            'quotation_id' => $quotation->id,
            'signature_name' => $president->name,
            'signature_data' => $this->signatureData(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(PurchaseRequestStatus::ForBudgetProcessing, $request->fresh()->status);
        $this->assertSame($supplier->id, $request->fresh()->selected_supplier_id);
        $this->assertDatabaseHas('supplier_quotations', ['supplier_id' => $supplier->id, 'is_approved' => true, 'approved_by' => $president->id]);

        $returned = $this->createRequest($this->user(RoleSlug::TeamLeader), false, ['request_type' => 'OTHER']);
        $returned->forceFill(['status' => PurchaseRequestStatus::ForSupplierApproval])->save();
        $this->actingAs($president)->post(route('president-approvals.supplier.decision', $returned), [
            'decision' => 'REJECT',
            'remarks' => 'Please revise the recommendation.',
            'signature_name' => $president->name,
            'signature_data' => $this->signatureData(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForCanvassing, $returned->fresh()->status);
    }

    public function test_purchasing_cannot_use_legacy_supplier_approval_endpoint(): void
    {
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), false, ['request_type' => 'OTHER']);
        $request->forceFill(['status' => PurchaseRequestStatus::ForSupplierApproval])->save();
        $purchasing = $this->user(RoleSlug::Purchasing);

        $this->actingAs($purchasing)->post(route('canvassing.supplier.approve', $request), [
            'supplier_id' => 1,
        ])->assertForbidden();
    }

    public function test_returned_request_can_be_edited_and_resubmitted_to_om(): void
    {
        [$request, $om, $requestor] = $this->submittedRequest('IT');
        $this->actingAs($om)->post(route('om-approvals.return', $request), ['remarks' => 'Use the approved model.', 'signature_name' => $om->name, 'signature_data' => $this->signatureData()]);

        $payload = $this->payload([
            'action' => 'submit',
            ...$this->workflowSignature($requestor),
            'title' => 'Updated Laptop Request',
            'items' => [[
                'id' => $request->items()->firstOrFail()->id,
                'description' => 'Updated laptop',
                'quantity' => 3,
                'unit' => 'unit',
                'estimated_unit_price' => 300,
                'brand_specifications' => 'Approved model',
                'reference_url' => 'https://example.test/approved',
            ], [
                'description' => 'Laptop sleeve',
                'quantity' => 1,
                'unit' => 'piece',
                'estimated_unit_price' => 0,
            ], [
                'description' => 'Laptop stand',
                'quantity' => 1,
                'unit' => 'piece',
                'estimated_unit_price' => 0,
            ]],
        ]);

        $this->actingAs($requestor)->put(route('purchase-requests.update', $request), $payload)
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::ForOmApproval, $request->status);
        $this->assertSame('900.00', $request->total_amount);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $request->id,
            'action' => 'RESUBMITTED_FOR_OM_APPROVAL',
        ]);
    }

    public function test_it_can_record_available_assets_and_release_them_automatically(): void
    {
        $request = $this->screeningRequest('IT');
        $it = $this->user(RoleSlug::ItStaff);
        $this->actingAs($it)->post(route('request-flow.claim', $request))->assertRedirect();

        $this->post(route('request-flow.screening.store', $request), [
            ...$this->workflowSignature(auth()->user()),
            'decision' => 'AVAILABLE',
            'evidence' => 'Matched against the IT stock list.',
            'assets' => [[
                'asset_tag' => 'IT-1001',
                'item_code' => 'LAP-16',
                'description' => 'Business laptop',
                'quantity' => 1,
                'serial_number' => 'SN-ABC-001',
                'location' => 'IT Stockroom',
                'source' => 'IT_SELF_INVENTORY',
                'release_date' => now()->format('Y-m-d'),
                'recipient' => 'Test Requestor',
            ]],
        ])->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::Fulfilled, $request->status);
        $this->assertNull($request->current_responsible_role_id);
        $this->assertDatabaseHas('request_screenings', [
            'purchase_request_id' => $request->id,
            'screened_by' => $it->id,
            'team' => 'IT',
            'decision' => 'AVAILABLE',
        ]);
        $this->assertDatabaseHas('screening_assets', [
            'asset_tag' => 'IT-1001',
            'item_code' => $request->screening->assets()->firstOrFail()->item_code,
            'released_by' => $it->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $request->id,
            'action' => 'INVENTORY_AVAILABLE',
        ]);
        $this->assertSame(1, $request->endorsements()->count());
        $this->assertSame(1, $request->screening->assets()->firstOrFail()->inventoryItem->releases()->count());
    }

    public function test_unavailable_screening_records_optional_evidence_and_routes_to_procurement(): void
    {
        Storage::fake('local');
        $request = $this->screeningRequest('PREMISES');
        $premises = $this->user(RoleSlug::PremisesStaff);
        $this->actingAs($premises)->post(route('request-flow.claim', $request))->assertRedirect();

        $this->post(route('request-flow.screening.store', $request), [
            ...$this->workflowSignature(auth()->user()),
            'decision' => 'UNAVAILABLE',
            'evidence' => 'No matching unit was found in the Premises inventory export.',
            'quotation_evidence' => UploadedFile::fake()->create('quotation.pdf', 120, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::ForProcurement, $request->status);
        // Unavailable stock stays with the screening team for quotation preparation.
        $this->assertSame($this->roleId(RoleSlug::PremisesStaff), $request->current_responsible_role_id);
        $attachment = $request->screening()->firstOrFail()->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame('screening_evidence', $attachment->category);
    }

    public function test_premises_can_import_available_assets_from_csv(): void
    {
        Storage::fake('local');
        $request = $this->screeningRequest('PREMISES');
        $premises = $this->user(RoleSlug::PremisesStaff);
        $this->actingAs($premises)->post(route('request-flow.claim', $request))->assertRedirect();
        $csv = implode("\n", [
            'asset_tag,item_code,description,serial_number,location,release_date,recipient',
            'PREM-9,CHAIR-1,Ergonomic chair,,Premises Stockroom,'.now()->format('Y-m-d').',Test Requestor',
        ]);

        $this->actingAs($premises)->post(route('request-flow.screening.store', $request), [
            ...$this->workflowSignature(auth()->user()),
            'decision' => 'AVAILABLE',
            'evidence' => 'Imported from the Premises Excel export.',
            'premises_import' => UploadedFile::fake()->createWithContent('premises.csv', $csv),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('screening_assets', [
            'asset_tag' => 'PREM-9',
            'source' => 'PREMISES_EXCEL_IMPORT',
        ]);
        $this->assertSame(PurchaseRequestStatus::Fulfilled, $request->fresh()->status);
    }

    public function test_role_middleware_and_policy_prevent_unauthorized_workflow_actions(): void
    {
        [$request] = $this->submittedRequest('IT');
        $requestor = $this->user(RoleSlug::Requestor);

        $this->actingAs($requestor)->post(route('om-approvals.approve', $request))->assertForbidden();
        $this->post(route('request-flow.screening.store', $request), ['decision' => 'AVAILABLE'])->assertForbidden();
        $this->assertSame(PurchaseRequestStatus::ForOmApproval, $request->fresh()->status);
    }

    public function test_purchasing_temporary_workflow_access_covers_downstream_policies(): void
    {
        $purchasing = $this->user(RoleSlug::Purchasing);
        [$request] = $this->submittedRequest('IT');

        $this->assertFalse(Gate::forUser($purchasing)->allows('reviewPresident', $request));
        foreach (['manageBudget', 'processPayment', 'receiveGoods'] as $ability) {
            $this->assertTrue(
                Gate::forUser($purchasing)->allows($ability, $request),
                "Purchasing temporary access should allow {$ability}."
            );
        }
    }

    public function test_duplicate_submissions_approvals_and_screening_decisions_are_prevented(): void
    {
        [$request, $om, $requestor] = $this->submittedRequest('IT');
        $auditCount = $request->auditLogs()->count();

        $this->actingAs($requestor)->post(route('purchase-requests.submit', $request))->assertForbidden();
        $this->assertSame($auditCount, $request->auditLogs()->count());

        $this->actingAs($om)->post(route('om-approvals.approve', $request), ['signature_name' => $om->name, 'signature_data' => $this->signatureData()]);
        $approvalCount = RequestApproval::query()->where('purchase_request_id', $request->id)->count();
        $this->post(route('om-approvals.approve', $request), ['signature_name' => $om->name, 'signature_data' => $this->signatureData()])->assertSessionHasErrors('status');
        $this->assertSame($approvalCount, RequestApproval::query()->where('purchase_request_id', $request->id)->count());

        $screeningRequest = $this->screeningRequest('IT');
        $it = $this->user(RoleSlug::ItStaff);
        $this->actingAs($it)->post(route('request-flow.claim', $screeningRequest))->assertRedirect();
        $screening = [
            ...$this->workflowSignature($it),
            'decision' => 'AVAILABLE',
            'assets' => [[
                'asset_tag' => 'IT-2001', 'item_code' => 'LAP-1', 'description' => 'Laptop',
                'quantity' => 1,
                'serial_number' => null, 'location' => 'IT', 'source' => 'MANUAL',
                'release_date' => now()->format('Y-m-d'), 'recipient' => 'Requestor',
            ]],
        ];
        $this->post(route('request-flow.screening.store', $screeningRequest), $screening)->assertSessionHasNoErrors();
        $this->post(route('request-flow.screening.store', $screeningRequest), $screening)->assertForbidden();
        $this->assertSame(1, $screeningRequest->screening()->count());
    }

    public function test_audit_timeline_captures_team_leader_submission_and_om_review_changes(): void
    {
        [$request, $om] = $this->submittedRequest('IT');
        $this->actingAs($om)->post(route('om-approvals.return', $request), ['remarks' => 'Revise it.', 'signature_name' => $om->name, 'signature_data' => $this->signatureData()]);
        $requestor = $request->requestor;
        $this->actingAs($requestor)->post(route('purchase-requests.submit', $request), $this->workflowSignature($requestor));
        $this->actingAs($om)->post(route('om-approvals.approve', $request), ['signature_name' => $om->name, 'signature_data' => $this->signatureData()]);

        $transitions = AuditLog::query()
            ->where('auditable_type', PurchaseRequest::class)
            ->where('auditable_id', $request->id)
            ->whereColumn('previous_status', '!=', 'new_status')
            ->orderBy('id')
            ->get(['previous_status', 'new_status', 'action'])
            ->toArray();

        $this->assertSame([
            ['previous_status' => 'DRAFT', 'new_status' => 'FOR_OM_APPROVAL', 'action' => 'SUBMITTED_FOR_OM_APPROVAL'],
            ['previous_status' => 'FOR_OM_APPROVAL', 'new_status' => 'RETURNED', 'action' => 'OM_RETURNED'],
            ['previous_status' => 'RETURNED', 'new_status' => 'FOR_OM_APPROVAL', 'action' => 'RESUBMITTED_FOR_OM_APPROVAL'],
            ['previous_status' => 'FOR_OM_APPROVAL', 'new_status' => 'FOR_TEAM_SCREENING', 'action' => 'OM_APPROVED_FOR_SCREENING'],
        ], $transitions);

        // The OM's own view closes to a receipt after approving, so read the timeline as the requestor.
        $this->actingAs($requestor)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('Workflow and Audit Timeline')
            ->assertSee('workflow-history-disclosure', false)
            ->assertSee('Expand')
            ->assertSee('See less')
            ->assertSee('workflow-timeline-key--completed', false)
            ->assertSee('workflow-timeline-key--current', false)
            ->assertSee('workflow-entry--completed', false)
            ->assertSee('workflow-entry--current', false);
    }

    public function test_purchasing_team_must_submit_a_quotation_before_creating_a_purchase_order(): void
    {
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), false);
        $request->forceFill(['status' => PurchaseRequestStatus::ForProcurement])->save();
        $purchasing = $this->user(RoleSlug::PurchasingTeam);
        foreach ([
            [PurchaseOrderApprovalStage::PreparedBy, $purchasing],
            [PurchaseOrderApprovalStage::CheckedBy, $this->user(RoleSlug::Tl)],
            [PurchaseOrderApprovalStage::NotedBy, $this->user(RoleSlug::Om)],
            [PurchaseOrderApprovalStage::ApprovedBy, $this->user(RoleSlug::Smt)],
        ] as [$stage, $user]) {
            PoRoleAssignment::query()->create(['stage' => $stage, 'user_id' => $user->id]);
        }

        $this->actingAs($purchasing)->get(route('dashboard', ['workflow' => 'canvassing']))
            ->assertOk()
            ->assertSee($request->request_number)
            ->assertSee('Add quotation');
        $this->get(route('dashboard', ['workflow' => 'purchase-order']))
            ->assertOk()
            ->assertDontSee($request->request_number);
        $this->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('name="quotations[0][supplier_name]"', false)
            ->assertDontSee('id="purchase-order-actions"', false);

        $payload = [
            'expected_delivery_date' => now()->addDays(14)->toDateString(),
            'remarks' => 'Purchase after approval.',
            'payable_on' => now()->addDays(30)->toDateString(),
            'payment_details' => 'Bank transfer',
            'account_number' => '123456789',
            'account_name' => 'Approved Supplier',
            'payment_terms' => '30 days',
            ...$this->workflowSignature($purchasing),
        ];
        $this->post(route('purchase-orders.store', $request), $payload)
            ->assertSessionHasErrors('status');

        $this->assertNull($request->fresh()->purchaseOrder);

        $this->post(route('canvassing.quotations.store', $request), [
            'quotation_mode' => 'manual',
            'quotations' => [[
                'supplier_name' => 'Approved Supplier',
                'quoted_amount' => 500,
                'vat_treatment' => 'EXEMPT',
                'delivery_lead_time' => '7 days',
                'delivery_location' => \App\Models\SupplierQuotation::DELIVERY_LOCATIONS[0],
                'warranty' => '1 year',
                'quotation_url' => 'https://example.com/approved-quote.pdf',
                'bank_details' => 'Example Bank 123456789',
                'remarks' => 'Recommended after canvassing',
                'recommended' => '1',
            ]],
        ])->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->status);
        $this->assertNull($request->purchaseOrder);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $request->id,
            'action' => 'CANVASSING_UPDATED',
        ]);
        $this->get(route('dashboard', ['workflow' => 'canvassing']))
            ->assertOk()
            ->assertSee($request->request_number);
        $this->post(route('purchase-orders.store', $request), $payload)
            ->assertSessionHasErrors('status');

        $president = $this->user(RoleSlug::President);
        $quotation = $request->quotations()->firstOrFail();
        $this->actingAs($president)->post(route('president-approvals.supplier.decision', $request), [
            'decision' => 'APPROVE',
            'quotation_id' => $quotation->id,
            ...$this->workflowSignature($president),
        ])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForBudgetProcessing, $request->fresh()->status);

        $this->actingAs($purchasing)->get(route('dashboard', ['workflow' => 'purchase-order']))
            ->assertOk()
            ->assertSee($request->request_number)
            ->assertSee('Create purchase order');
        $this->get(route('dashboard', ['workflow' => 'canvassing']))
            ->assertOk()
            ->assertDontSee('Create purchase order');
        $this->post(route('purchase-orders.store', $request), $payload)
            ->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertNotNull($request->purchaseOrder);
        $this->assertSame($quotation->supplier_id, $request->purchaseOrder->supplier_id);
        $this->get(route('dashboard', ['workflow' => 'purchase-order']))
            ->assertOk()
            ->assertSee($request->purchaseOrder->po_number)
            ->assertSee($request->request_number)
            ->assertDontSee('Purchase Order Review History');
        $this->get(route('dashboard', ['workflow' => 'canvassing']))
            ->assertOk()
            ->assertSee($request->request_number);
        $this->get(route('dashboard', ['workflow' => 'budget-request']))
            ->assertOk()
            ->assertDontSee($request->purchaseOrder->po_number);
    }

    public function test_purchase_order_line_prices_are_calculated_from_the_approved_quotation(): void
    {
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), false);
        $firstItem = $request->items()->firstOrFail();
        $firstItem->update(['quantity' => 3, 'unit' => 'unit', 'estimated_unit_price' => 20, 'estimated_total' => 60]);
        $secondItem = $request->items()->create([
            'description' => 'Second quoted item',
            'quantity' => null,
            'unit' => 'piece',
            'estimated_unit_price' => 40,
            'estimated_total' => 40,
        ]);
        $purchasing = $this->user(RoleSlug::PurchasingTeam);
        $supplier = Supplier::query()->create(['name' => 'Approved line supplier', 'is_active' => true]);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForBudgetProcessing,
            'selected_supplier_id' => $supplier->id,
        ])->save();
        $request->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $purchasing->id,
            'item_or_service' => $request->title,
            'price' => 100.01,
            'vat_treatment' => 'EXEMPT',
            'base_amount' => 100.01,
            'vat_amount' => 0,
            'total_amount' => 100.01,
            'is_approved' => true,
            'approved_at' => now(),
        ]);
        foreach ([
            [PurchaseOrderApprovalStage::PreparedBy, $purchasing],
            [PurchaseOrderApprovalStage::CheckedBy, $this->user(RoleSlug::Tl)],
            [PurchaseOrderApprovalStage::NotedBy, $this->user(RoleSlug::Om)],
            [PurchaseOrderApprovalStage::ApprovedBy, $this->user(RoleSlug::Smt)],
        ] as [$stage, $user]) {
            PoRoleAssignment::query()->create(['stage' => $stage, 'user_id' => $user->id]);
        }

        $this->actingAs($purchasing)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('data-po-line-amount="60.01"', false)
            ->assertSee('data-po-line-amount="40.00"', false)
            ->assertDontSee('name="po_items['.$firstItem->id.'][unit_price]"', false);

        $this->post(route('purchase-orders.store', $request), [
            'expected_delivery_date' => now()->addWeek()->toDateString(),
            'remarks' => 'Automatically priced from the approved quote.',
            'payable_on' => now()->addWeeks(2)->toDateString(),
            'payment_details' => 'Bank transfer',
            'account_number' => '123456789',
            'account_name' => $supplier->name,
            'payment_terms' => '30 days',
            'po_items' => [
                $firstItem->id => ['quantity' => 3, 'unit' => 'unit', 'unit_price' => 1],
                $secondItem->id => ['quantity' => 2, 'unit' => 'piece', 'unit_price' => 1],
            ],
            ...$this->workflowSignature($purchasing),
        ])->assertSessionHasNoErrors();

        $order = $request->fresh()->purchaseOrder;
        $this->assertSame('100.01', $order->subtotal);
        $this->assertSame('100.01', $order->total_amount);
        $this->assertSame('60.01', $order->items()->where('purchase_request_item_id', $firstItem->id)->firstOrFail()->total_price);
        $this->assertSame('40.00', $order->items()->where('purchase_request_item_id', $secondItem->id)->firstOrFail()->total_price);
    }

    public function test_purchasing_can_create_a_purchase_order_from_the_approved_supplier(): void
    {
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), true, ['request_type' => 'IT']);
        $supplier = Supplier::query()->create([
            'name' => 'Alpha Procurement',
            'is_active' => true,
        ]);
        $purchasing = $this->user(RoleSlug::Purchasing);
        $president = $this->user(RoleSlug::President);
        $checker = $this->user(RoleSlug::Tl);
        $noter = $this->user(RoleSlug::Om);
        $poApprover = $this->user(RoleSlug::Smt);
        foreach ([
            [PurchaseOrderApprovalStage::PreparedBy, $purchasing],
            [PurchaseOrderApprovalStage::CheckedBy, $checker],
            [PurchaseOrderApprovalStage::NotedBy, $noter],
            [PurchaseOrderApprovalStage::ApprovedBy, $poApprover],
        ] as [$stage, $user]) {
            PoRoleAssignment::query()->create(['stage' => $stage, 'user_id' => $user->id]);
        }
        $campaign = Campaign::query()->where('name', 'AC MOBILITY')->firstOrFail();
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForBudgetProcessing,
            'selected_supplier_id' => $supplier->id,
            'current_responsible_role_id' => $this->roleId(RoleSlug::Purchasing),
        ])->save();
        $this->actingAs($purchasing)
            ->post(route('purchase-orders.store', $request), [
                'account_number' => 'PO-ACCOUNT-001',
                'payment_terms' => '30 days',
                'signature_name' => $purchasing->name,
                'signature_data' => $this->signatureData(),
            ])
            ->assertSessionHasErrors('status');
        $this->assertNull($request->fresh()->purchaseOrder);
        $request->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $purchasing->id,
            'item_or_service' => $request->title,
            'price' => 2500,
            'delivery_terms' => '30 days',
            'delivery_lead_time' => '10 days',
            'warranty' => '1 year',
            'bank_details' => 'Alpha Bank · Alpha Procurement · 00123456789',
            'remarks' => 'Recommended vendor',
            'quoted_at' => now()->toDateString(),
            'is_recommended' => true,
            'is_approved' => true,
            'approved_by' => $president->id,
            'approved_at' => now(),
        ]);

        $this->actingAs($purchasing)
            ->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('Automatically carried from the Purchase Request.')
            ->assertSee('Automatically filled from the selected supplier.')
            ->assertSee('Automatically filled from the approved quotation.')
            ->assertSee('Required. Confirm the payment account number from the approved supplier payment details.')
            ->assertSee('id="po_priority_display"', false)
            ->assertSee('id="po_account_name" name="account_name" maxlength="255" value="Alpha Procurement" readonly', false)
            ->assertSee('id="po_payment_terms"', false)
            ->assertSee('Prepared By signature')
            ->assertSee('id="po-create-prepared-signature-heading"', false)
            ->assertSee('Creating the Purchase Order will submit it directly to the assigned Checker.');

        $this->actingAs($purchasing)
            ->post(route('purchase-orders.store', $request), [
                'account_number' => 'PO-ACCOUNT-001',
                'payment_details' => 'Transfer on delivery',
            ])
            ->assertSessionHasErrors('signature_data');
        $this->assertNull($request->fresh()->purchaseOrder);

        $redirectUrl = route('purchase-requests.show', [
            'purchaseRequest' => $request,
            'from_stage' => 'budget-request',
        ]);
        $this->actingAs($purchasing)
            ->post(route('purchase-orders.store', $request), [
                'expected_delivery_date' => now()->addDays(10)->format('Y-m-d'),
                'remarks' => 'PO created after supplier approval.',
                'account_number' => 'PO-ACCOUNT-001',
                'payment_details' => 'Transfer on delivery',
                'signature_name' => $purchasing->name,
                'signature_data' => $this->signatureData(),
            ])
            ->assertRedirect($redirectUrl)
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('success')
            ->assertSessionMissing('purchase_order_created');

        $request->refresh();
        $this->assertNotNull($request->purchaseOrder);
        $this->assertSame('NORMAL', $request->priority_level);
        $this->assertSame($supplier->id, $request->purchaseOrder->supplier_id);
        $this->assertSame($request->fresh()->requestor->name, $request->purchaseOrder->requested_by);
        $this->assertSame('PO-ACCOUNT-001', $request->purchaseOrder->account_number);
        $this->assertSame('Alpha Procurement', $request->purchaseOrder->account_name);
        $this->assertSame('30 days', $request->purchaseOrder->payment_terms);
        $this->assertNull($request->purchaseOrder->budget_transaction_type);
        $this->assertNull($request->purchaseOrder->budget_fund_source);
        $this->assertMatchesRegularExpression('/^ASI\\d{6}$/', $request->purchaseOrder->po_number);
        $this->assertNotSame($request->purchaseOrder->budget_campaign_account_number, $request->purchaseOrder->account_number);
        $this->assertSame('Transfer on delivery', $request->purchaseOrder->payment_details);
        $this->assertDatabaseHas('purchase_orders', [
            'purchase_request_id' => $request->id,
            'supplier_id' => $supplier->id,
        ]);
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $request->purchaseOrder->id,
            'description' => 'Business laptop',
        ]);
        $longDescription = 'Business laptop with enterprise security, extended docking support, and a detailed configuration description that must wrap cleanly in the A4 item table.';
        $longPurpose = 'Replace aging workstations while preserving secure access, reliable video conferencing, and compatibility with the existing corporate support environment.';
        $request->purchaseOrder->items()->firstOrFail()->update(['description' => $longDescription]);
        $request->forceFill(['purpose' => $longPurpose])->save();
        $this->actingAs($purchasing)
            ->get(route('purchase-requests.show', ['purchaseRequest' => $request, 'from_stage' => 'budget-request']))
            ->assertOk()
            ->assertSee('Submitted to Checker.')
            ->assertSee($checker->name)
            ->assertSee('Your preparation stage is closed')
            ->assertDontSee('Purchase Order Approval History')
            ->assertDontSee('View Purchase Order')
            ->assertDontSee('Print after approval');

        $preparedApproval = $request->purchaseOrder->approvalFor(PurchaseOrderApprovalStage::PreparedBy);
        $this->assertTrue($preparedApproval->hasValidSignature());
        $this->assertSame($purchasing->id, $preparedApproval->acted_by);
        $this->assertNotNull($preparedApproval->signed_at);
        $this->assertNotNull($preparedApproval->acted_at);

        $this->get(route('purchase-orders.document', ['purchaseRequest' => $request, 'mode' => 'export']))
            ->assertOk()
            ->assertSee('PURCHASE ORDER')
            ->assertSee('Acct name:')
            ->assertSee('PO-ACCOUNT-001')
            ->assertSee('Alpha Procurement')
            ->assertSee('30 days')
            ->assertSee('Item Code')
            ->assertSee('Campaign')
            ->assertSee('Net of VAT')
            ->assertSee('VAT:')
            ->assertSee($longDescription)
            ->assertSee($longPurpose)
            ->assertSee('Prepared by')
            ->assertSee('Checked by')
            ->assertSee('Noted by')
            ->assertSee('Approved by')
            ->assertDontSee('ACCOUNT TYPE')
            ->assertDontSee('Main Fund')
            ->assertDontSee('Export / Save as PDF')
            ->assertDontSee("window.print()", false);

        $this->get(route('purchase-requests.show', ['purchaseRequest' => $request, 'from_stage' => 'budget-request']))
            ->assertOk()
            ->assertSee('Submitted to Checker.')
            ->assertDontSee('Processed Purchase Orders');
        $this->actingAs($purchasing)->patch(route('purchase-orders.update', $request), [
            'account_number' => 'PO-ACCOUNT-001',
            'account_name' => 'Approved Supplier Account Updated',
            'payment_terms' => '30 days',
            'payment_details' => 'Transfer on delivery',
            'remarks' => 'PO created after supplier approval.',
        ])->assertForbidden();
        $this->assertSame('Alpha Procurement', $request->purchaseOrder->fresh()->account_name);
        $this->actingAs($request->requestor)->patch(route('purchase-orders.update', $request), [
            'account_number' => 'UNAUTHORIZED',
            'account_name' => 'Unauthorized',
            'payment_terms' => 'Immediate',
            'transaction_type' => 'CASH',
            'fund_source' => 'OTHER',
        ])->assertForbidden();
        $this->actingAs($purchasing);
        $this->actingAs($purchasing)->post(route('purchase-orders.check', $request))->assertForbidden();
        $this->actingAs($checker)->post(route('purchase-orders.check', $request))->assertSessionHasErrors('signature_data');
        $this->actingAs($purchasing)->post(route('purchase-orders.signature', [$request, PurchaseOrderApprovalStage::CheckedBy->value]), ['signature_name' => $purchasing->name, 'signature_data' => $this->signatureData()])->assertForbidden();
        $this->actingAs($this->user(RoleSlug::ProcurementStaff))->post(route('purchase-orders.check', $request))->assertForbidden();

        $this->actingAs($checker)->post(route('purchase-orders.signature', [$request, PurchaseOrderApprovalStage::CheckedBy->value]), ['signature_name' => $checker->name, 'signature_data' => $this->signatureData()])->assertSessionHasNoErrors();
        $this->actingAs($checker)->post(route('purchase-orders.check', $request))->assertSessionHasNoErrors();
        $this->actingAs($this->user(RoleSlug::FinanceBudgetStaff))->post(route('purchase-orders.acknowledge', $request))->assertForbidden();
        $this->actingAs($noter)->post(route('purchase-orders.signature', [$request, PurchaseOrderApprovalStage::NotedBy->value]), ['signature_name' => $noter->name, 'signature_data' => $this->signatureData()])->assertSessionHasNoErrors();
        $this->actingAs($noter)->post(route('purchase-orders.acknowledge', $request))->assertSessionHasNoErrors();
        $this->actingAs($purchasing)->post(route('purchase-orders.approve', $request), ['decision' => 'APPROVE'])->assertForbidden();
        $this->actingAs($this->user(RoleSlug::President))->post(route('purchase-orders.approve', $request), ['decision' => 'APPROVE'])->assertForbidden();
        $this->actingAs($poApprover)->post(route('purchase-orders.signature', [$request, PurchaseOrderApprovalStage::ApprovedBy->value]), ['signature_name' => $poApprover->name, 'signature_data' => $this->signatureData()])->assertSessionHasNoErrors();
        $this->actingAs($poApprover)->post(route('purchase-orders.approve', $request), ['decision' => 'APPROVE'])->assertSessionHasNoErrors();
        $request->refresh();
        foreach (PurchaseOrderApprovalStage::cases() as $signedStage) {
            $savedApproval = $request->purchaseOrder->approvalFor($signedStage);
            $this->assertNotNull($savedApproval?->signature_data);
            $this->assertNotNull($savedApproval?->signed_at);
            $this->assertSame($savedApproval?->assigned_user_id, $savedApproval?->acted_by);
        }
        $request->refresh();
        $this->assertSame('APPROVED', $request->purchaseOrder->status);        $this->actingAs($purchasing)
            ->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee($request->purchaseOrder->po_number)
            ->assertSee('>Preview<', false)
            ->assertSee('data-po-signature-stage="PO_PREPARED_BY"', false)
            ->assertSee('data-po-signature-stage="PO_CHECKED_BY"', false)
            ->assertSee('data-po-signature-stage="PO_NOTED_BY"', false)
            ->assertSee('data-po-signature-stage="PO_APPROVED_BY"', false)
            ->assertSee('Signature saved');
        $this->actingAs($purchasing)
            ->get(route('purchase-orders.preview', $request->purchaseOrder))
            ->assertOk()
            ->assertJsonPath('po_number', $request->purchaseOrder->po_number)
            ->assertJsonPath('template', 'official-purchase-order')
            ->assertJsonPath('is_processed', true)
            ->assertJsonPath('can_print', true)
            ->assertJsonPath('html', fn ($html) => str_contains($html, 'Prepared by') && str_contains($html, 'Checked by') && str_contains($html, 'Noted by') && str_contains($html, 'Approved by') && str_contains($html, $checker->name) && str_contains($html, $noter->name) && str_contains($html, $poApprover->name));
        $this->actingAs($purchasing)
            ->get(route('budget.create', ['purchase_request_id' => $request->id]))
            ->assertOk()
            ->assertSee('Create Budget Request')
            ->assertSee('Purchase Order Details')
            ->assertSee($request->fresh()->requestor->name)
            ->assertSee('PO-ACCOUNT-001')
            ->assertSee('Alpha Procurement')
            ->assertSee('30 days')
            ->assertSee('Transfer on delivery');

        $this->actingAs($request->requestor)
            ->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertDontSee('href="#budget-maker-actions"', false);

        $this->actingAs($this->user(RoleSlug::FinanceBudgetStaff))
            ->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertDontSee('href="#budget-maker-actions"', false)
            ->assertDontSee('id="budget-request-actions"', false)
            ->assertDontSee('Prepare Budget Request')
            ->assertDontSee('Approve as Budget Maker');
    }


    public function test_legacy_budget_request_can_be_finalized_by_purchasing(): void
    {
        $requestor = $this->user(RoleSlug::TeamLeader);
        $purchasing = $this->user(RoleSlug::Purchasing);
        $president = $this->user(RoleSlug::President);
        $request = $this->createRequest($requestor);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForBudgetMaker,
            'current_responsible_role_id' => $this->roleId(RoleSlug::BudgetChecker),
        ])->save();
        $supplier = Supplier::query()->create(['name' => 'Legacy Budget Supplier', 'is_active' => true]);
        $purchaseOrder = $request->purchaseOrder()->create([
            'po_number' => 'PO-LEGACY-'.$request->id,
            'supplier_id' => $supplier->id,
            'created_by' => $purchasing->id,
            'issued_at' => now()->toDateString(),
            'status' => 'ISSUED',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
        ]);
        $budget = BudgetRequest::query()->create([
            'budget_request_number' => 'BR-LEGACY-'.$request->id,
            'purchase_request_id' => $request->id,
            'purchase_order_id' => $purchaseOrder->id,
            'requested_by' => $requestor->id,
            'payment_method' => 'ONLINE',
            'amount' => 100,
            'status' => 'MAKER_APPROVED',
            'requested_at' => now()->toDateString(),
        ]);
        $budget->allocations()->create([
            'purchase_request_id' => $request->id,
            'purchase_order_id' => $purchaseOrder->id,
            'amount' => 100,
        ]);

        $this->actingAs($president)->post(route('budget.finalize.store'), [
            'ids' => [$budget->id],
        ])->assertForbidden();
        $this->actingAs($purchasing)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('id="final-approval-actions"', false)
            ->assertSee('Final budget &amp; issue BR code', false)
            ->assertDontSee('Reject and return to Canvassing');
        $this->post(route('budget.finalize.store'), [
            'ids' => [$budget->id], 'priority_level' => 'NORMAL', 'signature_name' => $purchasing->name, 'signature_data' => $this->signatureData(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(PurchaseRequestStatus::ForPayment, $request->fresh()->status);
        $this->assertSame($purchasing->id, $budget->fresh()->final_approval_by);
        $this->assertSame('APPROVED', $budget->fresh()->final_approval_status);
        // A legacy BR code is kept rather than replaced.
        $this->assertSame('BR-LEGACY-'.$request->id, $budget->fresh()->budget_request_number);
    }

    public function test_canvassing_actor_priority_is_request_scoped_and_uses_quotation_only_as_legacy_fallback(): void
    {
        $requestor = $this->user(RoleSlug::TeamLeader);
        $workflowActor = $this->user(RoleSlug::ProcurementStaff);
        $completionActor = $this->user(RoleSlug::Purchasing);
        $otherRequestActor = $this->user(RoleSlug::Purchasing);
        $quotationActor = $this->user(RoleSlug::Purchasing);
        $request = $this->createRequest($requestor);

        $request->auditLogs()->create([
            'user_id' => $workflowActor->id,
            'action' => 'CANVASSING_UPDATED',
            'previous_status' => PurchaseRequestStatus::ForProcurement->value,
            'new_status' => PurchaseRequestStatus::ForSupplierApproval->value,
            'remarks' => 'Supplier recommendation submitted.',
        ]);
        $request->auditLogs()->create([
            'user_id' => $completionActor->id,
            'action' => 'CANVASS_COMPLETED',
            'previous_status' => PurchaseRequestStatus::ForProcurement->value,
            'new_status' => PurchaseRequestStatus::ForSupplierApproval->value,
            'remarks' => 'Explicit canvassing completion.',
        ]);

        $otherRequest = $this->createRequest($requestor);
        $otherRequest->auditLogs()->create([
            'user_id' => $otherRequestActor->id,
            'action' => 'CANVASS_COMPLETED',
            'previous_status' => PurchaseRequestStatus::ForProcurement->value,
            'new_status' => PurchaseRequestStatus::ForSupplierApproval->value,
            'remarks' => 'Completion for a different request.',
        ]);

        $this->assertSame($completionActor->id, $request->canvassingActor()?->id);
        $this->assertSame($otherRequestActor->id, $otherRequest->canvassingActor()?->id);

        $legacyRequest = $this->createRequest($requestor);
        $supplier = Supplier::query()->create(['name' => 'Legacy Quotation Supplier', 'is_active' => true]);
        $legacyRequest->forceFill(['selected_supplier_id' => $supplier->id])->save();
        $legacyRequest->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $quotationActor->id,
            'item_or_service' => $legacyRequest->title,
            'price' => 100,
            'is_recommended' => true,
        ]);

        $this->assertSame($quotationActor->id, $legacyRequest->canvassingActor()?->id);
    }

    public function test_unauthorized_user_cannot_create_a_budget_request(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $request = $this->createRequest($requestor);
        $request->forceFill(['status' => PurchaseRequestStatus::ForBudgetProcessing])->save();

        $this->actingAs($requestor)->post(route('budget.store'), [
            'purchase_request_id' => $request->id,
            'transaction_type' => 'ONLINE',
        ])->assertForbidden();

        $this->assertDatabaseMissing('budget_requests', ['purchase_request_id' => $request->id]);
        $this->assertDatabaseCount('budget_request_lines', 0);
    }

    public function test_demo_purchase_order_request_is_complete_visible_and_generates_a_real_po(): void
    {
        Storage::fake('local');
        $this->seed(ProcurementWorkflowDemoSeeder::class);

        $request = PurchaseRequest::query()
            ->where('request_number', 'REQ-SAMPLE-PO-001')
            ->with(['requestor', 'items', 'quotations.supplier', 'approvals', 'attachments'])
            ->firstOrFail();
        $purchasing = User::query()->where('email', 'purchasing@example.com')->firstOrFail();
        $poChecker = $this->user(RoleSlug::Tl);
        $poNoter = $this->user(RoleSlug::Om);
        $poApprover = $this->user(RoleSlug::Smt);
        foreach ([
            [PurchaseOrderApprovalStage::PreparedBy, $purchasing],
            [PurchaseOrderApprovalStage::CheckedBy, $poChecker],
            [PurchaseOrderApprovalStage::NotedBy, $poNoter],
            [PurchaseOrderApprovalStage::ApprovedBy, $poApprover],
        ] as [$stage, $user]) {
            PoRoleAssignment::query()->create(['stage' => $stage, 'user_id' => $user->id]);
        }

        $this->assertSame('Sample Team Leader', $request->requestor->name);
        $this->assertSame('Operations', $request->requestor->department);
        $this->assertSame('Operations', $request->department_campaign);
        $this->assertSame(PurchaseRequestStatus::ForBudgetProcessing, $request->status);
        $this->assertSame(3, $request->items->count());
        $this->assertEquals(19480, $request->items->sum('estimated_total'));
        $this->assertSame('19480.00', $request->total_amount);
        $this->assertSame(3, $request->quotations->count());
        $this->assertSame('Metro Office Solutions', $request->selectedSupplier->name);
        $this->assertTrue($request->quotations->where('is_recommended', true)->firstOrFail()->is_approved);
        $request->quotations->where('is_approved', true)->firstOrFail()->update([
            'bank_details' => 'Metro Bank | Metro Office Solutions | 0011223344',
        ]);
        $request->quotations->where('is_approved', false)->firstOrFail()->update([
            'bank_details' => 'Unapproved Supplier Bank | 9988776655',
        ]);
        $this->assertCount(3, $request->attachments);
        $this->assertTrue($request->approvals->contains(fn ($approval) => $approval->approval_level === 'PRESIDENT_SUPPLIER' && $approval->action === 'APPROVE'));
        $this->assertNull($request->purchaseOrder);
        $this->assertNull($request->budgetRequest);

        $queueUrl = route('request-flow.index', ['stage' => 'purchase-order']);
        $detailUrl = route('purchase-requests.show', [
            'purchaseRequest' => $request,
            'from_stage' => 'purchase-order',
        ]);
        $this->actingAs($purchasing)->get($queueUrl)
            ->assertOk()
            ->assertSee($request->request_number)
            ->assertSee('Sample Team Leader')
            ->assertSee('class="btn btn-sm btn-primary" href="'.$queueUrl.'"', false)
            ->assertSee('href="'.$detailUrl.'"', false);

        $this->get($detailUrl)
            ->assertOk()
            ->assertSee('Ready to create purchase order')
            ->assertSee('Request at a glance')
            ->assertSee('Approved quotation')
            ->assertSee('Create purchase order')
            ->assertSee('class="po-print-summary"', false)
            ->assertSee('Request summary')
            ->assertSee('Metro Office Solutions')
            ->assertSee('Wireless keyboard')
            ->assertSee('Wireless mouse')
            ->assertSee('HDMI cable')
            ->assertSee('Campaign')
            ->assertSee('Department')
            ->assertSee('href="'.$queueUrl.'"', false);

        $postPoDetailUrl = route('purchase-requests.show', [
            'purchaseRequest' => $request,
            'from_stage' => 'budget-request',
        ]);
        $campaign = Campaign::query()->where('name', 'AC MOBILITY')->firstOrFail();

        $this->post(route('purchase-orders.store', [
            'purchaseRequest' => $request,
            'from_stage' => 'purchase-order',
        ]), [
            'priority_level' => 'URGENT',
            'expected_delivery_date' => now()->addDays(14)->toDateString(),
            'remarks' => 'Generated from the PO-ready demo request.',
            'campaign_id' => $campaign->id,
            'account_type' => 'ACA',
            'transaction_type' => 'ONLINE',
            'fund_source' => 'MAIN_FUND',
            'source_bank_account' => 'Operating Account',
            'main_fund' => 'Operating Fund',
            'account_number' => 'DEMO-ACCOUNT-001',
            'account_name' => 'Demo Supplier Account',
            'payment_terms' => 'Due on delivery',
            'payment_details' => 'Pay after delivery',
            'signature_name' => $purchasing->name,
            'signature_data' => $this->signatureData(),
        ])->assertRedirect($postPoDetailUrl)
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('success')
            ->assertSessionMissing('purchase_order_created');

        $request->refresh();
        $this->assertNotNull($request->purchaseOrder);
        $this->assertSame('URGENT', $request->priority_level);
        $this->assertSame(3, $request->purchaseOrder->items()->count());
        $this->assertSame('19480.00', $request->purchaseOrder->total_amount);
        $this->assertSame($request->selected_supplier_id, $request->purchaseOrder->supplier_id);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $request->id,
            'action' => 'PURCHASE_ORDER_CREATED',
        ]);

        $budgetDetailUrl = route('purchase-requests.show', [
            'purchaseRequest' => $request,
            'from_stage' => 'budget-request',
        ]);
        $budgetQueueUrl = route('budget.index');
        $this->get($budgetDetailUrl)
            ->assertOk()
            ->assertSee('Submitted to Checker.')
            ->assertSee($poChecker->name)
            ->assertSee('Your preparation stage is closed')
            ->assertDontSee('View Purchase Order')
            ->assertDontSee('Print after approval');
        $this->assertTrue($request->purchaseOrder->approvalFor(PurchaseOrderApprovalStage::PreparedBy)->hasValidSignature());

        // The signature drawn in each action form is saved by the action itself.
        $this->actingAs($poChecker)->get(route('purchase-requests.show', $request))->assertOk()->assertSee('It is saved automatically when you select Check Purchase Order.')->assertDontSee('Save Checked By Signature');
        $this->actingAs($poChecker)->post(route('purchase-orders.check', $request), ['remarks' => 'Checked.', 'signature_name' => $poChecker->name, 'signature_data' => $this->signatureData()])->assertSessionHasNoErrors();
        $this->actingAs($poChecker)->get(route('purchase-requests.show', $request))->assertOk()->assertSee('Submitted to Noter.')->assertSee($poNoter->name)->assertSee('Your checking stage is closed')->assertDontSee('Check Purchase Order');
        $this->actingAs($poNoter)->post(route('purchase-orders.acknowledge', $request), ['remarks' => 'Noted.', 'signature_name' => $poNoter->name, 'signature_data' => $this->signatureData()])->assertSessionHasNoErrors();
        $this->actingAs($poNoter)->get(route('purchase-requests.show', $request))->assertOk()->assertSee('Submitted to Approver.')->assertSee($poApprover->name)->assertSee('Your noting stage is closed');
        $this->actingAs($poApprover)->post(route('purchase-orders.approve', $request), ['decision' => 'APPROVE', 'signature_name' => $poApprover->name, 'signature_data' => $this->signatureData()])->assertSessionHasNoErrors();
        if (! $poApprover->hasAnyRole([RoleSlug::Admin->value, RoleSlug::Purchasing->value])) {
            $this->actingAs($poApprover)->get(route('purchase-requests.show', $request))->assertOk()->assertSee('Purchase Order approved.')->assertSee('Your approval stage is closed');
        }
        $request->refresh();
        $this->assertSame('APPROVED', $request->purchaseOrder->status);
        foreach ([PurchaseOrderApprovalStage::CheckedBy, PurchaseOrderApprovalStage::NotedBy, PurchaseOrderApprovalStage::ApprovedBy] as $signedStage) {
            $this->assertTrue($request->purchaseOrder->approvalFor($signedStage)->hasValidSignature());
            $this->assertSame('APPROVED', $request->purchaseOrder->approvalFor($signedStage)->status);
        }

        // Completed signers can edit only their own task, and every edit is logged.
        $order = $request->purchaseOrder;
        $this->actingAs($poChecker)->getJson(route('purchase-orders.preview', $order))
            ->assertOk()->assertJsonPath('can_edit_task', true)
            ->assertJson(fn ($json) => $json->where('task_edit_html', fn ($html) => str_contains($html, 'stage_remarks[PO_CHECKED_BY]') && ! str_contains($html, 'stage_remarks[PO_NOTED_BY]') && ! str_contains($html, 'name="account_number"'))->etc());
        $this->actingAs($poChecker)->patchJson(route('purchase-orders.task.update', $request), ['stage_remarks' => [PurchaseOrderApprovalStage::CheckedBy->value => 'Checked again.']])
            ->assertOk()->assertJsonPath('message', 'Purchase Order task updated.');
        $this->assertSame('Checked again.', $order->approvalFor(PurchaseOrderApprovalStage::CheckedBy)->fresh()->remarks);
        $this->assertSame('Checked again.', $order->fresh()->checker_remarks);
        $this->actingAs($poChecker)->patchJson(route('purchase-orders.task.update', $request), ['stage_remarks' => [PurchaseOrderApprovalStage::NotedBy->value => 'Not my task.']])->assertForbidden();
        $this->actingAs($poChecker)->patchJson(route('purchase-orders.task.update', $request), ['account_number' => 'HIJACK', 'payment_terms' => 'Net 1'])->assertOk();
        $this->assertNotSame('HIJACK', $order->fresh()->account_number);
        $originalAccountNumber = $order->fresh()->account_number;
        $this->actingAs($purchasing)->patchJson(route('purchase-orders.task.update', $request), ['account_number' => 'ACC-EDITED-01', 'payment_terms' => (string) $order->fresh()->payment_terms])->assertOk();
        $this->assertSame('ACC-EDITED-01', $order->fresh()->account_number);
        $editLog = AuditLog::query()->where('action', 'PO_TASK_EDITED')->latest('id')->firstOrFail();
        $this->assertSame($purchasing->id, $editLog->user_id);
        $this->assertSame('Payment account number', $editLog->metadata['changes'][0]['field']);
        $this->actingAs($poNoter)->getJson(route('purchase-orders.preview', $order))
            ->assertOk()
            ->assertJson(fn ($json) => $json->where('task_edit_html', fn ($html) => str_contains($html, 'ACC-EDITED-01') && str_contains($html, $purchasing->name) && str_contains($html, 'Checked again.'))
                ->where('html', fn ($html) => str_contains($html, 'ACC-EDITED-01') && str_contains($html, 'Delivery:') && str_contains($html, 'Payment details:') && str_contains($html, 'Remarks:'))
                ->etc());
        $this->actingAs($poApprover)->get(route('dashboard'))->assertOk()
            ->assertSee('data-po-preview-row', false)
            ->assertSee(route('purchase-orders.preview', $order), false)
            ->assertSee('purchase-order-task-edit-modal', false)
            ->assertDontSee('Recent requests');
        $this->actingAs($purchasing)->patchJson(route('purchase-orders.task.update', $request), ['account_number' => $originalAccountNumber, 'payment_terms' => (string) $order->fresh()->payment_terms])->assertOk();
        $this->assertSame(2, AuditLog::query()->where('action', 'PO_TASK_EDITED')->where('user_id', $purchasing->id)->count());        $this->actingAs($purchasing)
            ->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee($request->purchaseOrder->po_number)
            ->assertSee('>Preview<', false);
        $this->actingAs($purchasing)
            ->get(route('purchase-orders.preview', $request->purchaseOrder))
            ->assertOk()
            ->assertJsonPath('po_number', $request->purchaseOrder->po_number)
            ->assertJsonPath('template', 'official-purchase-order')
            ->assertJsonPath('is_processed', true)
            ->assertJsonPath('can_print', true)
            ->assertJsonPath('html', fn ($html) => str_contains($html, 'Prepared by') && str_contains($html, 'Checked by') && str_contains($html, 'Noted by') && str_contains($html, 'Approved by') && str_contains($html, $poApprover->name));

        $this->get($budgetQueueUrl)
            ->assertOk()
            ->assertSee('Priority Level')
            ->assertSee('PO Code')
            ->assertSee('Supplier Bank Details')
            ->assertSeeInOrder(['<th>PO Code</th>', '<th>Supplier Bank Details</th>', '<th>Requestor</th>'], false)
            ->assertSee('Prepare Budget Request')
            ->assertDontSee('data-select-all-budgets', false)
            ->assertDontSee('data-budget-request-select', false)
            // Priority is chosen at the Final budget, so the Budget Maker queue only shows it.
            ->assertDontSee('data-priority-select', false)
            ->assertSee($request->purchaseOrder->po_number)
            ->assertSee('Metro Bank | Metro Office Solutions | 0011223344')
            ->assertDontSee('Unapproved Supplier Bank | 9988776655')
            ->assertSee('Urgent')
            ->assertDontSee('Authorized Action');

        $otherPurchasing = $this->user(RoleSlug::Purchasing);
        $this->actingAs($otherPurchasing)->patch(route('budget.priority.update', $request), [
            'priority_level' => 'LOW',
        ])->assertForbidden();

        $this->actingAs($purchasing)->patch(route('budget.priority.update', $request), [
            'priority_level' => 'HIGH',
        ])->assertRedirect($budgetQueueUrl)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Priority level recorded successfully.');

        $this->assertSame('HIGH', $request->refresh()->priority_level);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $request->id,
            'action' => 'PRIORITY_LEVEL_UPDATED',
        ]);

        $this->get(route('purchase-orders.document', ['purchaseRequest' => $request, 'mode' => 'export']))
            ->assertOk()
            ->assertSee('Wireless keyboard')
            ->assertSee('Wireless mouse')
            ->assertSee('HDMI cable')
            ->assertSee('PURCHASE ORDER')
            ->assertSee($request->purchaseOrder->po_number)
            ->assertSee('DEMO-ACCOUNT-001')
            ->assertSee('Demo Supplier Account')
            ->assertSee('Due on delivery')
            ->assertSee('Net of VAT')
            ->assertSee('19,480.00')
            ->assertSee('Prepared by')
            ->assertSee('Checked by')
            ->assertSee('Noted by')
            ->assertSee($poApprover->name)
            ->assertDontSee('ACCOUNT TYPE')
            ->assertDontSee('Main Fund');
    }

    public function test_legacy_purchase_order_document_requires_linked_request_access_without_creating_workflow_rows(): void
    {
        $owner = $this->user(RoleSlug::TeamLeader);
        $unauthorized = $this->user(RoleSlug::Requestor);
        $purchaseRequest = $this->createRequest($owner, true);
        $supplier = Supplier::query()->create(['name' => 'Legacy PO Document Supplier', 'is_active' => true]);
        $purchaseOrder = $purchaseRequest->purchaseOrder()->create([
            'po_number' => 'PO-LEGACY-DOCUMENT-'.$purchaseRequest->id,
            'supplier_id' => $supplier->id,
            'created_by' => $owner->id,
            'issued_at' => now()->toDateString(),
            'status' => 'ISSUED',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
        ]);
        $purchaseOrder->items()->create([
            'purchase_request_item_id' => $purchaseRequest->items()->value('id'),
            'description' => 'Legacy document item',
            'quantity' => 1,
            'unit' => 'piece',
            'unit_price' => 100,
            'total_price' => 100,
        ]);

        $documentUrl = route('purchase-orders.document', ['purchaseRequest' => $purchaseRequest, 'mode' => 'export']);

        $this->actingAs($owner)->get($documentUrl)
            ->assertOk()
            ->assertSee($purchaseOrder->po_number);
        $this->actingAs($unauthorized)->get($documentUrl)->assertForbidden();

        $this->assertDatabaseCount('purchase_order_approvals', 0);
        $this->assertDatabaseCount('po_role_assignments', 0);
    }
    public function test_budget_request_queue_accepts_br_owned_fields_and_links_eligible_issued_pos_to_details(): void
    {
        $this->seed([SampleUserSeeder::class, BudgetRequestDemoSeeder::class]);
        $purchasing = User::query()->where('email', 'purchasing@example.com')->firstOrFail();

        $this->actingAs($purchasing)
            ->get(route('request-flow.index', ['stage' => 'budget-request']))
            ->assertRedirect(route('dashboard'));

        $this->get(route('budget.index'))
            ->assertOk()
            ->assertSee('Budget Request Preparation')
            ->assertSee('How to prepare a Budget Request')
            ->assertDontSee('REQ-BR-DEMO-001')
            ->assertDontSee('REQ-BR-DEMO-002')
            ->assertDontSee('REQ-BR-DEMO-003')
            ->assertDontSee('PO-BR-DEMO-001')
            ->assertDontSee('PO-BR-DEMO-002')
            ->assertDontSee('PO-BR-DEMO-003')
            ->assertSee('0 PO(s)')
            ->assertDontSee('data-budget-request-select', false)
            ->assertDontSee('data-add-budget', false);

        $purchaseRequest = PurchaseRequest::query()->where('request_number', 'REQ-BR-DEMO-001')->firstOrFail();
        $this->get(route('budget.create', ['purchase_request_id' => $purchaseRequest->id]))
            ->assertOk()
            ->assertSee('Campaign Source Account')
            ->assertDontSee('Type of Transaction')
            ->assertDontSee('missing required PO details');
        // Budget Information is completed by Finance at final approval, not here.
        $this->post(route('budget.store'), ['purchase_request_id' => $purchaseRequest->id])
            ->assertSessionHasErrors(['campaign_id'])
            ->assertSessionDoesntHaveErrors(['account_type', 'transaction_type', 'fund_source', 'main_fund', 'purchase_request_id']);

        $campaign = Campaign::query()->where('name', 'AC MOBILITY')->firstOrFail();
        $purchaseRequest->purchaseOrder->update([
            'budget_campaign_id' => $campaign->id,
            'budget_campaign_account_number' => $campaign->account_number,
            'budget_account_type' => 'ACA',
            'budget_transaction_type' => 'ONLINE',
            'budget_fund_source' => 'MAIN_FUND',
            'budget_source_bank_account' => 'Operating Account',
            'main_fund' => 'Operating Fund',
            'account_number' => '001-234567-89',
            'payment_details' => 'Pay after delivery',
        ]);
        $purchaseRequest->purchaseOrder->update([
            'budget_account_type' => null,
            'main_fund' => null,
        ]);

        $this->get(route('budget.index'))
            ->assertOk()
            ->assertSee('PO-BR-DEMO-001')
            ->assertSee('href="'.route('purchase-orders.document', $purchaseRequest).'">PO-BR-DEMO-001</a>', false);
    }

    public function test_each_purchase_order_creates_its_own_budget_request(): void
    {
        $this->seed(ProcurementWorkflowDemoSeeder::class);
        $purchasing = User::query()->where('email', 'purchasing@example.com')->firstOrFail();
        $requests = PurchaseRequest::query()
            ->where('status', PurchaseRequestStatus::ForBudgetProcessing->value)
            ->whereHas('purchaseOrder')
            ->with('purchaseOrder')
            ->orderBy('id')
            ->limit(2)
            ->get();

        $this->assertCount(2, $requests);
        BudgetRequest::query()
            ->whereIn('purchase_request_id', $requests->pluck('id'))
            ->get()
            ->each(fn (BudgetRequest $budget) => $budget->forceDelete());
        $initialBudgetCount = BudgetRequest::query()->count();
        $campaign = Campaign::query()->where('name', 'AC MOBILITY')->firstOrFail();
        $secondCampaign = Campaign::query()->where('name', 'AIA 1')->firstOrFail();
        foreach ($requests as $purchaseRequest) {
            $purchaseRequest->purchaseOrder->update(['status' => 'ISSUED']);
        }

        $this->actingAs($purchasing)
            ->get(route('budget.create', ['purchase_request_id' => $requests[0]->id]))
            ->assertOk()
            ->assertSeeInOrder(['Campaign Source Account', 'Purchase Order Details'])
            ->assertSee($requests[0]->purchaseOrder->po_number)
            ->assertDontSee($requests[1]->purchaseOrder->po_number)
            ->assertSee('name="purchase_request_id"', false)
            ->assertDontSee('name="purchase_request_ids[]"', false);
        $this->get(route('budget.create', ['purchase_request_ids' => $requests->pluck('id')->all()]))
            ->assertSessionHasErrors(['purchase_request_id', 'purchase_request_ids']);

        $payload = [
            'purchase_request_id' => $requests[0]->id,
            'campaign_id' => $campaign->id,
            ...$this->workflowSignature($purchasing),
            'account_type' => 'OWN_ACCOUNT',
            'transaction_type' => 'CHECK',
            'fund_source' => 'MAIN_FUND',
            'main_fund' => 'Operating Fund A',
        ];
        $this->post(route('budget.store'), [...$payload, 'purchase_request_ids' => $requests->pluck('id')->all()])
            ->assertSessionHasErrors('purchase_request_ids');
        $this->assertSame($initialBudgetCount, BudgetRequest::query()->count());

        $this->post(route('budget.store'), $payload)
            ->assertRedirect(route('purchase-requests.show', $requests[0]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Budget Request created. It is now waiting for Finance.');

        $firstBudget = BudgetRequest::query()->where('purchase_request_id', $requests[0]->id)
            ->with(['allocations', 'lines'])->firstOrFail();
        $this->assertSame($requests[0]->purchaseOrder->id, $firstBudget->purchase_order_id);
        $this->assertCount(1, $firstBudget->allocations);
        $this->assertCount(1, $firstBudget->lines);
        $this->assertEquals((float) $requests[0]->purchaseOrder->total_amount, (float) $firstBudget->amount);
        $this->assertSame($campaign->id, $firstBudget->campaign_id);
        $this->assertSame(PurchaseRequestStatus::AwaitingPresidentFinalApproval, $requests[0]->fresh()->status);
        $this->assertSame(PurchaseRequestStatus::ForBudgetProcessing, $requests[1]->fresh()->status);

        $this->post(route('budget.store'), [
            ...$payload,
            'purchase_request_id' => $requests[1]->id,
            'campaign_id' => $secondCampaign->id,
        ])->assertSessionHasNoErrors();
        $secondBudget = BudgetRequest::query()->where('purchase_request_id', $requests[1]->id)
            ->with(['allocations', 'lines'])->firstOrFail();
        $this->assertNotSame($firstBudget->id, $secondBudget->id);
        $this->assertCount(1, $secondBudget->allocations);
        $this->assertCount(1, $secondBudget->lines);
        $this->assertSame($secondCampaign->id, $secondBudget->campaign_id);
        $this->assertSame($initialBudgetCount + 2, BudgetRequest::query()->count());

        $approver = $this->user(RoleSlug::ProcurementStaff);
        $this->actingAs($approver)->post(route('budget.finalize.store'), [
            'ids' => [$firstBudget->id], 'priority_level' => 'NORMAL', 'signature_name' => $approver->name, 'signature_data' => $this->signatureData(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForPayment, $requests[0]->fresh()->status);
        $this->assertSame(PurchaseRequestStatus::AwaitingPresidentFinalApproval, $requests[1]->fresh()->status);
        $this->assertSame('APPROVED', $firstBudget->fresh()->final_approval_status);
        $this->assertNull($secondBudget->fresh()->final_approval_status);
    }
    public function test_it_screening_request_opens_directly_and_records_the_screener_without_changing_requestor(): void
    {
        $requestor = $this->user(RoleSlug::TeamLeader);
        $itStaff = $this->user(RoleSlug::ItStaff);
        $otherItStaff = $this->user(RoleSlug::ItStaff);
        $request = $this->createRequest($requestor, true, ['request_type' => 'IT']);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForItScreening,
            'current_responsible_role_id' => $this->roleId(RoleSlug::ItStaff),
        ])->save();

        $this->actingAs($itStaff)
            ->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertDontSee('Claim as IT')
            ->assertSee('id="screening-actions"', false);

        $this->post(route('request-flow.screening.store', $request), [
            ...$this->workflowSignature($itStaff),
            'decision' => 'UNAVAILABLE',
            'evidence' => 'Not available',
        ])->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame($requestor->id, $request->requestor_id);
        $this->assertSame($itStaff->id, $request->assigned_user_id);

        $this->actingAs($otherItStaff)
            ->post(route('request-flow.screening.store', $request), [
                ...$this->workflowSignature(auth()->user()),
                'decision' => 'UNAVAILABLE',
                'evidence' => 'Not available',
            ])
            ->assertForbidden();
    }

    public function test_premises_screening_request_can_be_claimed_without_changing_the_original_requestor(): void
    {
        $requestor = $this->user(RoleSlug::TeamLeader);
        $premisesStaff = $this->user(RoleSlug::PremisesStaff);
        $request = $this->createRequest($requestor, true, ['request_type' => 'PREMISES']);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForPremisesScreening,
            'current_responsible_role_id' => $this->roleId(RoleSlug::PremisesStaff),
        ])->save();

        $this->actingAs($premisesStaff)
            ->post(route('request-flow.claim', $request))
            ->assertRedirect();

        $request->refresh();
        $this->assertSame($requestor->id, $request->requestor_id);
        $this->assertSame($premisesStaff->id, $request->assigned_user_id);
    }

    public function test_claim_endpoint_rejects_team_leaders_cross_team_staff_and_alternate_methods(): void
    {
        $itStaff = $this->user(RoleSlug::ItStaff);
        $premisesStaff = $this->user(RoleSlug::PremisesStaff);
        $purchasing = $this->user(RoleSlug::Purchasing);
        $itRequest = $this->screeningRequest('IT');
        $premisesRequest = $this->screeningRequest('PREMISES');

        foreach ([$itRequest, $premisesRequest] as $request) {
            $this->actingAs($request->requestor)
                ->get(route('purchase-requests.show', $request))
                ->assertOk()
                ->assertDontSee('action="'.route('request-flow.claim', $request).'"', false);

            $this->post(route('request-flow.claim', $request), [
                'requestor_id' => $request->requestor_id,
                'assigned_user_id' => $request->requestor_id,
                'request_type' => $request->request_type,
            ])->assertForbidden();

            $this->actingAs($purchasing)
                ->post(route('request-flow.claim', $request))
                ->assertForbidden();
        }

        $this->actingAs($itStaff)->post(route('request-flow.claim', $itRequest))->assertRedirect();
        $this->actingAs($premisesStaff)->post(route('request-flow.claim', $premisesRequest))->assertRedirect();
        $this->post(route('request-flow.claim', $itRequest))->assertForbidden();
        $this->actingAs($itStaff)->post(route('request-flow.claim', $premisesRequest))->assertForbidden();
        $this->actingAs($itStaff)->put(route('request-flow.claim', $itRequest))->assertStatus(405);
        $this->patch(route('request-flow.claim', $itRequest))->assertStatus(405);

        $this->assertSame($itStaff->id, $itRequest->fresh()->assigned_user_id);
        $this->assertSame($premisesStaff->id, $premisesRequest->fresh()->assigned_user_id);
    }

    public function test_team_specific_request_can_only_be_claimed_by_its_team(): void
    {
        $request = $this->screeningRequest('IT');
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForPremisesScreening,
            'current_responsible_role_id' => $this->roleId(RoleSlug::PremisesStaff),
        ])->save();

        $it = $this->user(RoleSlug::ItStaff);
        $premises = $this->user(RoleSlug::PremisesStaff);
        $this->actingAs($it)->post(route('request-flow.screening.store', $request), ['decision' => 'AVAILABLE'])
            ->assertForbidden();
        $this->post(route('request-flow.claim', $request))->assertForbidden();
        $this->get(route('purchase-requests.show', $request))->assertForbidden();
        $this->actingAs($premises)->post(route('request-flow.claim', $request))->assertRedirect();

        $request->refresh();
        $this->assertSame($premises->id, $request->assigned_user_id);
        $this->assertSame('PREMISES', $request->request_type);
        $this->assertSame(PurchaseRequestStatus::ForPremisesScreening, $request->status);
        $this->assertFalse($request->screening()->exists());
    }

    public function test_screener_can_unclaim_before_deciding_and_return_request_to_the_shared_queue(): void
    {
        $request = $this->approvedForTeamClassification();
        $it = $this->user(RoleSlug::ItStaff);
        $otherIt = $this->user(RoleSlug::ItStaff);
        $premises = $this->user(RoleSlug::PremisesStaff);

        $this->actingAs($it)->post(route('request-flow.claim', $request))->assertRedirect();
        $this->get(route('purchase-requests.show', $request))
            ->assertOk()->assertSee('Unclaim request');
        $this->get(route('dashboard'))->assertOk()
            ->assertSee(route('request-flow.unclaim', $request), false)
            ->assertSee('Unclaim');
        $this->actingAs($otherIt)->delete(route('request-flow.unclaim', $request))->assertForbidden();
        $this->actingAs($it)->delete(route('request-flow.unclaim', $request))
            ->assertRedirect(route('dashboard'));

        $request->refresh();
        $this->assertNull($request->assigned_user_id);
        $this->assertSame(PurchaseRequestStatus::ForTeamScreening, $request->status);
        $this->assertSame('UNCLASSIFIED', $request->request_type);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $request->id,
            'action' => 'SCREENING_REQUEST_UNCLAIMED',
            'new_status' => PurchaseRequestStatus::ForTeamScreening->value,
        ]);
        $this->actingAs($premises)->get(route('dashboard'))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->contains(fn ($action) => $action['request']->is($request)));
    }

    public function test_unclaim_keeps_team_specific_queue_and_is_blocked_after_screening(): void
    {
        $request = $this->screeningRequest('PREMISES');
        $staff = $this->user(RoleSlug::PremisesStaff);
        $this->actingAs($staff)->post(route('request-flow.claim', $request))->assertRedirect();
        $this->delete(route('request-flow.unclaim', $request))->assertRedirect(route('dashboard'));
        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::ForPremisesScreening, $request->status);
        $this->assertSame('PREMISES', $request->request_type);
        $this->assertNull($request->assigned_user_id);
        $this->actingAs($this->user(RoleSlug::ItStaff))->get(route('purchase-requests.show', $request))->assertForbidden();

        $this->actingAs($staff)->post(route('request-flow.claim', $request))->assertRedirect();
        $this->post(route('request-flow.screening.store', $request), [
            ...$this->workflowSignature($staff),
            'decision' => 'UNAVAILABLE',
            'evidence' => 'No suitable stock.',
        ])->assertSessionHasNoErrors();
        $this->delete(route('request-flow.unclaim', $request))->assertForbidden();
    }

    public function test_it_and_premises_screening_pages_offer_available_inventory_without_manual_asset_controls(): void
    {
        foreach ([[RoleSlug::ItStaff, 'IT'], [RoleSlug::PremisesStaff, 'PREMISES']] as [$role, $requestType]) {
            $staff = $this->user($role);
            $request = $this->screeningRequest($requestType);
            $stock = $this->inventoryItem($request, 3);

            $this->actingAs($staff)->post(route('request-flow.claim', $request))->assertRedirect();
            $this->get(route('purchase-requests.show', $request))
                ->assertOk()
                ->assertDontSee('data-bs-target="#availableModal"', false)
                ->assertDontSee("Add {$requestType} Asset")
                ->assertDontSee('id="add-screening-asset"', false)
                ->assertSee('Inventory Available')
                ->assertSee($stock->inventory_code)
                ->assertSee('data-sku-input', false)
                ->assertSee($stock->sku)
                ->assertSee('Sign &amp; Release items', false)
                ->assertSee('Inventory Unavailable');
        }
    }

    public function test_screening_cannot_release_stock_from_the_other_team(): void
    {
        foreach ([[RoleSlug::ItStaff, 'IT'], [RoleSlug::PremisesStaff, 'PREMISES']] as [$role, $type]) {
            $staff = $this->user($role);
            $request = $this->screeningRequest($type);
            $ownStock = $this->inventoryItem($request, 3);
            $otherRole = $role === RoleSlug::ItStaff ? RoleSlug::PremisesStaff : RoleSlug::ItStaff;
            $otherStock = InventoryItem::query()->create([
                'inventory_code' => InventoryItem::nextCode(),
                'logged_by' => $this->user($otherRole)->id,
                'description' => 'Other team stock '.$request->id,
                'quantity' => 3,
                'unit' => 'pc',
                'location' => 'Other stockroom',
                'status' => 'LOGGED',
                'logged_at' => now(),
            ]);

            $this->actingAs($staff)->post(route('request-flow.claim', $request))->assertRedirect();
            $this->get(route('purchase-requests.show', $request))->assertOk()
                ->assertSee($ownStock->sku)
                ->assertDontSee($otherStock->sku);
            $this->post(route('request-flow.screening.store', $request), [
                ...$this->workflowSignature($staff),
                'decision' => 'AVAILABLE',
                'assets' => [$this->screeningAsset(
                    $request,
                    $otherStock,
                    1,
                    $type === 'IT' ? 'IT_SELF_INVENTORY' : 'MANUAL',
                )],
            ])->assertSessionHasErrors('assets');
            $this->assertSame('3.00', $otherStock->fresh()->quantity);
            $this->assertFalse($request->screening()->exists());
        }
    }

    public function test_selected_inventory_deducts_stock_sets_status_and_creates_endorsement(): void
    {
        $it = $this->user(RoleSlug::ItStaff);
        $depletedRequest = $this->screeningRequest('IT');
        $depletedInventory = $this->inventoryItem($depletedRequest, 2);
        $this->actingAs($it)->post(route('request-flow.claim', $depletedRequest))->assertRedirect();

        $this->post(route('request-flow.screening.store', $depletedRequest), [
            ...$this->workflowSignature(auth()->user()),
            'decision' => 'AVAILABLE',
            'assets' => [$this->screeningAsset($depletedRequest, $depletedInventory, 2)],
        ])->assertSessionHasNoErrors();

        $this->assertSame(PurchaseRequestStatus::Fulfilled, $depletedRequest->fresh()->status);
        $this->assertSame('0.00', $depletedInventory->fresh()->quantity);
        $this->assertSame('RELEASED', $depletedInventory->fresh()->status);
        $this->assertDatabaseHas('inventory_releases', ['inventory_item_id' => $depletedInventory->id, 'released_by' => $it->id, 'quantity' => 2]);
        $inventoryStaff = $this->user(RoleSlug::InventoryStaff);
        $asset = $depletedRequest->screening->assets()->firstOrFail();
        $this->post(route('purchase-requests.inventory-release', $depletedRequest), [
            ...$this->workflowSignature($inventoryStaff),
            'inventory' => [$asset->id => $depletedInventory->id],
            'released_to' => $depletedRequest->requestor->name,
        ])->assertForbidden();
        $this->assertSame(1, $depletedInventory->releases()->count());
        $admin = $this->user(RoleSlug::Admin);
        $this->actingAs($admin)->post(route('purchase-requests.inventory-release', $depletedRequest), [
            ...$this->workflowSignature($admin),
            'inventory' => [$asset->id => $depletedInventory->id],
            'released_to' => $depletedRequest->requestor->name,
        ])->assertForbidden();
        $this->assertDatabaseHas('endorsements', [
            'purchase_request_id' => $depletedRequest->id,
            'inventory_item_id' => $depletedInventory->id,
            'requestor_id' => $depletedRequest->requestor_id,
            'status' => 'PROVIDED',
        ]);

        $partialRequest = $this->screeningRequest('IT');
        $partialInventory = $this->inventoryItem($partialRequest, 5);
        $this->actingAs($it)->post(route('request-flow.claim', $partialRequest))->assertRedirect();
        $this->post(route('request-flow.screening.store', $partialRequest), [
            ...$this->workflowSignature(auth()->user()),
            'decision' => 'AVAILABLE',
            'assets' => [$this->screeningAsset($partialRequest, $partialInventory, 2)],
        ])->assertSessionHasNoErrors();

        $this->assertSame(PurchaseRequestStatus::Fulfilled, $partialRequest->fresh()->status);
        $this->assertSame('3.00', $partialInventory->fresh()->quantity);
        $this->assertSame('LOGGED', $partialInventory->fresh()->status);
        $this->assertSame(1, $partialRequest->endorsements()->count());
    }

    public function test_signed_screening_releases_all_selected_rows_atomically(): void
    {
        $it = $this->user(RoleSlug::ItStaff);
        $request = $this->screeningRequest('IT');
        $stock = $this->inventoryItem($request, 5);
        $this->actingAs($it)->post(route('request-flow.claim', $request))->assertRedirect();
        $first = $this->screeningAsset($request, $stock, 2);
        $second = [...$first, 'asset_tag' => 'SECOND-ASSET'];
        $this->post(route('purchase-requests.inventory-release', $request), [
            ...$this->workflowSignature($it),
        ])->assertForbidden();
        $this->post(route('request-flow.screening.store', $request), [
            ...$this->workflowSignature($it), 'decision' => 'AVAILABLE', 'assets' => [$first, $second],
        ])->assertSessionHasNoErrors();
        $this->assertSame('1.00', $stock->fresh()->quantity);
        $this->assertSame(2, $stock->releases()->count());
        $this->assertSame(PurchaseRequestStatus::Fulfilled, $request->fresh()->status);
        $this->assertDatabaseHas('request_approvals', [
            'purchase_request_id' => $request->id, 'user_id' => $it->id,
            'action' => 'INVENTORY_AVAILABLE', 'signature_data' => $this->workflowSignature($it)['signature_data'],
        ]);
    }

    public function test_inventory_screening_rejects_nonpositive_insufficient_and_aggregate_overdraws(): void
    {
        $it = $this->user(RoleSlug::ItStaff);
        $request = $this->screeningRequest('IT');
        $inventory = $this->inventoryItem($request, 5);
        $this->actingAs($it)->post(route('request-flow.claim', $request))->assertRedirect();

        $this->post(route('request-flow.screening.store', $request), [
            ...$this->workflowSignature(auth()->user()),
            'decision' => 'AVAILABLE',
            'assets' => [$this->screeningAsset($request, $inventory, 0)],
        ])->assertSessionHasErrors('assets.0.quantity');

        $this->post(route('request-flow.screening.store', $request), [
            ...$this->workflowSignature(auth()->user()),
            'decision' => 'AVAILABLE',
            'assets' => [$this->screeningAsset($request, $inventory, 6)],
        ])->assertSessionHasErrors('assets');

        $first = $this->screeningAsset($request, $inventory, 3);
        $second = $this->screeningAsset($request, $inventory, 3);
        $second['asset_tag'] .= '-2';
        $second['item_code'] .= '-2';
        $this->post(route('request-flow.screening.store', $request), [
            ...$this->workflowSignature(auth()->user()),
            'decision' => 'AVAILABLE',
            'assets' => [$first, $second],
        ])->assertSessionHasErrors('assets');

        $this->assertSame('5.00', $inventory->fresh()->quantity);
        $this->assertSame('LOGGED', $inventory->fresh()->status);
        $this->assertFalse($request->screening()->exists());
        $this->assertSame(0, $request->endorsements()->count());
    }

    public function test_locked_inventory_allocation_rejects_a_second_competing_overdraw(): void
    {
        $it = $this->user(RoleSlug::ItStaff);
        $firstRequest = $this->screeningRequest('IT');
        $secondRequest = $this->screeningRequest('IT');
        $inventory = $this->inventoryItem($firstRequest, 5);
        $this->actingAs($it)->post(route('request-flow.claim', $firstRequest))->assertRedirect();
        $this->post(route('request-flow.claim', $secondRequest))->assertRedirect();

        $this->post(route('request-flow.screening.store', $firstRequest), [
            ...$this->workflowSignature(auth()->user()),
            'decision' => 'AVAILABLE',
            'assets' => [$this->screeningAsset($firstRequest, $inventory, 3)],
        ])->assertSessionHasNoErrors();

        $this->post(route('request-flow.screening.store', $secondRequest), [
            ...$this->workflowSignature(auth()->user()),
            'decision' => 'AVAILABLE',
            'assets' => [$this->screeningAsset($secondRequest, $inventory, 3)],
        ])->assertSessionHasErrors('assets');

        $this->assertSame('2.00', $inventory->fresh()->quantity);
        $this->assertSame('LOGGED', $inventory->fresh()->status);
        $this->assertSame(1, $firstRequest->endorsements()->count());
        $this->assertSame(0, $secondRequest->endorsements()->count());
        $this->assertFalse($secondRequest->screening()->exists());
        $this->assertSame(PurchaseRequestStatus::ForItScreening, $secondRequest->fresh()->status);
    }

    public function test_unavailable_inventory_does_not_deduct_or_endorse_and_routes_to_purchasing(): void
    {
        Storage::fake('local');
        $request = $this->screeningRequest('PREMISES');
        $inventory = $this->inventoryItem($request, 4);
        $premises = $this->user(RoleSlug::PremisesStaff);
        $this->actingAs($premises)->post(route('request-flow.claim', $request))->assertRedirect();

        $this->post(route('request-flow.screening.store', $request), [
            ...$this->workflowSignature(auth()->user()),
            'decision' => 'UNAVAILABLE',
            'evidence' => 'No suitable premises stock is available.',
            'quotation_evidence' => UploadedFile::fake()->create('premises-quote.pdf', 10, 'application/pdf'),
            'assets' => [$this->screeningAsset($request, $inventory, 2, 'PREMISES_EXCEL_IMPORT')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(PurchaseRequestStatus::ForProcurement, $request->fresh()->status);
        $this->assertSame($this->roleId(RoleSlug::ProcurementStaff), $request->fresh()->current_responsible_role_id);
        $this->assertSame('4.00', $inventory->fresh()->quantity);
        $this->assertSame('LOGGED', $inventory->fresh()->status);
        $this->assertSame(0, $request->endorsements()->count());
        $this->assertSame(0, $request->screening()->firstOrFail()->assets()->count());
    }

    public function test_request_detail_shows_one_primary_request_information_section_and_honest_empty_quotation_summary(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $request = $this->createRequest($requestor);

        $response = $this->actingAs($requestor)->get(route('purchase-requests.show', $request));

        $response->assertOk()
            ->assertSee('Quotation Summary')
            ->assertSee('request-info-grid', false)
            ->assertSee('request-info-label', false)
            ->assertSee('No supplier quotations are recorded yet.')
            ->assertDontSee('Lowest Price');
        $this->assertSame(1, substr_count($response->getContent(), '>Request Information<'));
    }

    public function test_one_quotation_is_not_labeled_as_lowest_price(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $purchasing = $this->user(RoleSlug::Purchasing);
        $request = $this->createRequest($requestor);
        $supplier = Supplier::query()->create(['name' => 'Only Supplier', 'is_active' => true]);
        $request->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $purchasing->id,
            'item_or_service' => $request->title,
            'price' => 900,
        ]);

        $this->actingAs($requestor)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('One quotation is recorded. At least two like-for-like quotations are needed')
            ->assertDontSee('Lowest Price');
    }

    public function test_quotation_summary_keeps_lowest_price_separate_from_recorded_recommendation(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $purchasing = $this->user(RoleSlug::Purchasing);
        $request = $this->createRequest($requestor);
        $lowestSupplier = Supplier::query()->create(['name' => 'Lowest Supplier', 'is_active' => true]);
        $recommendedSupplier = Supplier::query()->create(['name' => 'Recommended Supplier', 'is_active' => true]);

        $request->quotations()->create([
            'supplier_id' => $lowestSupplier->id,
            'created_by' => $purchasing->id,
            'item_or_service' => $request->title,
            'price' => 800,
        ]);
        $request->quotations()->create([
            'supplier_id' => $recommendedSupplier->id,
            'created_by' => $purchasing->id,
            'item_or_service' => $request->title,
            'price' => 950,
            'remarks' => 'Better warranty and delivery terms.',
            'is_recommended' => true,
        ]);

        $response = $this->actingAs($requestor)->get(route('purchase-requests.show', $request));

        $response->assertOk()
            ->assertSee('Quote creator account')
            ->assertSee($purchasing->name)
            ->assertSee('&lt;'.$purchasing->email.'&gt;', false)
            ->assertSee('Lowest Price')
            ->assertSee('Lowest Supplier')
            ->assertSee('&#8369; 800.00', false)
            ->assertSee('Recommended')
            ->assertSee('Recommended Supplier')
            ->assertSee('Better warranty and delivery terms.')
            ->assertSee('&#8369; 950.00', false);
    }

    public function test_quotation_summary_consolidates_legacy_manual_and_item_linked_rows_for_the_same_quote(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $purchasing = $this->user(RoleSlug::Purchasing);
        $request = $this->createRequest($requestor);
        $requestItem = $request->items()->firstOrFail();
        $recommendedSupplier = Supplier::query()->create(['name' => 'Metro Office Solutions', 'is_active' => true]);
        $otherSupplier = Supplier::query()->create(['name' => 'Other Supplier', 'is_active' => true]);

        $request->quotations()->create([
            'supplier_id' => $recommendedSupplier->id,
            'purchase_request_item_id' => $requestItem->id,
            'created_by' => $purchasing->id,
            'item_or_service' => $request->title,
            'price' => 12500,
            'remarks' => 'Original itemized quotation.',
            'quotation_url' => 'https://vendor.example/quote-a.pdf',
            'is_recommended' => true,
        ]);
        $request->quotations()->create([
            'supplier_id' => $recommendedSupplier->id,
            'created_by' => $purchasing->id,
            'item_or_service' => $request->title,
            'price' => 12500,
            'remarks' => 'Legacy duplicate row with revised notes.',
            'quotation_url' => 'https://vendor.example/quote-b.pdf',
            'is_recommended' => true,
        ]);
        $request->quotations()->create([
            'supplier_id' => $otherSupplier->id,
            'purchase_request_item_id' => $requestItem->id,
            'created_by' => $purchasing->id,
            'item_or_service' => $request->title,
            'price' => 13350,
        ]);

        $response = $this->actingAs($requestor)->get(route('purchase-requests.show', $request));

        $response->assertOk()
            ->assertSee('Lowest Price')
            ->assertSee('Recommended')
            ->assertSee('Metro Office Solutions')
            ->assertSee('&#8369; 12,500.00', false)
            ->assertDontSee('&#8369; 25,000.00', false)
            ->assertSee('1 repeated quotation record retained in the database and consolidated for review.');
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create([
            'role_id' => $this->roleId($role),
            'campaign_id' => in_array($role, [RoleSlug::Requestor, RoleSlug::TeamLeader], true)
                ? Campaign::query()->firstOrFail()->id
                : null,
        ]);
    }

    private function roleId(RoleSlug $role): int
    {
        return Role::query()->where('slug', $role->value)->firstOrFail()->id;
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'action' => 'save_draft',
            'title' => 'Laptop Request',
            'department_campaign' => 'Operations',
            'purpose' => 'Replacement equipment',
            'required_date' => now()->addWeek()->format('Y-m-d'),
            'delivery_location' => 'Main Office',
            'remarks' => 'Please process.',
            'items' => [[
                'description' => 'Business laptop',
                'quantity' => 2,
                'unit' => 'unit',
                'estimated_unit_price' => 250,
                'brand_specifications' => '16 GB RAM',
                'reference_url' => 'https://example.test/laptop',
                'attachment' => UploadedFile::fake()->create('approval.pdf', 20, 'application/pdf'), 'picture' => UploadedFile::fake()->image('item.jpg'),
            ]],
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function createRequest(User $requestor, bool $submit = false, array $overrides = []): PurchaseRequest
    {
        $testRequestType = $overrides['request_type'] ?? null;
        unset($overrides['request_type']);
        $payload = $this->payload($overrides);

        while ($submit && count($payload['items']) < 3) {
            $number = count($payload['items']) + 1;
            $payload['items'][] = [
                'description' => "Supporting item {$number}",
                'quantity' => 1,
                'unit' => 'piece',
                'estimated_unit_price' => 0,
                'brand_specifications' => 'Standard',
                'attachment' => UploadedFile::fake()->create("approval-{$number}.pdf", 20, 'application/pdf'), 'picture' => UploadedFile::fake()->image('item.jpg'),
            ];
        }

        $this->actingAs($requestor)->post(route('purchase-requests.store'), [
            ...$payload,
            'action' => $submit ? 'submit' : 'save_draft',
            ...($submit ? $this->workflowSignature($requestor) : []),
        ])->assertSessionHasNoErrors();

        $purchaseRequest = PurchaseRequest::query()->latest('id')->firstOrFail();
        if ($testRequestType !== null) {
            $purchaseRequest->forceFill(['request_type' => $testRequestType])->save();
        }

        return $purchaseRequest;
    }

    /** @return array{PurchaseRequest, User, User} */
    private function submittedRequest(string $type): array
    {
        $requestor = $this->user(RoleSlug::TeamLeader);
        $om = $this->user(RoleSlug::OmSmtsApprover);

        return [$this->createRequest($requestor, true, ['request_type' => $type]), $om, $requestor];
    }

    private function screeningRequest(string $type): PurchaseRequest
    {
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), false, ['request_type' => $type]);
        $status = $type === 'IT'
            ? PurchaseRequestStatus::ForItScreening
            : PurchaseRequestStatus::ForPremisesScreening;
        $role = $type === 'IT' ? RoleSlug::ItStaff : RoleSlug::PremisesStaff;
        $request->forceFill([
            'status' => $status,
            'current_responsible_role_id' => $this->roleId($role),
        ])->save();

        return $request;
    }

    private function approvedForTeamClassification(): PurchaseRequest
    {
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), true);
        $om = $this->user(RoleSlug::OmSmtsApprover);
        $this->actingAs($om)
            ->post(route('om-approvals.approve', $request), ['signature_name' => $om->name, 'signature_data' => $this->signatureData()])
            ->assertSessionHasNoErrors();
        return $request->fresh();
    }

    private function signatureData(): string
    {
        return 'data:image/png;base64,'.base64_encode(str_repeat('signature-stroke', 12));
    }
    private function inventoryItem(PurchaseRequest $purchaseRequest, float $quantity): InventoryItem
    {
        $inventoryStaff = $this->user(RoleSlug::InventoryStaff);
        $supplier = Supplier::query()->create([
            'name' => 'Inventory Source Supplier '.$purchaseRequest->id,
            'is_active' => true,
        ]);
        $purchaseOrder = $purchaseRequest->purchaseOrder()->create([
            'po_number' => 'PO-STOCK-'.$purchaseRequest->id,
            'supplier_id' => $supplier->id,
            'created_by' => $inventoryStaff->id,
            'issued_at' => now()->toDateString(),
            'status' => 'ISSUED',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
        ]);
        $purchaseOrderItem = $purchaseOrder->items()->create([
            'purchase_request_item_id' => $purchaseRequest->items()->value('id'),
            'description' => 'Reusable inventory stock',
            'quantity' => $quantity,
            'unit' => 'pc',
            'unit_price' => 100,
            'total_price' => 100,
        ]);
        $goodsReceipt = $purchaseRequest->goodsReceipts()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'receipt_number' => 'GR-STOCK-'.$purchaseRequest->id,
            'received_by' => $inventoryStaff->id,
            'received_at' => now(),
            'status' => 'COMPLETE',
        ]);
        $goodsReceiptItem = $goodsReceipt->items()->create([
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'description' => 'Reusable inventory stock',
            'quantity_received' => $quantity,
            'unit' => 'pc',
            'condition' => 'GOOD',
        ]);

        return $goodsReceiptItem->inventoryItems()->create([
            'inventory_code' => 'INV-STOCK-'.$purchaseRequest->id,
            'purchase_request_id' => $purchaseRequest->id,
            'logged_by' => $inventoryStaff->id,
            'description' => 'Reusable inventory stock',
            'quantity' => $quantity,
            'unit' => 'pc',
            'location' => 'Stockroom',
            'status' => 'LOGGED',
            'logged_at' => now(),
        ]);
    }

    /** @return array<string, int|float|string> */
    private function screeningAsset(
        PurchaseRequest $purchaseRequest,
        InventoryItem $inventoryItem,
        float $quantity,
        string $source = 'IT_SELF_INVENTORY',
    ): array {
        return [
            'inventory_item_id' => $inventoryItem->id,
            'quantity' => $quantity,
            'asset_tag' => 'ALLOC-'.$purchaseRequest->id.'-'.$inventoryItem->id,
            'item_code' => 'STOCK-'.$inventoryItem->id,
            'description' => $inventoryItem->description,
            'serial_number' => null,
            'location' => $inventoryItem->location,
            'source' => $source,
            'release_date' => now()->format('Y-m-d'),
            'recipient' => $purchaseRequest->requestor->name,
        ];
    }
    public function test_only_the_quotation_creator_can_update_saved_quotation_details(): void
    {
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), false, ['request_type' => 'OTHER']);
        $request->forceFill(['status' => PurchaseRequestStatus::ForProcurement, 'current_responsible_role_id' => $this->roleId(RoleSlug::ProcurementStaff)])->save();
        $creator = $this->user(RoleSlug::Purchasing);
        $otherUser = $this->user(RoleSlug::ProcurementStaff);
        $supplier = Supplier::query()->create(['name' => 'Owner-only quotation supplier', 'is_active' => true]);
        $quotation = $request->quotations()->create([
            'supplier_id' => $supplier->id, 'created_by' => $creator->id, 'item_or_service' => 'Owner-only quotation',
            'price' => 500, 'quoted_at' => now(),
        ]);

        $payload = ['supplier_name' => 'Updated Supplier', 'quoted_amount' => 1250.50, 'delivery_location' => 'Seneca Plaza — E. Rodriguez, Quezon City', 'delivery_lead_time' => '3 days', 'remarks' => 'Updated by the creator'];
        $this->actingAs($otherUser)->patch(route('canvassing.quotations.update', [$request, $quotation]), $payload)->assertForbidden();
        $invalidPayload = [...$payload, 'supplier_name' => '', 'quotation_url' => 'not-a-url'];
        $this->actingAs($creator)
            ->patch(route('canvassing.quotations.update', [$request, $quotation]), $invalidPayload)
            ->assertSessionHasErrors(['supplier_name', 'quotation_url'])
            ->assertSessionHas('quotation_edit_failed', $quotation->id);
        $this->assertSame(1, $request->quotations()->count());
        $this->assertSame(500.0, (float) $quotation->fresh()->price);
        $this->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('Changes were not saved.')
            ->assertSee("quotation-edit-{$quotation->id}", false);

        $this->actingAs($creator)->patch(route('canvassing.quotations.update', [$request, $quotation]), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, $request->quotations()->count());

        $this->assertDatabaseHas('supplier_quotations', [
            'id' => $quotation->id,
            'price' => 1250.50,
            'delivery_location' => $payload['delivery_location'],
            'delivery_lead_time' => $payload['delivery_lead_time'],
            'remarks' => $payload['remarks'],
        ]);
        $this->assertSame('Updated Supplier', $quotation->fresh()->supplier->name);
        $this->actingAs($otherUser)->get(route('purchase-requests.show', $request))
            ->assertOk()->assertSee('quotation-preview-'.$quotation->id, false)->assertDontSee('quotation-edit-'.$quotation->id, false);
$this->actingAs($creator)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('quotation-edit-'.$quotation->id, false)
            ->assertSee('Created By / Requestor')
            ->assertSee('name="supplier_name" value="Updated Supplier"', false)
            ->assertSee('name="quoted_amount" value="1250.50"', false)
            ->assertSee('Save Changes');
    }

    public function test_checked_user_sees_signature_pad_after_actual_earlier_preparer_signature_is_completed(): void
    {
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), true);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForBudgetProcessing,
            'current_responsible_role_id' => $this->roleId(RoleSlug::Purchasing),
        ])->save();

        $preparer = $this->user(RoleSlug::Purchasing);
        $preparer->update(['name' => 'PO Prepared Regression']);
        $checker = $this->user(RoleSlug::Tl);
        $checker->update(['name' => 'PO Checked Regression']);
        $noter = $this->user(RoleSlug::Om);
        $approver = $this->user(RoleSlug::Smt);
        foreach ([
            [PurchaseOrderApprovalStage::PreparedBy, $preparer],
            [PurchaseOrderApprovalStage::CheckedBy, $checker],
            [PurchaseOrderApprovalStage::NotedBy, $noter],
            [PurchaseOrderApprovalStage::ApprovedBy, $approver],
        ] as [$stage, $assignedUser]) {
            PoRoleAssignment::query()->create(['stage' => $stage, 'user_id' => $assignedUser->id]);
        }

        $supplier = Supplier::query()->create(['name' => 'Refresh regression supplier', 'is_active' => true]);
        $order = $request->purchaseOrder()->create([
            'po_number' => 'PO-REFRESH-SIGNATURE',
            'supplier_id' => $supplier->id,
            'created_by' => $preparer->id,
            'status' => 'FOR_CHECKING',
            'issued_at' => now(),
            'subtotal' => 5000,
            'tax_amount' => 0,
            'total_amount' => 5000,
        ]);
        foreach ([
            [PurchaseOrderApprovalStage::PreparedBy, $preparer, 'APPROVED'],
            [PurchaseOrderApprovalStage::CheckedBy, $checker, 'PENDING'],
            [PurchaseOrderApprovalStage::NotedBy, $noter, 'PENDING'],
            [PurchaseOrderApprovalStage::ApprovedBy, $approver, 'PENDING'],
        ] as [$stage, $assignedUser, $status]) {
            $order->approvals()->create([
                'stage' => $stage,
                'assigned_user_id' => $assignedUser->id,
                'acted_by' => $status === 'APPROVED' ? $assignedUser->id : null,
                'status' => $status,
                'decision' => $status === 'APPROVED' ? 'APPROVED' : null,
                'acted_at' => $status === 'APPROVED' ? now() : null,
            ]);
        }

        $this->actingAs($checker)
            ->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('Prepared By signature required.')
            ->assertSee('PO Prepared Regression')
            ->assertSee('Your Checked By signature box will be available after the Prepared By signature is saved.')
            ->assertDontSee('Save Prepared By Signature')
            ->assertDontSee('Save Checked By Signature')
            ->assertDontSee('data-signature-canvas', false);
        $this->actingAs($checker)
            ->post(route('purchase-orders.signature', [$request, PurchaseOrderApprovalStage::PreparedBy->value]), [
                'signature_name' => $checker->name,
                'signature_data' => $this->signatureData(),
            ])->assertForbidden();

        $this->actingAs($preparer)
            ->get(route('request-flow.index', ['stage' => 'purchase-order']))
            ->assertOk()
            ->assertDontSee($request->request_number);
        $this->actingAs($preparer)
            ->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('It is saved automatically when you select Move to Checker.')
            ->assertSee('data-signature-canvas', false);
        $this->actingAs($preparer)
            ->post(route('purchase-orders.signature', [$request, PurchaseOrderApprovalStage::PreparedBy->value]), [
                'signature_name' => $preparer->name,
                'signature_data' => $this->signatureData(),
            ])->assertSessionHasNoErrors();
        $this->actingAs($preparer)->post(route('purchase-orders.move-to-checker', $request))->assertSessionHasNoErrors();

        $this->actingAs($checker)
            ->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('Checked By signature required.')
            ->assertSee('It is saved automatically when you select Check Purchase Order.')
            ->assertSee('data-signature-canvas', false);
    }
    public function test_missing_po_signatures_restore_the_earliest_stage_and_preserve_existing_signatures(): void
    {
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), true);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForBudgetProcessing,
            'current_responsible_role_id' => $this->roleId(RoleSlug::Purchasing),
        ])->save();

        $preparer = $this->user(RoleSlug::Purchasing);
        $checker = $this->user(RoleSlug::Tl);
        $noter = $this->user(RoleSlug::Om);
        $approver = $this->user(RoleSlug::Smt);
        foreach ([
            [PurchaseOrderApprovalStage::PreparedBy, $preparer],
            [PurchaseOrderApprovalStage::CheckedBy, $checker],
            [PurchaseOrderApprovalStage::NotedBy, $noter],
            [PurchaseOrderApprovalStage::ApprovedBy, $approver],
        ] as [$stage, $user]) {
            PoRoleAssignment::query()->create(['stage' => $stage, 'user_id' => $user->id]);
        }

        $supplier = Supplier::query()->create(['name' => 'Signature recovery supplier', 'is_active' => true]);
        $order = $request->purchaseOrder()->create([
            'po_number' => 'PO-SIGNATURE-RECOVERY',
            'supplier_id' => $supplier->id,
            'created_by' => $preparer->id,
            'status' => 'FOR_APPROVAL',
            'issued_at' => now(),
            'subtotal' => 5000,
            'tax_amount' => 0,
            'total_amount' => 5000,
        ]);
        $checkedSignature = 'data:image/png;base64,'.base64_encode(str_repeat('preserved-checker-signature', 8));
        $invalidNotedSignature = 'data:image/png;base64,'.base64_encode('incomplete');
        foreach ([
            [PurchaseOrderApprovalStage::PreparedBy, $preparer, 'APPROVED', null],
            [PurchaseOrderApprovalStage::CheckedBy, $checker, 'APPROVED', $checkedSignature],
            [PurchaseOrderApprovalStage::NotedBy, $noter, 'APPROVED', $invalidNotedSignature],
            [PurchaseOrderApprovalStage::ApprovedBy, $approver, 'PENDING', null],
        ] as [$stage, $assignedUser, $approvalStatus, $savedSignature]) {
            $order->approvals()->create([
                'stage' => $stage,
                'assigned_user_id' => $assignedUser->id,
                'acted_by' => $approvalStatus === 'APPROVED' ? $assignedUser->id : null,
                'status' => $approvalStatus,
                'decision' => $approvalStatus === 'APPROVED' ? 'APPROVED' : null,
                'signature_name' => $savedSignature ? $assignedUser->name : null,
                'signature_data' => $savedSignature,
                'signed_at' => $savedSignature ? now() : null,
                'acted_at' => $approvalStatus === 'APPROVED' ? now() : null,
            ]);
        }

        $this->actingAs($preparer)
            ->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('Prepared By signature required.')
            ->assertSee('It is saved automatically when you select Move to Checker.')
            ->assertSee('data-signature-canvas', false);

        $this->actingAs($approver)
            ->post(route('purchase-orders.approve', $request), ['decision' => 'APPROVE'])
            ->assertSessionHasErrors(['signature_data' => 'The Prepared By signature is required. The Purchase Order has been returned to Prepared By so the assigned user can sign it.']);

        $this->assertSame('FOR_CHECKING', $order->fresh()->status);
        $this->assertSame($checkedSignature, $order->approvalFor(PurchaseOrderApprovalStage::CheckedBy)?->fresh()->signature_data);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $request->id,
            'action' => 'PO_SIGNATURE_REQUIRED',
            'previous_status' => 'FOR_APPROVAL',
            'new_status' => 'FOR_CHECKING',
        ]);

        $this->actingAs($request->requestor)
            ->post(route('purchase-orders.signature', [$request, PurchaseOrderApprovalStage::PreparedBy->value]), [
                'signature_name' => $request->requestor->name,
                'signature_data' => $this->signatureData(),
            ])->assertForbidden();

        $this->actingAs($preparer)
            ->post(route('purchase-orders.signature', [$request, PurchaseOrderApprovalStage::PreparedBy->value]), [
                'signature_name' => $preparer->name,
                'signature_data' => $this->signatureData(),
            ])->assertSessionHasNoErrors();
        $this->actingAs($preparer)->post(route('purchase-orders.move-to-checker', $request))->assertSessionHasNoErrors();

        $this->actingAs($noter)
            ->post(route('purchase-orders.acknowledge', $request))
            ->assertSessionHasErrors(['signature_data' => 'The Noted By signature is required. The Purchase Order has been returned to Noted By so the assigned user can sign it.']);
        $this->assertSame('FOR_ACKNOWLEDGEMENT', $order->fresh()->status);

        $this->actingAs($noter)
            ->post(route('purchase-orders.signature', [$request, PurchaseOrderApprovalStage::NotedBy->value]), [
                'signature_name' => $noter->name,
                'signature_data' => $this->signatureData(),
            ])->assertSessionHasNoErrors();
        $this->actingAs($noter)->post(route('purchase-orders.acknowledge', $request))->assertSessionHasNoErrors();

        $this->actingAs($approver)
            ->post(route('purchase-orders.approve', $request), ['decision' => 'APPROVE'])
            ->assertSessionHasErrors(['signature_data' => 'The Approved By signature is required. The Purchase Order has been returned to Approved By so the assigned user can sign it.']);
        $this->actingAs($approver)
            ->post(route('purchase-orders.signature', [$request, PurchaseOrderApprovalStage::ApprovedBy->value]), [
                'signature_name' => $approver->name,
                'signature_data' => $this->signatureData(),
            ])->assertSessionHasNoErrors();
        $this->actingAs($approver)
            ->post(route('purchase-orders.approve', $request), ['decision' => 'APPROVE'])
            ->assertSessionHasNoErrors();

        $this->assertSame('APPROVED', $order->fresh()->status);
        $this->assertSame($checkedSignature, $order->approvalFor(PurchaseOrderApprovalStage::CheckedBy)?->fresh()->signature_data);
        foreach (PurchaseOrderApprovalStage::cases() as $stage) {
            $this->assertNotNull($order->approvalFor($stage)?->fresh()->signature_data);
        }
    }
    public function test_authorized_po_preparer_can_sign_and_save(): void
    {
        $purchasing = $this->user(RoleSlug::Purchasing);
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), true);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForBudgetProcessing,
            'current_responsible_role_id' => $this->roleId(RoleSlug::Purchasing),
        ])->save();

        PoRoleAssignment::query()->create(['stage' => PurchaseOrderApprovalStage::PreparedBy, 'user_id' => $purchasing->id]);
        $supplier = Supplier::query()->create(['name' => 'Test Supplier', 'is_active' => true]);
        $request->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $purchasing->id,
            'item_or_service' => $request->title,
            'price' => $request->total_amount,
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $order = $request->purchaseOrder()->create([
            'po_number' => 'PO-SIGNATURE-TEST',
            'supplier_id' => $supplier->id,
            'created_by' => $purchasing->id,
            'status' => 'FOR_CHECKING',
            'issued_at' => now(),
            'subtotal' => 5000,
            'tax_amount' => 0,
            'total_amount' => 5000,
            'remarks' => 'Test PO',
            'budget_campaign_id' => null,
            'budget_campaign_account_number' => null,
            'budget_payable_on' => null,
            'budget_source_bank_account' => null,
            'account_number' => '0012345678',
            'account_name' => 'Test Name',
            'payment_terms' => 'Net 30',
            'payment_details' => 'Test details',
        ]);
        $order->approvals()->create([
            'stage' => PurchaseOrderApprovalStage::PreparedBy,
            'assigned_user_id' => $purchasing->id,
            'acted_by' => $purchasing->id,
            'status' => 'APPROVED',
            'decision' => 'APPROVED',
            'acted_at' => now(),
        ]);
        $order->items()->create([
            'purchase_request_item_id' => $request->items()->value('id'),
            'description' => 'Test item',
            'quantity' => 1,
            'unit' => 'unit',
            'unit_price' => 5000,
            'total_price' => 5000,
            'specifications' => 'Test specifications',
        ]);

        $this->actingAs($purchasing)
            ->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('It is saved automatically when you select Move to Checker.');

        $signatureData = 'data:image/png;base64,'.base64_encode(str_repeat('signature-stroke', 12));

        $this->actingAs($purchasing)
            ->post(route('purchase-orders.prepare-signature', $request), [
                'signature_name' => $purchasing->name,
                'signature_data' => $signatureData,
            ])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertDatabaseHas('purchase_order_approvals', [
            'purchase_order_id' => $order->id,
            'stage' => PurchaseOrderApprovalStage::PreparedBy->value,
            'signature_name' => $purchasing->name,
            'signature_data' => $signatureData,
        ]);

        $this->actingAs($purchasing)
            ->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('Signature saved')
            ->assertSee($signatureData, false)
            ->assertSee($purchasing->name);
    }

    public function test_saved_signature_persists_after_reload(): void
    {
        $purchasing = $this->user(RoleSlug::Purchasing);
        $request = $this->createRequest($this->user(RoleSlug::TeamLeader), true);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForBudgetProcessing,
            'current_responsible_role_id' => $this->roleId(RoleSlug::Purchasing),
        ])->save();

        PoRoleAssignment::query()->create(['stage' => PurchaseOrderApprovalStage::PreparedBy, 'user_id' => $purchasing->id]);
        $supplier = Supplier::query()->create(['name' => 'Test Supplier', 'is_active' => true]);
        $request->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $purchasing->id,
            'item_or_service' => $request->title,
            'price' => $request->total_amount,
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $order = $request->purchaseOrder()->create([
            'po_number' => 'PO-PERSIST-TEST',
            'supplier_id' => $supplier->id,
            'created_by' => $purchasing->id,
            'status' => 'FOR_CHECKING',
            'issued_at' => now(),
            'subtotal' => 5000,
            'tax_amount' => 0,
            'total_amount' => 5000,
            'remarks' => 'Test PO',
        ]);
        $order->approvals()->create([
            'stage' => PurchaseOrderApprovalStage::PreparedBy,
            'assigned_user_id' => $purchasing->id,
            'acted_by' => $purchasing->id,
            'status' => 'APPROVED',
            'decision' => 'APPROVED',
            'acted_at' => now(),
        ]);
        $order->items()->create([
            'purchase_request_item_id' => $request->items()->value('id'),
            'description' => 'Test item',
            'quantity' => 1,
            'unit' => 'unit',
            'unit_price' => 5000,
            'total_price' => 5000,
            'specifications' => 'Test specifications',
        ]);

        $signatureData = $this->signatureData();
        $this->actingAs($purchasing)
            ->post(route('purchase-orders.prepare-signature', $request), [
                'signature_name' => $purchasing->name,
                'signature_data' => $signatureData,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($purchasing)
            ->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('Signature saved')
            ->assertSee($signatureData, false)
            ->assertSee($purchasing->name);
    }

    public function test_unauthorized_user_cannot_save_signature(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $request = $this->createRequest($requestor, true);
        $request->forceFill([
            'status' => PurchaseRequestStatus::ForBudgetProcessing,
        ])->save();

        $supplier = Supplier::query()->create(['name' => 'Test Supplier', 'is_active' => true]);
        $request->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $requestor->id,
            'item_or_service' => $request->title,
            'price' => $request->total_amount,
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $order = $request->purchaseOrder()->create([
            'po_number' => 'PO-UNAUTH-TEST',
            'supplier_id' => $supplier->id,
            'created_by' => $requestor->id,
            'status' => 'FOR_CHECKING',
            'issued_at' => now(),
            'subtotal' => 5000,
            'tax_amount' => 0,
            'total_amount' => 5000,
        ]);
        $order->items()->create([
            'purchase_request_item_id' => $request->items()->value('id'),
            'description' => 'Test item',
            'quantity' => 1,
            'unit' => 'unit',
            'unit_price' => 5000,
            'total_price' => 5000,
        ]);

        $this->actingAs($requestor)
            ->get(route('purchase-requests.show', $request))
            ->assertOk();

        $this->assertNull(session('data-preparer-signature-saved'));

        $this->actingAs($requestor)
            ->post(route('purchase-orders.prepare-signature', $request), [
                'signature_name' => $requestor->name,
                'signature_data' => $this->signatureData(),
            ])
            ->assertStatus(403);
    }

    public function test_om_approval_still_requires_signature(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $om = $this->user(RoleSlug::OmSmtsApprover);
        $request = $this->createRequest($requestor, true, ['request_type' => 'IT']);

        $this->actingAs($om)
            ->post(route('om-approvals.approve', $request), [
                'remarks' => 'Approved for screening.',
                'signature_name' => mb_strtolower($om->name),
                'signature_data' => $this->signatureData(),
            ])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::ForTeamScreening, $request->status);
        $this->assertDatabaseHas('request_approvals', [
            'purchase_request_id' => $request->id,
            'approval_level' => 'OM_REVIEW',
            'action' => 'OM_APPROVED_FOR_SCREENING',
        ]);
    }

    public function test_other_workflow_statuses_do_not_trigger_irrelevant_signature_errors(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $request = $this->createRequest($requestor, false, ['request_type' => 'OTHER']);

        $this->actingAs($requestor)->get(route('purchase-requests.show', $request))
            ->assertOk();

        $this->assertFalse(session()->has('errors'));
    }
}
