<?php

namespace Tests\Feature;

use App\Models\BudgetRequest;
use App\Models\Campaign;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\WorkflowSignature;
use Database\Seeders\BudgetRequestDemoSeeder;
use Database\Seeders\CampaignSeeder;
use Database\Seeders\ProcurementWorkflowDemoSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\RoleWorkflowSampleSeeder;
use Database\Seeders\SampleUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeders_refuse_production_before_writing_anything(): void
    {
        $this->app['env'] = 'production';
        foreach ([SampleUserSeeder::class, ProcurementWorkflowDemoSeeder::class,
            RoleWorkflowSampleSeeder::class, BudgetRequestDemoSeeder::class] as $seeder) {
            try {
                app($seeder)->run();
                $this->fail('Demo seeder must refuse production.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('restricted', $error->getMessage());
            }
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('purchase_requests', 0);
    }

    public function test_reference_seeding_preserves_edited_bank_accounts_and_inactive_campaigns(): void
    {
        Campaign::create(['name' => 'AIA 1', 'account_number' => 'ADMIN-VERIFIED', 'is_active' => false]);
        $this->seed(CampaignSeeder::class);
        $this->assertDatabaseHas('campaigns', ['name' => 'AIA 1', 'account_number' => 'ADMIN-VERIFIED', 'is_active' => false]);
    }

    public function test_deactivated_account_loses_its_authenticated_session(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->actingAs($user)->getJson(route('dashboard'))->assertForbidden();
        $this->assertGuest();
    }

    public function test_cleanup_plan_is_exact_and_read_only(): void
    {
        $user = User::factory()->create();
        foreach (['REQ-SAMPLE-001', 'REQ-SAMPLE-001-REAL'] as $number) {
            PurchaseRequest::create([
                'request_number' => $number, 'title' => 'Review candidate', 'requestor_id' => $user->id,
                'department_campaign' => 'Operations', 'purpose' => 'Test plan safety', 'required_date' => now(),
                'delivery_location' => 'Office', 'status' => 'DRAFT', 'request_type' => 'IT',
            ]);
        }
        $this->artisan('demo:cleanup-plan', ['--dry-run' => true, '--database' => ':memory:'])
            ->expectsOutputToContain('REQ-SAMPLE-001')
            ->doesntExpectOutputToContain('REQ-SAMPLE-001-REAL')
            ->assertSuccessful();
        $this->assertDatabaseCount('purchase_requests', 2);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_cleanup_plan_requires_backup_and_explicit_production_review(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('demo:cleanup-plan', ['--database' => ':memory:'])->assertFailed();
    }

    public function test_purchasing_does_not_have_temporary_receiving_access(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create(['role_id' => Role::where('slug', 'purchasing')->value('id')]);
        $this->assertFalse($user->can('receiveGoods', new PurchaseRequest));
    }

    public function test_budget_documents_use_recorded_actors_instead_of_fixed_names(): void
    {
        config(['procurement.budget_request_signatories' => ['requested_by' => null, 'approved_by' => null]]);
        $budget = new BudgetRequest;
        $budget->setRelation('requester', new User(['name' => 'Actual Requestor']));
        $budget->setRelation('finalApprover', new User(['name' => 'Actual Approver']));
        $this->view('budget-requests.partials.br-signatures', ['budget' => $budget, 'purchaseRequest' => null])
            ->assertSee('Actual Requestor')->assertSee('Actual Approver')->assertDontSee('Ma\'am Weng');
    }

    public function test_signature_validation_rejects_plain_text_and_oversized_png_dimensions(): void
    {
        $this->assertTrue(WorkflowSignature::isValidImage($this->signatureImage()));
        $this->assertFalse(WorkflowSignature::isValidImage('data:image/png;base64,'.base64_encode(str_repeat('not an image', 20))));
        $binary = base64_decode(explode(',', $this->signatureImage())[1]);
        $oversized = substr_replace($binary, pack('N', 100000), 16, 4);
        $this->assertFalse(WorkflowSignature::isValidImage('data:image/png;base64,'.base64_encode($oversized)));
    }
}
