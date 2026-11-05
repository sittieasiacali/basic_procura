<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\Campaign;
use App\Models\PurchaseRequest;
use App\Models\PoRoleAssignment;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurchaseRequestListTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $operations;

    private Campaign $sales;

    private int $requestSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        $this->operations = Campaign::query()->create(['name' => 'Operations', 'is_active' => true]);
        $this->sales = Campaign::query()->create(['name' => 'Sales', 'is_active' => true]);
    }

    public function test_request_list_visibility_is_scoped_by_owner_campaign_and_review_role(): void
    {
        $operationsRequestor = $this->user(RoleSlug::Requestor, $this->operations);
        $salesRequestor = $this->user(RoleSlug::Requestor, $this->sales);
        $operationsRequest = $this->purchaseRequest($operationsRequestor, PurchaseRequestStatus::ForOmApproval, 'Operations Request');
        $salesRequest = $this->purchaseRequest($salesRequestor, PurchaseRequestStatus::ForOmApproval, 'Sales Request');
        $operationsTeamLeader = $this->user(RoleSlug::TeamLeader, $this->operations);
        $teamLeaderRequest = $this->purchaseRequest($operationsTeamLeader, PurchaseRequestStatus::ForOmApproval, 'Team Leader Owned Request');

        $this->assertListVisibility($operationsRequestor, [$operationsRequest], [$salesRequest]);
        $this->assertListVisibility($operationsTeamLeader, [$teamLeaderRequest], [$operationsRequest, $salesRequest]);
        $this->actingAs($operationsTeamLeader)->get(route('purchase-requests.show', $teamLeaderRequest))->assertOk();
        $this->get(route('purchase-requests.show', $operationsRequest))->assertForbidden();
        $this->get(route('purchase-requests.show', $salesRequest))->assertForbidden();
        $this->assertListVisibility($this->user(RoleSlug::TeamLeader, $this->sales), [], [$operationsRequest, $salesRequest, $teamLeaderRequest]);

        foreach ([RoleSlug::OmSmtsApprover, RoleSlug::Smt, RoleSlug::Admin] as $role) {
            $this->assertListVisibility($this->user($role), [$operationsRequest, $salesRequest, $teamLeaderRequest], []);
        }
    }

    public function test_summary_counts_use_only_requests_visible_to_the_current_user(): void
    {
        $requestor = $this->user(RoleSlug::Requestor, $this->operations);
        $other = $this->user(RoleSlug::Requestor, $this->sales);
        $this->purchaseRequest($requestor, PurchaseRequestStatus::Draft, 'Pending Request');
        $this->purchaseRequest($requestor, PurchaseRequestStatus::Approved, 'Approved Request');
        $this->purchaseRequest($requestor, PurchaseRequestStatus::Rejected, 'Disapproved Request');
        $this->purchaseRequest($other, PurchaseRequestStatus::Draft, 'Private Request');

        $this->actingAs($requestor)->get(route('purchase-requests.index'))
            ->assertOk()
            ->assertSee('data-summary-count="all">3', false)
            ->assertSee('data-summary-count="pending">1', false)
            ->assertSee('data-summary-count="approved">1', false)
            ->assertSee('data-summary-count="disapproved">1', false)
            ->assertDontSee('Private Request');
    }

    public function test_summary_counts_classify_every_stage_and_hide_other_peoples_drafts_from_staff(): void
    {
        // Every status belongs to exactly one summary bucket.
        foreach (PurchaseRequestStatus::cases() as $status) {
            $this->assertContains($status->summaryGroup(), ['pending', 'approved', 'disapproved'], $status->value);
        }

        $requestor = $this->user(RoleSlug::Requestor, $this->operations);
        $this->purchaseRequest($requestor, PurchaseRequestStatus::Draft, 'Unsubmitted Draft');
        $this->purchaseRequest($requestor, PurchaseRequestStatus::ForOmApproval, 'Awaiting OM');
        $this->purchaseRequest($requestor, PurchaseRequestStatus::ForProcurement, 'In Canvassing');
        $this->purchaseRequest($requestor, PurchaseRequestStatus::ForBudgetProcessing, 'Supplier Approved');
        $this->purchaseRequest($requestor, PurchaseRequestStatus::ForBudgetMaker, 'Budget Maker Stage');
        $this->purchaseRequest($requestor, PurchaseRequestStatus::ForPayment, 'Payment Stage');
        $this->purchaseRequest($requestor, PurchaseRequestStatus::Fulfilled, 'Fulfilled Request');
        $this->purchaseRequest($requestor, PurchaseRequestStatus::Cancelled, 'Cancelled Request');

        // IT staff see every submitted request, but never someone else's draft.
        $this->actingAs($this->user(RoleSlug::ItStaff))->get(route('purchase-requests.index'))
            ->assertOk()
            ->assertSee('data-summary-count="all">7', false)
            ->assertSee('data-summary-count="pending">2', false)
            ->assertSee('data-summary-count="approved">4', false)
            ->assertSee('data-summary-count="disapproved">1', false)
            ->assertDontSee('Unsubmitted Draft');
        $this->get(route('purchase-requests.index', ['summary' => 'approved']))
            ->assertOk()
            ->assertSee('Budget Maker Stage')
            ->assertSee('Payment Stage')
            ->assertDontSee('In Canvassing');

        // The requestor still counts their own draft as pending.
        $this->actingAs($requestor)->get(route('purchase-requests.index'))
            ->assertOk()
            ->assertSee('data-summary-count="all">8', false)
            ->assertSee('data-summary-count="pending">3', false);
    }

    public function test_request_cards_show_items_and_approval_badges_from_database_records(): void
    {
        $requestor = $this->user(RoleSlug::Requestor, $this->operations);
        $approver = $this->user(RoleSlug::OmSmtsApprover);
        $purchaseRequest = $this->purchaseRequest($requestor, PurchaseRequestStatus::Rejected, 'Approval Mapping');
        $purchaseRequest->approvals()->create([
            'user_id' => $approver->id,
            'approval_level' => 'OM_REVIEW',
            'action' => 'OM_APPROVED_FOR_SCREENING',
            'previous_status' => PurchaseRequestStatus::Draft->value,
            'new_status' => PurchaseRequestStatus::ForOmApproval->value,
            'acted_at' => now()->subMinute(),
        ]);
        $this->actingAs($requestor)->get(route('purchase-requests.index'))
            ->assertOk()
            ->assertSeeInOrder(['flow-request-grid', 'flow-request-card', $purchaseRequest->request_number, 'OM review:', 'Items:', 'Amount:'])
            ->assertSee($purchaseRequest->request_number)
            ->assertSee('Operations')
            ->assertSee('Requested item 1')
            ->assertSee('&times; 1 piece', false)
            ->assertSee('+1 more')
            ->assertSee('Approved')
            ->assertSee('Disapproved');
    }

    public function test_search_status_campaign_and_date_filters_are_applied_without_leaking_requests(): void
    {
        $operationsRequestor = $this->user(RoleSlug::Requestor, $this->operations);
        $salesRequestor = $this->user(RoleSlug::Requestor, $this->sales);
        $matching = $this->purchaseRequest($operationsRequestor, PurchaseRequestStatus::ForOmApproval, 'Unique Laptop');
        $matching->forceFill(['created_at' => '2026-08-03 08:00:00'])->save();
        $excluded = $this->purchaseRequest($salesRequestor, PurchaseRequestStatus::Approved, 'Office Chair');
        $excluded->forceFill(['created_at' => '2026-07-01 08:00:00'])->save();

        $this->actingAs($this->user(RoleSlug::Admin))->get(route('purchase-requests.index', [
            'search' => 'Requested item 1',
            'status' => PurchaseRequestStatus::ForOmApproval->value,
            'campaign_id' => $this->operations->id,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31',
        ]))
            ->assertOk()
            ->assertSee($matching->request_number)
            ->assertSee('Aug 3, 2026')
            ->assertDontSee($excluded->request_number);
    }

    public function test_purchase_request_list_is_paginated(): void
    {
        $requestor = $this->user(RoleSlug::Requestor, $this->operations);
        foreach (range(1, 16) as $number) {
            $this->purchaseRequest($requestor, PurchaseRequestStatus::Draft, "Paginated Request {$number}");
        }

        $this->actingAs($requestor)->get(route('purchase-requests.index'))
            ->assertOk()
            ->assertViewHas('purchaseRequests', fn ($requests) => $requests->count() === 15 && $requests->total() === 16)
            ->assertSee('pagination');
    }

    public function test_create_form_renders_a_simple_item_builder_and_canvassing_supplier_notice(): void
    {
        $teamLeader = $this->user(RoleSlug::TeamLeader, $this->operations);
        $response = $this->actingAs($teamLeader)->get(route('purchase-requests.create'));

        $response->assertOk()
            ->assertSee('Request Details')
            ->assertSee('Screening route is assigned by IT or Premises.')
            ->assertDontSee('name="request_type"', false)
            ->assertSee('Supplier selection happens during Canvassing.')
            ->assertDontSee('Bulk Purchase')
            ->assertSee('Add item')
            ->assertSee('name="purchase_mode" value="manual"', false)
            ->assertSee('Requestor')
            ->assertSee('name="items[0][description]"', false)
            ->assertDontSee('name="items[1][description]"', false)
            ->assertDontSee('/ 3 minimum')
            ->assertSee('name="items[0][attachment]"', false)
            ->assertSee('name="items[0][reference_url]"', false)
            ->assertSee('More item details')
            ->assertSee('name="items[0][quantity]"', false)
            ->assertSee('name="items[0][brand_specifications]"', false)
            ->assertSee('name="items[0][picture]"', false)
            ->assertSee('name="items[0][unit]"', false)
            ->assertDontSee('name="items[0][estimated_unit_price]"', false)
            ->assertDontSee('Estimated total')
            ->assertSee('1</strong>&nbsp;<span id="item-count-noun">item</span>', false)
            ->assertSee('Request Summary')
            ->assertSee('Procurement Workflow')
            ->assertSee('Submit Request')
            ->assertSee('My Requests')
            ->assertDontSee('Assigned Requests')
            ->assertDontSee('name="supplier_id"', false)
            ->assertDontSee('svgAdd');
    }

    public function test_requestor_campaign_is_read_only_and_server_derived(): void
    {
        $teamLeader = $this->user(RoleSlug::TeamLeader, $this->operations);
        $payload = $this->formPayload(3);
        $payload['department_campaign'] = $this->sales->name;

        $this->actingAs($teamLeader)->get(route('purchase-requests.create'))
            ->assertOk()
            ->assertSee('<strong>'.$this->operations->name.'</strong>', false)
            ->assertDontSee('name="department_campaign"', false);

        $this->actingAs($teamLeader)->post(route('purchase-requests.store'), [
            ...$payload,
            'action' => 'submit',
            ...$this->workflowSignature(auth()->user()),
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->operations->name, PurchaseRequest::query()->firstOrFail()->department_campaign);
    }

    public function test_team_leader_cannot_submit_or_override_a_screening_route(): void
    {
        $teamLeader = $this->user(RoleSlug::TeamLeader, $this->operations);
        $payload = $this->formPayload(3);
        $payload['request_type'] = 'IT';

        $this->actingAs($teamLeader)->post(route('purchase-requests.store'), [
            ...$payload,
            'action' => 'submit',
            ...$this->workflowSignature(auth()->user()),
        ])->assertSessionHasErrors('request_type');

        $this->assertSame(0, PurchaseRequest::query()->count());

        unset($payload['request_type']);
        $this->post(route('purchase-requests.store'), [
            ...$payload,
            'action' => 'submit',
            ...$this->workflowSignature(auth()->user()),
        ])->assertSessionHasNoErrors();

        $this->assertSame('UNCLASSIFIED', PurchaseRequest::query()->firstOrFail()->request_type);
    }

    public function test_requestor_without_campaign_cannot_submit(): void
    {
        $teamLeader = $this->user(RoleSlug::TeamLeader);

        $this->actingAs($teamLeader)->post(route('purchase-requests.store'), [
            ...$this->formPayload(3),
            'action' => 'submit',
            ...$this->workflowSignature(auth()->user()),
        ])->assertSessionHasErrors([
            'department_campaign' => 'Your account must have an assigned Campaign before submitting a purchase request.',
        ]);

        $this->assertSame(0, PurchaseRequest::query()->count());
    }

    public function test_single_item_request_can_be_submitted_directly_or_from_a_draft(): void
    {
        $requestor = $this->user(RoleSlug::Requestor, $this->operations);
        $payload = $this->formPayload(1);

        $this->actingAs($requestor)->post(route('purchase-requests.store'), [
            ...$payload,
            'action' => 'submit',
            ...$this->workflowSignature(auth()->user()),
        ])->assertSessionHasNoErrors();
        $submitted = PurchaseRequest::query()->firstOrFail();
        $this->assertSame(1, $submitted->items()->count());
        $this->assertSame(PurchaseRequestStatus::ForTeamScreening, $submitted->status);

        $this->actingAs($requestor)->post(route('purchase-requests.store'), [
            ...$payload,
            'action' => 'save_draft',
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, PurchaseRequest::query()->count());

        $draft = PurchaseRequest::query()->where('status', PurchaseRequestStatus::Draft)->firstOrFail();
        $this->post(route('purchase-requests.submit', $draft), $this->workflowSignature(auth()->user()))
            ->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForTeamScreening, $draft->fresh()->status);
    }

    public function test_request_without_estimates_can_be_saved_submitted_and_viewed(): void
    {
        $requestor = $this->user(RoleSlug::Requestor, $this->operations);
        $payload = $this->formPayload(2);
        $payload['items'] = [
            ['description' => 'Conference display', 'quantity' => 1, 'brand_specifications' => '65 inch', 'attachment' => UploadedFile::fake()->create('approval-1.pdf', 20, 'application/pdf'), 'picture' => UploadedFile::fake()->image('item.jpg')],
            ['description' => 'Mounting bracket', 'quantity' => 2, 'brand_specifications' => 'Wall mount', 'reference_url' => 'https://example.test/bracket', 'attachment' => UploadedFile::fake()->create('approval-2.pdf', 20, 'application/pdf'), 'picture' => UploadedFile::fake()->image('item.jpg')],
        ];

        $this->actingAs($requestor)->post(route('purchase-requests.store'), [
            ...$payload,
            'action' => 'save_draft',
        ])->assertSessionHasNoErrors();

        $draft = PurchaseRequest::query()->firstOrFail();
        $this->assertNull($draft->total_amount);
        $this->assertSame(2, $draft->items()->count());
        $this->assertNull($draft->items()->firstOrFail()->unit);
        $this->assertNull($draft->items()->firstOrFail()->estimated_unit_price);

        $this->get(route('purchase-requests.show', $draft))
            ->assertOk()
            ->assertSee('Conference display')
            ->assertSee('Mounting bracket')
            ->assertDontSee('Request estimate');

        $this->post(route('purchase-requests.submit', $draft), $this->workflowSignature(auth()->user()))->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForTeamScreening, $draft->fresh()->status);
    }

    public function test_purchase_order_requires_confirmed_lines_for_an_unestimated_request(): void
    {
        $requestor = $this->user(RoleSlug::Requestor, $this->operations);
        $purchasing = $this->user(RoleSlug::Purchasing);
        foreach ([
            [PurchaseOrderApprovalStage::PreparedBy, $purchasing],
            [PurchaseOrderApprovalStage::CheckedBy, $this->user(RoleSlug::Tl)],
            [PurchaseOrderApprovalStage::NotedBy, $this->user(RoleSlug::Om)],
            [PurchaseOrderApprovalStage::ApprovedBy, $this->user(RoleSlug::Smt)],
        ] as [$stage, $user]) {
            PoRoleAssignment::query()->create(['stage' => $stage, 'user_id' => $user->id]);
        }
        $payload = $this->formPayload(1);
        $payload['items'] = [['description' => 'Conference display', 'quantity' => 1, 'brand_specifications' => '65 inch', 'attachment' => UploadedFile::fake()->create('approval.pdf', 20, 'application/pdf'), 'picture' => UploadedFile::fake()->image('item.jpg')]];

        $this->actingAs($requestor)->post(route('purchase-requests.store'), [
            ...$payload,
            'action' => 'submit',
            ...$this->workflowSignature(auth()->user()),
        ])->assertSessionHasNoErrors();

        $purchaseRequest = PurchaseRequest::query()->firstOrFail();
        $supplier = Supplier::query()->create(['name' => 'Office Supplier', 'is_active' => true]);
        $purchaseRequest->forceFill([
            'status' => PurchaseRequestStatus::ForBudgetProcessing,
            'selected_supplier_id' => $supplier->id,
        ])->save();
        $purchaseRequest->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $purchasing->id,
            'item_or_service' => 'Conference display',
            'price' => 1200,
            'is_approved' => true,
        ]);

        $this->actingAs($purchasing)->get(route('purchase-requests.show', $purchaseRequest))
            ->assertOk()
            ->assertSee('Purchase order line details')
            ->assertSee('name="po_items['.$purchaseRequest->items()->firstOrFail()->id.'][quantity]"', false);

        $poUrl = route('purchase-orders.store', $purchaseRequest);
        $poBase = [
            'priority_level' => 'NORMAL',
            'transaction_type' => 'CASH',
            'fund_source' => 'MAIN_FUND',
            'account_number' => '001234567',
            'account_name' => 'Conference Supplier',
            'payment_terms' => '30 days',
            'signature_name' => $purchasing->name,
            'signature_data' => $this->signatureImage(),
        ];
        $this->post($poUrl, $poBase)->assertSessionHasErrors('po_items');
        $this->assertNull($purchaseRequest->fresh()->purchaseOrder);

        $lineId = $purchaseRequest->items()->firstOrFail()->id;
        $this->post($poUrl, [
            ...$poBase,
            'po_items' => [$lineId => ['quantity' => 2, 'unit' => 'piece', 'unit_price' => 500]],
        ])->assertSessionHasErrors('po_items');

        $this->post($poUrl, [
            ...$poBase,
            'po_items' => [$lineId => ['quantity' => 2, 'unit' => 'piece', 'unit_price' => 600]],
        ])->assertSessionHasNoErrors();

        $order = $purchaseRequest->fresh()->purchaseOrder;
        $this->assertSame('1200.00', $order->total_amount);
        $this->assertSame('2.00', $order->items()->firstOrFail()->quantity);
        $this->assertNull($purchaseRequest->fresh()->total_amount);
    }

    public function test_three_item_submission_calculates_item_totals_and_grand_total(): void
    {
        $requestor = $this->user(RoleSlug::Requestor, $this->operations);
        $payload = $this->formPayload(3);
        $payload['items'][0]['quantity'] = 2;
        $payload['items'][0]['estimated_unit_price'] = 125.50;
        $payload['items'][1]['quantity'] = 3;
        $payload['items'][1]['estimated_unit_price'] = 50;
        $payload['items'][2]['quantity'] = 1;
        $payload['items'][2]['estimated_unit_price'] = 99.99;

        $this->actingAs($requestor)->post(route('purchase-requests.store'), [
            ...$payload,
            'action' => 'submit',
            ...$this->workflowSignature(auth()->user()),
        ])->assertSessionHasNoErrors();

        $purchaseRequest = PurchaseRequest::query()->firstOrFail();
        $this->assertSame('REQ00001', $purchaseRequest->request_number);
        $this->assertSame(PurchaseRequestStatus::ForTeamScreening, $purchaseRequest->status);
        $this->assertSame('500.99', $purchaseRequest->total_amount);
        $this->assertSame(['251.00', '150.00', '99.99'], $purchaseRequest->items()->orderBy('id')->pluck('estimated_total')->all());
    }

    /** @param list<PurchaseRequest> $visible @param list<PurchaseRequest> $hidden */
    private function assertListVisibility(User $user, array $visible, array $hidden): void
    {
        $response = $this->actingAs($user)->get(route('purchase-requests.index'))->assertOk();
        foreach ($visible as $purchaseRequest) {
            $response->assertSee($purchaseRequest->request_number);
        }
        foreach ($hidden as $purchaseRequest) {
            $response->assertDontSee($purchaseRequest->request_number);
        }
    }

    private function user(RoleSlug $role, ?Campaign $campaign = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role->value)->value('id'),
            'campaign_id' => $campaign?->id,
        ]);
    }

    private function purchaseRequest(User $requestor, PurchaseRequestStatus $status, string $title): PurchaseRequest
    {
        $this->requestSequence++;
        $purchaseRequest = PurchaseRequest::query()->create([
            'request_number' => 'TEST'.str_pad((string) $this->requestSequence, 5, '0', STR_PAD_LEFT),
            'title' => $title,
            'requestor_id' => $requestor->id,
            'department_campaign' => $requestor->campaign?->name ?? 'Unassigned',
            'request_type' => 'UNCLASSIFIED',
            'purpose' => 'Test purpose',
            'required_date' => now()->addWeek(),
            'delivery_location' => 'Main Office',
            'status' => $status,
            'current_responsible_role_id' => $requestor->role_id,
            'total_amount' => 60,
        ]);
        foreach (range(1, 3) as $number) {
            $purchaseRequest->items()->create([
                'description' => "Requested item {$number}",
                'quantity' => 1,
                'unit' => 'piece',
                'estimated_unit_price' => 20,
                'estimated_total' => 20,
            ]);
        }

        return $purchaseRequest;
    }

    /** @return array<string, mixed> */
    private function formPayload(int $itemCount): array
    {
        return [
            'title' => 'Three Item Request',
            'department_campaign' => $this->operations->name,
            'purpose' => 'Browser and automated verification',
            'required_date' => now()->addWeek()->format('Y-m-d'),
            'delivery_location' => 'Main Office',
            'items' => collect(range(1, $itemCount))->map(fn ($number) => [
                'description' => "Item {$number}",
                'quantity' => 1,
                'unit' => 'piece',
                'estimated_unit_price' => 10,
                'reference_url' => "https://example.test/item-{$number}",
                'brand_specifications' => "Quote {$number}",
                'attachment' => UploadedFile::fake()->create("approval-{$number}.pdf", 20, 'application/pdf'), 'picture' => UploadedFile::fake()->image('item.jpg'),
            ])->all(),
        ];
    }
}
