<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\RoleSlug;
use App\Models\Campaign;
use App\Models\PoRoleAssignment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultipleAccountRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_creates_multiple_roles_and_both_are_filterable_and_visible(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $payload = $this->payload([RoleSlug::PurchasingTeam, RoleSlug::Requestor]);
        $this->actingAs($admin)->post(route('admin.accounts.store'), $payload)->assertSessionHasNoErrors();
        $account = User::where('email', $payload['email'])->firstOrFail();
        $this->assertCount(2, $account->assignedRoles());
        $this->assertSame($this->roleId(RoleSlug::PurchasingTeam), $account->role_id);
        $this->get(route('admin.accounts.index', ['role_id' => $this->roleId(RoleSlug::Requestor)]))
            ->assertOk()->assertSee($account->email)->assertSee('PURCHASING')->assertSee('TL / REQUESTOR');
        $this->get(route('dashboard', ['accounts_role_id' => $this->roleId(RoleSlug::Requestor)]))
            ->assertOk()->assertSee($account->email);
        $this->get(route('admin.accounts.edit', $account))->assertOk()->assertSee('name="role_ids[]"', false);
    }

    public function test_requestor_requirements_apply_even_when_requestor_is_secondary(): void
    {
        $payload = $this->payload([RoleSlug::PurchasingTeam, RoleSlug::Requestor]);
        unset($payload['campaign_id'], $payload['om_notification_email'], $payload['smt_notification_email']);
        $this->actingAs($this->user(RoleSlug::Admin))->post(route('admin.accounts.store'), $payload)
            ->assertSessionHasErrors(['campaign_id', 'om_notification_email', 'smt_notification_email']);
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_no_roles_duplicate_roles_and_unknown_roles_are_rejected(): void
    {
        $this->actingAs($this->user(RoleSlug::Admin));
        foreach ([[], [$this->roleId(RoleSlug::Finance), $this->roleId(RoleSlug::Finance)], [99999]] as $ids) {
            $payload = [...$this->payload([RoleSlug::Finance]), 'role_ids' => $ids];
            $response = $this->post(route('admin.accounts.store'), $payload);
            $this->assertNotEmpty(session('errors')->getBag('default')->getMessages());
            $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
            $response->assertRedirect();
        }
    }

    public function test_switching_changes_permissions_without_changing_saved_primary_role(): void
    {
        $user = $this->user(RoleSlug::PurchasingTeam, [RoleSlug::Requestor]);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Switch role');
        $this->post(route('workspace-role.update'), ['role_id' => $this->roleId(RoleSlug::Requestor)])
            ->assertRedirect(route('dashboard'))->assertSessionHas('workspace_role_id', $this->roleId(RoleSlug::Requestor));
        $this->get(route('dashboard'))->assertOk()->assertSee('My requests');
        $this->get(route('workflow-tracker.index'))->assertForbidden();
        $this->get(route('purchase-requests.create'))->assertOk();
        $this->assertSame($this->roleId(RoleSlug::PurchasingTeam), $user->fresh()->role_id);

        $this->post(route('workspace-role.update'), ['role_id' => $this->roleId(RoleSlug::PurchasingTeam)])
            ->assertSessionHasNoErrors();
        $this->get(route('workflow-tracker.index'))->assertOk();
        $this->get(route('purchase-requests.create'))->assertForbidden();
    }

    public function test_an_unassigned_role_cannot_be_selected_or_used_to_manage_accounts(): void
    {
        $user = $this->user(RoleSlug::Requestor);
        $this->actingAs($user)->post(route('workspace-role.update'), ['role_id' => $this->roleId(RoleSlug::Admin)])
            ->assertSessionHasErrors('role_id');
        $this->get(route('admin.accounts.index'))->assertForbidden();
        $this->post(route('admin.accounts.store'), $this->payload([RoleSlug::Admin]))->assertForbidden();
        $this->put(route('admin.accounts.update', $user), $this->payload([RoleSlug::Admin]))->assertForbidden();
    }

    public function test_removed_workspace_role_is_revalidated_on_next_request(): void
    {
        $user = $this->user(RoleSlug::Requestor, [RoleSlug::Admin]);
        $this->actingAs($user)->withSession(['workspace_role_id' => $this->roleId(RoleSlug::Admin)])
            ->get(route('admin.accounts.index'))->assertOk();
        $user->roles()->detach($this->roleId(RoleSlug::Admin));
        $this->get(route('admin.accounts.index'))->assertForbidden()->assertSessionMissing('workspace_role_id');
        $this->get(route('dashboard'))->assertOk()->assertSee('My requests');
    }

    public function test_admin_can_add_and_remove_secondary_roles_and_changes_are_audited(): void
    {
        $user = $this->user(RoleSlug::PurchasingTeam);
        $this->actingAs($this->user(RoleSlug::Admin));
        $payload = [...$this->payload([RoleSlug::Requestor, RoleSlug::PurchasingTeam]), 'name' => $user->name, 'email' => $user->email, 'password' => ''];
        $this->put(route('admin.accounts.update', $user), $payload)->assertSessionHasNoErrors();
        $this->assertSame($this->roleId(RoleSlug::PurchasingTeam), $user->fresh()->role_id);
        $this->assertTrue($user->fresh()->hasAssignedRole(RoleSlug::Requestor->value));
        $this->assertSame(2, count($user->auditLogs()->where('action', 'ROLE_CHANGED')->latest('id')->firstOrFail()->metadata['to_role_ids']));
        $this->put(route('admin.accounts.update', $user), [...$payload, 'role_ids' => [$this->roleId(RoleSlug::PurchasingTeam)]])
            ->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->hasAssignedRole(RoleSlug::Requestor->value));
    }

    public function test_last_admin_is_protected_when_admin_is_a_secondary_role(): void
    {
        $user = $this->user(RoleSlug::Finance, [RoleSlug::Admin]);
        $this->actingAs($user)->withSession(['workspace_role_id' => $this->roleId(RoleSlug::Admin)]);
        $payload = [...$this->payload([RoleSlug::Finance]), 'name' => $user->name, 'email' => $user->email, 'password' => ''];
        $this->put(route('admin.accounts.update', $user), $payload)->assertSessionHasErrors('is_active');
        $this->patch(route('admin.accounts.status', $user))->assertSessionHasErrors('is_active');
        $this->assertTrue($user->fresh()->hasAssignedRole(RoleSlug::Admin->value));
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_po_assignment_does_not_grant_checker_actions_in_requestor_workspace(): void
    {
        $user = $this->user(RoleSlug::Requestor, [RoleSlug::PoChecker]);
        PoRoleAssignment::create(['user_id' => $user->id, 'stage' => PurchaseOrderApprovalStage::CheckedBy->value]);
        $this->assertFalse($user->hasPoRole(PurchaseOrderApprovalStage::CheckedBy));
        $user->useWorkspaceRole(Role::findOrFail($this->roleId(RoleSlug::PoChecker)));
        $this->assertTrue($user->hasPoRole(PurchaseOrderApprovalStage::CheckedBy));
    }

    public function test_existing_single_role_accounts_keep_their_access_and_do_not_show_switcher(): void
    {
        $user = $this->user(RoleSlug::Requestor);
        $this->assertCount(1, $user->assignedRoles());
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('My requests')->assertDontSee('Switch role');
    }

    private function roleId(RoleSlug $role): int
    {
        return Role::where('slug', $role->value)->firstOrFail()->id;
    }

    private function user(RoleSlug $primary, array $additional = []): User
    {
        $user = User::factory()->create(['role_id' => $this->roleId($primary)]);
        $user->roles()->sync(array_map(fn ($role) => $this->roleId($role), $additional));

        return $user;
    }

    private function payload(array $roles): array
    {
        return [
            'name' => 'Multi Role User', 'email' => 'multi@example.test', 'employee_id' => 'MULTI-001',
            'password' => 'TemporaryPassword123!', 'is_active' => true,
            'role_ids' => array_map(fn ($role) => $this->roleId($role), $roles), 'role_selection' => 1,
            'campaign_id' => Campaign::where('name', 'AC MOBILITY - EVRO')->firstOrFail()->id,
            'om_notification_email' => 'om@example.test', 'smt_notification_email' => 'smt@example.test',
        ];
    }
}
