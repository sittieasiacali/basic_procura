<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\CampaignSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresidentApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, CampaignSeeder::class]);
    }

    public function test_president_queue_only_contains_actionable_requests_and_shows_stage_and_recommendation(): void
    {
        $request = $this->request(PurchaseRequestStatus::ForSupplierApproval, 'Queue Request');
        $this->request(PurchaseRequestStatus::ForCanvassing, 'Not Actionable');
        $supplier = Supplier::query()->create(['name' => 'Queue Supplier', 'is_active' => true]);
        $request->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $this->user(RoleSlug::Purchasing)->id,
            'item_or_service' => $request->title,
            'price' => 12500,
            'is_recommended' => true,
        ]);

        $this->actingAs($this->user(RoleSlug::President))
            ->get(route('president-approvals.index'))
            ->assertOk()
            ->assertSee($request->request_number)
            ->assertSee('Supplier Approval')
            ->assertSee('Queue Supplier')
            ->assertSee('Purchasing recommendation')
            ->assertDontSee('Not Actionable');
    }

    public function test_president_must_choose_a_specific_current_quotation_and_can_override_recommendation(): void
    {
        $request = $this->request(PurchaseRequestStatus::ForSupplierApproval, 'Override Request');
        $purchasing = $this->user(RoleSlug::Purchasing);
        $recommendedSupplier = Supplier::query()->create(['name' => 'Recommended Vendor', 'is_active' => true]);
        $alternateSupplier = Supplier::query()->create(['name' => 'Alternate Vendor', 'is_active' => true]);
        $recommended = $request->quotations()->create([
            'supplier_id' => $recommendedSupplier->id,
            'created_by' => $purchasing->id,
            'item_or_service' => $request->title,
            'price' => 10000,
            'is_recommended' => true,
        ]);
        $alternate = $request->quotations()->create([
            'supplier_id' => $alternateSupplier->id,
            'created_by' => $purchasing->id,
            'item_or_service' => $request->title,
            'price' => 11000,
        ]);
        $president = $this->user(RoleSlug::President);

        $this->actingAs($president)
            ->post(route('president-approvals.supplier.decision', $request), ['decision' => 'APPROVE'])
            ->assertSessionHasErrors('quotation_id');
        $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->fresh()->status);

        $this->actingAs($president)
            ->post(route('president-approvals.supplier.decision', $request), [
                'decision' => 'APPROVE',
                'quotation_id' => $alternate->id,
                'remarks' => 'Selected based on lead time.',
                'signature_name' => $president->name,
                'signature_data' => $this->signatureData(),
            ])
            ->assertRedirect(route('purchase-requests.show', $request));

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::ForBudgetProcessing, $request->status);
        $this->assertSame($this->roleId(RoleSlug::Purchasing), $request->current_responsible_role_id);
        $this->assertSame($alternateSupplier->id, $request->selected_supplier_id);
        $this->assertTrue($alternate->fresh()->is_approved);
        $this->assertTrue($recommended->fresh()->is_recommended);
        $this->assertFalse($recommended->fresh()->is_approved);
        $this->assertDatabaseHas('request_approvals', [
            'purchase_request_id' => $request->id,
            'approval_level' => 'PRESIDENT_SUPPLIER_APPROVAL',
            'action' => 'PRESIDENT_SUPPLIER_APPROVAL_APPROVED',
        ]);
    }

    public function test_return_requires_remarks_and_removes_request_from_pending_queue(): void
    {
        $request = $this->request(PurchaseRequestStatus::ForSupplierApproval, 'Return Request');
        $president = $this->user(RoleSlug::President);

        $this->actingAs($president)
            ->post(route('president-approvals.supplier.decision', $request), ['decision' => 'REJECT'])
            ->assertSessionHasErrors('remarks');

        $this->actingAs($president)
            ->post(route('president-approvals.supplier.decision', $request), [
                'decision' => 'REJECT',
                'remarks' => 'Please provide another quotation.',
                'signature_name' => $president->name,
                'signature_data' => $this->signatureData(),
            ])
            ->assertRedirect(route('purchase-requests.show', $request));

        $this->assertSame(PurchaseRequestStatus::ForCanvassing, $request->fresh()->status);
        $this->actingAs($president)
            ->get(route('president-approvals.index'))
            ->assertDontSee($request->request_number);
    }

    public function test_purchasing_can_view_supplier_queue_but_cannot_use_president_approval_actions(): void
    {
        $request = $this->request(PurchaseRequestStatus::ForSupplierApproval, 'Protected Request');
        $purchasing = $this->user(RoleSlug::Purchasing);

        $this->actingAs($purchasing)
            ->get(route('president-approvals.index'))
            ->assertForbidden();

        $this->get(route('request-flow.index', ['stage' => 'supplier-approval']))
            ->assertOk()
            ->assertSee($request->request_number)
            ->assertSee('>View request</a>', false)
            ->assertDontSee('#supplier-approval-actions', false);

        $this->actingAs($purchasing)
            ->post(route('president-approvals.supplier.decision', $request), [
                'decision' => 'REJECT',
                'remarks' => 'Not authorized.',
            ])->assertForbidden();

        $this->actingAs($purchasing)
            ->post(route('canvassing.supplier.approve', $request), [
                'quotation_id' => 1,
            ])->assertForbidden();
    }

    public function test_president_can_view_and_execute_supplier_approval_from_request_flow(): void
    {
        $request = $this->request(PurchaseRequestStatus::ForSupplierApproval, 'President Request');
        $supplier = Supplier::query()->create(['name' => 'President Supplier', 'is_active' => true]);
        $quotation = $request->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $this->user(RoleSlug::Purchasing)->id,
            'item_or_service' => $request->title,
            'price' => 12500,
            'is_recommended' => true,
        ]);
        $president = $this->user(RoleSlug::President);

        $this->actingAs($president)
            ->get(route('request-flow.index', ['stage' => 'supplier-approval']))
            ->assertOk()
            ->assertSee($request->request_number)
            ->assertSee('Supplier Approval');

        $this->actingAs($president)
            ->post(route('president-approvals.supplier.decision', $request), [
                'decision' => 'APPROVE',
                'quotation_id' => $quotation->id,
                'signature_name' => $president->name,
                'signature_data' => $this->signatureData(),
            ])->assertRedirect(route('purchase-requests.show', $request));

        $this->assertSame(PurchaseRequestStatus::ForBudgetProcessing, $request->fresh()->status);
        $this->actingAs($president)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('Submitted to Purchasing.')
            ->assertSee('move to Purchasing for Purchase Order preparation');
    }

    private function request(PurchaseRequestStatus $status, string $title): PurchaseRequest
    {
        $requestor = $this->user(RoleSlug::Requestor);

        return PurchaseRequest::query()->create([
            'request_number' => 'REQ-'.str()->upper(str()->random(8)),
            'title' => $title,
            'requestor_id' => $requestor->id,
            'department_campaign' => 'Operations',
            'request_type' => 'OTHER',
            'purpose' => 'Testing',
            'required_date' => now()->addDays(7),
            'delivery_location' => 'Main Office',
            'status' => $status,
            'current_responsible_role_id' => $this->roleId(RoleSlug::President),
            'total_amount' => 12500,
        ]);
    }

    private function user(RoleSlug $role): User
    {
        $roleModel = Role::query()->where('slug', $role->value)->firstOrFail();

        return User::factory()->create([
            'role_id' => $roleModel->id,
            'campaign_id' => null,
            'department' => 'Operations',
        ]);
    }

    private function roleId(RoleSlug $role): int
    {
        return (int) Role::query()->where('slug', $role->value)->value('id');
    }

    private function signatureData(): string
    {
        return 'data:image/png;base64,'.base64_encode(str_repeat('signature-stroke', 12));
    }
}
