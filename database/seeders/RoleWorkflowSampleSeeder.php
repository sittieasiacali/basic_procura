<?php

namespace Database\Seeders;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleWorkflowSampleSeeder extends Seeder
{
    /** Create five idempotent queue samples for each active workflow stage. */
    public function run(): void
    {
        $this->call([RoleSeeder::class, CampaignSeeder::class, SampleUserSeeder::class]);

        $roles = Role::query()->pluck('id', 'slug');
        $users = User::query()->whereIn('email', [
            'requestor@example.com', 'approver@example.com', 'it@example.com', 'premises@example.com',
            'purchasing@example.com', 'president@example.com', 'budget-maker@example.com',
            'budget-checker@example.com', 'budget-proceeder@example.com', 'finance-payment@example.com',
            'inventory@example.com',
        ])->get()->keyBy('email');
        $requestor = $users->get('requestor@example.com');

        if (! $requestor) {
            throw new \RuntimeException('The sample requestor account is required before creating workflow samples.');
        }

        $stages = [
            ['OM', 'Operations Manager approval', PurchaseRequestStatus::ForOmApproval, RoleSlug::OmSmtsApprover, null, 'OTHER'],
            ['TEAM', 'Team classification', PurchaseRequestStatus::ForTeamScreening, null, null, 'UNCLASSIFIED'],
            ['IT', 'IT screening', PurchaseRequestStatus::ForItScreening, RoleSlug::ItStaff, 'it@example.com', 'IT'],
            ['PREMISES', 'Premises screening', PurchaseRequestStatus::ForPremisesScreening, RoleSlug::PremisesStaff, 'premises@example.com', 'PREMISES'],
            ['CANVASS', 'Canvassing', PurchaseRequestStatus::ForCanvassing, RoleSlug::Purchasing, null, 'OTHER'],
            ['SUPPLIER', 'Supplier approval', PurchaseRequestStatus::ForSupplierApproval, RoleSlug::President, null, 'OTHER'],
            ['PO', 'Purchase order preparation', PurchaseRequestStatus::ForBudgetProcessing, RoleSlug::Purchasing, null, 'OTHER'],
            ['MAKER', 'Budget maker review', PurchaseRequestStatus::ForBudgetMaker, RoleSlug::BudgetMaker, null, 'OTHER'],
            ['CHECKER', 'Budget checker review', PurchaseRequestStatus::ForBudgetChecker, RoleSlug::BudgetChecker, null, 'OTHER'],
            ['PROCEEDER', 'Budget proceeder review', PurchaseRequestStatus::ForBudgetProceeder, RoleSlug::BudgetProceeder, null, 'OTHER'],
            ['FINAL', 'Final approval', PurchaseRequestStatus::AwaitingPresidentFinalApproval, RoleSlug::Purchasing, null, 'OTHER'],
            ['PAYMENT', 'Payment processing', PurchaseRequestStatus::ForPayment, RoleSlug::Finance, null, 'OTHER'],
            ['RECEIVING', 'Inventory receiving', PurchaseRequestStatus::ForReceiving, RoleSlug::Inventory, null, 'OTHER'],
        ];

        foreach ($stages as [$code, $label, $status, $role, $assignedEmail, $type]) {
            for ($number = 1; $number <= 5; $number++) {
                $request = PurchaseRequest::query()->updateOrCreate(
                    ['request_number' => sprintf('REQ-UAT-%s-%02d', $code, $number)],
                    [
                        'title' => sprintf('%s test request %d', $label, $number),
                        'requestor_id' => $requestor->id,
                        'team_leader_id' => $requestor->id,
                        'department_campaign' => 'Operations',
                        'request_type' => $type,
                        'priority_level' => $number % 2 ? 'NORMAL' : 'HIGH',
                        'purpose' => sprintf('Idempotent UAT sample %d for the %s workflow queue.', $number, strtolower($label)),
                        'required_date' => now()->addDays($number + 7)->toDateString(),
                        'delivery_location' => 'ASI Main Office',
                        'status' => $status->value,
                        'current_responsible_role_id' => $role ? ($roles[$role->value] ?? null) : null,
                        'assigned_user_id' => $assignedEmail ? $users->get($assignedEmail)?->id : null,
                        'total_amount' => 0,
                        'remarks' => 'UAT workflow sample. Safe to use for local testing.',
                        'submitted_at' => now()->subDays($number),
                    ],
                );

                PurchaseRequestItem::query()->updateOrCreate(
                    ['purchase_request_id' => $request->id, 'description' => sprintf('%s sample item', $label)],
                    [
                        'quantity' => 1,
                        'unit' => 'unit',
                        'estimated_unit_price' => 0,
                        'estimated_total' => 0,
                        'brand_specifications' => 'Local UAT sample item.',
                    ],
                );
            }
        }
    }
}
