<?php

namespace Database\Seeders;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\BudgetRequest;
use App\Models\Campaign;
use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\RequestApproval;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcurementWorkflowDemoSeeder extends Seeder
{
    use \Database\Seeders\Concerns\RequiresDemoEnvironment;

    public function run(): void
    {
        $this->requireDemoEnvironment();
        $this->call([RoleSeeder::class, CampaignSeeder::class, SampleUserSeeder::class]);

        $users = User::query()
            ->whereIn('email', [
                'requestor@example.com', 'teamleader@example.com', 'tl@example.com', 'purchasing@example.com',
                'president@example.com', 'budget-maker@example.com', 'budget-checker@example.com',
                'budget-proceeder@example.com', 'procurement@example.com', 'finance@example.com', 'finance-payment@example.com', 'inventory@example.com', 'po-approver@example.com',
            ])
            ->get()
            ->keyBy('email');

        $roles = Role::query()->pluck('id', 'slug');
        $campaign = Campaign::query()->where('name', 'Operations')->firstOrFail();

        DB::transaction(function () use ($users, $roles, $campaign): void {
            $suppliers = $this->suppliers();

            $this->purchaseOrderReadyRequest($users, $roles, $campaign, $suppliers);
            $this->purchaseOrderQueueRequest($users, $roles, $campaign, $suppliers);
            $this->purchaseOrderApprovalSamples($users, $roles, $campaign, $suppliers);

            $stageRequests = [
                ['REQ-SAMPLE-001', 'Office supply replenishment', PurchaseRequestStatus::ForCanvassing, RoleSlug::Purchasing],
                ['REQ-SAMPLE-002', 'Network workstation accessories', PurchaseRequestStatus::ForSupplierApproval, RoleSlug::President],
                ['REQ-SAMPLE-003', 'Multifunction printer', PurchaseRequestStatus::ForBudgetProcessing, RoleSlug::Purchasing],
                ['REQ-SAMPLE-004', 'Ergonomic workstations', PurchaseRequestStatus::ForBudgetProcessing, RoleSlug::FinanceBudgetStaff],
                ['REQ-SAMPLE-005', 'Printer toner and drums', PurchaseRequestStatus::ForBudgetProcessing, RoleSlug::FinanceBudgetStaff],
                ['REQ-SAMPLE-006', 'Laptop docking stations', PurchaseRequestStatus::ForBudgetMaker, RoleSlug::Purchasing],
                ['REQ-SAMPLE-007', 'Meeting room display', PurchaseRequestStatus::ForBudgetChecker, RoleSlug::Purchasing],
                ['REQ-SAMPLE-008', 'Department laptops', PurchaseRequestStatus::AwaitingPresidentFinalApproval, RoleSlug::Purchasing],
                ['REQ-SAMPLE-009', 'Pantry consumables', PurchaseRequestStatus::ForPayment, RoleSlug::Finance],
                ['REQ-SAMPLE-010', 'Office chairs', PurchaseRequestStatus::ForReceiving, RoleSlug::Inventory],
                ['REQ-SAMPLE-011', 'Completed printer accessories', PurchaseRequestStatus::Fulfilled, RoleSlug::Inventory],
                ['REQ-SAMPLE-012', 'Marketing materials', PurchaseRequestStatus::ForOmApproval, RoleSlug::Om],
                ['REQ-SAMPLE-013', 'Branch signage', PurchaseRequestStatus::ForTeamScreening, null],
                ['REQ-SAMPLE-014', 'IT helpdesk laptops', PurchaseRequestStatus::ForItScreening, RoleSlug::ItStaff],
                ['REQ-SAMPLE-015', 'Premises storage cabinets', PurchaseRequestStatus::ForPremisesScreening, RoleSlug::PremisesStaff],
            ];

            foreach ($stageRequests as [$number, $title, $status, $responsibleRole]) {
                $request = $this->request(
                    $number,
                    $title,
                    $status,
                    $responsibleRole ? ($roles[$responsibleRole->value] ?? null) : null,
                    $users['requestor@example.com']->id,
                    $users['tl@example.com']->id,
                    $campaign->name,
                );

                $item = $this->item($request, $this->itemDescription($title), $this->quantityFor($number), $this->priceFor($number));

                if (in_array($number, ['REQ-SAMPLE-001', 'REQ-SAMPLE-002', 'REQ-SAMPLE-003', 'REQ-SAMPLE-004', 'REQ-SAMPLE-005', 'REQ-SAMPLE-006', 'REQ-SAMPLE-007', 'REQ-SAMPLE-008', 'REQ-SAMPLE-009', 'REQ-SAMPLE-010', 'REQ-SAMPLE-011'], true)) {
                    $this->quotations($request, $item, $suppliers, $users['purchasing@example.com']->id, $number === 'REQ-SAMPLE-001' ? false : true, $users['president@example.com']->id, $number === 'REQ-SAMPLE-002');
                }

                if (in_array($number, ['REQ-SAMPLE-004', 'REQ-SAMPLE-005', 'REQ-SAMPLE-006', 'REQ-SAMPLE-007', 'REQ-SAMPLE-008', 'REQ-SAMPLE-009', 'REQ-SAMPLE-010', 'REQ-SAMPLE-011'], true)) {
                    $po = $this->purchaseOrder($request, $item, $suppliers[0], $users['purchasing@example.com']->id, $number);
                    $budget = $this->budget($request, $po, $users['requestor@example.com']->id, $number);

                    if ($number === 'REQ-SAMPLE-006') {
                        $budget->update([
                            'maker_user_id' => $users['budget-maker@example.com']->id,
                            'maker_approved_at' => now()->subDays(3),
                            'status' => 'MAKER_APPROVED',
                        ]);
                    }
                    if ($number === 'REQ-SAMPLE-007') {
                        $budget->update([
                            'maker_user_id' => $users['budget-maker@example.com']->id,
                            'checker_user_id' => $users['budget-checker@example.com']->id,
                            'maker_approved_at' => now()->subDays(4),
                            'checker_approved_at' => now()->subDays(2),
                            'status' => 'CHECKER_APPROVED',
                        ]);
                    }
                    if (in_array($number, ['REQ-SAMPLE-008', 'REQ-SAMPLE-009', 'REQ-SAMPLE-010', 'REQ-SAMPLE-011'], true)) {
                        $budget->update([
                            'maker_user_id' => $users['budget-maker@example.com']->id,
                            'checker_user_id' => $users['budget-checker@example.com']->id,
                            'proceeder_user_id' => $users['budget-proceeder@example.com']->id,
                            'maker_approved_at' => now()->subDays(6),
                            'checker_approved_at' => now()->subDays(5),
                            'proceeder_approved_at' => now()->subDays(4),
                            'status' => 'PROCEEDER_APPROVED',
                        ]);
                    }
                    if (in_array($number, ['REQ-SAMPLE-009', 'REQ-SAMPLE-010', 'REQ-SAMPLE-011'], true)) {
                        $budget->update([
                            'final_approval_by' => $users['president@example.com']->id,
                            'final_approval_at' => now()->subDays(3),
                            'final_approval_status' => 'APPROVED',
                        ]);
                    }
                    if (in_array($number, ['REQ-SAMPLE-010', 'REQ-SAMPLE-011'], true)) {
                        $this->payment($request, $po, $budget, $users['finance-payment@example.com']->id, $number);
                    }
                    if (in_array($number, ['REQ-SAMPLE-010', 'REQ-SAMPLE-011'], true)) {
                        $receipt = $this->receipt($request, $po, $item, $users['inventory@example.com']->id, $number);
                        if ($number === 'REQ-SAMPLE-011') {
                            $this->inventory($request, $receipt, $users['inventory@example.com']->id, $number);
                        }
                    }
                }

                if ($number === 'REQ-SAMPLE-002') {
                    RequestApproval::query()->firstOrCreate(
                        ['purchase_request_id' => $request->id, 'approval_level' => 'PRESIDENT_SUPPLIER'],
                        [
                            'user_id' => $users['president@example.com']->id,
                            'action' => 'PENDING',
                            'previous_status' => PurchaseRequestStatus::ForCanvassing->value,
                            'new_status' => PurchaseRequestStatus::ForSupplierApproval->value,
                            'remarks' => 'Demo supplier selection is ready for review.',
                            'acted_at' => now()->subDay(),
                        ],
                    );
                }

                if (in_array($number, ['REQ-SAMPLE-003', 'REQ-SAMPLE-004', 'REQ-SAMPLE-005', 'REQ-SAMPLE-006', 'REQ-SAMPLE-007', 'REQ-SAMPLE-008', 'REQ-SAMPLE-009', 'REQ-SAMPLE-010', 'REQ-SAMPLE-011'], true)) {
                    RequestApproval::query()->firstOrCreate(
                        ['purchase_request_id' => $request->id, 'approval_level' => 'PRESIDENT_SUPPLIER'],
                        [
                            'user_id' => $users['president@example.com']->id,
                            'action' => 'APPROVE',
                            'previous_status' => PurchaseRequestStatus::ForSupplierApproval->value,
                            'new_status' => PurchaseRequestStatus::ForBudgetProcessing->value,
                            'remarks' => 'Demo supplier selection approved.',
                            'acted_at' => now()->subDays(7),
                        ],
                    );
                }

                if (in_array($number, ['REQ-SAMPLE-008', 'REQ-SAMPLE-009', 'REQ-SAMPLE-010', 'REQ-SAMPLE-011'], true)) {
                    RequestApproval::query()->firstOrCreate(
                        ['purchase_request_id' => $request->id, 'approval_level' => 'PRESIDENT_FINAL'],
                        [
                            'user_id' => $users['president@example.com']->id,
                            'action' => $number === 'REQ-SAMPLE-008' ? 'PENDING' : 'APPROVE',
                            'previous_status' => PurchaseRequestStatus::ForBudgetProceeder->value,
                            'new_status' => $number === 'REQ-SAMPLE-008'
                                ? PurchaseRequestStatus::AwaitingPresidentFinalApproval->value
                                : PurchaseRequestStatus::ForPayment->value,
                            'remarks' => 'Demo final approval checkpoint.',
                            'acted_at' => now()->subDays(3),
                        ],
                    );
                }

                AuditLog::query()->firstOrCreate(
                    ['auditable_type' => PurchaseRequest::class, 'auditable_id' => $request->id, 'action' => 'DEMO_DATA_SEEDED'],
                    [
                        'user_id' => $users['purchasing@example.com']->id,
                        'previous_status' => null,
                        'new_status' => $status->value,
                        'remarks' => 'Sample procurement workflow record.',
                        'metadata' => ['demo' => true],
                    ],
                );
            }
        });
    }

    /**
     * @param  Collection<string, User>  $users
     * @param  Collection<string, int>  $roles
     * @param  array<int, Supplier>  $suppliers
     */
    private function purchaseOrderReadyRequest($users, $roles, Campaign $campaign, array $suppliers): void
    {
        $requestor = $users['teamleader@example.com'];
        $purchasing = $users['purchasing@example.com'];
        $president = $users['president@example.com'];
        $request = PurchaseRequest::query()->updateOrCreate(
            ['request_number' => 'REQ-SAMPLE-PO-001'],
            [
                'title' => 'Operations workstation accessory package',
                'requestor_id' => $requestor->id,
                'team_leader_id' => $requestor->id,
                'department_campaign' => $campaign->name,
                'request_type' => 'OTHER',
                'purpose' => 'Equip the Operations team with standardized workstation accessories for the next onboarding wave.',
                'required_date' => now()->addDays(21)->toDateString(),
                'delivery_location' => 'ASI Main Office - Operations Stockroom',
                'remarks' => 'Demo request prepared for Purchase Order generation testing.',
                'status' => PurchaseRequestStatus::ForBudgetProcessing->value,
                'current_responsible_role_id' => $roles[RoleSlug::Purchasing->value] ?? null,
                'selected_supplier_id' => $suppliers[0]->id,
                'total_amount' => 19480,
                'submitted_at' => now()->subDays(10),
            ],
        );

        $items = [
            ['Wireless keyboard', 10, 'pcs', 999, 'Full-size wireless keyboard with USB receiver'],
            ['Wireless mouse', 10, 'pcs', 599, 'Ergonomic wireless optical mouse'],
            ['HDMI cable', 10, 'pcs', 350, 'Two-meter HDMI 2.0 cable'],
        ];

        foreach ($items as [$description, $quantity, $unit, $unitPrice, $specifications]) {
            PurchaseRequestItem::query()->updateOrCreate(
                ['purchase_request_id' => $request->id, 'description' => $description],
                [
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'estimated_unit_price' => $unitPrice,
                    'estimated_total' => $quantity * $unitPrice,
                    'brand_specifications' => $specifications,
                ],
            );
        }

        $quotationTotals = [19480, 20750, 21600];
        $leadTimes = ['7 business days', '10 business days', '12 business days'];
        $warranties = ['1 year replacement warranty', '1 year service warranty', '6 months replacement warranty'];

        foreach ($suppliers as $index => $supplier) {
            $slug = (string) str($supplier->name)->slug();
            $path = "purchase-requests/{$request->id}/quotations/{$slug}-quotation.txt";
            $contents = implode(PHP_EOL, [
                "Supplier quotation: {$supplier->name}",
                'Request: REQ-SAMPLE-PO-001',
                'Wireless keyboard - 10 pcs',
                'Wireless mouse - 10 pcs',
                'HDMI cable - 10 pcs',
                'Quoted total: PHP '.number_format($quotationTotals[$index], 2, '.', ','),
                'Lead time: '.$leadTimes[$index],
                'Warranty: '.$warranties[$index],
            ]);
            Storage::disk('local')->put($path, $contents);
            $attachment = $request->attachments()->updateOrCreate(
                ['category' => 'supplier_quotation', 'original_name' => "{$slug}-quotation.txt"],
                [
                    'uploaded_by' => $purchasing->id,
                    'disk' => 'local',
                    'path' => $path,
                    'mime_type' => 'text/plain',
                    'size' => strlen($contents),
                ],
            );

            SupplierQuotation::query()->updateOrCreate(
                ['purchase_request_id' => $request->id, 'supplier_id' => $supplier->id],
                [
                    'purchase_request_item_id' => null,
                    'created_by' => $purchasing->id,
                    'item_or_service' => 'Keyboard, mouse, and HDMI cable package',
                    'price' => $quotationTotals[$index],
                    'brand_quality' => ['Recommended specification', 'Equivalent specification', 'Alternative specification'][$index],
                    'delivery_terms' => 'Delivery to ASI Main Office - Operations Stockroom',
                    'delivery_lead_time' => $leadTimes[$index],
                    'warranty' => $warranties[$index],
                    'quotation_url' => route('attachments.download', $attachment, false),
                    'remarks' => $index === 0 ? 'Best compliant offer recommended by Purchasing.' : 'Alternative supplier quotation for comparison.',
                    'is_recommended' => $index === 0,
                    'is_approved' => $index === 0,
                    'approved_by' => $index === 0 ? $president->id : null,
                    'approved_at' => $index === 0 ? now()->subDays(2) : null,
                    'quoted_at' => now()->subDays(4 - $index)->toDateString(),
                ],
            );
        }

        RequestApproval::query()->updateOrCreate(
            ['purchase_request_id' => $request->id, 'approval_level' => 'PRESIDENT_SUPPLIER'],
            [
                'user_id' => $president->id,
                'action' => 'APPROVE',
                'previous_status' => PurchaseRequestStatus::ForSupplierApproval->value,
                'new_status' => PurchaseRequestStatus::ForBudgetProcessing->value,
                'remarks' => 'President approved Metro Office Solutions for Purchase Order generation.',
                'acted_at' => now()->subDays(2),
            ],
        );

        AuditLog::query()->updateOrCreate(
            ['auditable_type' => PurchaseRequest::class, 'auditable_id' => $request->id, 'action' => 'DEMO_PO_READY'],
            [
                'user_id' => $president->id,
                'previous_status' => PurchaseRequestStatus::ForSupplierApproval->value,
                'new_status' => PurchaseRequestStatus::ForBudgetProcessing->value,
                'remarks' => 'Demo request is legitimately ready for Purchase Order generation after President supplier approval.',
                'metadata' => ['demo' => true, 'stage' => 'purchase-order'],
            ],
        );
    }

    /**
     * Creates a dedicated queue item for testing Purchase Order generation.
     * It deliberately has an approved supplier quotation but no purchase order.
     *
     * @param  Collection<string, User>  $users
     * @param  Collection<string, int>  $roles
     * @param  array<int, Supplier>  $suppliers
     */
    private function purchaseOrderQueueRequest($users, $roles, Campaign $campaign, array $suppliers): void
    {
        $request = PurchaseRequest::query()->firstOrCreate(
            ['request_number' => 'REQ-SAMPLE-PO-QUEUE-001'],
            [
                'title' => 'New-hire workstation setup package',
                'requestor_id' => $users['requestor@example.com']->id,
                'team_leader_id' => $users['tl@example.com']->id,
                'department_campaign' => $campaign->name,
                'request_type' => 'OTHER',
                'purpose' => 'Demo request ready for generating a purchase order from an approved supplier quotation.',
                'required_date' => now()->addDays(14)->toDateString(),
                'delivery_location' => 'ASI Main Office - Operations Stockroom',
                'remarks' => 'Create a purchase order from this sample request to test the next workflow step.',
                'status' => PurchaseRequestStatus::ForBudgetProcessing->value,
                'current_responsible_role_id' => $roles[RoleSlug::Purchasing->value] ?? null,
                'total_amount' => 0,
                'submitted_at' => now()->subDays(6),
            ],
        );

        $item = $this->item($request, 'Ergonomic keyboard and mouse set', 8, 1850);
        $this->quotations(
            $request,
            $item,
            $suppliers,
            $users['purchasing@example.com']->id,
            true,
            $users['president@example.com']->id,
            false,
        );

        RequestApproval::query()->firstOrCreate(
            ['purchase_request_id' => $request->id, 'approval_level' => 'PRESIDENT_SUPPLIER'],
            [
                'user_id' => $users['president@example.com']->id,
                'action' => 'APPROVE',
                'previous_status' => PurchaseRequestStatus::ForSupplierApproval->value,
                'new_status' => PurchaseRequestStatus::ForBudgetProcessing->value,
                'remarks' => 'Sample supplier selection approved and ready for purchase order generation.',
                'acted_at' => now()->subDays(2),
            ],
        );

        AuditLog::query()->firstOrCreate(
            ['auditable_type' => PurchaseRequest::class, 'auditable_id' => $request->id, 'action' => 'DEMO_PO_QUEUE_READY'],
            [
                'user_id' => $users['purchasing@example.com']->id,
                'previous_status' => PurchaseRequestStatus::ForSupplierApproval->value,
                'new_status' => PurchaseRequestStatus::ForBudgetProcessing->value,
                'remarks' => 'Sample request seeded with an approved quotation and no purchase order.',
                'metadata' => ['demo' => true, 'stage' => 'purchase-order'],
            ],
        );
    }

    /** Seed POs at every new approval checkpoint plus one ready for Budget Request. */
    private function purchaseOrderApprovalSamples($users, $roles, Campaign $campaign, array $suppliers): void
    {
        $samples = [
            ['REQ-SAMPLE-PO-CHECK-001', 'PO-SAMPLE-CHECK-001', 'FOR_CHECKING', 'New office monitor package'],
            ['REQ-SAMPLE-PO-ACK-001', 'PO-SAMPLE-ACK-001', 'FOR_ACKNOWLEDGEMENT', 'Branch router replacement'],
            ['REQ-SAMPLE-PO-APP-001', 'PO-SAMPLE-APP-001', 'FOR_APPROVAL', 'Finance filing cabinet'],
            ['REQ-SAMPLE-PO-APPROVED-001', 'PO-SAMPLE-APPROVED-001', 'APPROVED', 'Approved training room projector'],
        ];

        foreach ($samples as [$requestNumber, $poNumber, $status, $title]) {
            $request = PurchaseRequest::query()->updateOrCreate(
                ['request_number' => $requestNumber],
                [
                    'title' => $title,
                    'requestor_id' => $users['requestor@example.com']->id,
                    'team_leader_id' => $users['tl@example.com']->id,
                    'department_campaign' => $campaign->name,
                    'request_type' => 'OTHER',
                    'priority_level' => 'NORMAL',
                    'purpose' => 'Sample record for the Purchase Order approval workflow.',
                    'required_date' => now()->addDays(21)->toDateString(),
                    'delivery_location' => 'ASI Main Office',
                    'status' => PurchaseRequestStatus::ForBudgetProcessing->value,
                    'current_responsible_role_id' => $roles[RoleSlug::Purchasing->value] ?? null,
                    'selected_supplier_id' => $suppliers[0]->id,
                    'total_amount' => 15000,
                    'submitted_at' => now()->subDays(7),
                ],
            );
            $item = $this->item($request, $title, 1, 15000);
            $this->quotations($request, $item, $suppliers, $users['purchasing@example.com']->id, true, $users['president@example.com']->id, false);

            $values = [
                'purchase_request_id' => $request->id,
                'supplier_id' => $suppliers[0]->id,
                'created_by' => $users['purchasing@example.com']->id,
                'issued_at' => now()->subDays(3)->toDateString(),
                'expected_delivery_date' => now()->addDays(14)->toDateString(),
                'status' => $status,
                'subtotal' => 15000,
                'tax_amount' => 0,
                'total_amount' => 15000,
                'remarks' => 'Sample Purchase Order for '.$status.'.',
                'budget_campaign_id' => $campaign->id,
                'budget_campaign_account_number' => $campaign->account_number,
                'budget_transaction_type' => 'ONLINE',
                'budget_fund_source' => 'CAMPAIGN_FUND',
                'budget_source_bank_account' => $campaign->account_number,
                'account_number' => $campaign->account_number,
                'payment_details' => 'Demo payment instruction retained from the Purchase Order.',
                'requested_by' => $users['tl@example.com']->name,
                'checked_by' => in_array($status, ['FOR_ACKNOWLEDGEMENT', 'FOR_APPROVAL', 'APPROVED'], true) ? $users['procurement@example.com']->id : null,
                'checked_at' => in_array($status, ['FOR_ACKNOWLEDGEMENT', 'FOR_APPROVAL', 'APPROVED'], true) ? now()->subDays(2) : null,
                'acknowledged_by' => in_array($status, ['FOR_APPROVAL', 'APPROVED'], true) ? $users['finance@example.com']->id : null,
                'acknowledged_at' => in_array($status, ['FOR_APPROVAL', 'APPROVED'], true) ? now()->subDay() : null,
                'approved_by' => $status === 'APPROVED' ? $users['po-approver@example.com']->id : null,
                'approved_at' => $status === 'APPROVED' ? now()->subHours(8) : null,
            ];
            $po = PurchaseOrder::query()->updateOrCreate(['po_number' => $poNumber], $values);
            PurchaseOrderItem::query()->updateOrCreate(
                ['purchase_order_id' => $po->id, 'purchase_request_item_id' => $item->id],
                ['description' => $title, 'quantity' => 1, 'unit' => 'unit', 'unit_price' => 15000, 'total_price' => 15000, 'specifications' => 'Sample approved specification'],
            );
        }
    }
    /** @return array<int, Supplier> */
    private function suppliers(): array
    {
        return [
            Supplier::query()->firstOrCreate(['name' => 'Metro Office Solutions'], ['contact_person' => 'Ana Reyes', 'email' => 'sales@metro-office.example', 'phone' => '02-8000-1001', 'is_active' => true]),
            Supplier::query()->firstOrCreate(['name' => 'TechSource Philippines'], ['contact_person' => 'Marco Santos', 'email' => 'quotes@techsource.example', 'phone' => '02-8000-1002', 'is_active' => true]),
            Supplier::query()->firstOrCreate(['name' => 'Prime Business Equipment'], ['contact_person' => 'Liza Cruz', 'email' => 'sales@prime-equipment.example', 'phone' => '02-8000-1003', 'is_active' => true]),
        ];
    }

    private function request(string $number, string $title, PurchaseRequestStatus $status, ?int $roleId, int $requestorId, int $teamLeaderId, string $campaign): PurchaseRequest
    {
        return PurchaseRequest::query()->firstOrCreate(
            ['request_number' => $number],
            [
                'title' => $title,
                'requestor_id' => $requestorId,
                'team_leader_id' => $teamLeaderId,
                'department_campaign' => $campaign,
                'request_type' => match ($status) {
                    PurchaseRequestStatus::ForItScreening => 'IT',
                    PurchaseRequestStatus::ForPremisesScreening => 'PREMISES',
                    default => 'OTHER',
                },
                'purpose' => 'Sample data for demonstrating the ASI PROCURA procurement workflow.',
                'required_date' => now()->addDays(30)->toDateString(),
                'delivery_location' => 'ASI Main Office',
                'status' => $status->value,
                'current_responsible_role_id' => $roleId,
                'total_amount' => 0,
                'submitted_at' => now()->subDays(5),
            ],
        );
    }

    private function item(PurchaseRequest $request, string $description, float $quantity, float $price): PurchaseRequestItem
    {
        return PurchaseRequestItem::query()->firstOrCreate(
            ['purchase_request_id' => $request->id, 'description' => $description],
            [
                'quantity' => $quantity,
                'unit' => 'unit',
                'estimated_unit_price' => $price,
                'estimated_total' => $quantity * $price,
                'brand_specifications' => 'Commercial-grade sample item',
            ],
        );
    }

    /** @param array<int, Supplier> $suppliers */
    private function quotations(PurchaseRequest $request, PurchaseRequestItem $item, array $suppliers, int $createdBy, bool $approved, int $approvedBy, bool $pending): void
    {
        foreach ($suppliers as $index => $supplier) {
            SupplierQuotation::query()->firstOrCreate(
                ['purchase_request_id' => $request->id, 'supplier_id' => $supplier->id],
                [
                    'purchase_request_item_id' => $item->id,
                    'created_by' => $createdBy,
                    'item_or_service' => $item->description,
                    'price' => 12500 + ($index * 850),
                    'brand_quality' => ['Standard', 'Business', 'Premium'][$index],
                    'delivery_terms' => 'Delivery to ASI Main Office',
                    'delivery_lead_time' => (string) (7 + $index * 2).' business days',
                    'warranty' => $index === 2 ? '2 years' : '1 year',
                    'quotation_url' => null,
                    'remarks' => 'Sample quotation for workflow demonstration.',
                    'is_recommended' => $index === 0,
                    'is_approved' => $approved && $index === 0 && ! $pending,
                    'approved_by' => $approved && $index === 0 && ! $pending ? $approvedBy : null,
                    'approved_at' => $approved && $index === 0 && ! $pending ? now()->subDays(4) : null,
                    'quoted_at' => now()->subDays(7 - $index),
                ],
            );
        }

        $request->update([
            'total_amount' => $request->items()->sum('estimated_total'),
            'selected_supplier_id' => ($approved || $pending) ? $suppliers[0]->id : null,
        ]);
    }

    private function purchaseOrder(PurchaseRequest $request, PurchaseRequestItem $item, Supplier $supplier, int $createdBy, string $number): PurchaseOrder
    {
        $quotation = $request->quotations()->where('supplier_id', $supplier->id)->firstOrFail();
        $po = PurchaseOrder::query()->firstOrCreate(
            ['po_number' => 'PO-SAMPLE-'.substr($number, -3)],
            [
                'purchase_request_id' => $request->id,
                'supplier_id' => $supplier->id,
                'created_by' => $createdBy,
                'issued_at' => now()->subDays(3)->toDateString(),
                'expected_delivery_date' => now()->addDays(10)->toDateString(),
                'status' => 'APPROVED',
                'subtotal' => $quotation->price,
                'tax_amount' => 0,
                'total_amount' => $quotation->price,
                'payment_terms' => 'Net 30 days',
                'delivery_terms' => $quotation->delivery_terms,
                'remarks' => 'Sample purchase order linked to approved quotation.',
            ],
        );
        PurchaseOrderItem::query()->firstOrCreate(
            ['purchase_order_id' => $po->id, 'purchase_request_item_id' => $item->id],
            ['description' => $item->description, 'quantity' => $item->quantity, 'unit' => $item->unit, 'unit_price' => $quotation->price, 'total_price' => $quotation->price, 'specifications' => $item->brand_specifications],
        );

        return $po;
    }

    private function budget(PurchaseRequest $request, PurchaseOrder $po, int $requestedBy, string $number): BudgetRequest
    {
        $budget = BudgetRequest::query()->firstOrCreate(
            ['budget_request_number' => 'BR-SAMPLE-'.substr($number, -3)],
            ['purchase_request_id' => $request->id, 'purchase_order_id' => $po->id, 'requested_by' => $requestedBy, 'payment_method' => 'ONLINE', 'amount' => $po->total_amount, 'status' => 'PENDING', 'requested_at' => now()->subDays(4)->toDateString(), 'remarks' => 'Sample budget request for demonstration.'],
        );

        $budget->lines()->firstOrCreate(
            ['details' => $request->title],
            ['purchase_order_id' => $po->id, 'requested_by_user_id' => $requestedBy, 'amount' => $budget->amount, 'bank_details' => 'ASI source bank account'],
        );
        $budget->allocations()->firstOrCreate(
            ['purchase_order_id' => $po->id],
            ['purchase_request_id' => $request->id, 'amount' => $budget->amount],
        );

        return $budget;
    }

    private function payment(PurchaseRequest $request, PurchaseOrder $po, BudgetRequest $budget, int $recordedBy, string $number): void
    {
        Payment::query()->firstOrCreate(
            ['purchase_request_id' => $request->id],
            ['budget_request_id' => $budget->id, 'purchase_order_id' => $po->id, 'recorded_by' => $recordedBy, 'payment_type' => 'PROCUREMENT', 'payment_method' => 'ONLINE', 'payment_channel' => 'ONLINE', 'reference_number' => 'DEMO-PAY-'.substr($number, -3), 'amount' => $po->total_amount, 'paid_at' => now()->subDays(2)->toDateString(), 'status' => 'PAID', 'payment_status' => 'PAID', 'invoice_number' => 'DEMO-INV-'.substr($number, -3), 'details' => 'Sample payment record.'],
        );
    }

    private function receipt(PurchaseRequest $request, PurchaseOrder $po, PurchaseRequestItem $item, int $receivedBy, string $number): GoodsReceipt
    {
        $receipt = GoodsReceipt::query()->firstOrCreate(
            ['receipt_number' => 'GR-SAMPLE-'.substr($number, -3)],
            ['purchase_request_id' => $request->id, 'purchase_order_id' => $po->id, 'received_by' => $receivedBy, 'received_at' => now()->subDay(), 'status' => 'RECEIVED', 'delivery_note_number' => 'DN-SAMPLE-'.substr($number, -3), 'remarks' => 'Sample receiving record.'],
        );
        $receiptItem = $receipt->items()->firstOrCreate(
            ['description' => $item->description],
            ['quantity_received' => $item->quantity, 'unit' => $item->unit, 'condition' => 'GOOD', 'remarks' => 'Received in good condition.'],
        );
        $receipt->setRelation('demoItem', $receiptItem);

        return $receipt;
    }

    private function inventory(PurchaseRequest $request, GoodsReceipt $receipt, int $loggedBy, string $number): void
    {
        $item = $receipt->items()->where('description', $request->items()->value('description'))->firstOrFail();
        InventoryItem::query()->firstOrCreate(
            ['inventory_code' => 'INV-SAMPLE-'.substr($number, -3)],
            ['asset_tag' => 'ASI-DEMO-'.substr($number, -3), 'goods_receipt_item_id' => $item->id, 'purchase_request_id' => $request->id, 'logged_by' => $loggedBy, 'description' => $item->description, 'quantity' => $item->quantity_received, 'unit' => $item->unit, 'location' => 'ASI Main Inventory', 'sticker_details' => 'Demo inventory item', 'status' => 'LOGGED', 'logged_at' => now()->subDay()],
        );
    }

    private function itemDescription(string $title): string
    {
        return match (true) {
            str_contains(strtolower($title), 'printer') => 'Business printer and consumables',
            str_contains(strtolower($title), 'laptop') => 'Business laptop package',
            str_contains(strtolower($title), 'pantry') => 'Pantry consumables',
            str_contains(strtolower($title), 'chair') => 'Ergonomic office chair',
            default => $title,
        };
    }

    private function quantityFor(string $number): float
    {
        return (float) (2 + ((int) substr($number, -3) % 5));
    }

    private function priceFor(string $number): float
    {
        return (float) (2500 + ((int) substr($number, -3) * 175));
    }
}
