<?php

namespace App\Enums;

enum RoleSlug: string
{
    case Requestor = 'requestor';
    case TeamLeader = 'team-leader';
    case Tl = 'tl';
    case CanvassingStaff = 'canvassing-staff';
    case Om = 'om';
    case OmSmtsApprover = 'om-smts-approver';
    case ItStaff = 'it-staff';
    case PremisesStaff = 'premises-staff';
    case ProcurementStaff = 'procurement-staff';
    case Purchasing = 'purchasing';
    case PurchasingTeam = 'purchasing-team';
    case FinanceBudgetStaff = 'finance-budget-staff';
    case Finance = 'finance';
    case InventoryStaff = 'inventory-staff';
    case Inventory = 'inventory';
    case Smt = 'smt';
    case President = 'president';
    case BudgetMaker = 'budget-maker';
    case BudgetChecker = 'budget-checker';
    case BudgetProceeder = 'budget-proceeder';
    case PoWorkflow = 'po-workflow';
    case PoChecker = 'po-checker';
    case PoNoted = 'po-noted';
    case PoApprover = 'po-approver';
    case Admin = 'admin';
}
