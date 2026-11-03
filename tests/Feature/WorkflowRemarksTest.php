<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\BudgetRequest;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Support\WorkflowRemarks;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkflowRemarksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_saved_notes_from_every_stage_are_visible_without_opening_a_disclosure(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $staff = $this->user(RoleSlug::Admin);
        $request = $this->purchaseRequest($requestor, 1);
        $unsafe = "Request note <script>alert('test')</script>\nSecond line";
        $request->update(['remarks' => $unsafe]);
        $at = now()->startOfSecond();
        $request->approvals()->create([
            'user_id' => $staff->id, 'approval_level' => 'OM_REVIEW', 'action' => 'OM_APPROVED',
            'previous_status' => 'FOR_OM_APPROVAL', 'new_status' => 'FOR_TEAM_SCREENING',
            'remarks' => 'Manager review note', 'acted_at' => $at,
        ]);
        $request->auditLogs()->create([
            'user_id' => $staff->id, 'action' => 'OM_APPROVED', 'remarks' => 'Manager review note',
            'created_at' => $at,
        ]);
        $request->screening()->create([
            'screened_by' => $staff->id, 'team' => 'IT', 'decision' => 'UNAVAILABLE',
            'evidence' => 'Screening evidence note', 'decided_at' => $at,
        ]);
        $supplier = Supplier::create(['name' => 'Remark Supplier', 'is_active' => true]);
        $request->quotations()->create([
            'supplier_id' => $supplier->id, 'created_by' => $staff->id,
            'item_or_service' => 'Workstation', 'price' => 100, 'remarks' => 'Quotation note',
        ]);
        $order = $request->purchaseOrder()->create([
            'po_number' => 'PO-REMARKS-1', 'supplier_id' => $supplier->id, 'created_by' => $staff->id,
            'issued_at' => now(), 'status' => 'APPROVED', 'total_amount' => 100, 'remarks' => 'PO preparation note',
        ]);
        $order->approvals()->create([
            'stage' => PurchaseOrderApprovalStage::CheckedBy, 'assigned_user_id' => $staff->id,
            'acted_by' => $staff->id, 'status' => 'APPROVED', 'remarks' => 'PO checker note', 'acted_at' => $at,
        ]);
        $budget = BudgetRequest::create([
            'purchase_request_id' => $request->id, 'purchase_order_id' => $order->id,
            'requested_by' => $requestor->id,
            'maker_user_id' => $staff->id, 'amount' => 100, 'status' => 'APPROVED',
            'remarks' => 'Budget preparation note', 'requested_at' => now(),
        ]);
        $budget->allocations()->create(['purchase_request_id' => $request->id, 'purchase_order_id' => $order->id, 'amount' => 100]);
        $budget->lines()->create([
            'purchase_order_id' => $order->id, 'requested_by_user_id' => $requestor->id,
            'details' => 'Workstation', 'amount' => 100, 'remarks' => 'Budget line note',
        ]);
        $request->payments()->create([
            'budget_request_id' => $budget->id, 'purchase_order_id' => $order->id, 'recorded_by' => $staff->id,
            'payment_type' => 'PROCUREMENT', 'payment_method' => 'ONLINE', 'amount' => 100, 'details' => 'Payment note', 'paid_at' => now(),
        ]);
        $request->goodsReceipts()->create([
            'receipt_number' => 'GR-REMARKS-1', 'purchase_order_id' => $order->id,
            'received_by' => $staff->id, 'received_at' => now(), 'remarks' => 'Receiving note',
        ]);

        $response = $this->actingAs($staff)->get(route('purchase-requests.show', $request))->assertOk();
        $response->assertSee($unsafe)->assertDontSee("<script>alert('test')</script>", false);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $section = '//*[@aria-labelledby="visible-workflow-remarks-heading"]';
        $this->assertSame(0, $xpath->query($section.'/ancestor::details')->length);
        foreach (['Manager review note', 'Screening evidence note', 'Quotation note', 'PO preparation note', 'PO checker note', 'Budget preparation note', 'Budget line note', 'Payment note', 'Receiving note'] as $text) {
            $this->assertStringContainsString($text, $xpath->query($section)->item(0)->textContent);
        }
        $entries = app(WorkflowRemarks::class)->forRequest($request->id);
        $this->assertSame(1, $entries->where('text', 'Manager review note')->count());
    }

    public function test_list_notes_are_batched_and_request_scoped_and_do_not_show_other_owners_requests(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $other = $this->user(RoleSlug::Requestor);
        $first = $this->purchaseRequest($owner, 1);
        $second = $this->purchaseRequest($owner, 2);
        $hidden = $this->purchaseRequest($other, 3);
        $hidden->update(['remarks' => 'Private remarks from another request']);

        $service = app(WorkflowRemarks::class);
        DB::enableQueryLog();
        $service->prepareRecords(collect([$first, $second]));
        $queries = count(DB::getQueryLog());
        $service->forRequest($first->id);
        $service->forRequest($second->id);
        $this->assertSame($queries, count(DB::getQueryLog()), 'Rendering each note cell must not issue more queries.');
        DB::disableQueryLog();

        // Lists keep rows short: remarks show on the request page, not on the dashboard.
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Request note 1')->assertDontSee('Private remarks from another request');
        $this->get(route('purchase-requests.show', $first))->assertOk()->assertSee('Request note 1');
        $first->update(['remarks' => 'Updated note on next request']);
        $this->get(route('purchase-requests.show', $first))->assertOk()->assertSee('Updated note on next request')->assertDontSee('Request note 1');
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role->value)->value('id')]);
    }

    private function purchaseRequest(User $owner, int $number): PurchaseRequest
    {
        return PurchaseRequest::create([
            'request_number' => 'REMARKS-'.$number, 'title' => 'Visible remarks '.$number,
            'requestor_id' => $owner->id, 'department_campaign' => 'Operations', 'request_type' => 'OTHER',
            'purpose' => 'Remark display verification', 'required_date' => now()->addWeek(),
            'delivery_location' => 'Main Office', 'status' => PurchaseRequestStatus::ForBudgetProcessing,
            'total_amount' => 100, 'remarks' => 'Request note '.$number,
        ]);
    }
}
