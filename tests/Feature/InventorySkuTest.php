<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Models\InventoryItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventorySkuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_inventory_sku_is_generated_searchable_and_unique(): void
    {
        $staff = $this->user(RoleSlug::ItStaff);
        $payload = ['sku' => '  ignored-user-sku  ', 'description' => 'Business laptop', 'quantity' => 4, 'unit' => 'pc', 'location' => 'IT stockroom'];
        $this->actingAs($staff)->post(route('inventory.store'), $payload)->assertSessionHasNoErrors();
        $stock = InventoryItem::query()->sole();
        $this->assertSkuFormat($stock->sku, 'IT');
        $this->assertNotSame('IGNORED-USER-SKU', $stock->sku);
        $this->post(route('inventory.store'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(2, InventoryItem::query()->count());
        $this->assertSame(2, InventoryItem::query()->distinct()->count('sku'));
        $this->get(route('inventory.index', ['search' => $stock->sku]))->assertOk()->assertSee('Business laptop');
        $this->get(route('inventory.index', ['search' => 'SKU-NOT-FOUND']))->assertOk()->assertDontSee('Business laptop');
    }

    public function test_inventory_creation_generates_a_sku_even_when_an_importer_supplies_one(): void
    {
        $staff = $this->user(RoleSlug::InventoryStaff);
        $stock = InventoryItem::query()->create([
            'inventory_code' => InventoryItem::nextCode(), 'sku' => 'IMPORTED-CODE', 'logged_by' => $staff->id,
            'description' => 'Imported desk', 'quantity' => 3, 'unit' => 'pc', 'location' => 'Stockroom', 'status' => 'LOGGED', 'logged_at' => now(),
        ]);
        $this->assertSkuFormat($stock->sku, 'IT');
        $this->assertNotSame('IMPORTED-CODE', $stock->sku);
        $this->assertSame('3.00', $stock->quantity);
    }

    public function test_sku_picker_exposes_matching_description_stock_and_release_quantity(): void
    {
        $staff = $this->user(RoleSlug::ItStaff);
        $stock = $this->stock($staff);
        $html = $this->actingAs($staff)->blade('<x-inventory-sku-picker id="test-sku" :inventory-items="$items" name="inventory[1]" :selected-id="$selected" :quantity="2" />', [
            'items' => collect([$stock]), 'selected' => $stock->id,
        ]);
        $html->assertSee($stock->sku)->assertSee('Office chair')->assertSee('5.00 pc available')
            ->assertSee('Quantity to release')->assertSee('Type or scan SKU')->assertSee('max="5.00"', false);
    }

    public function test_premises_inventory_receives_a_premises_sku(): void
    {
        $premises = $this->user(RoleSlug::PremisesStaff);
        $stock = $this->stock($premises);

        $this->assertSkuFormat($stock->sku, 'PREM');
    }

    public function test_skus_and_inventory_codes_are_numbered_in_order(): void
    {
        $staff = $this->user(RoleSlug::ItStaff);
        $premises = $this->user(RoleSlug::PremisesStaff);
        $today = now()->format('Ymd');

        $this->assertSame(['IT-00001', "INV-{$today}-0001"], array_values($this->stock($staff)->only(['sku', 'inventory_code'])));
        $this->assertSame(['IT-00002', "INV-{$today}-0002"], array_values($this->stock($staff)->only(['sku', 'inventory_code'])));
        // Each team keeps its own SKU sequence; the inventory code sequence is shared.
        $this->assertSame(['PREM-00001', "INV-{$today}-0003"], array_values($this->stock($premises)->only(['sku', 'inventory_code'])));
    }

    private function stock(User $staff): InventoryItem
    {
        return InventoryItem::query()->create([
            'inventory_code' => InventoryItem::nextCode(), 'logged_by' => $staff->id,
            'description' => 'Office chair', 'quantity' => 5, 'unit' => 'pc',
            'location' => 'Stockroom', 'status' => 'LOGGED', 'logged_at' => now(),
        ]);
    }

    private function assertSkuFormat(string $sku, string $prefix): void
    {
        $this->assertMatchesRegularExpression('/^'.$prefix.'-\d{5}$/', $sku);
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $role->value)->value('id')]);
    }
}
