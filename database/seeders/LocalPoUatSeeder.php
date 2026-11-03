<?php

namespace Database\Seeders;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\PurchaseRequestStatus;
use App\Models\Campaign;
use App\Models\PoRoleAssignment;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocalPoUatSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(config('app.env') === 'local'
            && config('database.default') === 'mysql'
            && config('database.connections.mysql.host') === '127.0.0.1'
            && (string) config('database.connections.mysql.port') === '3306'
            && config('database.connections.mysql.database') === 'basic_procura', 403);

        $assignees = PoRoleAssignment::query()->pluck('user_id', 'stage');
        foreach (PurchaseOrderApprovalStage::cases() as $stage) {
            throw_unless($assignees->has($stage->value), \RuntimeException::class, 'All four PO workflow assignments are required before creating UAT samples.');
        }

        $requestor = User::query()->where('is_active', true)->where('email', 'requestor@example.com')->first()
            ?? User::query()->where('is_active', true)->firstOrFail();
        $teamLeader = User::query()->where('is_active', true)->where('email', 'tl@example.com')->first() ?? $requestor;
        $campaign = Campaign::query()->where('is_active', true)->firstOrFail();
        $supplier = Supplier::query()->where('is_active', true)->first()
            ?? Supplier::query()->create(['name' => 'PO UAT Supplier', 'is_active' => true]);

        DB::transaction(function () use ($assignees, $requestor, $teamLeader, $campaign, $supplier): void {
            foreach ([
                // Fresh preparation samples stay without a PO so the Prepared By
                // account receives the Create Purchase Order action.
                'CREATE' => null,
                'PREPARED' => null,
                'CHECKING' => 'FOR_CHECKING',
                'NOTATION' => 'FOR_ACKNOWLEDGEMENT',
                'APPROVAL' => 'FOR_APPROVAL',
            ] as $flow => $poStatus) {
                for ($index = 1; $index <= 5; $index++) {
                    $number = sprintf('UAT-PO-%s-%02d', $flow, $index);
                    $existing = PurchaseRequest::query()
                        ->withTrashed()
                        ->with('purchaseOrder.approvals')
                        ->where('request_number', $number)
                        ->first();

                    if ($existing) {
                        // Older UAT rows did not include the signed fields now required
                        // by the PO action queue. Repair them in place, without duplicating samples.
                        $existing->purchaseOrder?->approvals()
                            ->where('status', 'APPROVED')
                            ->update([
                                'signature_name' => 'Local UAT Signer',
                                'signature_data' => 'data:image/png;base64,'.base64_encode(str_repeat('local-po-uat-signature', 12)),
                                'signed_at' => now(),
                                'acted_at' => now(),
                            ]);

                        continue;
                    }

                    $amount = 1000 + ($index * 125);
                    $request = PurchaseRequest::query()->create([
                        'request_number' => $number,
                        'title' => "Local PO UAT {$flow} sample {$index}",
                        'requestor_id' => $requestor->id,
                        'team_leader_id' => $teamLeader->id,
                        'department_campaign' => $campaign->name,
                        'request_type' => 'OTHER',
                        'priority_level' => 'NORMAL',
                        'purpose' => "Local-only PO {$flow} workflow verification.",
                        'required_date' => now()->addDays($index)->toDateString(),
                        'delivery_location' => 'Local UAT Office',
                        'status' => PurchaseRequestStatus::ForBudgetProcessing,
                        'selected_supplier_id' => $supplier->id,
                        'total_amount' => $amount,
                        'submitted_at' => now(),
                    ]);
                    $item = $request->items()->create([
                        'description' => "PO UAT {$flow} item {$index}",
                        'quantity' => 1,
                        'unit' => 'piece',
                        'estimated_unit_price' => $amount,
                        'estimated_total' => $amount,
                    ]);
                    $request->quotations()->create([
                        'supplier_id' => $supplier->id,
                        'purchase_request_item_id' => $item->id,
                        'created_by' => $assignees[PurchaseOrderApprovalStage::PreparedBy->value],
                        'item_or_service' => $item->description,
                        'price' => $amount,
                        'delivery_terms' => '30 days',
                        'bank_details' => 'Local UAT supplier payment reference',
                        'quoted_at' => now()->toDateString(),
                        'is_recommended' => true,
                        'is_approved' => true,
                        'approved_by' => $assignees[PurchaseOrderApprovalStage::ApprovedBy->value],
                        'approved_at' => now(),
                    ]);

                    if ($poStatus === null) {
                        continue;
                    }

                    $order = $request->purchaseOrder()->create([
                        'po_number' => sprintf('UATPO-%s-%02d', substr($flow, 0, 3), $index),
                        'supplier_id' => $supplier->id,
                        'created_by' => $assignees[PurchaseOrderApprovalStage::PreparedBy->value],
                        'issued_at' => now()->toDateString(),
                        'expected_delivery_date' => now()->addDays($index + 7)->toDateString(),
                        'status' => $poStatus,
                        'subtotal' => $amount,
                        'tax_amount' => 0,
                        'total_amount' => $amount,
                        'budget_campaign_id' => $campaign->id,
                        'budget_campaign_account_number' => $campaign->account_number,
                        'account_number' => sprintf('UAT-%s-%02d', substr($flow, 0, 3), $index),
                        'account_name' => $supplier->name,
                        'payment_terms' => '30 days',
                        'requested_by' => $teamLeader->name,
                    ]);
                    $order->items()->create([
                        'purchase_request_item_id' => $item->id,
                        'description' => $item->description,
                        'quantity' => 1,
                        'unit' => 'piece',
                        'unit_price' => $amount,
                        'total_price' => $amount,
                    ]);

                    foreach (PurchaseOrderApprovalStage::cases() as $stage) {
                        $completed = match ($poStatus) {
                            'FOR_CHECKING' => $stage === PurchaseOrderApprovalStage::PreparedBy,
                            'FOR_ACKNOWLEDGEMENT' => in_array($stage, [PurchaseOrderApprovalStage::PreparedBy, PurchaseOrderApprovalStage::CheckedBy], true),
                            'FOR_APPROVAL' => $stage !== PurchaseOrderApprovalStage::ApprovedBy,
                        };
                        $order->approvals()->create([
                            'stage' => $stage,
                            'assigned_user_id' => $assignees[$stage->value],
                            'acted_by' => $completed ? $assignees[$stage->value] : null,
                            'status' => $completed ? 'APPROVED' : 'PENDING',
                            'decision' => $completed ? 'APPROVED' : null,
                            'remarks' => $completed ? 'Local UAT workflow sample.' : null,
                            'signature_name' => $completed ? 'Local UAT Signer' : null,
                            'signature_data' => $completed ? 'data:image/png;base64,'.base64_encode(str_repeat('local-po-uat-signature', 12)) : null,
                            'signed_at' => $completed ? now() : null,
                            'acted_at' => $completed ? now() : null,
                        ]);
                    }
                }
            }
        });
    }
}
