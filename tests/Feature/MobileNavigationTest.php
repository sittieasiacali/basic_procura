<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\MobileNavigation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_menu_requires_a_signed_in_account(): void
    {
        $this->get(route('mobile.menu'))->assertRedirect(route('login'));
    }

    public function test_requestor_menu_does_not_offer_purchasing_or_administration_tools(): void
    {
        $user = $this->user('requestor');
        $this->actingAs($user)->get(route('mobile.menu'))->assertOk()
            ->assertSee('New request')->assertSee('Conversations')
            ->assertDontSee('Workflow Tracker')->assertDontSee('System health');
        $groups = app(MobileNavigation::class)->forUser($user)['groups'];
        $this->assertArrayNotHasKey('Administration', $groups);
        $this->assertArrayNotHasKey('Monitoring', $groups);
        $this->get(route('workflow-tracker.index'))->assertForbidden();
    }

    public function test_purchasing_menu_uses_existing_workflow_routes_and_po_queue(): void
    {
        $user = $this->user('purchasing-team');
        $this->actingAs($user)->get(route('mobile.menu'))->assertOk()
            ->assertSee('class="mobile-app-header"', false)
            ->assertSee('Canvassing')->assertSee('Budget Requests')
            ->assertSee('Workflow Tracker')->assertDontSee('System health');
        $navigation = app(MobileNavigation::class)->forUser($user);
        $poTab = collect($navigation['tabs'])->firstWhere('label', 'POs');
        $this->assertSame(route('dashboard', ['workflow' => 'purchase-order']), $poTab['url']);
        $this->assertCount(5, $navigation['tabs']);
        $this->assertSame(1, collect($navigation['tabs'])->where('active', true)->count());
    }

    public function test_president_approvals_and_smt_updates_remain_distinct(): void
    {
        $president = $this->user('president');
        $this->actingAs($president)->get(route('mobile.menu'))->assertOk();
        $tabs = app(MobileNavigation::class)->forUser($president)['tabs'];
        $this->assertSame(route('president-approvals.index'), collect($tabs)->firstWhere('label', 'Approvals')['url']);
        $smt = $this->user('smt');
        $this->actingAs($smt)->get(route('mobile.menu'))->assertOk();
        $tabs = collect(app(MobileNavigation::class)->forUser($smt)['tabs']);
        $this->assertNull($tabs->firstWhere('label', 'Approvals'));
        $this->assertNotNull($tabs->firstWhere('label', 'Updates'));
    }

    public function test_home_and_work_queue_have_separate_mobile_presentations(): void
    {
        $this->actingAs($this->user('purchasing-team'));
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('data-mobile-home="true"', false)->assertSee('mobile-home-recent');
        $this->get(route('dashboard', ['mobile_tab' => 'actions']))->assertOk()
            ->assertSee('data-mobile-home="false"', false)->assertSee('My actions');
        $this->get(route('dashboard', ['workflow' => 'purchase-order']))->assertOk()
            ->assertSee('data-mobile-home="false"', false)->assertSee('Purchase Orders');
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->value('id'),
            'must_change_password' => false,
        ]);
    }
}
