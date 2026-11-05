<?php

namespace Database\Seeders;

use App\Enums\RoleSlug;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SampleUserSeeder extends Seeder
{
    use \Database\Seeders\Concerns\RequiresDemoEnvironment;

    public function run(): void
    {
        $this->requireDemoEnvironment();
        $password = Hash::make((string) env('DEMO_USER_PASSWORD', 'Procura123!'));

        $users = [
            RoleSlug::Requestor->value => ['Sample Requestor', 'requestor@example.com', 'Operations', 'Operations'],
            RoleSlug::TeamLeader->value => ['Sample Team Leader', 'teamleader@example.com', 'Operations', 'Operations'],
            RoleSlug::Tl->value => ['Sample TL', 'tl@example.com', 'Operations', 'Operations'],
            RoleSlug::CanvassingStaff->value => ['Sample Canvassing Staff', 'canvassing@example.com', 'Procurement', null],
            RoleSlug::Om->value => ['Sample OM', 'om@example.com', 'Management', null],
            RoleSlug::OmSmtsApprover->value => ['Sample Operations Manager', 'approver@example.com', 'Management', null],
            RoleSlug::ItStaff->value => ['Sample IT Screening Staff', 'it@example.com', 'IT', null],
            RoleSlug::PremisesStaff->value => ['Sample Premises Screening Staff', 'premises@example.com', 'Premises', null],
            RoleSlug::ProcurementStaff->value => ['Sample Procurement Staff', 'procurement@example.com', 'Procurement', null],
            RoleSlug::Purchasing->value => ['Sample Purchasing Officer', 'purchasing@example.com', 'Procurement', null],
            RoleSlug::FinanceBudgetStaff->value => ['Sample Finance Staff', 'finance@example.com', 'Finance', null],
            RoleSlug::Finance->value => ['Sample Payment Finance', 'finance-payment@example.com', 'Finance', null],
            RoleSlug::InventoryStaff->value => ['Sample Inventory Staff', 'inventory@example.com', 'Inventory', null],
            RoleSlug::Inventory->value => ['Sample Inventory Clerk', 'inventory-clerk@example.com', 'Inventory', null],
            RoleSlug::Smt->value => ['Sample SMT Approver', 'smt@example.com', 'Management', null],
            RoleSlug::President->value => ['President', 'president@example.com', 'Executive', null],
            RoleSlug::BudgetMaker->value => ['Budget Maker', 'budget-maker@example.com', 'Finance', null],
            RoleSlug::BudgetChecker->value => ['Budget Checker', 'budget-checker@example.com', 'Finance', null],
            RoleSlug::BudgetProceeder->value => ['Budget Proceeder', 'budget-proceeder@example.com', 'Finance', null],
            RoleSlug::Admin->value => ['System Administrator', 'admin@example.com', 'Administration', null],
        ];

        foreach ($users as $roleSlug => [$name, $email, $department, $campaignName]) {
            $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
            $campaignId = $campaignName
                ? Campaign::query()->where('name', $campaignName)->value('id')
                : null;

            $user = User::query()->firstOrNew(['email' => $email]);

            if (! $user->exists) {
                $user->fill([
                    'role_id' => $role->id,
                    'name' => $name,
                    'department' => $department,
                    'email_verified_at' => now(),
                    'is_active' => true,
                    'password' => $password,
                ]);
            }

            if ($campaignId && ! $user->campaign_id) {
                $user->campaign_id = $campaignId;
            }

            $user->save();
        }

        // The final PO approver must not be the account that created the PO.
        // It intentionally shares the display name used on the official form.
        $approverRole = Role::query()->where('slug', RoleSlug::Purchasing->value)->firstOrFail();
        $approver = User::query()->firstOrNew(['email' => 'po-approver@example.com']);

        if (! $approver->exists) {
            $approver->fill([
                'role_id' => $approverRole->id,
                'name' => 'Sample Purchasing Officer',
                'department' => 'Procurement',
                'email_verified_at' => now(),
                'is_active' => true,
                'password' => $password,
            ]);
        }

        $approver->save();
    }
}
