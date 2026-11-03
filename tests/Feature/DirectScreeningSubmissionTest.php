<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\Campaign;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\WorkflowHandoffEmail;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DirectScreeningSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Notification::fake();
        Storage::fake('local');
    }

    public function test_submission_routes_to_each_selected_team_and_only_emails_assigned_management(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->get(route('purchase-requests.create'))->assertOk()
            ->assertSee('Submit this request for IT / Premises screening?')
            ->assertDontSee('Operations Manager review');
        foreach (['IT' => 'COMPUTERS', 'PREMISES' => 'FURNITURE'] as $team => $category) {
            $this->post(route('purchase-requests.store'), [
                ...$this->payload(), 'requested_team' => $team,
                'request_category' => $category,
            ])->assertSessionHasNoErrors();
            $request = PurchaseRequest::query()->latest('id')->firstOrFail();
            $this->assertSame($team === 'IT' ? PurchaseRequestStatus::ForItScreening : PurchaseRequestStatus::ForPremisesScreening, $request->status);
            $this->assertSame($team, $request->request_type);
            $this->assertNotNull($request->submitted_at);
            $this->assertSame($this->roleId($team === 'IT' ? RoleSlug::ItStaff : RoleSlug::PremisesStaff), $request->current_responsible_role_id);
            $this->assertNull($request->assigned_user_id);
            $this->assertSame('SUBMITTED_FOR_SCREENING', $request->approvals()->sole()->action);
            $this->assertSame('REQUESTOR_SUBMISSION', $request->approvals()->sole()->approval_level);
            $this->get(route('purchase-requests.show', $request))->assertOk()
                ->assertSee('Submitted to IT / Premises screening.')
                ->assertDontSee('Submit for OM Approval');
        }
        Notification::assertSentOnDemandTimes(WorkflowHandoffEmail::class, 4);
        Notification::assertSentOnDemand(WorkflowHandoffEmail::class, fn ($notification, $channels, $recipient) =>
            $channels === ['mail'] && $recipient->routes['mail'] === 'om@example.test');
        Notification::assertSentOnDemand(WorkflowHandoffEmail::class, fn ($notification, $channels, $recipient) =>
            $channels === ['mail'] && $recipient->routes['mail'] === 'smt@example.test');
        Notification::assertCount(4);
    }

    public function test_draft_submit_and_returned_edit_submit_skip_management_and_reject_duplicates(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post(route('purchase-requests.store'), [...$this->payload(), 'action' => 'save_draft'])
            ->assertSessionHasNoErrors();
        $request = PurchaseRequest::query()->sole();
        Notification::assertNothingSent();
        $signature = $this->workflowSignature($owner);
        $this->post(route('purchase-requests.submit', $request), $signature)->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForTeamScreening, $request->fresh()->status);
        $this->post(route('purchase-requests.submit', $request), $signature)->assertForbidden();
        $request->update(['status' => PurchaseRequestStatus::Returned]);
        $this->put(route('purchase-requests.update', $request), $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForTeamScreening, $request->fresh()->status);
        $this->assertTrue($request->auditLogs()->where('action', 'RESUBMITTED_FOR_SCREENING')->exists());
        $this->assertSame(2, $request->approvals()->count());
    }

    public function test_om_and_smt_cannot_review_or_edit_submitted_requests(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post(route('purchase-requests.store'), $this->payload())->assertSessionHasNoErrors();
        $request = PurchaseRequest::query()->sole();
        foreach ([RoleSlug::OmSmtsApprover, RoleSlug::Om, RoleSlug::Smt] as $role) {
            $manager = User::factory()->create(['role_id' => $this->roleId($role)]);
            $this->actingAs($manager)->post(route('om-approvals.approve', $request), $this->workflowSignature($manager))->assertForbidden();
            $this->post(route('om-approvals.return', $request), ['remarks' => 'Return', ...$this->workflowSignature($manager)])->assertForbidden();
            $this->get(route('purchase-requests.edit', $request))->assertForbidden();
        }
        $this->assertFalse($request->approvals()->where('approval_level', 'OM_REVIEW')->exists());
    }

    public function test_pending_management_requests_migrate_without_rerouting_downstream_work(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post(route('purchase-requests.store'), $this->payload())->assertSessionHasNoErrors();
        $request = PurchaseRequest::query()->sole();
        $request->update(['status' => PurchaseRequestStatus::ForOmApproval, 'requested_team' => 'PREMISES']);
        $migration = require database_path('migrations/2026_10_29_000100_route_management_approval_requests_to_screening.php');
        $migration->up();
        $this->assertSame(PurchaseRequestStatus::ForPremisesScreening, $request->fresh()->status);
        $this->assertSame($this->roleId(RoleSlug::PremisesStaff), $request->fresh()->current_responsible_role_id);
        $request->update(['status' => PurchaseRequestStatus::ForPayment]);
        $migration->up();
        $this->assertSame(PurchaseRequestStatus::ForPayment, $request->fresh()->status);
        Notification::assertCount(2);
    }

    private function owner(): User
    {
        $campaign = Campaign::query()->create(['name' => 'Submission testing', 'is_active' => true]);
        return User::factory()->create([
            'role_id' => $this->roleId(RoleSlug::TeamLeader), 'campaign_id' => $campaign->id,
            'om_notification_email' => 'om@example.test', 'smt_notification_email' => 'smt@example.test',
        ]);
    }

    private function roleId(RoleSlug $role): int
    {
        return Role::query()->where('slug', $role->value)->value('id');
    }

    private function payload(): array
    {
        return [
            'action' => 'submit', 'title' => 'Office supplies', 'purpose' => 'Office use',
            'department_campaign' => auth()->user()->campaign->name,
            'required_date' => now()->addWeek()->toDateString(), 'delivery_location' => 'Main Office',
            'items' => [[
                'description' => 'Office chair', 'quantity' => 1, 'unit' => 'pc', 'brand_specifications' => 'Ergonomic, PHP 6,500',
                'attachment' => UploadedFile::fake()->create('approval.pdf', 20, 'application/pdf'), 'picture' => UploadedFile::fake()->image('item.jpg'),
            ]],
            ...$this->workflowSignature(auth()->user()),
        ];
    }
}
