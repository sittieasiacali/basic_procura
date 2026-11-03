<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasingTeamAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_creator_can_reach_requested_queues_but_not_downstream_workflows(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', RoleSlug::PurchasingTeam->value)->value('id'),
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Canvassing')
            ->assertSee('Purchase Orders')
            ->assertSee('Prepare Budget')
            ->assertSee('Final Budget Request')
            ->assertSee(route('dashboard', ['workflow' => 'canvassing']), false)
            ->assertSee(route('dashboard', ['workflow' => 'purchase-order']), false)
            ->assertSee(route('dashboard', ['workflow' => 'budget-request']), false);
        $this->actingAs($user)->get(route('dashboard', ['workflow' => 'canvassing']))->assertOk();
        $this->actingAs($user)->get(route('dashboard', ['workflow' => 'purchase-order']))->assertOk();
        $this->actingAs($user)->get(route('dashboard', ['workflow' => 'budget-request']))->assertOk();
        $this->actingAs($user)->get(route('dashboard', ['workflow' => 'budget-request', 'budget_view' => 'processed']))
            ->assertOk()
            ->assertSee('id="processed-requests-heading"', false)
            ->assertSee('name="budget_view" value="processed"', false)
            ->assertDontSee('id="my-actions-heading"', false)
            ->assertViewHas('creatorWorkflow', 'budget-request');
        $this->actingAs($user)->get(route('budget.index'))->assertOk();
        $this->actingAs($user)->get(route('request-flow.index', ['stage' => 'final-approval']))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($user->hasPoRole(PurchaseOrderApprovalStage::PreparedBy));
        $this->assertFalse($user->hasPoRole(PurchaseOrderApprovalStage::CheckedBy));
        $this->assertFalse($user->can('create', PurchaseRequest::class));
        $this->assertFalse($user->can('processPayment', new PurchaseRequest));
        $this->assertFalse($user->can('receiveGoods', new PurchaseRequest));
        $this->assertFalse($user->can('reviewBudgetFinal', new PurchaseRequest));
        $this->assertTrue($user->can('reviewBudgetFinal', new PurchaseRequest(['status' => PurchaseRequestStatus::AwaitingPresidentFinalApproval])));
        $this->assertTrue($user->can('prepare', PurchaseOrder::class));
    }

    public function test_creator_can_manage_canvassing_and_prepare_eligible_budget_request(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', RoleSlug::PurchasingTeam->value)->value('id'),
        ]);
        $request = new PurchaseRequest(['status' => PurchaseRequestStatus::ForCanvassing]);

        $this->assertTrue($user->can('manageCanvassing', $request));
        $this->assertFalse($user->can('manageBudget', $request));
    }
}
