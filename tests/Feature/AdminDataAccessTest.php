<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\AdminRecordDeleted;
use App\Support\AdminRecordCatalog;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDataAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Notification::fake();
    }

    public function test_admin_can_open_every_record_directory_and_switch_to_unassigned_workspaces(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        foreach (array_keys(AdminRecordCatalog::models()) as $type) {
            $this->actingAs($admin)->get(route('admin.data.index', ['type' => $type]))->assertOk()->assertSee('Manage Data');
        }
        $itRole = Role::where('slug', RoleSlug::ItStaff->value)->firstOrFail();
        $this->post(route('workspace-role.update'), ['role_id' => $itRole->id])->assertSessionHasNoErrors();
        $this->get(route('admin.data.index'))->assertOk();
        $this->assertTrue(Gate::forUser($admin)->allows('create', PurchaseRequest::class));
        $this->assertTrue(Gate::forUser($admin)->allows('prepare', PurchaseOrder::class));
        $this->assertFalse($admin->roles()->whereKey($itRole->id)->exists());
    }

    public function test_non_admin_cannot_manage_or_delete_data_or_switch_to_admin(): void
    {
        $user = $this->user(RoleSlug::Requestor);
        $supplier = Supplier::create(['name' => 'Keep supplier', 'is_active' => true]);
        $this->actingAs($user)->get(route('admin.data.index'))->assertForbidden();
        $this->delete(route('admin.data.destroy', ['type' => 'suppliers', 'id' => $supplier->id]), $this->confirmation())->assertForbidden();
        $this->post(route('workspace-role.update'), ['role_id' => Role::where('slug', 'admin')->value('id')])->assertSessionHasErrors('role_id');
        $this->assertModelExists($supplier);
        Notification::assertNothingSent();
    }

    public function test_request_deletion_is_audited_and_notifies_the_requestor_and_assigned_management(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $admin->update(['om_notification_email' => 'admin-om@example.test']);
        $owner = $this->user(RoleSlug::Requestor);
        $owner->update(['om_notification_email' => 'owner-om@example.test']);
        $request = $this->purchaseRequest($owner);
        $this->actingAs($admin)->delete(route('admin.data.destroy', ['type' => 'purchase-requests', 'id' => $request->id]), $this->confirmation())
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.data.index', ['type' => 'purchase-requests']));
        $this->assertSoftDeleted($request);
        $audit = AuditLog::where('action', 'ADMIN_RECORD_DELETED')->sole();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame($this->confirmation()['reason'], $audit->remarks);
        $this->assertSame([$owner->om_notification_email], $audit->metadata['om_recipients']);
        Notification::assertSentOnDemandTimes(AdminRecordDeleted::class, 3);
        Notification::assertSentOnDemand(AdminRecordDeleted::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $owner->om_notification_email
            && $notification->details['request_number'] === $request->request_number
            && $notification->afterCommit === true);
        $this->delete(route('admin.data.destroy', ['type' => 'purchase-requests', 'id' => $request->id]), $this->confirmation())->assertNotFound();
        Notification::assertSentOnDemandTimes(AdminRecordDeleted::class, 3);
    }

    public function test_admin_can_delete_unreferenced_accounts_and_setup_records(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $account = $this->user(RoleSlug::Requestor);
        $campaign = Campaign::create(['name' => 'Unused campaign', 'is_active' => true]);
        $role = Role::create(['name' => 'Unused role', 'slug' => 'unused-role']);
        foreach (['accounts' => $account, 'campaigns' => $campaign, 'roles' => $role] as $type => $record) {
            $this->actingAs($admin)->delete(route('admin.data.destroy', ['type' => $type, 'id' => $record->id]), $this->confirmation())->assertSessionHasNoErrors();
        }
        $this->assertModelMissing($account);
        $this->assertSoftDeleted($campaign);
        $this->assertModelMissing($role);
        $snapshot = AuditLog::where('action', 'ADMIN_RECORD_DELETED')->where('auditable_type', User::class)->sole()->metadata['snapshot'];
        $this->assertArrayNotHasKey('password', $snapshot);
        $this->assertArrayNotHasKey('remember_token', $snapshot);
    }

    public function test_deletion_requires_reason_confirmation_and_known_record_type(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $supplier = Supplier::create(['name' => 'Keep supplier', 'is_active' => true]);
        $this->actingAs($admin)->delete(route('admin.data.destroy', ['type' => 'suppliers', 'id' => $supplier->id]), [])
            ->assertSessionHasErrors(['reason', 'confirmation']);
        $this->delete(route('admin.data.destroy', ['type' => 'sessions', 'id' => 1]), $this->confirmation())->assertNotFound();
        $this->assertModelExists($supplier);
        $this->assertSame(0, AuditLog::where('action', 'ADMIN_RECORD_DELETED')->count());
    }

    public function test_admin_cannot_delete_the_signed_in_account_or_records_with_dependencies(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->purchaseRequest($owner);
        $request->items()->create(['description' => 'Linked item', 'quantity' => 1]);
        $this->actingAs($admin)->delete(route('admin.data.destroy', ['type' => 'accounts', 'id' => $admin->id]), $this->confirmation())->assertSessionHasErrors('record');
        $this->delete(route('admin.data.destroy', ['type' => 'purchase-requests', 'id' => $request->id]), $this->confirmation())->assertSessionHasErrors('record');
        $this->assertModelExists($request);
        $this->assertNull($request->fresh()->deleted_at);
        Notification::assertNothingSent();
    }

    public function test_request_related_deletion_is_blocked_when_the_om_is_unassigned(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $owner->update(['om_notification_email' => null]);
        $request = $this->purchaseRequest($owner);
        $this->actingAs($this->user(RoleSlug::Admin))->delete(route('admin.data.destroy', ['type' => 'purchase-requests', 'id' => $request->id]), $this->confirmation())
            ->assertSessionHasErrors('record');
        $this->assertNull($request->fresh()->deleted_at);
        $this->assertSame(0, AuditLog::where('action', 'ADMIN_RECORD_DELETED')->count());
        Notification::assertNothingSent();
    }

    public function test_deleting_a_request_item_emails_the_requests_om_and_preserves_the_request(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->purchaseRequest($owner);
        $item = $request->items()->create(['description' => 'Duplicate item', 'quantity' => 1]);
        $this->actingAs($this->user(RoleSlug::Admin))->delete(route('admin.data.destroy', ['type' => 'request-items', 'id' => $item->id]), $this->confirmation())
            ->assertSessionHasNoErrors();
        $this->assertSoftDeleted($item);
        $this->assertNull($request->fresh()->deleted_at);
        Notification::assertSentOnDemandTimes(AdminRecordDeleted::class, 3);
        Notification::assertSentOnDemand(AdminRecordDeleted::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $owner->om_notification_email
            && $notification->details['request_number'] === $request->request_number);
    }

    public function test_inactive_admin_has_no_privileged_access(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $admin->update(['is_active' => false]);
        $this->assertFalse($admin->isAdministrator());
        $this->assertFalse(Gate::forUser($admin)->allows('create', PurchaseRequest::class));
        $this->actingAs($admin)->get(route('admin.data.index'))->assertRedirect(route('login'));
    }

    public function test_admin_can_claim_and_screen_both_teams_requests(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        foreach (['IT' => PurchaseRequestStatus::ForItScreening, 'PREMISES' => PurchaseRequestStatus::ForPremisesScreening] as $team => $status) {
            $request = $this->purchaseRequest($this->user(RoleSlug::Requestor));
            $request->update(['request_type' => $team, 'requested_team' => $team, 'status' => $status]);
            $this->actingAs($admin)->post(route('request-flow.claim', $request))->assertSessionHasNoErrors();
            $this->assertSame($admin->id, $request->fresh()->assigned_user_id);
            $this->post(route('request-flow.screening.store', $request), [
                'decision' => 'UNAVAILABLE', ...$this->workflowSignature($admin),
            ])->assertSessionHasNoErrors();
            $this->assertSame(PurchaseRequestStatus::ForProcurement, $request->fresh()->status);
            $this->assertSame($admin->id, $request->screening()->sole()->screened_by);
        }
    }

    public function test_admin_can_delete_uuid_notification_records(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $notification = $owner->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['message' => 'Test notification'],
        ]);
        $this->actingAs($this->user(RoleSlug::Admin))->delete(route('admin.data.destroy', ['type' => 'notifications', 'id' => $notification->id]), $this->confirmation())
            ->assertSessionHasNoErrors();
        $this->assertModelMissing($notification);
        $this->assertSame($notification->id, AuditLog::where('action', 'ADMIN_RECORD_DELETED')->sole()->metadata['record_id']);
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role->value)->value('id'),
            'om_notification_email' => 'om@example.test', 'smt_notification_email' => 'smt@example.test',
        ]);
    }

    private function purchaseRequest(User $owner): PurchaseRequest
    {
        return PurchaseRequest::create([
            'request_number' => 'REQ-ADMIN-'.$owner->id, 'title' => 'Admin deletion test',
            'requestor_id' => $owner->id, 'department_campaign' => 'Operations',
            'request_type' => 'IT', 'purpose' => 'Test', 'required_date' => now()->addWeek(),
            'delivery_location' => 'Main office', 'status' => PurchaseRequestStatus::Draft,
        ]);
    }

    private function confirmation(): array
    {
        return ['reason' => 'Remove duplicate test record.', 'confirmation' => 1];
    }
}
