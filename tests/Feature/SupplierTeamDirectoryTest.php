<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTeamDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role)->value('id')]);
    }

    public function test_each_team_has_its_own_directory_and_can_use_the_same_supplier_name(): void
    {
        $actors = [];
        foreach (['it-staff' => 'IT', 'premises-staff' => 'PREMISES', 'purchasing-team' => 'PURCHASING'] as $role => $team) {
            $actor = $actors[$team] = $this->user($role);
            $this->actingAs($actor)->post(route('suppliers.store'), [
                'name' => 'Shared vendor name', 'email' => strtolower($team).'@supplier.test', 'is_active' => 1,
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseHas('suppliers', ['name' => 'Shared vendor name', 'team' => $team]);
            $this->post(route('suppliers.store'), ['name' => 'Shared vendor name', 'is_active' => 1])->assertSessionHasErrors('name');
        }
        $this->assertSame(3, Supplier::count());
        foreach ($actors as $team => $actor) {
            $response = $this->actingAs($actor)->get(route('suppliers.index'))->assertOk()
                ->assertSee(Supplier::TEAMS[$team].' supplier directory')->assertSee(strtolower($team).'@supplier.test');
            foreach (array_diff(array_keys($actors), [$team]) as $otherTeam) {
                $response->assertDontSee(strtolower($otherTeam).'@supplier.test');
            }
            $this->get(route('dashboard'))->assertOk()->assertSee('Supplier Management');
        }
    }

    public function test_cross_team_updates_and_forged_team_fields_are_rejected(): void
    {
        $actor = $this->user('it-staff');
        $foreign = Supplier::create(['team' => 'PREMISES', 'name' => 'Premises only', 'is_active' => true]);
        $own = Supplier::create(['team' => 'IT', 'name' => 'IT only', 'is_active' => true]);
        $this->actingAs($actor)->patch(route('suppliers.update', $foreign), [
            'name' => 'Changed', 'is_active' => 0,
        ])->assertForbidden();
        $this->post(route('suppliers.store'), ['team' => 'PREMISES', 'name' => 'Forged', 'is_active' => 1])->assertSessionHasErrors('team');
        $this->patch(route('suppliers.update', $own), ['team' => 'PREMISES', 'name' => 'Moved', 'is_active' => 1])->assertSessionHasErrors('team');
        $this->getJson(route('suppliers.index', ['team' => 'PREMISES']))->assertUnprocessable();
        $this->patch(route('suppliers.update', $own), ['name' => 'Updated IT', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($own->fresh()->is_active);
        $this->assertSame('Premises only', $foreign->fresh()->name);
        $this->assertSame('IT', $own->fresh()->team);
    }

    public function test_administrators_can_view_filter_and_manage_all_three_directories(): void
    {
        foreach (Supplier::TEAMS as $team => $label) {
            Supplier::create(['team' => $team, 'name' => $label.' Vendor', 'is_active' => true]);
        }
        $admin = $this->user('admin');
        $this->actingAs($admin)->get(route('suppliers.index'))->assertOk()
            ->assertSee('IT Vendor')->assertSee('Premises Vendor')->assertSee('Purchasing Team Vendor');
        $this->get(route('suppliers.index', ['team' => 'IT']))->assertOk()
            ->assertSee('IT Vendor')->assertDontSee('Premises Vendor')->assertDontSee('Purchasing Team Vendor');
        $this->post(route('suppliers.store'), ['team' => 'PREMISES', 'name' => 'Admin vendor', 'is_active' => 1])->assertSessionHasNoErrors();
        $supplier = Supplier::where('name', 'Admin vendor')->sole();
        $this->assertSame('PREMISES', $supplier->team);
        $this->patch(route('suppliers.update', $supplier), ['name' => 'Admin changed', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($supplier->fresh()->is_active);
    }

    public function test_requestor_workspace_has_no_supplier_management_access_even_with_an_it_role(): void
    {
        $actor = $this->user('requestor');
        $itRole = Role::where('slug', 'it-staff')->sole();
        $actor->roles()->attach($itRole);
        $this->actingAs($actor)->get(route('suppliers.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Supplier Management');
        $this->withSession(['workspace_role_id' => $itRole->id])->get(route('suppliers.index'))->assertOk()->assertSee('IT supplier directory');
    }

    public function test_existing_suppliers_keep_document_links_when_assigned_to_team_directories(): void
    {
        $it = $this->user('it-staff');
        $premises = $this->user('premises-staff');
        $supplierIds = [];
        foreach (['IT history' => [$it], 'Premises history' => [$premises], 'Mixed history' => [$it, $premises]] as $name => $actors) {
            $supplier = Supplier::create(['name' => $name, 'email' => 'preserved@supplier.test', 'is_active' => true]);
            $supplierIds[$name] = $supplier->id;
            foreach ($actors as $actor) {
                $request = PurchaseRequest::create([
                    'request_number' => 'MIGRATION-'.$supplier->id.'-'.$actor->id,
                    'requestor_id' => $actor->id, 'title' => 'Migration test', 'purpose' => 'Preserve supplier links',
                    'request_type' => 'IT', 'department_campaign' => 'Operations', 'delivery_location' => 'Office',
                    'status' => PurchaseRequestStatus::ForProcurement,
                ]);
                $request->quotations()->create([
                    'supplier_id' => $supplier->id, 'created_by' => $actor->id,
                    'item_or_service' => 'Migration test', 'price' => 100, 'quoted_at' => now(),
                ]);
            }
        }
        $migration = require database_path('migrations/2026_11_11_000200_separate_supplier_team_directories.php');
        $migration->down();
        $migration->up();
        foreach (['IT history' => 'IT', 'Premises history' => 'PREMISES', 'Mixed history' => 'PURCHASING'] as $name => $team) {
            $supplier = Supplier::findOrFail($supplierIds[$name]);
            $this->assertSame($team, $supplier->team);
            $this->assertSame('preserved@supplier.test', $supplier->email);
            $this->assertSame($name === 'Mixed history' ? 2 : 1, $supplier->quotations()->count());
        }
    }

    public function test_supplier_bank_details_can_be_saved_edited_and_reused_from_historical_quotations(): void
    {
        $actor = $this->user('it-staff');
        $this->actingAs($actor)->post(route('suppliers.store'), [
            'name' => 'Banked vendor', 'is_active' => 1, 'bank_details' => 'BDO account 123',
        ])->assertSessionHasNoErrors();
        $supplier = Supplier::where('name', 'Banked vendor')->sole();
        $this->assertSame('BDO account 123', $supplier->bank_details);
        $this->patch(route('suppliers.update', $supplier), [
            'name' => 'Banked vendor', 'is_active' => 1, 'bank_details' => 'BPI account 456',
        ])->assertSessionHasNoErrors();
        $this->get(route('suppliers.index'))->assertOk()->assertSee('BPI account 456');
        $this->patch(route('suppliers.update', $supplier), [
            'name' => 'Banked vendor', 'is_active' => 1, 'bank_details' => str_repeat('x', 2001),
        ])->assertSessionHasErrors('bank_details');
        $request = PurchaseRequest::create([
            'request_number' => 'BANK-MIGRATION', 'requestor_id' => $actor->id, 'title' => 'Bank history',
            'purpose' => 'Preserve records', 'request_type' => 'IT', 'department_campaign' => 'Operations',
            'delivery_location' => 'Office', 'status' => PurchaseRequestStatus::ForProcurement,
        ]);
        foreach (['Older instructions', 'Latest instructions'] as $details) {
            $request->quotations()->create([
                'supplier_id' => $supplier->id, 'created_by' => $actor->id, 'item_or_service' => 'Item',
                'price' => 100, 'quoted_at' => now(), 'bank_details' => $details,
            ]);
        }
        $migration = require database_path('migrations/2026_11_11_000300_add_bank_details_to_suppliers.php');
        $migration->down();
        $migration->up();
        $this->assertSame('Latest instructions', $supplier->fresh()->bank_details);
        $this->assertSame(2, $request->quotations()->count());
    }
}
