<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CampaignSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, CampaignSeeder::class]);
    }

    public function test_asi_procura_branding_is_visible_on_login_and_dashboard(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('ASI PROCURA');

        $this->actingAs($this->user(RoleSlug::Admin))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('System overview')
            ->assertSee('Dashboard')
            ->assertSee('ASI PROCURA');
    }

    public function test_admin_can_open_manage_accounts_and_non_admin_cannot(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $requestor = $this->user(RoleSlug::Requestor);

        $this->actingAs($admin)->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertSee('Manage Accounts')
            ->assertSee('Create Account');

        $this->actingAs($requestor)->get(route('admin.accounts.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Manage Accounts');
    }

    public function test_admin_can_create_requestor_with_campaign_and_hashed_password(): void
    {
        $campaign = Campaign::query()->firstOrFail();
        $response = $this->actingAs($this->user(RoleSlug::Admin))
            ->post(route('admin.accounts.store'), $this->payload(RoleSlug::Requestor, $campaign, [
                'name' => 'New Requestor',
                'email' => 'new.requestor@example.com',
            ]));

        $account = User::query()->where('email', 'new.requestor@example.com')->firstOrFail();
        $response->assertRedirect(route('admin.accounts.show', $account));
        $this->assertSame($campaign->id, $account->campaign_id);
        $this->assertSame(RoleSlug::Requestor->value, $account->role->slug);
        $this->assertTrue(Hash::check('SecurePass123!', $account->password));
        $this->assertNotSame('SecurePass123!', $account->password);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class,
            'auditable_id' => $account->id,
            'action' => 'ACCOUNT_CREATED',
        ]);
    }

    public function test_create_account_password_fields_have_accessible_non_submit_visibility_toggles(): void
    {
        $response = $this->actingAs($this->user(RoleSlug::Admin))->get(route('admin.accounts.create'));

        $response->assertOk()
            ->assertSee('name="password" type="password"', false)
            ->assertSee('name="password_confirmation" type="password"', false)
            ->assertSee('data-password-target="password"', false)
            ->assertSee('data-password-target="password_confirmation"', false)
            ->assertSee('aria-label="Show password"', false)
            ->assertSee('type="button"', false)
            ->assertDontSee('value="SecurePass123!"', false);
    }

    public function test_blank_create_account_passwords_show_required_errors(): void
    {
        $campaign = Campaign::query()->firstOrFail();
        $payload = $this->payload(RoleSlug::Requestor, $campaign, [
            'name' => 'Blank Password User',
            'email' => 'blank.password@example.com',
            'password' => '',
            'password_confirmation' => '',
        ]);

        $response = $this->actingAs($this->user(RoleSlug::Admin))
            ->from(route('admin.accounts.create'))
            ->post(route('admin.accounts.store'), $payload);

        $response->assertRedirect(route('admin.accounts.create'))
            ->assertSessionHasErrors(['password', 'password_confirmation'])
            ->assertSessionHasInput('name', 'Blank Password User')
            ->assertSessionHasInput('email', 'blank.password@example.com')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');
        $this->assertDatabaseMissing('users', ['email' => 'blank.password@example.com']);
    }

    public function test_mismatched_create_account_passwords_show_confirmation_error_and_preserve_safe_input(): void
    {
        $campaign = Campaign::query()->firstOrFail();
        $payload = $this->payload(RoleSlug::Requestor, $campaign, [
            'name' => 'Mismatch User',
            'email' => 'mismatch@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'DifferentPass123!',
        ]);

        $response = $this->actingAs($this->user(RoleSlug::Admin))
            ->from(route('admin.accounts.create'))
            ->post(route('admin.accounts.store'), $payload);

        $response->assertRedirect(route('admin.accounts.create'))
            ->assertSessionHasErrors([
                'password' => 'The password confirmation does not match the password.',
            ])
            ->assertSessionHasInput('name', 'Mismatch User')
            ->assertSessionHasInput('email', 'mismatch@example.com')
            ->assertSessionHasInput('role_id', (string) $this->roleId(RoleSlug::Requestor))
            ->assertSessionHasInput('campaign_id', (string) $campaign->id)
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');
        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.com']);

        $this->get(route('admin.accounts.create'))
            ->assertOk()
            ->assertSee('Mismatch User')
            ->assertSee('The password confirmation does not match the password.')
            ->assertDontSee('SecurePass123!')
            ->assertDontSee('DifferentPass123!');
    }

    public function test_admin_can_create_team_leader_with_campaign(): void
    {
        $campaign = Campaign::query()->firstOrFail();

        $this->actingAs($this->user(RoleSlug::Admin))
            ->post(route('admin.accounts.store'), $this->payload(RoleSlug::TeamLeader, $campaign, [
                'name' => 'New Team Leader',
                'email' => 'new.teamlead@example.com',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'new.teamlead@example.com',
            'role_id' => $this->roleId(RoleSlug::TeamLeader),
            'campaign_id' => $campaign->id,
        ]);
    }

    public function test_campaign_is_required_for_requestor_and_team_leader(): void
    {
        $admin = $this->user(RoleSlug::Admin);

        foreach ([RoleSlug::Requestor, RoleSlug::TeamLeader] as $role) {
            $this->actingAs($admin)
                ->post(route('admin.accounts.store'), $this->payload($role, null, [
                    'email' => $role->value.'@example.com',
                ]))
                ->assertSessionHasErrors('campaign_id');
        }
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $existing = $this->user(RoleSlug::Requestor);

        $this->actingAs($this->user(RoleSlug::Admin))
            ->post(route('admin.accounts.store'), $this->payload(RoleSlug::Requestor, Campaign::query()->firstOrFail(), [
                'email' => strtoupper($existing->email),
            ]))
            ->assertSessionHasErrors('email');
    }

    public function test_account_list_search_and_role_campaign_status_filters_are_applied(): void
    {
        $operations = Campaign::query()->firstOrFail();
        $otherCampaign = Campaign::query()->create(['name' => 'Support', 'is_active' => true]);
        $match = $this->user(RoleSlug::Requestor, [
            'name' => 'Alpha Match',
            'campaign_id' => $operations->id,
            'is_active' => true,
        ]);
        $hidden = $this->user(RoleSlug::TeamLeader, [
            'name' => 'Beta Hidden',
            'campaign_id' => $otherCampaign->id,
            'is_active' => false,
        ]);

        $this->actingAs($this->user(RoleSlug::Admin))
            ->get(route('admin.accounts.index', [
                'search' => 'Alpha',
                'role_id' => $this->roleId(RoleSlug::Requestor),
                'campaign_id' => $operations->id,
                'is_active' => '1',
            ]))
            ->assertOk()
            ->assertSee($match->email)
            ->assertDontSee($hidden->email);
    }

    public function test_role_based_top_navigation_only_shows_available_modules(): void
    {
        $this->actingAs($this->user(RoleSlug::FinanceBudgetStaff))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('app-navbar', false)
            ->assertDontSee('Budget Request')
            ->assertDontSee('Payment')
            ->assertDontSee('Canvassing')
            ->assertDontSee('Receiving')
            ->assertDontSee('Purchase Orders')
            ->assertDontSee('Manage Accounts');

        $this->actingAs($this->user(RoleSlug::President))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Pending approvals')
            ->assertSee('President Supplier Approval')
            ->assertDontSee('President Final Approval')
            ->assertDontSee('Payment');

        $this->actingAs($this->user(RoleSlug::OmSmtsApprover))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Awaiting OM review')
            ->assertSee('For Approval')
            ->assertSee('Control Center');
    }

    public function test_purchasing_top_navigation_exposes_real_workflow_stage_filters(): void
    {
        $this->actingAs($this->user(RoleSlug::Purchasing))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Workflows')
            ->assertSee('Canvassing')
            ->assertSee('Purchase Orders')
            ->assertSee('Budget Request')
            ->assertDontSee('Budget Maker')
            ->assertDontSee('Budget Checker')
            ->assertDontSee('Budget Proceed')
            ->assertSee('Purchasing Final Approval')
            ->assertDontSee('President Supplier Approval')
            ->assertDontSee('President Final Approval')
            ->assertSee('Payment')
            ->assertSee('Receiving')
            ->assertSee('Inventory / Fulfilled');
    }

    public function test_president_top_navigation_exposes_only_presidential_checkpoints(): void
    {
        $this->actingAs($this->user(RoleSlug::President))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('President Supplier Approval')
            ->assertDontSee('President Final Approval')
            ->assertDontSee('Purchase Orders')
            ->assertDontSee('Payment');
    }

    public function test_admin_can_edit_role_campaign_status_and_preserve_blank_password(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $account = $this->user(RoleSlug::Requestor);
        $oldPassword = $account->password;
        $campaign = Campaign::query()->create(['name' => 'Customer Care', 'is_active' => true]);

        $this->actingAs($admin)->put(route('admin.accounts.update', $account), [
            'name' => 'Updated Team Leader',
            'email' => 'updated.teamlead@example.com',
            'role_id' => $this->roleId(RoleSlug::TeamLeader),
            'campaign_id' => $campaign->id,
            'is_active' => '0',
            'password' => '',
            'password_confirmation' => '',
        ])->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame($this->roleId(RoleSlug::TeamLeader), $account->role_id);
        $this->assertSame($campaign->id, $account->campaign_id);
        $this->assertFalse($account->is_active);
        $this->assertSame($oldPassword, $account->password);

        $this->actingAs($admin)
            ->get(route('admin.accounts.edit', $account))
            ->assertOk()
            ->assertSee($campaign->name);

        foreach (['ACCOUNT_UPDATED', 'ROLE_CHANGED', 'CAMPAIGN_CHANGED', 'ACCOUNT_DEACTIVATED'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'auditable_id' => $account->id,
                'auditable_type' => User::class,
                'action' => $action,
            ]);
        }
    }

    public function test_admin_can_deactivate_and_reactivate_account_and_inactive_login_is_blocked(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $account = $this->user(RoleSlug::Requestor, ['password' => Hash::make('LoginPass123!')]);

        $this->actingAs($admin)->patch(route('admin.accounts.status', $account))->assertSessionHasNoErrors();
        $this->assertFalse($account->fresh()->is_active);

        $this->post(route('logout'));
        $this->post('/login', ['email' => $account->email, 'password' => 'LoginPass123!'])
            ->assertSessionHasErrors('email');

        $this->actingAs($admin)->patch(route('admin.accounts.status', $account))->assertSessionHasNoErrors();
        $this->assertTrue($account->fresh()->is_active);
        $this->assertSame(2, AuditLog::query()->where('auditable_type', User::class)->where('auditable_id', $account->id)->count());
    }

    public function test_only_active_admin_cannot_be_deactivated_or_demoted(): void
    {
        $admin = $this->user(RoleSlug::Admin);

        $this->actingAs($admin)
            ->patch(route('admin.accounts.status', $admin))
            ->assertSessionHasErrors('is_active');
        $this->assertTrue($admin->fresh()->is_active);

        $this->put(route('admin.accounts.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role_id' => $this->roleId(RoleSlug::Requestor),
            'campaign_id' => Campaign::query()->firstOrFail()->id,
            'is_active' => '1',
            'password' => '',
            'password_confirmation' => '',
        ])->assertSessionHasErrors('is_active');
        $this->assertSame($this->roleId(RoleSlug::Admin), $admin->fresh()->role_id);
    }

    public function test_password_reset_is_audited_without_password_data(): void
    {
        $account = $this->user(RoleSlug::Requestor);

        $this->actingAs($this->user(RoleSlug::Admin))->put(route('admin.accounts.update', $account), [
            'name' => $account->name,
            'email' => $account->email,
            'role_id' => $account->role_id,
            'campaign_id' => Campaign::query()->firstOrFail()->id,
            'is_active' => '1',
            'password' => 'ResetPass123!',
            'password_confirmation' => 'ResetPass123!',
        ])->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertTrue(Hash::check('ResetPass123!', $account->password));
        $log = $account->auditLogs()->where('action', 'PASSWORD_RESET')->firstOrFail();
        $this->assertStringNotContainsString('ResetPass123!', json_encode($log->toArray()));
        $this->assertArrayNotHasKey('password', $log->metadata ?? []);
    }

    public function test_account_changes_do_not_remove_existing_users_or_requests(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $request = PurchaseRequest::query()->create([
            'request_number' => 'PR-PRESERVE-001',
            'title' => 'Existing Request',
            'requestor_id' => $requestor->id,
            'department_campaign' => 'Operations',
            'request_type' => 'IT',
            'purpose' => 'Preservation test',
            'required_date' => now()->addWeek(),
            'delivery_location' => 'Main Office',
            'status' => PurchaseRequestStatus::Draft,
            'current_responsible_role_id' => $this->roleId(RoleSlug::Requestor),
            'total_amount' => 0,
        ]);

        $this->actingAs($this->user(RoleSlug::Admin))
            ->post(route('admin.accounts.store'), $this->payload(RoleSlug::TeamLeader, Campaign::query()->firstOrFail()))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['id' => $requestor->id]);
        $this->assertDatabaseHas('purchase_requests', ['id' => $request->id, 'request_number' => 'PR-PRESERVE-001']);
    }

    /** @param array<string, mixed> $attributes */
    private function user(RoleSlug $role, array $attributes = []): User
    {
        return User::factory()->create([
            'role_id' => $this->roleId($role),
            ...$attributes,
        ]);
    }

    private function roleId(RoleSlug $role): int
    {
        return Role::query()->where('slug', $role->value)->firstOrFail()->id;
    }

    /** @param array<string, mixed> $overrides */
    private function payload(RoleSlug $role, ?Campaign $campaign, array $overrides = []): array
    {
        return [
            'name' => 'Managed Account',
            'email' => 'managed.'.uniqid().'@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $this->roleId($role),
            'campaign_id' => $campaign?->id,
            'is_active' => '1',
            ...$overrides,
        ];
    }
}
