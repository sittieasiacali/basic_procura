<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CampaignSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, CampaignSeeder::class]);
    }

    public function test_admin_can_view_search_and_filter_campaign_list(): void
    {
        $operations = Campaign::query()->firstOrFail();
        $hidden = Campaign::query()->create(['name' => 'Inactive Support', 'description' => 'Hidden result', 'is_active' => false]);

        $this->actingAs($this->user(RoleSlug::Admin))
            ->get(route('admin.campaigns.index', ['search' => 'Operations', 'is_active' => '1']))
            ->assertOk()
            ->assertSee('Campaigns')
            ->assertSee($operations->name)
            ->assertDontSee($hidden->name);
    }

    public function test_non_admin_cannot_access_campaign_management(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $campaign = Campaign::query()->firstOrFail();

        $this->actingAs($requestor)->get(route('admin.campaigns.index'))->assertForbidden();
        $this->get(route('admin.campaigns.show', $campaign))->assertForbidden();
        $this->post(route('admin.campaigns.store'), ['name' => 'Blocked', 'is_active' => '1'])->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Campaigns');
    }

    public function test_admin_can_create_campaign_and_audit_is_recorded(): void
    {
        $response = $this->actingAs($this->user(RoleSlug::Admin))->post(route('admin.campaigns.store'), [
            'name' => 'Customer Experience',
            'description' => 'Daytime support campaign.',
            'is_active' => '1',
        ]);

        $campaign = Campaign::query()->where('name', 'Customer Experience')->firstOrFail();
        $response->assertRedirect(route('admin.campaigns.show', $campaign));
        $this->assertSame('Daytime support campaign.', $campaign->description);
        $this->assertTrue($campaign->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Campaign::class,
            'auditable_id' => $campaign->id,
            'action' => 'CAMPAIGN_CREATED',
        ]);
    }

    public function test_duplicate_campaign_name_is_rejected(): void
    {
        $existing = Campaign::query()->firstOrFail();

        $this->actingAs($this->user(RoleSlug::Admin))->post(route('admin.campaigns.store'), [
            'name' => $existing->name,
            'description' => 'Duplicate',
            'is_active' => '1',
        ])->assertSessionHasErrors('name');

        $this->assertSame(1, Campaign::query()->where('name', $existing->name)->count());
    }

    public function test_admin_can_edit_campaign_and_audit_is_recorded(): void
    {
        $campaign = Campaign::query()->firstOrFail();

        $this->actingAs($this->user(RoleSlug::Admin))->put(route('admin.campaigns.update', $campaign), [
            'name' => 'Operations Prime',
            'description' => 'Updated operational campaign.',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $campaign->refresh();
        $this->assertSame('Operations Prime', $campaign->name);
        $this->assertSame('Updated operational campaign.', $campaign->description);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Campaign::class,
            'auditable_id' => $campaign->id,
            'action' => 'CAMPAIGN_UPDATED',
        ]);
    }

    public function test_admin_can_deactivate_and_reactivate_unassigned_campaign(): void
    {
        $campaign = Campaign::query()->create(['name' => 'Flexible Campaign', 'is_active' => true]);
        $admin = $this->user(RoleSlug::Admin);

        $this->actingAs($admin)->patch(route('admin.campaigns.status', $campaign))->assertSessionHasNoErrors();
        $this->assertFalse($campaign->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $campaign->id, 'auditable_type' => Campaign::class, 'action' => 'CAMPAIGN_DEACTIVATED']);

        $this->patch(route('admin.campaigns.status', $campaign))->assertSessionHasNoErrors();
        $this->assertTrue($campaign->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $campaign->id, 'auditable_type' => Campaign::class, 'action' => 'CAMPAIGN_ACTIVATED']);
    }

    public function test_campaign_with_active_assigned_users_cannot_be_deactivated(): void
    {
        $campaign = Campaign::query()->firstOrFail();
        $account = $this->user(RoleSlug::Requestor, ['campaign_id' => $campaign->id, 'is_active' => true]);

        $this->actingAs($this->user(RoleSlug::Admin))
            ->patch(route('admin.campaigns.status', $campaign))
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($campaign->fresh()->is_active);
        $this->assertDatabaseHas('users', ['id' => $account->id, 'campaign_id' => $campaign->id]);
        $this->assertDatabaseMissing('audit_logs', ['auditable_id' => $campaign->id, 'auditable_type' => Campaign::class, 'action' => 'CAMPAIGN_DEACTIVATED']);
    }

    public function test_admin_can_view_users_assigned_to_campaign(): void
    {
        $campaign = Campaign::query()->firstOrFail();
        $account = $this->user(RoleSlug::TeamLeader, [
            'name' => 'Assigned Leader',
            'email' => 'assigned.leader@example.com',
            'campaign_id' => $campaign->id,
        ]);

        $this->actingAs($this->user(RoleSlug::Admin))
            ->get(route('admin.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Assigned Accounts')
            ->assertSee($account->name)
            ->assertSee($account->email)
            ->assertSee('Team Leader');
    }

    public function test_campaign_changes_preserve_existing_campaigns_and_assignments(): void
    {
        $existing = Campaign::query()->firstOrFail();
        $account = $this->user(RoleSlug::Requestor, ['campaign_id' => $existing->id]);

        $this->actingAs($this->user(RoleSlug::Admin))->post(route('admin.campaigns.store'), [
            'name' => 'Additional Campaign',
            'description' => null,
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('campaigns', ['id' => $existing->id, 'name' => $existing->name]);
        $this->assertDatabaseHas('users', ['id' => $account->id, 'campaign_id' => $existing->id]);
    }

    /** @param array<string, mixed> $attributes */
    private function user(RoleSlug $role, array $attributes = []): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            ...$attributes,
        ]);
    }
}
