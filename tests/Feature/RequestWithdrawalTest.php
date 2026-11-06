<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\Campaign;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\CampaignSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, CampaignSeeder::class]);
    }

    public function test_requestor_withdraws_and_the_handling_staff_acknowledge_to_void(): void
    {
        $requestor = $this->user(RoleSlug::TeamLeader);
        $request = $this->request($requestor, PurchaseRequestStatus::ForItScreening, 'IT');
        $itStaff = $this->user(RoleSlug::ItStaff);
        $this->assertTrue($itStaff->can('claim', $request));

        $this->actingAs($requestor)->get(route('purchase-requests.show', $request))->assertOk()
            ->assertSee('Withdraw Request')->assertSee('withdraws the <strong>whole request and all its items</strong>', false);
        $this->post(route('purchase-requests.withdraw', $request), ['withdrawal_reason' => ''])
            ->assertSessionHasErrors('withdrawal_reason');

        $this->post(route('purchase-requests.withdraw', $request), ['withdrawal_reason' => 'Equipment no longer needed.'])
            ->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::WithdrawalPending, $request->status);
        $this->assertSame($requestor->id, $request->withdrawn_by);
        $this->assertNotNull($request->withdrawn_at);
        $this->assertSame('FOR_IT_SCREENING', $request->withdrawal_stage);
        $this->assertSame('Equipment no longer needed.', $request->withdrawal_reason);
        $this->assertSame($this->roleId(RoleSlug::ItStaff), $request->current_responsible_role_id);
        $this->assertSame(1, $request->auditLogs()->where('action', 'WITHDRAWAL_REQUESTED')->count());

        // Processing is paused: IT can no longer claim it, and the requestor cannot acknowledge.
        $this->assertFalse($itStaff->fresh()->can('claim', $request));
        $this->get(route('purchase-requests.show', $request))->assertOk()
            ->assertSee('Withdrawal pending acknowledgment')->assertDontSee('Acknowledge Withdrawal</button>', false);
        $this->post(route('purchase-requests.withdrawal.acknowledge', $request))->assertForbidden();
        $this->actingAs($this->user(RoleSlug::PremisesStaff))->post(route('purchase-requests.withdrawal.acknowledge', $request))->assertForbidden();

        // The IT staff handling the stage see it on their dashboard and acknowledge it.
        $this->actingAs($itStaff)->get(route('dashboard'))->assertOk()
            ->assertSee('Withdrawals to acknowledge')->assertSee('Equipment no longer needed.');
        $this->post(route('purchase-requests.withdrawal.acknowledge', $request), ['acknowledgment_remarks' => 'Noted, screening stopped.'])
            ->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::Voided, $request->status);
        $this->assertSame($itStaff->id, $request->withdrawal_acknowledged_by);
        $this->assertNotNull($request->withdrawal_acknowledged_at);
        $this->assertNull($request->current_responsible_role_id);
        $this->actingAs($itStaff)->get(route('dashboard'))->assertOk()->assertDontSee('Withdrawals to acknowledge');

        // Kept in history with the full record.
        $this->actingAs($requestor)->get(route('purchase-requests.show', $request))->assertOk()
            ->assertSee('Request voided')->assertSee('IT screening')->assertSee($itStaff->name)
            ->assertSee('Noted, screening stopped.')->assertDontSee('data-bs-target="#withdraw-request-modal"', false);
        // Voided monitoring: in the Voided tab, not in Completed or the pending cards.
        $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('voidedCount', 1)
            ->assertViewHas('voidPendingCount', 0)
            ->assertViewHas('doneRequests', fn ($rows) => ! $rows->getCollection()->contains('id', $request->id))
            ->assertViewHas('pendingRequests', fn ($rows) => ! $rows->contains('id', $request->id));
        $this->get(route('dashboard', ['request_tab' => 'voided']))->assertOk()
            ->assertSee('Voided requests')->assertSee($request->request_number)
            ->assertSee('Equipment no longer needed.')->assertSee($itStaff->name);
    }

    public function test_withdrawal_is_allowed_only_before_a_purchase_order_exists(): void
    {
        $requestor = $this->user(RoleSlug::TeamLeader);
        $withoutPo = $this->request($requestor, PurchaseRequestStatus::ForBudgetProcessing, 'IT');
        $withPo = $this->request($requestor, PurchaseRequestStatus::ForBudgetProcessing, 'IT');
        $withPo->purchaseOrder()->create([
            'po_number' => 'PO-WD-1', 'supplier_id' => Supplier::query()->create(['name' => 'WD Supplier', 'is_active' => true])->id,
            'created_by' => $requestor->id, 'issued_at' => now(), 'status' => 'FOR_CHECKING', 'total_amount' => 100,
        ]);

        $this->actingAs($requestor)->get(route('purchase-requests.show', $withPo))->assertOk()
            ->assertDontSee('data-bs-target="#withdraw-request-modal"', false);
        $this->post(route('purchase-requests.withdraw', $withPo), ['withdrawal_reason' => 'Changed our mind.'])->assertForbidden();
        $this->assertSame(PurchaseRequestStatus::ForBudgetProcessing, $withPo->fresh()->status);

        $this->post(route('purchase-requests.withdraw', $withoutPo), ['withdrawal_reason' => 'Changed our mind.'])->assertSessionHasNoErrors();
        $withoutPo->refresh();
        $this->assertSame(PurchaseRequestStatus::WithdrawalPending, $withoutPo->status);
        $this->assertTrue($this->user(RoleSlug::Purchasing)->can('acknowledgeWithdrawal', $withoutPo));
        $this->assertFalse($this->user(RoleSlug::ItStaff)->can('acknowledgeWithdrawal', $withoutPo));
    }

    public function test_withdrawal_form_keeps_the_browser_scheme_when_the_origin_uses_http(): void
    {
        $owner = $this->user(RoleSlug::TeamLeader);
        $request = $this->request($owner, PurchaseRequestStatus::ForItScreening, 'IT');

        $this->actingAs($owner)->get(route('purchase-requests.show', $request))->assertOk()
            ->assertSee('action="'.route('purchase-requests.withdraw', $request, false).'"', false)
            ->assertDontSee('action="'.route('purchase-requests.withdraw', $request).'"', false);
    }

    public function test_requestor_dashboard_offers_void_only_on_withdrawable_cards(): void
    {
        $requestor = $this->user(RoleSlug::TeamLeader);
        $withdrawable = $this->request($requestor, PurchaseRequestStatus::ForOmApproval, null);
        $withPo = $this->request($requestor, PurchaseRequestStatus::ForBudgetProcessing, 'IT');
        $withPo->purchaseOrder()->create([
            'po_number' => 'PO-WD-2', 'supplier_id' => Supplier::query()->create(['name' => 'WD Supplier 2', 'is_active' => true])->id,
            'created_by' => $requestor->id, 'issued_at' => now(), 'status' => 'FOR_CHECKING', 'total_amount' => 100,
        ]);

        $this->actingAs($requestor)->get(route('dashboard'))->assertOk()
            ->assertSee('id="void-request-modal"', false)
            ->assertSee('data-void-url="'.route('purchase-requests.withdraw', $withdrawable, false).'"', false)
            ->assertDontSee('data-void-url="'.route('purchase-requests.withdraw', $withPo, false).'"', false);

        // Voiding from the dashboard returns there.
        $this->from(route('dashboard'))->post(route('purchase-requests.withdraw', $withdrawable), ['withdrawal_reason' => 'Duplicate request.'])
            ->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk()
            ->assertDontSee('data-void-url="'.route('purchase-requests.withdraw', $withdrawable, false).'"', false)
            ->assertSee('Withdrawal Pending Acknowledgment');
        $this->get(route('dashboard', ['request_tab' => 'voided']))->assertOk()
            ->assertViewHas('voidPendingCount', 1)
            ->assertSee('1 pending')->assertSee('Waiting ·')->assertSee('Duplicate request.');
    }

    public function test_screeners_monitor_their_stages_and_purchasing_uses_the_shared_register(): void
    {
        $requestor = $this->user(RoleSlug::TeamLeader);
        $itRequest = $this->request($requestor, PurchaseRequestStatus::ForItScreening, 'IT');
        $premisesRequest = $this->request($requestor, PurchaseRequestStatus::ForPremisesScreening, 'PREMISES');
        $canvassRequest = $this->request($requestor, PurchaseRequestStatus::ForCanvassing, 'IT');
        $this->actingAs($requestor);
        foreach ([$itRequest, $premisesRequest, $canvassRequest] as $request) {
            $this->post(route('purchase-requests.withdraw', $request), ['withdrawal_reason' => 'Reason for '.$request->request_number])->assertSessionHasNoErrors();
        }
        $itStaff = $this->user(RoleSlug::ItStaff);
        $this->actingAs($itStaff)->post(route('purchase-requests.withdrawal.acknowledge', $itRequest))->assertSessionHasNoErrors();

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Voided requests')
            ->assertViewHas('voidedMonitoring', fn ($rows) => $rows->pluck('id')->all() === [$itRequest->id])
            ->assertSee('Reason for '.$itRequest->request_number)->assertSee($itStaff->name);
        $this->get(route('dashboard', ['voided_status' => 'WITHDRAWAL_PENDING']))->assertOk()
            ->assertViewHas('voidedMonitoring', fn ($rows) => $rows->total() === 0);

        $this->actingAs($this->user(RoleSlug::PremisesStaff))->get(route('dashboard'))->assertOk()
            ->assertViewHas('voidedMonitoring', fn ($rows) => $rows->pluck('id')->all() === [$premisesRequest->id])
            ->assertSee('data-workflow-action-title="Acknowledge withdrawal"', false);

        $this->actingAs($this->user(RoleSlug::PurchasingTeam))->get(route('dashboard'))->assertOk()
            ->assertViewHas('voidedMonitoring', null)
            ->assertDontSee('voided-monitoring-heading', false)
            ->assertDontSee('Withdrawals to acknowledge')
            ->assertSee(route('voided-monitoring.index'), false);
        $this->get(route('voided-monitoring.index'))->assertOk()
            ->assertViewHas('voidedMonitoring', fn ($rows) => $rows->total() === 3)
            ->assertSee($itRequest->request_number)->assertSee($premisesRequest->request_number)->assertSee($canvassRequest->request_number);

        // Other roles do not get this panel.
        $this->actingAs($this->user(RoleSlug::Finance))->get(route('dashboard'))->assertOk()
            ->assertViewHas('voidedMonitoring', null)->assertDontSee('voided-monitoring-heading', false);
    }

    public function test_only_the_requestor_can_withdraw_a_submitted_request(): void
    {
        $requestor = $this->user(RoleSlug::TeamLeader);
        $submitted = $this->request($requestor, PurchaseRequestStatus::ForOmApproval, null);
        $draft = $this->request($requestor, PurchaseRequestStatus::Draft, null);

        $this->actingAs($this->user(RoleSlug::OmSmtsApprover))
            ->post(route('purchase-requests.withdraw', $submitted), ['withdrawal_reason' => 'Not mine to withdraw.'])->assertForbidden();
        $this->actingAs($requestor)
            ->post(route('purchase-requests.withdraw', $draft), ['withdrawal_reason' => 'Still a draft.'])->assertForbidden();

        $this->post(route('purchase-requests.withdraw', $submitted), ['withdrawal_reason' => 'Duplicate request.'])->assertSessionHasNoErrors();
        $this->assertTrue($this->user(RoleSlug::OmSmtsApprover)->can('acknowledgeWithdrawal', $submitted->fresh()));
    }

    public function test_all_four_purchasing_workflow_views_link_to_one_voided_monitor(): void
    {
        $this->actingAs($this->user(RoleSlug::PurchasingTeam));
        foreach ([
            ['workflow' => 'canvassing'],
            ['workflow' => 'purchase-order'],
            ['workflow' => 'budget-request'],
            ['workflow' => 'budget-request', 'budget_view' => 'processed'],
        ] as $query) {
            $response = $this->get(route('dashboard', $query))->assertOk()
                ->assertSeeInOrder(['Workflow Tracker', 'Voided Monitoring'])
                ->assertDontSee('id="voided-monitoring-heading"', false);
            $this->assertSame(1, substr_count($response->getContent(), 'href="'.route('voided-monitoring.index').'"'));
        }
    }

    public function test_shared_monitor_preserves_acknowledgment_permissions_and_history(): void
    {
        $owner = $this->user(RoleSlug::TeamLeader);
        $screener = $this->user(RoleSlug::ItStaff);
        $team = $this->user(RoleSlug::PurchasingTeam);
        $screening = $this->request($owner, PurchaseRequestStatus::ForItScreening, 'IT');
        $canvassing = $this->request($owner, PurchaseRequestStatus::ForCanvassing, 'IT');
        $poPreparation = $this->request($owner, PurchaseRequestStatus::ForBudgetProcessing, 'IT');
        foreach ([$screening, $canvassing, $poPreparation] as $row) {
            $this->actingAs($owner)->post(route('purchase-requests.withdraw', $row), ['withdrawal_reason' => 'No longer needed.'])->assertSessionHasNoErrors();
        }
        $this->actingAs($screener)->post(route('purchase-requests.withdrawal.acknowledge', $screening))->assertSessionHasNoErrors();

        $this->actingAs($team)->get(route('voided-monitoring.index'))->assertOk()
            ->assertSee('No longer needed.')->assertSee($owner->name)->assertSee($screener->name)
            ->assertSee('IT screening')->assertSee('Canvassing')->assertSee('Purchase Order preparation')
            ->assertViewHas('voidedMonitoring', fn ($rows) => $rows->pluck('id')->all() === [$poPreparation->id, $canvassing->id, $screening->id]);
        $this->post(route('purchase-requests.withdrawal.acknowledge', $screening))->assertForbidden();
        $this->post(route('purchase-requests.withdrawal.acknowledge', $canvassing))->assertSessionHasNoErrors();
        $this->get(route('voided-monitoring.index', ['voided_status' => 'VOIDED']))->assertOk()
            ->assertSee($canvassing->request_number)->assertSee($team->name)
            ->assertDontSee('data-workflow-action-title="Acknowledge withdrawal"', false);
    }

    public function test_shared_monitor_filters_and_paginates_without_losing_filters(): void
    {
        $owner = $this->user(RoleSlug::TeamLeader);
        for ($index = 0; $index < 17; $index++) {
            $row = $this->request($owner, PurchaseRequestStatus::WithdrawalPending, 'IT');
            $row->forceFill([
                'withdrawal_reason' => 'Duplicate equipment request.', 'withdrawal_stage' => 'FOR_CANVASSING',
                'withdrawn_at' => now()->subDays(2), 'withdrawn_by' => $owner->id,
            ])->save();
        }
        $this->request($owner, PurchaseRequestStatus::ForCanvassing, 'IT');
        $this->actingAs($this->user(RoleSlug::PurchasingTeam));
        $filters = ['voided_search' => 'Duplicate equipment', 'voided_to' => now()->toDateString()];
        $this->get(route('voided-monitoring.index', $filters))->assertOk()
            ->assertViewHas('voidedMonitoring', fn ($rows) => $rows->total() === 17 && $rows->count() === 15)
            ->assertSee('voided_search=Duplicate%20equipment', false);
        $this->get(route('voided-monitoring.index', [...$filters, 'voided_page' => 2]))->assertOk()
            ->assertViewHas('voidedMonitoring', fn ($rows) => $rows->count() === 2);
        $this->get(route('voided-monitoring.index', ['voided_status' => 'VOIDED']))->assertOk()->assertSee('No voided requests');
        $this->get(route('voided-monitoring.index', ['voided_search' => $owner->name]))->assertOk()
            ->assertViewHas('voidedMonitoring', fn ($rows) => $rows->total() === 17);
        $this->get(route('voided-monitoring.index', ['voided_from' => now()->toDateString()]))->assertOk()
            ->assertViewHas('voidedMonitoring', fn ($rows) => $rows->total() === 0);
        $this->get(route('voided-monitoring.index', ['voided_status' => 'FOR_CANVASSING']))->assertSessionHasErrors('voided_status');
        $this->get(route('voided-monitoring.index', ['voided_from' => 'invalid']))->assertSessionHasErrors('voided_from');
    }

    public function test_shared_monitor_requires_purchasing_team_or_admin_access(): void
    {
        $this->get(route('voided-monitoring.index'))->assertRedirect(route('login'));
        foreach ([RoleSlug::TeamLeader, RoleSlug::ItStaff, RoleSlug::Finance] as $role) {
            $this->actingAs($this->user($role))->get(route('voided-monitoring.index'))->assertForbidden();
        }
        $this->actingAs($this->user(RoleSlug::Admin))->get(route('voided-monitoring.index'))->assertOk();
    }

    public function test_monitor_acknowledges_directly_and_keeps_workflow_and_account_history(): void
    {
        $owner = $this->user(RoleSlug::TeamLeader);
        $staff = $this->user(RoleSlug::PurchasingTeam);
        $row = $this->request($owner, PurchaseRequestStatus::ForCanvassing, 'IT');
        $row->update(['assigned_user_id' => $staff->id, 'current_responsible_role_id' => $staff->role_id]);
        $this->actingAs($owner)->post(route('purchase-requests.withdraw', $row), [
            'withdrawal_reason' => 'Equipment is no longer required.',
        ])->assertSessionHasNoErrors();

        $this->actingAs($staff)->get(route('voided-monitoring.index'))->assertOk()
            ->assertSee('Account owner')->assertSee('Workflow stopped at')
            ->assertSee($owner->email)->assertSee('Handling staff')->assertSee($staff->name)
            ->assertSee($staff->email)->assertSee('Purchasing Team')
            ->assertSee('data-bs-target="#acknowledge-withdrawal-'.$row->id.'"', false)
            ->assertSee('action="'.route('purchase-requests.withdrawal.acknowledge', $row, false).'"', false)
            ->assertSee('name="return_to_monitoring" value="1"', false);

        $this->post(route('purchase-requests.withdrawal.acknowledge', $row), [
            'acknowledgment_remarks' => 'Canvassing stopped; no supplier order placed.',
            'return_to_monitoring' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect(route('voided-monitoring.index'));
        $row->refresh();
        $this->assertSame(PurchaseRequestStatus::Voided, $row->status);
        $this->assertSame($staff->id, $row->withdrawal_acknowledged_by);
        $this->assertNull($row->assigned_user_id);
        $log = $row->auditLogs()->where('action', 'WITHDRAWAL_ACKNOWLEDGED')->sole();
        $this->assertSame('FOR_CANVASSING', $log->metadata['stopped_stage']);
        $this->assertSame('Canvassing', $log->metadata['stopped_stage_label']);
        $this->assertSame($staff->name, $log->metadata['acknowledged_by_name']);
        $this->assertSame($staff->email, $log->metadata['acknowledged_by_email']);
        $this->assertSame('Purchasing Team', $log->metadata['acknowledged_by_role']);

        // Account changes after acknowledgment must not rewrite the recorded owners.
        $ownerName = $owner->name;
        $staffName = $staff->name;
        $staffEmail = $staff->email;
        $owner->update(['name' => 'Updated requestor']);
        $staff->update(['name' => 'Updated staff', 'email' => 'updated-staff@example.test', 'role_id' => $this->roleId(RoleSlug::Finance)]);
        $this->actingAs($this->user(RoleSlug::Admin))->get(route('voided-monitoring.index'))->assertOk()
            ->assertSee($ownerName)->assertSee($staffName)->assertSee($staffEmail)
            ->assertSee('Canvassing stopped; no supplier order placed.')
            ->assertDontSee('data-bs-target="#acknowledge-withdrawal-'.$row->id.'"', false);
        $this->post(route('purchase-requests.withdrawal.acknowledge', $row), ['return_to_monitoring' => 1])->assertStatus(409);
        $this->assertSame(1, $row->auditLogs()->where('action', 'WITHDRAWAL_ACKNOWLEDGED')->count());
        $this->assertSame($staff->id, $row->fresh()->withdrawal_acknowledged_by);
    }

    public function test_monitor_only_offers_direct_acknowledgment_to_handling_staff(): void
    {
        $owner = $this->user(RoleSlug::TeamLeader);
        $row = $this->request($owner, PurchaseRequestStatus::ForItScreening, 'IT');
        $this->actingAs($owner)->post(route('purchase-requests.withdraw', $row), ['withdrawal_reason' => 'No longer required.'])->assertSessionHasNoErrors();
        $this->actingAs($this->user(RoleSlug::PurchasingTeam))->get(route('voided-monitoring.index'))->assertOk()
            ->assertSee('Not assigned to an individual')->assertSee('IT Screening')
            ->assertDontSee('data-bs-target="#acknowledge-withdrawal-'.$row->id.'"', false);
        $this->post(route('purchase-requests.withdrawal.acknowledge', $row), ['return_to_monitoring' => 1])->assertForbidden();
        $this->assertSame(PurchaseRequestStatus::WithdrawalPending, $row->fresh()->status);
        $this->assertNull($row->withdrawal_acknowledged_by);
    }

    public function test_invalid_monitor_acknowledgment_does_not_close_the_request(): void
    {
        $owner = $this->user(RoleSlug::TeamLeader);
        $row = $this->request($owner, PurchaseRequestStatus::ForCanvassing, 'IT');
        $this->actingAs($owner)->post(route('purchase-requests.withdraw', $row), ['withdrawal_reason' => 'No longer required.'])->assertSessionHasNoErrors();
        $this->actingAs($this->user(RoleSlug::PurchasingTeam))->from(route('voided-monitoring.index'))
            ->post(route('purchase-requests.withdrawal.acknowledge', $row), [
                'acknowledgment_remarks' => str_repeat('x', 2001), 'return_to_monitoring' => 1,
            ])->assertRedirect(route('voided-monitoring.index'))->assertSessionHasErrors('acknowledgment_remarks');
        $this->assertSame(PurchaseRequestStatus::WithdrawalPending, $row->fresh()->status);
        $this->assertSame(0, $row->auditLogs()->where('action', 'WITHDRAWAL_ACKNOWLEDGED')->count());
    }

    private function request(User $owner, PurchaseRequestStatus $status, ?string $team): PurchaseRequest
    {
        $this->sequence++;
        $request = PurchaseRequest::query()->create([
            'request_number' => 'WD-'.$this->sequence, 'title' => 'Withdrawal check '.$this->sequence,
            'requestor_id' => $owner->id, 'department_campaign' => $owner->campaign?->name ?? 'Operations',
            'request_type' => $team ?? 'UNCLASSIFIED', 'requested_team' => $team, 'purpose' => 'Testing withdrawal',
            'required_date' => now()->addWeek(), 'delivery_location' => 'Main Office',
            'status' => $status, 'total_amount' => 100,
            'current_responsible_role_id' => match ($status) {
                PurchaseRequestStatus::ForItScreening => $this->roleId(RoleSlug::ItStaff),
                PurchaseRequestStatus::ForOmApproval => $this->roleId(RoleSlug::OmSmtsApprover),
                PurchaseRequestStatus::ForBudgetProcessing => $this->roleId(RoleSlug::Purchasing),
                default => null,
            },
        ]);
        $request->items()->create(['description' => 'Laptop', 'quantity' => 1, 'unit' => 'pc']);

        return $request;
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create([
            'role_id' => $this->roleId($role),
            'campaign_id' => in_array($role, [RoleSlug::Requestor, RoleSlug::TeamLeader], true) ? Campaign::query()->value('id') : null,
        ]);
    }

    private function roleId(RoleSlug $role): int
    {
        return Role::query()->where('slug', $role->value)->value('id');
    }
}
