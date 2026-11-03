<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowSubmissionPopupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_canvassing_submission_carries_view_and_cancel_popup_through_the_iframe_receipt(): void
    {
        $actor = $this->user(RoleSlug::PurchasingTeam);
        $request = $this->requestFor($actor, PurchaseRequestStatus::ForCanvassing);
        $this->actingAs($actor)->post(route('canvassing.quotations.store', $request), [
            'quotation_mode' => 'manual', 'quotations' => [], 'confirmed_without_quotation' => 1, '_embedded' => 1,
            'purchase_request_id' => $this->requestFor($this->user(RoleSlug::Requestor), PurchaseRequestStatus::Draft)->id,
        ])->assertRedirect(route('workflow.done'))->assertSessionHas('workflow_submission_notice', fn ($notice) => count($notice['requests']) === 1 && $notice['requests'][0]['code'] === $request->request_number
            && str_contains($notice['requests'][0]['url'], 'preview=1')
            && $notice['stage'] === 'Sent to President review');
        $this->get(route('workflow.done'))->assertOk()->assertSee('asi-procura:submission')->assertSee($request->request_number);
        $this->get(route('dashboard'))->assertOk()->assertSee('View request')->assertSee('Cancel');
        $this->assertSame(PurchaseRequestStatus::ForSupplierApproval, $request->fresh()->status);
    }

    public function test_screening_and_president_actions_have_the_same_popup_payload(): void
    {
        $actor = $this->user(RoleSlug::ItStaff);
        $request = $this->requestFor($actor, PurchaseRequestStatus::ForItScreening);
        $this->actingAs($actor)->post(route('request-flow.screening.store', $request), [
            'decision' => 'UNAVAILABLE', ...$this->workflowSignature($actor),
        ])->assertSessionHasNoErrors()->assertSessionHas('workflow_submission_notice', fn ($notice) => $notice['requests'][0]['code'] === $request->request_number && str_contains($notice['stage'], 'Screening completed'));

        $president = $this->user(RoleSlug::President);
        $request->update(['status' => PurchaseRequestStatus::ForSupplierApproval]);
        $this->actingAs($president)->post(route('president-approvals.supplier.decision', $request), [
            'decision' => 'ACKNOWLEDGE', ...$this->workflowSignature($president),
        ])->assertSessionHasNoErrors()->assertSessionHas('workflow_submission_notice', fn ($notice) => $notice['requests'][0]['code'] === $request->request_number && $notice['stage'] === 'Sent to Canvassing');
        $this->get(route('purchase-requests.show', $request))->assertOk()
            ->assertSee('id="workflow-submission-data"', false)->assertSee('View request')->assertSee('Cancel');
    }

    public function test_failed_submissions_and_simple_page_visits_do_not_create_a_success_popup(): void
    {
        $actor = $this->user(RoleSlug::PurchasingTeam);
        $request = $this->requestFor($actor, PurchaseRequestStatus::ForCanvassing);
        $this->actingAs($actor)->get(route('dashboard'))->assertOk()->assertDontSee('id="workflow-submission-data"', false);
        $this->post(route('canvassing.quotations.store', $request), ['quotations' => []])
            ->assertSessionHasErrors('confirmed_without_quotation')->assertSessionMissing('workflow_submission_notice');
        $this->assertSame(PurchaseRequestStatus::ForCanvassing, $request->fresh()->status);
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role->value)->value('id')]);
    }

    private function requestFor(User $actor, PurchaseRequestStatus $status): PurchaseRequest
    {
        return PurchaseRequest::create([
            'request_number' => 'REQ-POPUP-'.$actor->id, 'title' => 'Workflow confirmation', 'requestor_id' => $actor->id,
            'status' => $status, 'request_type' => 'IT', 'assigned_user_id' => $actor->id,
            'department_campaign' => 'Operations', 'purpose' => 'Confirm workflow result',
            'required_date' => now()->addWeek(), 'delivery_location' => 'Main Office',
        ]);
    }
}
