<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FocusedWorkflowDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_legacy_workflow_urls_redirect_all_roles_to_the_current_dashboard(): void
    {
        foreach ([
            RoleSlug::CanvassingStaff,
            RoleSlug::PoWorkflow,
            RoleSlug::BudgetMaker,
            RoleSlug::Admin,
        ] as $role) {
            $user = User::factory()->create([
                'role_id' => Role::query()->where('slug', $role->value)->value('id'),
            ]);

            $this->actingAs($user)
                ->get(route('request-flow.index', ['stage' => 'purchase-order']))
                ->assertRedirect(route('dashboard'));

            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee('Workflow stage');
        }
    }

    public function test_old_purchasing_team_workflow_link_opens_the_matching_dashboard_tab(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', RoleSlug::PurchasingTeam->value)->value('id'),
        ]);

        $this->actingAs($user)
            ->get(route('request-flow.index', ['stage' => 'purchase-order', 'search' => 'REQ-123']))
            ->assertRedirect(route('dashboard', ['search' => 'REQ-123', 'workflow' => 'purchase-order']));

        $this->actingAs($user)
            ->get(route('dashboard', ['workflow' => 'purchase-order']))
            ->assertOk()
            ->assertSee('Purchase Orders for my action')
            ->assertSee('aria-current="page"', false)
            ->assertDontSee('Request Control Center');
    }
}
