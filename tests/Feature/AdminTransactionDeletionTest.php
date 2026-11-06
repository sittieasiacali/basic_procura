<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models;
use App\Notifications\AdminRecordDeleted;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminTransactionDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        Notification::fake();
    }

    public function test_delete_buttons_are_admin_only_throughout_the_request_workflow(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->request($owner);
        $admin = $this->user(RoleSlug::Admin);
        foreach ([PurchaseRequestStatus::Draft, PurchaseRequestStatus::ForItScreening, PurchaseRequestStatus::ForPremisesScreening,
            PurchaseRequestStatus::ForProcurement, PurchaseRequestStatus::ForCanvassing, PurchaseRequestStatus::ForSupplierApproval,
            PurchaseRequestStatus::ForBudgetProcessing, PurchaseRequestStatus::ForPayment, PurchaseRequestStatus::ForReceiving,
            PurchaseRequestStatus::ForInventoryLogging, PurchaseRequestStatus::Fulfilled] as $status) {
            $request->update(['status' => $status]);
            $this->actingAs($admin)->get(route('purchase-requests.index'))->assertOk()
                ->assertSee('data-admin-delete-transaction="'.$request->id.'"', false);
        }
        $this->get(route('dashboard'))->assertOk()->assertSee('data-admin-delete-transaction="'.$request->id.'"', false);
        $this->actingAs($owner)->get(route('purchase-requests.index'))->assertOk()
            ->assertDontSee('data-admin-delete-transaction', false);
        $this->get(route('admin.transactions.delete', $request))->assertForbidden();
        $this->delete(route('admin.transactions.destroy', $request), [])->assertForbidden();
        $this->assertNull($request->fresh()->deleted_at);
    }

    public function test_admin_deletes_the_entire_workflow_and_notifies_only_the_correct_people(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $owner = $this->user(RoleSlug::Requestor);
        $om = $this->user(RoleSlug::Om);
        $smt = $this->user(RoleSlug::Smt);
        $owner->update(['om_notification_email' => $om->email, 'smt_notification_email' => $smt->email]);
        $request = $this->request($owner);
        $records = $this->workflow($request, $admin);
        $unrelated = $this->request($this->user(RoleSlug::Requestor));
        $this->actingAs($admin);
        $review = $this->get(route('admin.transactions.delete', $request))->assertOk()
            ->assertSee('Delete entire transaction')->assertSee('Payments')->assertSee($om->email)->assertSee($smt->email);
        $this->delete(route('admin.transactions.destroy', $request), $this->confirmation($review->viewData('planToken')))
            ->assertSessionHasNoErrors()->assertRedirect(route('purchase-requests.index'));
        $this->assertSoftDeleted($request);
        foreach ($records['soft'] as $record) {
            $this->assertDatabaseMissing($record->getTable(), [$record->getKeyName() => $record->getKey()]);
        }
        foreach ($records['hard'] as $record) {
            $this->assertModelMissing($record);
        }
        $this->assertNull($unrelated->fresh()->deleted_at);
        $this->assertModelExists($owner);
        $this->assertModelExists($records['supplier']);
        Storage::disk('local')->assertExists('quotation.pdf');
        $audit = Models\AuditLog::where('action', 'ADMIN_TRANSACTION_DELETED')->sole();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertNotEmpty($audit->metadata['deleted_records']);
        $approvalSnapshot = collect($audit->metadata['deleted_records'])->firstWhere('model', Models\RequestApproval::class);
        $this->assertSame('saved signature', $approvalSnapshot['attributes']['signature_data']);
        $this->assertTrue(Models\AuditLog::where('action', 'CREATED_DRAFT')->where('auditable_id', $request->id)->exists());
        Notification::assertSentOnDemandTimes(AdminRecordDeleted::class, 3);
        foreach ([$owner, $om, $smt] as $recipient) {
            Notification::assertSentOnDemand(AdminRecordDeleted::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === strtolower($recipient->email));
            Notification::assertSentTo($recipient, AdminRecordDeleted::class, fn ($notification, $channels) => $channels === ['database']
                && $notification->toArray($recipient)['request_number'] === $request->request_number);
        }
        Notification::assertNotSentTo($admin, AdminRecordDeleted::class);
        $this->delete(route('admin.transactions.destroy', $request), $this->confirmation($review->viewData('planToken')))->assertNotFound();
        Notification::assertSentOnDemandTimes(AdminRecordDeleted::class, 3);
    }

    public function test_review_confirmation_and_valid_recipients_are_required_before_deletion(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->request($owner);
        $this->actingAs($admin)->delete(route('admin.transactions.destroy', $request), [])->assertSessionHasErrors(['reason', 'confirmation', 'plan_token', 'return_to']);
        $token = $this->get(route('admin.transactions.delete', $request))->viewData('planToken');
        $request->items()->create(['description' => 'Added after review', 'quantity' => 1]);
        $this->delete(route('admin.transactions.destroy', $request), $this->confirmation($token))->assertSessionHasErrors('record');
        $this->assertNull($request->fresh()->deleted_at);
        $token = $this->get(route('admin.transactions.delete', $request))->viewData('planToken');
        $owner->update(['smt_notification_email' => null]);
        $this->delete(route('admin.transactions.destroy', $request), $this->confirmation($token))->assertSessionHasErrors('record');
        $this->assertSame(1, $request->items()->count());
        $this->assertNull($request->fresh()->deleted_at);
        $this->assertSame(0, Models\AuditLog::where('action', 'ADMIN_TRANSACTION_DELETED')->count());
        Notification::assertNothingSent();
    }

    public function test_duplicate_recipient_addresses_receive_one_email_and_bell_alert(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $owner->update(['om_notification_email' => $owner->email, 'smt_notification_email' => strtoupper($owner->email)]);
        $request = $this->request($owner);
        $this->actingAs($this->user(RoleSlug::Admin));
        $token = $this->get(route('admin.transactions.delete', $request))->viewData('planToken');
        $this->delete(route('admin.transactions.destroy', $request), $this->confirmation($token))->assertSessionHasNoErrors();
        Notification::assertSentOnDemandTimes(AdminRecordDeleted::class, 1);
        Notification::assertSentToTimes($owner, AdminRecordDeleted::class, 1);
    }

    public function test_shared_budgets_are_protected_from_deleting_another_requests_data(): void
    {
        $admin = $this->user(RoleSlug::Admin);
        $request = $this->request($this->user(RoleSlug::Requestor));
        $other = $this->request($this->user(RoleSlug::Requestor));
        $budget = Models\BudgetRequest::create([
            'budget_request_number' => 'BR-SHARED', 'purchase_request_id' => $request->id,
            'requested_by' => $admin->id, 'amount' => 200, 'requested_at' => now(),
        ]);
        foreach ([$request, $other] as $entry) {
            Models\BudgetRequestPurchaseOrder::create(['budget_request_id' => $budget->id, 'purchase_request_id' => $entry->id, 'amount' => 100]);
        }
        $this->actingAs($admin)->get(route('admin.transactions.delete', $request))->assertOk()
            ->assertSee('shared Budget Request')->assertSee('disabled', false);
        $this->delete(route('admin.transactions.destroy', $request), $this->confirmation(str_repeat('0', 64)))->assertSessionHasErrors('record');
        $this->assertNull($request->fresh()->deleted_at);
        $this->assertNull($other->fresh()->deleted_at);
        $this->assertNull($budget->fresh()->deleted_at);
        Notification::assertNothingSent();
    }

    public function test_deleting_a_screened_transaction_restores_its_released_shared_stock_once(): void
    {
        [$request, $stock, $release] = $this->releasedStock();
        $this->actingAs($this->user(RoleSlug::Admin));
        $review = $this->get(route('admin.transactions.delete', $request))->assertOk()
            ->assertSee('2 units restored.');
        $this->delete(route('admin.transactions.destroy', $request), $this->confirmation($review->viewData('planToken')))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($request);
        $this->assertModelMissing($release);
        $this->assertSame('3.00', $stock->fresh()->quantity);
        $this->assertSame('LOGGED', $stock->fresh()->status);
        $this->assertSame(1, $stock->activities()->where('action', 'RELEASE_REVERSED')->count());
        $this->delete(route('admin.transactions.destroy', $request), $this->confirmation($review->viewData('planToken')))->assertNotFound();
        $this->assertSame('3.00', $stock->fresh()->quantity);
    }

    public function test_a_late_inventory_failure_rolls_back_the_entire_deletion(): void
    {
        [$request, $stock, $release] = $this->releasedStock();
        $this->actingAs($this->user(RoleSlug::Admin));
        $token = $this->get(route('admin.transactions.delete', $request))->viewData('planToken');
        $stock->delete();
        $this->delete(route('admin.transactions.destroy', $request), $this->confirmation($token))->assertSessionHasErrors('record');
        $this->assertNull($request->fresh()->deleted_at);
        $this->assertModelExists($release);
        $this->assertTrue($request->screening()->exists());
        $this->assertSame(0, Models\AuditLog::where('action', 'ADMIN_TRANSACTION_DELETED')->count());
        Notification::assertNothingSent();
    }

    private function releasedStock(): array
    {
        $owner = $this->user(RoleSlug::Requestor);
        $actor = $this->user(RoleSlug::ItStaff);
        $request = $this->request($owner);
        $request->update(['status' => PurchaseRequestStatus::Fulfilled]);
        $stock = Models\InventoryItem::create(['inventory_code' => 'INV-SOURCE', 'logged_by' => $actor->id,
            'description' => 'Existing stock', 'quantity' => 3, 'unit' => 'pc', 'status' => 'LOGGED', 'logged_at' => now()]);
        $stock->update(['quantity' => 1]);
        $screening = $request->screening()->create(['screened_by' => $actor->id, 'team' => 'IT', 'decision' => 'AVAILABLE', 'decided_at' => now()]);
        $screening->assets()->create(['inventory_item_id' => $stock->id, 'asset_tag' => 'TAG-SOURCE', 'item_code' => $stock->sku,
            'description' => 'Existing stock', 'quantity' => 2, 'location' => 'Main office', 'source' => 'IT_SELF_INVENTORY',
            'release_date' => now(), 'released_by' => $actor->id, 'recipient' => $owner->name]);
        $release = $stock->releases()->create(['released_by' => $actor->id, 'recipient_user_id' => $owner->id, 'quantity' => 2,
            'released_to' => $owner->name, 'remarks' => 'Automatically released for request '.$request->request_number.'.', 'released_at' => now()]);
        $stock->activities()->create(['inventory_release_id' => $release->id, 'actor_id' => $actor->id, 'action' => 'RELEASED', 'quantity' => 2, 'occurred_at' => now()]);

        return [$request, $stock, $release];
    }

    private function workflow(Models\PurchaseRequest $request, Models\User $actor): array
    {
        $item = $request->items()->create(['description' => 'Laptop', 'quantity' => 1, 'unit' => 'pc']);
        $approval = $request->approvals()->create(['user_id' => $actor->id, 'approval_level' => 'REQUESTOR_SUBMISSION', 'new_status' => 'FOR_IT_SCREENING', 'action' => 'SUBMITTED_FOR_SCREENING', 'acted_at' => now(), 'signature_data' => 'saved signature']);
        $screening = $request->screening()->create(['screened_by' => $actor->id, 'team' => 'IT', 'decision' => 'UNAVAILABLE', 'decided_at' => now()]);
        $supplier = Models\Supplier::create(['name' => 'Supplier', 'is_active' => true]);
        $quotation = $request->quotations()->create(['supplier_id' => $supplier->id, 'created_by' => $actor->id, 'item_or_service' => 'Laptop', 'price' => 100]);
        Storage::disk('local')->put('quotation.pdf', 'saved quotation');
        $attachment = $quotation->attachments()->create(['uploaded_by' => $actor->id, 'category' => 'supplier_quotation', 'original_name' => 'quotation.pdf', 'disk' => 'local', 'path' => 'quotation.pdf', 'mime_type' => 'application/pdf', 'size' => 15]);
        $order = $request->purchaseOrder()->create(['po_number' => 'PO-DELETE', 'supplier_id' => $supplier->id, 'created_by' => $actor->id, 'issued_at' => now(), 'status' => 'APPROVED', 'total_amount' => 100]);
        $orderItem = $order->items()->create(['purchase_request_item_id' => $item->id, 'description' => 'Laptop', 'quantity' => 1, 'unit' => 'pc', 'unit_price' => 100, 'total_price' => 100]);
        $orderApproval = $order->approvals()->create(['stage' => PurchaseOrderApprovalStage::PreparedBy, 'assigned_user_id' => $actor->id, 'status' => 'APPROVED']);
        $budget = Models\BudgetRequest::create(['budget_request_number' => 'BR-DELETE', 'purchase_request_id' => $request->id, 'purchase_order_id' => $order->id, 'requested_by' => $actor->id, 'amount' => 100, 'requested_at' => now()]);
        $allocation = Models\BudgetRequestPurchaseOrder::create(['budget_request_id' => $budget->id, 'purchase_request_id' => $request->id, 'purchase_order_id' => $order->id, 'amount' => 100]);
        $line = $budget->lines()->create(['purchase_order_id' => $order->id, 'requested_by_user_id' => $actor->id, 'details' => 'Laptop', 'amount' => 100]);
        $payment = $request->payments()->create(['budget_request_id' => $budget->id, 'recorded_by' => $actor->id, 'amount' => 100, 'payment_type' => 'FULL', 'payment_method' => 'CASH', 'paid_at' => now()]);
        $receipt = $request->goodsReceipts()->create(['receipt_number' => 'GR-DELETE', 'purchase_order_id' => $order->id, 'received_by' => $actor->id, 'received_at' => now(), 'status' => 'COMPLETE']);
        $receiptItem = $receipt->items()->create(['purchase_order_item_id' => $orderItem->id, 'description' => 'Laptop', 'quantity_received' => 1, 'unit' => 'pc', 'condition' => 'GOOD']);
        $stock = Models\InventoryItem::create(['purchase_request_id' => $request->id, 'goods_receipt_item_id' => $receiptItem->id, 'inventory_code' => 'INV-DELETE', 'logged_by' => $actor->id, 'description' => 'Laptop', 'quantity' => 1, 'unit' => 'pc', 'logged_at' => now()]);
        $request->auditLogs()->create(['user_id' => $actor->id, 'action' => 'CREATED_DRAFT', 'remarks' => 'Original audit history']);

        return ['soft' => [$item, $quotation, $attachment, $order, $budget, $payment, $receipt, $stock],
            'hard' => [$approval, $screening, $orderItem, $orderApproval, $allocation, $line, $receiptItem], 'supplier' => $supplier];
    }

    private function user(RoleSlug $role): Models\User
    {
        return Models\User::factory()->create(['role_id' => Models\Role::where('slug', $role->value)->value('id'), 'om_notification_email' => 'om@example.test', 'smt_notification_email' => 'smt@example.test']);
    }

    private function request(Models\User $owner): Models\PurchaseRequest
    {
        return Models\PurchaseRequest::create(['request_number' => 'REQ-TXN-'.$owner->id, 'requestor_id' => $owner->id,
            'title' => 'Transaction deletion', 'department_campaign' => 'Operations', 'request_type' => 'IT',
            'purpose' => 'Test', 'required_date' => now()->addWeek(), 'delivery_location' => 'Main office', 'status' => PurchaseRequestStatus::Draft]);
    }

    private function confirmation(string $token): array
    {
        return ['reason' => 'Remove duplicate transaction.', 'confirmation' => 1, 'return_to' => 'purchase-requests.index', 'plan_token' => $token];
    }
}
