<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\TeamLeaderActivityNotifier;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowEmailRecipientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_secondary_requestor_role_receives_management_emails(): void
    {
        $this->seed(RoleSeeder::class);
        $owner = $this->owner(RoleSlug::PurchasingTeam);
        $owner->roles()->attach(Role::where('slug', RoleSlug::Requestor->value)->value('id'));

        $this->assertSame(['om@example.test', 'smt@example.test'], $this->addresses($owner));
    }

    public function test_legacy_primary_requestor_role_still_receives_management_emails_and_deduplicates(): void
    {
        $this->seed(RoleSeeder::class);
        $owner = $this->owner(RoleSlug::Requestor);
        $owner->update(['smt_notification_email' => 'om@example.test']);

        $this->assertSame(['om@example.test'], $this->addresses($owner));
    }

    public function test_staff_without_an_assigned_requestor_role_is_not_a_recipient_source(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertSame([], $this->addresses($this->owner(RoleSlug::PurchasingTeam)));
    }

    private function owner(RoleSlug $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role->value)->value('id'),
            'om_notification_email' => 'om@example.test',
            'smt_notification_email' => 'smt@example.test',
        ]);
    }

    private function addresses(User $owner): array
    {
        $request = new PurchaseRequest(['requestor_id' => $owner->id]);

        return app(TeamLeaderActivityNotifier::class)->addresses($request);
    }
}
