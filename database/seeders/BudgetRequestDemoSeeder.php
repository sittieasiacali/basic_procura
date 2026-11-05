<?php

namespace Database\Seeders;

use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BudgetRequestDemoSeeder extends Seeder
{
    use \Database\Seeders\Concerns\RequiresDemoEnvironment;

    public function run(): void
    {
        $this->requireDemoEnvironment();
        $requestor = User::query()->where('email', 'requestor@example.com')->first();
        $purchasing = User::query()->where('email', 'purchasing@example.com')->first();
        $president = User::query()->where('email', 'president@example.com')->first();
        $purchasingRole = Role::query()->where('slug', 'purchasing')->first();

        if (! $requestor || ! $purchasing || ! $president || ! $purchasingRole) {
            throw new RuntimeException('Run RoleSeeder and SampleUserSeeder before BudgetRequestDemoSeeder.');
        }

        $samples = [
            [
                'request_number' => 'REQ-BR-DEMO-001',
                'po_number' => 'PO-BR-DEMO-001',
                'title' => 'Branch office laptop package',
                'item' => 'Business laptop package',
                'supplier' => 'Northstar Technology Supplies',
                'bank_details' => 'BDO | Northstar Technology Supplies | 001-234567-89',
                'priority' => 'URGENT',
                'quantity' => 2,
                'unit_price' => 32500,
            ],
            [
                'request_number' => 'REQ-BR-DEMO-002',
                'po_number' => 'PO-BR-DEMO-002',
                'title' => 'Finance printer and scanner',
                'item' => 'Multifunction printer with duplex scanner',
                'supplier' => 'Metro Office Solutions',
                'bank_details' => 'BPI | Metro Office Solutions | 1098-7654-32',
                'priority' => 'HIGH',
                'quantity' => 1,
                'unit_price' => 28750,
            ],
            [
                'request_number' => 'REQ-BR-DEMO-003',
                'po_number' => 'PO-BR-DEMO-003',
                'title' => 'Operations network accessories',
                'item' => 'Managed network switch and cabling set',
                'supplier' => 'Prime Business Equipment',
                'bank_details' => 'Metrobank | Prime Business Equipment | 567-890123-4',
                'priority' => 'NORMAL',
                'quantity' => 3,
                'unit_price' => 8450,
            ],
        ];

        DB::transaction(function () use ($samples, $requestor, $purchasing, $president, $purchasingRole): void {
            foreach ($samples as $sample) {
                if (PurchaseRequest::withTrashed()->where('request_number', $sample['request_number'])->exists()) {
                    continue;
                }

                $amount = $sample['quantity'] * $sample['unit_price'];
                $supplier = Supplier::query()->firstOrCreate(
                    ['name' => $sample['supplier']],
                    [
                        'contact_person' => 'Demo Supplier Contact',
                        'email' => str($sample['supplier'])->slug().'-demo@example.com',
                        'phone' => '02-8000-'.random_int(1000, 9999),
                        'address' => 'Metro Manila, Philippines',
                        'is_active' => true,
                    ],
                );

                $purchaseRequest = PurchaseRequest::query()->create([
                    'request_number' => $sample['request_number'],
                    'title' => $sample['title'],
                    'requestor_id' => $requestor->id,
                    'team_leader_id' => $requestor->id,
                    'department_campaign' => $requestor->campaign?->name ?? $requestor->department ?? 'Operations',
                    'request_type' => 'IT',
                    'priority_level' => $sample['priority'],
                    'purpose' => 'Demonstration record for consolidated Budget Request preparation.',
                    'required_date' => now()->addDays(30)->toDateString(),
                    'delivery_location' => 'ASI Main Office',
                    'status' => PurchaseRequestStatus::ForBudgetProcessing,
                    'current_responsible_role_id' => $purchasingRole->id,
                    'selected_supplier_id' => $supplier->id,
                    'total_amount' => $amount,
                    'remarks' => 'Safe demonstration PO for the bulk Budget Request workflow.',
                    'submitted_at' => now()->subDays(5),
                ]);

                $requestItem = $purchaseRequest->items()->create([
                    'description' => $sample['item'],
                    'quantity' => $sample['quantity'],
                    'unit' => 'unit',
                    'estimated_unit_price' => $sample['unit_price'],
                    'estimated_total' => $amount,
                    'brand_specifications' => 'Approved demonstration specification',
                ]);

                $purchaseRequest->quotations()->create([
                    'supplier_id' => $supplier->id,
                    'purchase_request_item_id' => $requestItem->id,
                    'created_by' => $purchasing->id,
                    'item_or_service' => $sample['item'],
                    'price' => $amount,
                    'delivery_lead_time' => '10 business days',
                    'warranty' => '1 year supplier warranty',
                    'bank_details' => $sample['bank_details'],
                    'remarks' => 'Approved quotation for Budget Request demonstration.',
                    'is_recommended' => true,
                    'is_approved' => true,
                    'approved_by' => $president->id,
                    'approved_at' => now()->subDays(2),
                    'quoted_at' => now()->subDays(4)->toDateString(),
                ]);

                $purchaseOrder = $purchaseRequest->purchaseOrder()->create([
                    'po_number' => $sample['po_number'],
                    'supplier_id' => $supplier->id,
                    'created_by' => $purchasing->id,
                    'issued_at' => now()->subDay()->toDateString(),
                    'expected_delivery_date' => now()->addDays(15)->toDateString(),
                    'status' => 'ISSUED',
                    'subtotal' => $amount,
                    'tax_amount' => 0,
                    'total_amount' => $amount,
                    'payment_terms' => 'Payment after approved Budget Request',
                    'delivery_terms' => 'Deliver to ASI Main Office',
                    'remarks' => 'Demonstration PO awaiting consolidated Budget Request.',
                ]);

                $purchaseOrder->items()->create([
                    'purchase_request_item_id' => $requestItem->id,
                    'description' => $sample['item'],
                    'quantity' => $sample['quantity'],
                    'unit' => 'unit',
                    'unit_price' => $sample['unit_price'],
                    'total_price' => $amount,
                    'specifications' => 'Approved demonstration specification',
                ]);
            }
        });
    }
}
