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

class DashboardQueueOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_canvassing_processed_rows_and_counts_belong_only_to_the_signed_in_actor(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $owner->roles()->attach(Role::where('slug', RoleSlug::PurchasingTeam->value)->value('id'));
        $other = $this->user(RoleSlug::PurchasingTeam);
        $unused = $this->user(RoleSlug::PurchasingTeam);
        $ownIds = [];
        foreach (['CANVASSING_UPDATED', 'CANVASSING_SUBMITTED_WITHOUT_QUOTATION', 'CANVASSING_SUBMITTED_WITHOUT_ADDITIONAL_QUOTATION'] as $index => $action) {
            $request = $this->request('REQ-OWN-'.$index, $other);
            $this->handoff($request, $owner, $action);
            $ownIds[] = $request->id;
        }
        $othersRequest = $this->request('REQ-OTHER', $owner);
        $this->handoff($othersRequest, $other, 'CANVASSING_UPDATED');

        foreach ([[$owner, $ownIds], [$other, [$othersRequest->id]], [$unused, []]] as [$viewer, $ids]) {
            $response = $this->actingAs($viewer)->withSession([
                'workspace_role_id' => Role::where('slug', RoleSlug::PurchasingTeam->value)->value('id'),
            ])->get(route('dashboard'));
            $response->assertOk()->assertViewHas('canvassingRecords', function ($records) use ($ids) {
                $actual = $records->pluck('id')->sort()->values()->all();
                sort($ids);

                return $actual === $ids;
            })->assertViewHas('processedTabCounts', fn ($counts) => $counts['processed'] === count($ids));
        }
    }

    public function test_president_sees_recent_real_handoffs_before_old_samples_and_can_page_through_every_request(): void
    {
        $actor = $this->user(RoleSlug::PurchasingTeam);
        $oldIds = [];
        for ($i = 1; $i <= 14; $i++) {
            $old = $this->request('REQ-UAT-OLD-'.$i, $actor);
            $old->forceFill(['updated_at' => now()->subDays(20)->addMinutes($i)])->save();
            $oldIds[] = $old->id;
        }
        $newIds = [];
        foreach (['REQ00136', 'REQ00134', 'REQ00043'] as $index => $number) {
            $new = $this->request($number, $actor);
            $new->forceFill(['updated_at' => now()->subMinutes(3 - $index)])->save();
            $newIds[] = $new->id;
        }
        $president = $this->user(RoleSlug::President);
        $first = $this->actingAs($president)->get(route('dashboard'))->assertOk()
            ->assertSee('REQ00043')->assertSee('REQ00134')->assertSee('REQ00136')
            ->assertViewHas('actionCount', 17)
            ->assertViewHas('myActions', fn ($actions) => $actions->take(3)->pluck('request.id')->all() === array_reverse($newIds));
        $pageOne = $first->viewData('myActions')->pluck('request.id')->all();
        $second = $this->get(route('dashboard', ['approval_page' => 2]))->assertOk();
        $all = array_merge($pageOne, $second->viewData('myActions')->pluck('request.id')->all());
        $this->assertCount(17, array_unique($all));
        $this->get(route('dashboard', ['approval_order' => 'oldest']))->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->first()['request']->id === $oldIds[0]);
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role->value)->value('id')]);
    }

    private function request(string $number, User $requestor): PurchaseRequest
    {
        return PurchaseRequest::create([
            'request_number' => $number, 'title' => $number.' pending review', 'requestor_id' => $requestor->id,
            'department_campaign' => 'Operations', 'request_type' => 'IT', 'purpose' => 'Dashboard isolation test',
            'required_date' => now()->addWeek(), 'delivery_location' => 'Main Office',
            'status' => PurchaseRequestStatus::ForSupplierApproval,
            'current_responsible_role_id' => Role::where('slug', RoleSlug::President->value)->value('id'),
        ]);
    }

    private function handoff(PurchaseRequest $request, User $actor, string $action): void
    {
        $request->auditLogs()->create([
            'user_id' => $actor->id, 'action' => $action,
            'previous_status' => PurchaseRequestStatus::ForCanvassing->value,
            'new_status' => PurchaseRequestStatus::ForSupplierApproval->value,
        ]);
    }
}
