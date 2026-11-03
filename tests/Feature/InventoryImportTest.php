<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Models\InventoryItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class InventoryImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_template_download_lists_the_import_columns(): void
    {
        $response = $this->actingAs($this->user(RoleSlug::ItStaff))->get(route('inventory.import-template'))->assertOk();

        $this->assertStringContainsString('attachment; filename=inventory-import-template.csv', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('description,quantity,unit,serial_number,location', $response->streamedContent());
        $this->get(route('inventory.index'))->assertOk()->assertSee('Import CSV')->assertSee('Download template');
    }

    public function test_valid_csv_adds_every_row_with_generated_codes(): void
    {
        $staff = $this->user(RoleSlug::ItStaff);
        $csv = "\xEF\xBB\xBFDescription,Quantity,Unit,Serial Number,Location\n"
            ."Wireless mouse,10,pc,,IT storage room\n"
            .",,,,\n"
            ."Docking station,2,pc,SN-DOCK-1,\n";

        $this->actingAs($staff)->post(route('inventory.import'), ['inventory_csv' => $this->csv($csv)])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('inventory.index', ['view' => 'added']))
            ->assertSessionHas('success', '2 inventory items imported.');

        $items = InventoryItem::query()->orderBy('id')->get();
        $this->assertSame(['Wireless mouse', 'Docking station'], $items->pluck('description')->all());
        $this->assertSame([null, 'SN-DOCK-1'], $items->pluck('serial_number')->all());
        $this->assertSame(['IT storage room', null], $items->pluck('location')->all());
        $this->assertSame(2, $items->pluck('sku')->filter()->unique()->count());
        $this->assertSame(2, $items->pluck('inventory_code')->filter()->unique()->count());
    }

    public function test_any_invalid_row_rejects_the_whole_file_with_row_numbers(): void
    {
        $staff = $this->user(RoleSlug::PremisesStaff);
        $csv = "description,quantity,unit\nOffice chair,5,pc\nDesk lamp,0,pc\n,3,box\n";

        $this->actingAs($staff)->post(route('inventory.import'), ['inventory_csv' => $this->csv($csv)])
            ->assertSessionHasErrors('inventory_csv');
        $messages = session('errors')->get('inventory_csv');
        $this->assertTrue(collect($messages)->contains(fn ($message) => str_starts_with($message, 'Row 3:')));
        $this->assertTrue(collect($messages)->contains(fn ($message) => str_starts_with($message, 'Row 4:')));
        $this->assertSame(0, InventoryItem::query()->count());

        $this->post(route('inventory.import'), ['inventory_csv' => $this->csv("name,qty\nChair,1\n")])
            ->assertSessionHasErrors(['inventory_csv' => 'The CSV is missing the column(s): description, quantity, unit. Download the template to see the expected columns.']);
    }

    public function test_only_stock_managers_can_import(): void
    {
        $this->actingAs($this->user(RoleSlug::Finance))
            ->post(route('inventory.import'), ['inventory_csv' => $this->csv("description,quantity,unit\nChair,1,pc\n")])
            ->assertForbidden();
        $this->get(route('inventory.import-template'))->assertForbidden();
        $this->assertSame(0, InventoryItem::query()->count());
    }

    private function csv(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('items.csv', $contents);
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $role->value)->value('id')]);
    }
}
