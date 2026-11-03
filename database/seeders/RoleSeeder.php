<?php

namespace Database\Seeders;

use App\Enums\RoleSlug;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            RoleSlug::Requestor->value => ['Requestor', 'Creates and tracks purchase requests.'],
            RoleSlug::TeamLeader->value => ['Team Leader / Requestor', 'Creates and tracks purchase requests for an assigned campaign.'],
            RoleSlug::Tl->value => ['TL', 'Team Leader approval and request ownership for the current campaign.'],
            RoleSlug::CanvassingStaff->value => ['Canvassing Staff', 'Records and compares supplier quotations.'],
            RoleSlug::Om->value => ['OM', 'Operational review and approval gate.'],
            RoleSlug::OmSmtsApprover->value => ['Operations Manager', 'Reviews operational requests and routes approved work.'],
            RoleSlug::ItStaff->value => ['IT Screening', 'Screens IT requests and checks IT inventory.'],
            RoleSlug::PremisesStaff->value => ['Premises Screening', 'Screens premises requests and checks premises inventory.'],
            RoleSlug::ProcurementStaff->value => ['Procurement Staff', 'Creates purchase orders and monitors delivery.'],
            RoleSlug::Purchasing->value => ['Purchasing', 'Canvassing, supplier quotations, and final Budget Request approval.'],
            RoleSlug::PurchasingTeam->value => ['Purchasing Team', 'Creates canvassing recommendations, Purchase Orders, and Budget Requests without payment or final approval access.'],
            RoleSlug::FinanceBudgetStaff->value => ['Finance', 'Processes canvassing, budgets, payments, and financial records.'],
            RoleSlug::Finance->value => ['Finance', 'Approves and records payments.'],
            RoleSlug::InventoryStaff->value => ['Inventory Staff', 'Receives, logs, labels, and endorses goods.'],
            RoleSlug::Inventory->value => ['Inventory', 'Inventory receiving and stock update role.'],
            RoleSlug::Smt->value => ['SMT', 'Reviews requests escalated by Operations Management.'],
            RoleSlug::President->value => ['President', 'Reviews and approves supplier selections.'],
            RoleSlug::BudgetMaker->value => ['Budget Maker', 'Prepares and reviews budget requirements.'],
            RoleSlug::BudgetChecker->value => ['Budget Checker', 'Verifies and validates the budget request.'],
            RoleSlug::BudgetProceeder->value => ['Budget Proceeder', 'Finalizes the budget requisition for approval.'],
            RoleSlug::PoWorkflow->value => ['PO Workflow', 'Dedicated Purchase Order workflow account with no Purchase Request role access.'],
            RoleSlug::PoChecker->value => ['PO CHECKER', 'Checks assigned Purchase Orders.'],
            RoleSlug::PoNoted->value => ['PO NOTED', 'Notes assigned Purchase Orders.'],
            RoleSlug::PoApprover->value => ['PO APPROVER', 'Approves assigned Purchase Orders.'],
            RoleSlug::Admin->value => ['Admin', 'Manages users, roles, settings, and audit access.'],
        ];

        foreach ($roles as $slug => [$name, $description]) {
            Role::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $description],
            );
        }
    }
}
