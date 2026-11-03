<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanvassingWithoutQuotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_multi_role_purchasing_can_submit_without_quotation_and_president_sees_it(): void
    {
        $user = $this->user(RoleSlug::Requestor);
        $user->roles()->attach($this->roleId(RoleSlug::PurchasingTeam));
        $request = $this->requestFor($user);
        $this->actingAs($user)->withSession(['workspace_role_id' => $this->roleId(RoleSlug::PurchasingTeam)])
            ->get(route('purchase-requests.show', $request))->assertOk()->assertSee('Proceed without quotation');
        $this->post(route('canvassing.without-quotation', $request), ['remarks' => 'Quotation will follow.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->fresh()->status);
        $this->assertSame($this->roleId(RoleSlug::President), $request->fresh()->current_responsible_role_id);
        $this->assertSame(0, $request->quotations()->count());
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $request->id, 'action' => 'CANVASSING_SUBMITTED_WITHOUT_QUOTATION']);
        $this->actingAs($this->user(RoleSlug::President))->get(route('president-approvals.index'))
            ->assertOk()->assertSee($request->request_number);
        $this->get(route('dashboard'))->assertOk()->assertSee($request->request_number);
    }

    public function test_requestor_and_screening_workspaces_cannot_bypass_canvassing(): void
    {
        foreach ([RoleSlug::Requestor, RoleSlug::ItStaff] as $role) {
            $user = $this->user($role);
            $request = $this->requestFor($user);
            $this->actingAs($user)->post(route('canvassing.without-quotation', $request))->assertForbidden();
            $this->assertSame(PurchaseRequestStatus::ForCanvassing, $request->fresh()->status);
        }
    }

    public function test_proceeding_without_an_additional_quote_preserves_existing_quotations(): void
    {
        $user = $this->user(RoleSlug::PurchasingTeam);
        $request = $this->requestFor($user);
        $supplier = Supplier::create(['name' => 'Existing supplier', 'is_active' => true]);
        $request->quotations()->create(['supplier_id' => $supplier->id, 'created_by' => $user->id, 'item_or_service' => 'Parts', 'price' => 100, 'quoted_at' => now()]);
        $this->actingAs($user)->post(route('canvassing.without-quotation', $request))->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->fresh()->status);
        $this->assertSame(1, $request->quotations()->count());
    }

    public function test_submitting_twice_does_not_create_duplicate_handoffs(): void
    {
        $user = $this->user(RoleSlug::PurchasingTeam);
        $request = $this->requestFor($user);
        $this->actingAs($user)->post(route('canvassing.without-quotation', $request), ['_embedded' => 1])
            ->assertRedirect(route('workflow.done'));
        $this->post(route('canvassing.without-quotation', $request))->assertForbidden();
        $this->assertSame(1, $request->auditLogs()->where('action', 'CANVASSING_SUBMITTED_WITHOUT_QUOTATION')->count());
    }

    public function test_president_sees_the_missing_pricing_notice_and_can_sign_an_acknowledgement(): void
    {
        $request = $this->requestFor($this->user(RoleSlug::PurchasingTeam));
        $request->update(['status' => PurchaseRequestStatus::ForSupplierApproval, 'current_responsible_role_id' => $this->roleId(RoleSlug::President)]);
        $president = $this->user(RoleSlug::President);
        $this->actingAs($president)->get(route('president-approvals.index'))->assertOk()->assertSee('No quotation / pricing provided');
        $this->get(route('purchase-requests.show', $request))->assertOk()
            ->assertSee('No supplier quotation or pricing provided.')
            ->assertSee('Acknowledge &amp; return to Purchasing', false)->assertDontSee('Approve Selected Supplier');
        $payload = ['decision' => 'ACKNOWLEDGE', ...$this->workflowSignature($president)];
        $this->post(route('president-approvals.supplier.decision', $request), $payload)->assertSessionHasNoErrors();
        $this->assertSame($request->request_number, session('workflow_request_code'));
        $this->assertSame(PurchaseRequestStatus::ForCanvassing, $request->fresh()->status);
        $this->assertNull($request->fresh()->selected_supplier_id);
        $this->assertSame(0, $request->quotations()->count());
        $this->assertSame(0, $request->purchaseOrder()->count());
        $this->get(route('president-approvals.index', ['tab' => 'acknowledged']))->assertOk()->assertSee($request->request_number);
        $this->get(route('president-approvals.index', ['tab' => 'approved']))->assertOk()->assertDontSee($request->request_number);
    }

    public function test_main_submit_accepts_empty_manual_and_bulk_rows_after_confirmation(): void
    {
        foreach (['manual', 'bulk'] as $mode) {
            $user = $this->user(RoleSlug::PurchasingTeam);
            $request = $this->requestFor($user);
            $row = ['supplier_name' => '', 'quoted_amount' => '', 'vat_treatment' => 'EXEMPT', 'delivery_location' => 'Main Office'];
            if ($mode === 'bulk') {
                $row['items'] = [['item_id' => '', 'quantity' => '1', 'unit_price' => '0']];
            }
            $this->actingAs($user)->post(route('canvassing.quotations.store', $request), [
                'quotation_mode' => $mode, 'quotations' => [$row], 'confirmed_without_quotation' => 1, '_embedded' => 1,
            ])->assertRedirect(route('workflow.done'))->assertSessionHasNoErrors()
                ->assertSessionHas('workflow_request_code', $request->request_number);
            $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->fresh()->status);
            $this->assertSame(0, $request->quotations()->count());
            $this->get(route('workflow.done'))->assertOk()->assertSee($request->request_number)->assertSee('Sent to President review');
        }
    }

    public function test_main_submit_requires_confirmation_for_empty_forms_and_validates_partial_quotes(): void
    {
        $user = $this->user(RoleSlug::PurchasingTeam);
        $request = $this->requestFor($user);
        $this->actingAs($user)->post(route('canvassing.quotations.store', $request), ['quotation_mode' => 'manual', 'quotations' => []])
            ->assertSessionHasErrors('confirmed_without_quotation');
        $this->post(route('canvassing.quotations.store', $request), [
            'quotation_mode' => 'manual', 'confirmed_without_quotation' => 1, 'quotations' => [['supplier_name' => 'Partial supplier']],
        ])->assertSessionHasErrors('quotations.0.quoted_amount');
        $this->assertSame(PurchaseRequestStatus::ForCanvassing, $request->fresh()->status);
    }

    public function test_it_and_premises_can_submit_to_canvassing_without_quotation_and_receive_request_code(): void
    {
        foreach ([RoleSlug::ItStaff, RoleSlug::PremisesStaff] as $role) {
            $user = $this->user($role);
            $request = $this->requestFor($user);
            $request->update(['status' => PurchaseRequestStatus::ForProcurement, 'request_type' => $role === RoleSlug::ItStaff ? 'IT' : 'PREMISES', 'assigned_user_id' => $user->id]);
            $request->screening()->create(['screened_by' => $user->id, 'team' => $request->request_type, 'decision' => 'UNAVAILABLE', 'decided_at' => now()]);
            $this->actingAs($user)->post(route('canvassing.quotations.store', $request), [
                'quotation_mode' => 'manual', 'quotations' => [], 'confirmed_without_quotation' => 1,
            ])->assertSessionHasNoErrors()->assertSessionHas('workflow_request_code', $request->request_number);
            $this->assertSame(PurchaseRequestStatus::ForCanvassing, $request->fresh()->status);
            $this->assertSame(0, $request->quotations()->count());
        }
    }

    public function test_president_queue_does_not_hide_a_pending_handoff_with_a_stale_responsible_role(): void
    {
        $request = $this->requestFor($this->user(RoleSlug::PurchasingTeam));
        $request->update(['status' => PurchaseRequestStatus::ForSupplierApproval]);
        $this->actingAs($this->user(RoleSlug::President))->get(route('president-approvals.index'))
            ->assertOk()->assertSee($request->request_number);
    }

    public function test_normal_quotation_handoff_from_multiple_roles_closes_the_modal_and_reaches_president(): void
    {
        $user = $this->user(RoleSlug::Requestor);
        $user->roles()->sync([$this->roleId(RoleSlug::PurchasingTeam), $this->roleId(RoleSlug::PoChecker)]);
        $request = $this->requestFor($user);
        $this->actingAs($user)->withSession(['workspace_role_id' => $this->roleId(RoleSlug::PurchasingTeam)])
            ->post(route('canvassing.quotations.store', $request), [
                '_embedded' => 1, 'quotation_mode' => 'manual', 'quotations' => [[
                    'supplier_name' => 'Quoted supplier', 'quoted_amount' => 800, 'vat_treatment' => 'EXEMPT',
                    'delivery_lead_time' => '3 days', 'delivery_location' => SupplierQuotation::DELIVERY_LOCATIONS[0],
                    'warranty' => '1 year', 'quotation_url' => 'https://example.test/quotation',
                    'bank_details' => 'Supplier payment details', 'recommended' => 1,
                ]],
            ])->assertRedirect(route('workflow.done'))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->fresh()->status);
        $quotation = $request->quotations()->firstOrFail();
        $president = $this->user(RoleSlug::President);
        $this->actingAs($president)->get(route('president-approvals.index'))->assertOk()->assertSee($request->request_number)->assertSee('Quoted supplier');
        $this->post(route('president-approvals.supplier.decision', $request), ['decision' => 'ACKNOWLEDGE', ...$this->workflowSignature($president)])
            ->assertSessionHasErrors('decision');
        $this->post(route('president-approvals.supplier.decision', $request), [
            'decision' => 'APPROVE', 'quotation_id' => $quotation->id, ...$this->workflowSignature($president),
        ])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForBudgetProcessing, $request->fresh()->status);
        $this->assertTrue($quotation->fresh()->is_approved);
    }

    private function roleId(RoleSlug $role): int
    {
        return Role::where('slug', $role->value)->firstOrFail()->id;
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create(['role_id' => $this->roleId($role)]);
    }

    private function requestFor(User $user): PurchaseRequest
    {
        return PurchaseRequest::create([
            'request_number' => 'REQ-NO-QUOTE-'.$user->id, 'title' => 'Optional quotation request',
            'requestor_id' => $user->id, 'request_type' => 'IT', 'department_campaign' => 'Operations',
            'purpose' => 'Review without supplier quotation.', 'required_date' => now()->addWeek(),
            'delivery_location' => 'Main office', 'status' => PurchaseRequestStatus::ForCanvassing,
            'current_responsible_role_id' => $this->roleId(RoleSlug::CanvassingStaff),
        ]);
    }
}
