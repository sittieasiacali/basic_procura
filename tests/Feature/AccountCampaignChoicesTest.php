<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountCampaignCatalog;
use Database\Seeders\CampaignSeeder;
use Database\Seeders\RoleSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountCampaignChoicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, CampaignSeeder::class]);
        $this->actingAs(User::factory()->create(['role_id' => Role::where('slug', RoleSlug::Admin->value)->value('id')]));
    }

    public function test_create_account_dropdown_contains_exactly_the_41_supplied_campaigns(): void
    {
        $expected = AccountCampaignCatalog::NAMES;
        sort($expected);
        $this->assertCount(41, $expected);
        foreach (['admin.accounts.index', 'dashboard'] as $route) {
            $html = $this->get(route($route))->assertOk()->getContent();
            $document = new DOMDocument;
            @$document->loadHTML($html);
            $xpath = new DOMXPath($document);
            $options = $xpath->query('//select[@id="new-user-campaign"]/option[@value!=""]');
            $actual = [];
            foreach ($options as $option) {
                $actual[] = trim($option->textContent);
            }
            $this->assertSame($expected, $actual);
        }
    }

    public function test_create_account_saves_the_selected_campaign_id_and_rejects_legacy_choices(): void
    {
        $roleId = Role::where('slug', RoleSlug::Requestor->value)->value('id');
        $campaign = Campaign::where('name', 'RBSC*SLGFI')->firstOrFail();
        $payload = [
            'name' => 'Campaign Example', 'employee_id' => 'CAMPAIGN-001', 'email' => 'campaign@example.test',
            'password' => 'Temporary123!', 'role_ids' => [$roleId], 'is_active' => true,
            'om_notification_email' => 'om@example.test', 'smt_notification_email' => 'smt@example.test',
            'campaign_id' => Campaign::where('name', 'E-GOV_BLUE 4')->value('id'),
        ];
        $this->post(route('admin.accounts.store'), $payload)->assertSessionHasErrors('campaign_id');
        $this->post(route('admin.accounts.store'), [...$payload, 'campaign_id' => $campaign->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'campaign@example.test', 'campaign_id' => $campaign->id]);
    }

    public function test_edit_can_preserve_an_existing_campaign_and_bank_account_options_are_unchanged(): void
    {
        $campaign = Campaign::where('name', 'E-GOV_BLUE 4')->firstOrFail();
        $account = User::factory()->create([
            'campaign_id' => $campaign->id,
            'role_id' => Role::where('slug', RoleSlug::Requestor->value)->value('id'),
        ]);
        $this->get(route('admin.accounts.edit', $account))->assertOk()
            ->assertSee('value="'.$campaign->id.'" selected', false);
        $this->put(route('admin.accounts.update', $account), [
            'name' => $account->name, 'email' => $account->email, 'role_ids' => [$account->role_id],
            'campaign_id' => $campaign->id, 'is_active' => true,
            'om_notification_email' => 'om@example.test', 'smt_notification_email' => 'smt@example.test',
        ])->assertSessionHasNoErrors();
        $this->assertSame($campaign->id, $account->fresh()->campaign_id);
        foreach (Campaign::BUDGET_ACCOUNT_OPTIONS as $name => $number) {
            $this->assertDatabaseHas('campaigns', ['name' => $name, 'account_number' => $number, 'is_active' => true]);
        }
    }

    public function test_catalog_migration_is_repeatable_and_preserves_existing_campaign_details_and_assignments(): void
    {
        $campaign = Campaign::where('name', 'AC MOBILITY - EVRO')->firstOrFail();
        $campaign->update(['account_number' => 'EXISTING-ACCOUNT', 'description' => 'Keep this', 'is_active' => false]);
        $account = User::factory()->create(['campaign_id' => $campaign->id]);
        $migration = require database_path('migrations/2026_11_03_000400_add_account_campaign_choices.php');
        $migration->up();
        $migration->up();
        $this->assertSame(41, Campaign::whereIn('name', AccountCampaignCatalog::NAMES)->count());
        $this->assertSame($campaign->id, $account->fresh()->campaign_id);
        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id, 'account_number' => 'EXISTING-ACCOUNT', 'description' => 'Keep this', 'is_active' => false,
        ]);
    }
}
