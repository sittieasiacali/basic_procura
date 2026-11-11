<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\ProcurementUpdate;
use App\Services\ProcurementNotifications;
use App\Services\WorkflowEmailNotifier;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierManagementAndNotificationsTest extends TestCase
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

    public function test_procurement_can_manage_suppliers_while_requestors_cannot(): void
    {
        $staff = $this->user('purchasing-team');
        $this->actingAs($staff)->post(route('suppliers.store'), ['name' => 'Acme', 'email' => 'sales@acme.test', 'is_active' => 1])->assertSessionHasNoErrors();
        $supplier = Supplier::sole();
        $this->assertSame(1, $supplier->auditLogs()->count());
        $this->get(route('suppliers.index'))->assertOk()->assertSee('Acme');
        $this->patch(route('suppliers.update', $supplier), ['name' => 'Acme', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($supplier->fresh()->is_active);
        $this->assertSame(2, $supplier->auditLogs()->count());
        $this->post(route('suppliers.store'), ['name' => 'Acme', 'is_active' => 1])->assertSessionHasErrors('name');
        $this->actingAs($this->user('requestor'))->get(route('suppliers.index'))->assertForbidden();
        $this->patch(route('suppliers.update', $supplier), ['name' => 'Changed', 'is_active' => 1])->assertForbidden();
    }

    public function test_notifications_are_scoped_to_the_owner_with_read_tracking(): void
    {
        $owner = $this->user('requestor');
        $stranger = $this->user('requestor');
        $owner->notify(new ProcurementUpdate(['title' => 'Owner only', 'message' => 'Ready for review']));
        $notification = $owner->notifications()->sole();
        $this->actingAs($stranger)->post(route('notifications.read', $notification->id))->assertNotFound();
        $this->get(route('notifications.index'))->assertOk()->assertDontSee('Owner only');
        $this->actingAs($owner)->get(route('notifications.index'))->assertOk()->assertSee('Owner only')->assertSee('1 unread');
        $this->post(route('notifications.read', $notification->id))->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);
        $owner->notify(new ProcurementUpdate(['title' => 'Second update']));
        $this->post(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $owner->unreadNotifications()->count());
        $this->get(route('notifications.summary'))->assertOk()->assertJson(['unread_count' => 0]);
        $this->get(route('notifications.index', ['filter' => 'unread']))->assertDontSee('Owner only');
    }

    public function test_po_handoffs_notify_the_next_signer_once_even_without_email_recipients(): void
    {
        $owner = $this->user('requestor');
        $checker = $this->user('requestor');
        $actor = $this->user('purchasing-team');
        $supplier = Supplier::create(['name' => 'PO supplier', 'is_active' => true]);
        $request = PurchaseRequest::create([
            'request_number' => 'REQ-PO-NOTIFY', 'request_type' => 'IT', 'requestor_id' => $owner->id,
            'title' => 'Laptop', 'department_campaign' => 'Operations', 'purpose' => 'Work',
            'delivery_location' => 'Office', 'status' => PurchaseRequestStatus::ForBudgetProcessing,
        ]);
        $order = $request->purchaseOrder()->create([
            'po_number' => 'PO-NOTIFY', 'supplier_id' => $supplier->id,
            'created_by' => $actor->id, 'status' => 'FOR_CHECKING', 'issued_at' => now(),
        ]);
        $order->approvals()->create([
            'stage' => PurchaseOrderApprovalStage::CheckedBy,
            'assigned_user_id' => $checker->id, 'status' => 'PENDING',
        ]);
        $notifier = app(WorkflowEmailNotifier::class);
        $notifier->purchaseOrderMoved($order, $actor);
        $notifier->purchaseOrderMoved($order, $actor);
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(1, $checker->notifications()->count());
        $this->assertSame(0, $actor->notifications()->count());
    }

    public function test_workflow_updates_reach_owner_and_authorized_staff_only_and_rollback_with_transaction(): void
    {
        $owner = $this->user('requestor');
        $stranger = $this->user('requestor');
        $staff = $this->user('it-staff');
        $inactive = $this->user('it-staff');
        $inactive->update(['is_active' => false]);
        $request = PurchaseRequest::create([
            'request_number' => 'REQ-NOTIFY', 'requestor_id' => $owner->id,
            'title' => 'Laptop', 'department_campaign' => 'Operations', 'purpose' => 'Work',
            'delivery_location' => 'Office', 'status' => PurchaseRequestStatus::ForItScreening,
            'request_type' => 'IT', 'current_responsible_role_id' => $staff->role_id,
        ]);
        app(ProcurementNotifications::class)->send($request, 'SUBMITTED_FOR_SCREENING', 'IT screening');
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(1, $staff->notifications()->count());
        $this->assertSame(0, $stranger->notifications()->count());
        $this->assertSame(0, $inactive->notifications()->count());
        DB::beginTransaction();
        app(ProcurementNotifications::class)->send($request, 'TEST', 'Rolled back');
        DB::rollBack();
        $this->assertSame(1, $owner->notifications()->count());
    }
}
