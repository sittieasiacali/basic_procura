<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceSidebarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_sidebar_renders_for_every_role_with_one_current_page(): void
    {
        foreach (RoleSlug::cases() as $role) {
            $user = User::factory()->create([
                'role_id' => Role::where('slug', $role->value)->value('id'),
            ]);
            $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
            preg_match('/<aside\b.*?<\/aside>/s', $html, $matches);
            preg_match('/<nav class="workspace-sidebar-links".*?<\/nav>/s', $matches[0], $primary);
            $this->assertSame(1, substr_count($primary[0], 'aria-current="page"'), $role->value);
            preg_match('/<nav class="workspace-drawer-links".*?<\/nav>/s', $matches[0], $mobile);
            $this->assertLessThanOrEqual(1, substr_count($mobile[0], 'aria-current="page"'), $role->value);
        }
    }

    public function test_sidebar_links_follow_the_active_role(): void
    {
        foreach ([
            [RoleSlug::Requestor, ['Create Request', 'My Requests'], ['Inventory', 'Users', 'Workflow Tracker']],
            [RoleSlug::Finance, ['My Actions', 'Inventory'], ['Create Request', 'Users', 'Workflow Tracker']],
            [RoleSlug::PurchasingTeam, ['Workflow Tracker', 'Voided Monitoring'], ['Create Request', 'Users', 'Inventory']],
            [RoleSlug::Admin, ['Users', 'Manage Data', 'System Health'], []],
        ] as [$role, $visible, $hidden]) {
            $user = User::factory()->create([
                'role_id' => Role::where('slug', $role->value)->value('id'),
            ]);
            $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
            preg_match('/<aside\b.*?<\/aside>/s', $html, $matches);
            $sidebar = $matches[0] ?? '';
            $this->assertNotEmpty($sidebar);
            foreach ($visible as $label) {
                $this->assertStringContainsString($label, $sidebar, $role->value);
            }
            foreach ($hidden as $label) {
                $this->assertStringNotContainsString($label, $sidebar, $role->value);
            }
        }
    }

    public function test_sidebar_changes_when_the_workspace_role_is_switched(): void
    {
        $requestor = Role::where('slug', RoleSlug::Requestor->value)->firstOrFail();
        $purchasing = Role::where('slug', RoleSlug::PurchasingTeam->value)->firstOrFail();
        $user = User::factory()->create(['role_id' => $purchasing->id]);
        $user->roles()->sync([$requestor->id]);
        $this->actingAs($user)->post(route('workspace-role.update'), ['role_id' => $requestor->id])
            ->assertSessionHasNoErrors();
        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        preg_match('/<aside\b.*?<\/aside>/s', $html, $matches);
        $this->assertStringContainsString('My Requests', $matches[0]);
        $this->assertStringNotContainsString('Workflow Tracker', $matches[0]);
        $this->get(route('workflow-tracker.index'))->assertForbidden();
    }
}
