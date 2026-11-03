<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\BudgetRequest;
use App\Models\Campaign;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\CampaignSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Requestor item requirements, IT priority, optional remarks, and the optional PO step. */
class RequestRevisionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, CampaignSeeder::class]);
        Storage::fake('local');
    }

    public function test_submitting_needs_quotation_quantity_email_approval_and_picture_but_a_draft_does_not(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $bareItem = ['description' => 'Business laptop'];

        $this->actingAs($requestor)->post(route('purchase-requests.store'), $this->payload(['items' => [$bareItem]]))
            ->assertSessionHasNoErrors();

        $this->post(route('purchase-requests.store'), [...$this->payload(['items' => [$bareItem]]), 'action' => 'submit', ...$this->workflowSignature($requestor)])
            ->assertSessionHasErrors(['items.0.brand_specifications', 'items.0.quantity', 'items.0.attachment', 'items.0.picture']);
        $this->assertSame(1, PurchaseRequest::query()->count());
    }

    public function test_submitted_item_keeps_its_email_approval_and_picture_apart(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $item = [...$this->completeItem(), 'picture' => UploadedFile::fake()->image('laptop.jpg')];

        $this->actingAs($requestor)->post(route('purchase-requests.store'), [...$this->payload(['items' => [$item]]), 'action' => 'submit', ...$this->workflowSignature($requestor)])
            ->assertSessionHasNoErrors();

        $stored = PurchaseRequestItem::query()->sole();
        $this->assertSame('Dell Latitude 5440, PHP 52,000', $stored->brand_specifications);
        $this->assertSame('2.00', $stored->quantity);
        $this->assertEqualsCanonicalizing(
            [PurchaseRequestItem::ATTACHMENT_EMAIL_APPROVAL, PurchaseRequestItem::ATTACHMENT_PICTURE],
            $stored->attachments->pluck('category')->all(),
        );
        $this->assertNotSame(PurchaseRequestStatus::Draft, $stored->purchaseRequest->status);
    }

    public function test_an_item_with_an_approval_on_file_can_be_resubmitted_without_uploading_again(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $this->actingAs($requestor)->post(route('purchase-requests.store'), $this->payload(['items' => [$this->completeItem()]]))->assertSessionHasNoErrors();
        $request = PurchaseRequest::query()->sole();
        $item = $request->items()->sole();
        $this->get(route('purchase-requests.create'))->assertOk()->assertSee('Email approval')->assertSee('Item picture')->assertSee('Quotation')->assertSee('name="items[0][unit]"', false)->assertSee('Piece (pc)');
        $this->get(route('purchase-requests.edit', $request))->assertOk()->assertSee('On file: approval.pdf');

        $resubmission = [
            ...$this->payload(['items' => [['id' => $item->id, ...$this->completeItem(), 'attachment' => null, 'picture' => null]]]),
            'department_campaign' => $requestor->campaign->name, 'action' => 'submit', ...$this->workflowSignature($requestor),
        ];
        $this->put(route('purchase-requests.update', $request), $resubmission)->assertSessionHasNoErrors();
        $this->assertNotSame(PurchaseRequestStatus::Draft, $request->fresh()->status);
    }

    public function test_a_draft_missing_its_email_approval_cannot_be_submitted_from_the_request_page(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $this->actingAs($requestor)->post(route('purchase-requests.store'), $this->payload(['items' => [[...$this->completeItem(), 'attachment' => null]]]))->assertSessionHasNoErrors();
        $request = PurchaseRequest::query()->sole();

        $this->post(route('purchase-requests.submit', $request), $this->workflowSignature($requestor))->assertSessionHasErrors('items');
        $this->assertSame(PurchaseRequestStatus::Draft, $request->fresh()->status);
    }

    public function test_it_staff_set_the_priority_while_screening_and_requestors_cannot(): void
    {
        $request = $this->requestAt(PurchaseRequestStatus::ForItScreening, ['request_type' => 'IT']);
        $itStaff = $this->user(RoleSlug::ItStaff);

        $this->actingAs($itStaff)->get(route('purchase-requests.show', $request))->assertOk()->assertSee('screening-priority-level', false);
        $this->patch(route('request-flow.priority.update', $request), ['priority_level' => 'URGENT'])
            ->assertRedirect(route('purchase-requests.show', $request));
        $this->assertSame('URGENT', $request->fresh()->priority_level);

        $this->actingAs($request->requestor)->patch(route('request-flow.priority.update', $request), ['priority_level' => 'LOW'])->assertForbidden();
        $this->assertSame('URGENT', $request->fresh()->priority_level);
    }

    public function test_screening_unavailable_needs_no_remarks_and_blank_remarks_read_na(): void
    {
        $request = $this->requestAt(PurchaseRequestStatus::ForItScreening, ['request_type' => 'IT', 'remarks' => null]);
        $itStaff = $this->user(RoleSlug::ItStaff);

        $this->actingAs($itStaff)->post(route('request-flow.screening.store', $request), ['decision' => 'UNAVAILABLE', ...$this->workflowSignature($itStaff)])
            ->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForProcurement, $request->fresh()->status);
        $this->get(route('purchase-requests.document', $request))->assertOk()->assertSee('<dt>Remarks</dt><dd>N/A</dd>', false);
    }

    public function test_a_request_can_skip_the_purchase_order_and_be_budgeted_from_its_quotation(): void
    {
        [$request, $supplier] = $this->awaitingPurchaseOrder();
        $preparer = $this->user(RoleSlug::PurchasingTeam);

        $this->actingAs($preparer)->get(route('purchase-requests.show', $request))->assertOk()->assertSee('Proceed without PO');
        $this->post(route('purchase-orders.skip', $request), ['remarks' => 'Small cash purchase.'])->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertNotNull($request->purchase_order_skipped_at);
        $this->assertNull($request->purchaseOrder);
        $this->assertFalse(PurchaseRequest::query()->eligibleForPurchaseOrderPreparation()->whereKey($request->id)->exists());
        $this->assertTrue(PurchaseRequest::query()->readyForBudgetRequest()->whereKey($request->id)->exists());
        $this->post(route('purchase-orders.skip', $request))->assertSessionHasErrors('status');

        $maker = $this->user(RoleSlug::BudgetMaker);
        $source = Campaign::query()->where('name', 'AIA 1')->firstOrFail();
        $this->actingAs($maker)->get(route('budget.index'))->assertOk()->assertSee($request->request_number)->assertSee('No PO');
        $this->get(route('budget.create', ['purchase_request_id' => $request->id]))->assertOk()->assertSee('The Purchase Order step was skipped.');
        $this->post(route('budget.store'), ['purchase_request_id' => $request->id, 'campaign_id' => $source->id, ...$this->workflowSignature($maker)])
            ->assertSessionHasNoErrors();

        $budget = BudgetRequest::query()->sole();
        $this->assertNull($budget->purchase_order_id);
        $this->assertSame('1120.00', $budget->amount);
        $this->assertSame($budget->id, $request->fresh()->budgetRequest?->id);
        $this->assertSame(PurchaseRequestStatus::AwaitingPresidentFinalApproval, $request->fresh()->status);
        $this->assertSame($supplier->id, $request->fresh()->selected_supplier_id);
    }

    public function test_only_the_po_preparer_can_skip_the_purchase_order(): void
    {
        [$request] = $this->awaitingPurchaseOrder();

        $this->actingAs($this->user(RoleSlug::BudgetMaker))->post(route('purchase-orders.skip', $request))->assertForbidden();
        $this->assertNull($request->fresh()->purchase_order_skipped_at);
    }

    /** @return array{PurchaseRequest, Supplier} */
    private function awaitingPurchaseOrder(): array
    {
        $supplier = Supplier::query()->create(['name' => 'No PO supplier', 'is_active' => true]);
        $request = $this->requestAt(PurchaseRequestStatus::ForBudgetProcessing, ['selected_supplier_id' => $supplier->id]);
        $request->items()->create(['description' => 'Desk lamp', 'quantity' => 2, 'unit' => 'pc', 'brand_specifications' => 'LED']);
        $request->quotations()->create([
            'supplier_id' => $supplier->id, 'created_by' => $request->requestor_id, 'item_or_service' => 'Desk lamp',
            'price' => 1000, 'vat_treatment' => 'EXCLUSIVE', 'base_amount' => 1000, 'vat_rate' => 12, 'vat_amount' => 120, 'total_amount' => 1120,
            'is_approved' => true, 'approved_at' => now(),
        ]);

        return [$request, $supplier];
    }

    /** @param array<string, mixed> $attributes */
    private function requestAt(PurchaseRequestStatus $status, array $attributes = []): PurchaseRequest
    {
        $requestor = $this->user(RoleSlug::Requestor);

        return PurchaseRequest::query()->create([
            'request_number' => 'REQ-REV-'.PurchaseRequest::query()->count(), 'title' => 'Revision check',
            'requestor_id' => $requestor->id, 'department_campaign' => $requestor->campaign->name,
            'request_type' => 'OTHER', 'purpose' => 'Revision verification', 'required_date' => now()->addWeek(),
            'delivery_location' => 'Main office', 'status' => $status, ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function completeItem(): array
    {
        return [
            'description' => 'Business laptop',
            'quantity' => 2,
            'brand_specifications' => 'Dell Latitude 5440, PHP 52,000',
            'attachment' => UploadedFile::fake()->create('approval.pdf', 40, 'application/pdf'), 'picture' => UploadedFile::fake()->image('item.jpg'),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'action' => 'save_draft',
            'title' => 'Laptop Request',
            'purpose' => 'Replacement equipment',
            'required_date' => now()->addWeek()->format('Y-m-d'),
            'delivery_location' => 'Main Office',
            ...$overrides,
        ];
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role->value)->value('id'),
            'campaign_id' => Campaign::query()->where('name', 'AC MOBILITY')->value('id'),
        ]);
    }
}
