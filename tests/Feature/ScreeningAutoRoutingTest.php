<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\Campaign;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Role;
use App\Models\User;
use App\Support\ScreeningRouter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreeningAutoRoutingTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_requests_of_only_it_equipment_go_straight_to_it_screening(): void
    {
        $request = $this->draftForScreening([
            ['description' => 'Business laptops', 'brand_specifications' => '16 GB RAM'],
            ['description' => '24-inch Monitor', 'brand_specifications' => null],
            ['description' => 'Wireless keyboard and mouse combo', 'brand_specifications' => null],
        ]);

        $this->submit($request);

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::ForItScreening, $request->status);
        $this->assertSame('IT', $request->request_type);
        $this->assertNull($request->assigned_user_id);
        $this->assertSame($this->roleId(RoleSlug::ItStaff), $request->current_responsible_role_id);
        $log = $request->auditLogs()->where('action', 'OM_APPROVED_FOR_IT_SCREENING')->sole();
        $this->assertSame('IT', $log->metadata['auto_routed']);
        $this->assertContains('laptop', $log->metadata['matched_keywords']);

        // Direct IT screening does not require a claim; the decision records the acting screener.
        $this->actingAs($this->user(RoleSlug::PremisesStaff))->post(route('request-flow.claim', $request))->assertForbidden();
        $itStaff = $this->user(RoleSlug::ItStaff);
        $this->assertTrue($itStaff->can('screen', $request));
        $this->actingAs($itStaff)->post(route('request-flow.claim', $request))->assertForbidden();
        $this->delete(route('request-flow.unclaim', $request))->assertForbidden();
        $this->assertSame(PurchaseRequestStatus::ForItScreening, $request->fresh()->status);
        $this->assertNull($request->fresh()->assigned_user_id);
    }

    public function test_mixed_or_non_it_requests_stay_in_the_shared_queue(): void
    {
        $mixed = $this->draftForScreening([
            ['description' => 'Laptop', 'brand_specifications' => null],
            ['description' => 'Office chair', 'brand_specifications' => null],
        ]);
        $premises = $this->draftForScreening([
            ['description' => 'Light switch replacement', 'brand_specifications' => null],
            ['description' => 'Window blinds', 'brand_specifications' => null],
        ]);

        $this->submit($mixed);
        $this->submit($premises);

        foreach ([$mixed, $premises] as $request) {
            $request->refresh();
            $this->assertSame(PurchaseRequestStatus::ForTeamScreening, $request->status);
            $this->assertSame('UNCLASSIFIED', $request->request_type);
        }
    }

    public function test_the_requestors_chosen_team_overrides_keyword_routing(): void
    {
        // Laptop items would route to IT by keyword, but the requestor chose Premises.
        $request = $this->draftForScreening([['description' => 'Laptop', 'brand_specifications' => null]]);
        $request->forceFill(['requested_team' => 'PREMISES', 'request_category' => 'FURNITURE'])->save();

        $this->submit($request);

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::ForPremisesScreening, $request->status);
        $this->assertSame('PREMISES', $request->request_type);
        $this->assertSame($this->roleId(RoleSlug::PremisesStaff), $request->current_responsible_role_id);
        $log = $request->auditLogs()->where('action', 'OM_APPROVED_FOR_PREMISES_SCREENING')->sole();
        $this->assertSame('requestor', $log->metadata['routed_by']);

        // It is offered only to Premises: IT neither sees it in their queue nor can claim it.
        $itStaff = $this->user(RoleSlug::ItStaff);
        $this->actingAs($itStaff)->post(route('request-flow.claim', $request))->assertForbidden();
        $this->assertFalse(PurchaseRequest::query()->visibleTo($itStaff)->whereKey($request->id)->exists());

        // Unclaiming keeps it in the Premises queue, not the shared one.
        $premises = $this->user(RoleSlug::PremisesStaff);
        $this->assertTrue(PurchaseRequest::query()->visibleTo($premises)->whereKey($request->id)->exists());
        $this->actingAs($premises)->post(route('request-flow.claim', $request))->assertSessionHasNoErrors();
        $this->delete(route('request-flow.unclaim', $request))->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::ForPremisesScreening, $request->status);
        $this->assertNull($request->assigned_user_id);
    }

    public function test_each_team_queue_shows_only_its_own_requests_plus_older_ones_without_a_team(): void
    {
        $itRequest = $this->draftForScreening([['description' => 'Laptop', 'brand_specifications' => null]]);
        $itRequest->forceFill(['requested_team' => 'IT', 'request_category' => 'COMPUTERS'])->save();
        $premisesRequest = $this->draftForScreening([['description' => 'Office chair', 'brand_specifications' => null]]);
        $premisesRequest->forceFill(['requested_team' => 'PREMISES', 'request_category' => 'FURNITURE'])->save();
        $olderRequest = $this->draftForScreening([['description' => 'Office chair', 'brand_specifications' => null]]);
        foreach ([$itRequest, $premisesRequest, $olderRequest] as $request) {
            $this->submit($request);
        }

        $it = $this->user(RoleSlug::ItStaff);
        $this->actingAs($it)->get(route('dashboard'))->assertOk()
            ->assertSee($itRequest->request_number)
            ->assertDontSee($premisesRequest->request_number)
            ->assertSee($olderRequest->request_number)
            ->assertSee('No team selected');

        $this->actingAs($this->user(RoleSlug::PremisesStaff))->get(route('dashboard'))->assertOk()
            ->assertSee($premisesRequest->request_number)
            ->assertDontSee($itRequest->request_number)
            ->assertSee($olderRequest->request_number);
    }

    public function test_keywords_match_whole_words_only(): void
    {
        $router = app(ScreeningRouter::class);

        $this->assertSame(['printer', 'toner'], $router->itMatches($this->draftForScreening([['description' => 'Printer toner', 'brand_specifications' => null]])));
        // "ram" inside "frame" and "ups" inside "cups" must not count as IT.
        $this->assertSame([], $router->itMatches($this->draftForScreening([['description' => 'Picture frame', 'brand_specifications' => 'Paper cups holder']])));
    }

    /** @param list<array{description: string, brand_specifications: ?string}> $items */
    private function draftForScreening(array $items): PurchaseRequest
    {
        $this->sequence++;
        $request = PurchaseRequest::query()->create([
            'request_number' => 'AUTO-'.$this->sequence,
            'title' => 'Auto routing '.$this->sequence,
            'requestor_id' => $this->user(RoleSlug::TeamLeader)->id,
            'department_campaign' => 'Operations',
            'request_type' => 'UNCLASSIFIED',
            'purpose' => 'Routing check',
            'required_date' => now()->addWeek(),
            'delivery_location' => 'Main Office',
            'status' => PurchaseRequestStatus::Draft,
            'total_amount' => 0,
        ]);
        foreach ($items as $item) {
            // Submission needs a quotation and an email approval; the wording avoids routing keywords.
            $created = $request->items()->create([...$item, 'brand_specifications' => $item['brand_specifications'] ?? 'Quoted 500', 'quantity' => 1, 'unit' => 'pc']);
            foreach ([PurchaseRequestItem::ATTACHMENT_EMAIL_APPROVAL => 'approval.pdf', PurchaseRequestItem::ATTACHMENT_PICTURE => 'item.jpg'] as $category => $name) {
                $created->attachments()->create([
                    'uploaded_by' => $request->requestor_id, 'category' => $category, 'original_name' => $name,
                    'disk' => 'local', 'path' => "items/{$name}", 'size' => 1,
                ]);
            }
        }

        return $request;
    }

    private function submit(PurchaseRequest $request): void
    {
        $owner = $request->requestor;
        $this->actingAs($owner)->post(route('purchase-requests.submit', $request), [
            'signature_name' => $owner->name,
            'signature_data' => $this->signatureImage(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseRequestStatus::ForOmApproval, $request->fresh()->status);
        $om = $this->user(RoleSlug::Om);
        $this->actingAs($om)->post(route('om-approvals.approve', $request), $this->workflowSignature($om))
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create(['role_id' => $this->roleId($role), 'campaign_id' => Campaign::firstOrCreate(['name' => 'Routing campaign'], ['is_active' => true])->id]);
    }

    private function roleId(RoleSlug $role): int
    {
        return Role::query()->where('slug', $role->value)->value('id');
    }
}
