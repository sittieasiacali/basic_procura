<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\Campaign;
use App\Models\DeliveryLocation;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CampaignSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryLocationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, CampaignSeeder::class]);
    }

    public function test_location_management_is_admin_only_and_request_creators_only_see_active_choices(): void
    {
        DeliveryLocation::query()->create(['name' => 'Main Office', 'is_active' => true]);
        DeliveryLocation::query()->create(['name' => 'Archived Warehouse', 'is_active' => false]);
        $requestor = $this->user(RoleSlug::Requestor, Campaign::query()->firstOrFail());

        $this->actingAs($requestor)->get(route('purchase-requests.create'))
            ->assertOk()
            ->assertSee('<option value="Main Office"', false)
            ->assertDontSee('Archived Warehouse')
            ->assertDontSee('Manage Locations');
        $this->getJson(route('delivery-locations.index'))->assertForbidden();

        $this->actingAs($this->user(RoleSlug::Admin))->get(route('purchase-requests.create'))
            ->assertOk()
            ->assertSee('Manage Locations');
    }

    public function test_admin_can_create_trim_and_update_locations_but_duplicates_are_rejected(): void
    {
        $admin = $this->user(RoleSlug::Admin);

        $created = $this->actingAs($admin)->postJson(route('delivery-locations.store'), ['name' => '  North   Office  '])
            ->assertCreated()
            ->assertJsonPath('location.name', 'North Office');
        $location = DeliveryLocation::query()->findOrFail($created->json('location.id'));

        $this->postJson(route('delivery-locations.store'), ['name' => 'north office'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $historicalRequest = $this->purchaseRequest('North Office');
        $this->patchJson(route('delivery-locations.update', $location), ['name' => ' North Annex '])
            ->assertOk()
            ->assertJsonPath('location.name', 'North Annex');
        $this->assertSame('North Office', $historicalRequest->fresh()->delivery_location);
    }

    public function test_referenced_location_is_archived_and_historical_request_value_is_preserved(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $location = DeliveryLocation::query()->create(['name' => 'Legacy Office', 'is_active' => true]);
        $purchaseRequest = $this->purchaseRequest('Legacy Office');

        $this->actingAs($admin)->deleteJson(route('delivery-locations.destroy', $location))
            ->assertOk()
            ->assertJsonPath('archived', true);

        $this->assertFalse($location->fresh()->is_active);
        $this->assertSame('Legacy Office', $purchaseRequest->fresh()->delivery_location);
    }

    public function test_unused_location_is_soft_deleted_and_legacy_units_remain_editable(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $location = DeliveryLocation::query()->create(['name' => 'Unused Office', 'is_active' => true]);
        $this->actingAs($admin)->deleteJson(route('delivery-locations.destroy', $location))->assertOk();
        $this->assertSoftDeleted('delivery_locations', ['id' => $location->id]);

        $requestor = $this->user(RoleSlug::Requestor, Campaign::query()->firstOrFail());
        $purchaseRequest = $this->purchaseRequest('Historical Site', $requestor);
        $purchaseRequest->items()->create([
            'description' => 'Legacy item', 'quantity' => 1, 'unit' => 'unit',
            'estimated_unit_price' => 10, 'estimated_total' => 10,
        ]);

        $this->actingAs($requestor)->get(route('purchase-requests.edit', $purchaseRequest))
            ->assertOk()
            ->assertSee('<option value="unit" selected>unit (legacy)</option>', false)
            ->assertSee('Piece (pc)')
            ->assertSee('Square Meter (m²)')
            ->assertSee('Service (service)');
    }

    private function user(RoleSlug $role, ?Campaign $campaign = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role->value)->value('id'),
            'campaign_id' => $campaign?->id,
        ]);
    }

    private function purchaseRequest(string $location, ?User $requestor = null): PurchaseRequest
    {
        $requestor ??= $this->user(RoleSlug::Requestor, Campaign::query()->firstOrFail());

        return PurchaseRequest::query()->create([
            'request_number' => 'REQ-'.str()->upper(str()->random(8)),
            'title' => 'Location test', 'requestor_id' => $requestor->id,
            'department_campaign' => 'Operations', 'request_type' => 'OTHER',
            'purpose' => 'Testing', 'required_date' => now()->addDay(),
            'delivery_location' => $location, 'status' => PurchaseRequestStatus::Draft,
            'total_amount' => 10,
        ]);
    }
}
