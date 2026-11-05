<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\RoleSlug;
use App\Models\BudgetRequest;
use App\Models\Campaign;
use App\Models\Payment;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\PurchaseOrder;
use App\Models\PoRoleAssignment;
use App\Models\Supplier;
use App\Models\User;
use App\Support\RequestStageReceipt;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private int $requestSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_dashboard_renders_for_every_existing_role_without_undefined_data(): void
    {
        $workflowRoles = [
            RoleSlug::Requestor, RoleSlug::TeamLeader, RoleSlug::Tl, RoleSlug::OmSmtsApprover, RoleSlug::Om,
            RoleSlug::ItStaff, RoleSlug::PremisesStaff, RoleSlug::President, RoleSlug::Purchasing, RoleSlug::ProcurementStaff,
        ];
        foreach (RoleSlug::cases() as $role) {
            $response = $this->actingAs($this->user($role))
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('Dashboard')
                ->assertSee('Status');
            if (in_array($role, $workflowRoles, true)) {
                // Like the PO signers: only "for my action" and "My processed requests".
                $response->assertSee('My processed requests')->assertDontSee('Recent requests')->assertDontSee('Quick links');
            } elseif ($role === RoleSlug::Finance) {
                $response->assertSee('Processed payment')->assertDontSee('Recent requests');
            } elseif ($role !== RoleSlug::PoWorkflow) {
                $response->assertSee('Recent requests');
            }
        }
    }

    public function test_it_and_premises_navigation_is_dashboard_only_but_control_center_stays_reachable(): void
    {
        foreach ([RoleSlug::ItStaff, RoleSlug::PremisesStaff] as $role) {
            $user = $this->user($role);
            $html = $this->actingAs($user)->get(route('dashboard'))
                ->assertOk()
                ->assertSee('dashboard-control-action', false)
                ->getContent();
            preg_match_all('/<a class="nav-link[^"]*" href="([^"]+)"/', $html, $navLinks);
            $this->assertSame([route('dashboard')], $navLinks[1], $role->value.' should only have the Dashboard nav link');

            // The Control Center page, with its workflow-stage filters, is still available.
            $this->get(route('request-flow.index'))
                ->assertOk()
                ->assertSee('Workflow stage');
        }
    }

    public function test_it_and_premises_share_claim_queue_then_open_screening_before_quotation(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $it = $this->user(RoleSlug::ItStaff);
        $premises = $this->user(RoleSlug::PremisesStaff);

        foreach ([
            [$it, PurchaseRequestStatus::ForItScreening, 'Claim as IT'],
            [$premises, PurchaseRequestStatus::ForPremisesScreening, 'Claim as Premises'],
        ] as [$staff, $screeningStatus, $claimLabel]) {
            $request = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForTeamScreening, 'Screen before quotation');
            $request->items()->create(['description' => 'Claimed team equipment', 'quantity' => 2, 'unit' => 'pc']);
            $quotationRequest = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForProcurement, 'Already awaiting quotation');
            $quotationRequest->forceFill(['assigned_user_id' => $staff->id])->save();

            foreach ([$it, $premises] as $viewer) {
                $this->actingAs($viewer)
                    ->get(route('dashboard'))
                    ->assertOk()
                    ->assertViewHas('myActions', fn ($actions) => $actions->contains(fn ($action) => $action['request']->is($request)
                        && $action['label'] === 'Claim for screening')
                        && ! $actions->contains(fn ($action) => $action['request']->is($quotationRequest)));
            }

            $this->actingAs($staff)
                ->get(route('purchase-requests.show', $request))
                ->assertOk()
                ->assertSee($claimLabel)
                ->assertDontSee('id="screening-actions"', false);

            $this->post(route('request-flow.claim', $request))
                ->assertRedirect(route('purchase-requests.show', $request))
                ->assertSessionHasNoErrors();
            $this->assertSame($screeningStatus, $request->fresh()->status);

            $this->get(route('dashboard'))
                ->assertOk()
                ->assertSee('Requested items: Claimed team equipment (2 pc)')
                ->assertViewHas('myActions', fn ($actions) => $actions->contains(fn ($action) => $action['request']->is($request) && $action['label'] === 'Screen request'))
                ->assertViewHas('processedRequests', fn ($requests) => ! $requests->contains(fn ($processed) => $processed->is($request)));

            $otherTeam = $staff->is($it) ? $premises : $it;
            $this->actingAs($otherTeam)->get(route('dashboard'))->assertOk()
                ->assertViewHas('myActions', fn ($actions) => ! $actions->contains(fn ($action) => $action['request']->is($request)));
            $this->get(route('purchase-requests.show', $request))->assertForbidden();
            $this->actingAs($staff);

            $this->get(route('purchase-requests.show', $request))
                ->assertOk()
                ->assertSee('id="screening-actions"', false)
                ->assertDontSee('id="canvassing-actions"', false);
        }
    }

    public function test_screener_work_closes_after_inventory_release_or_canvassing_submission(): void
    {
        foreach ([RoleSlug::ItStaff, RoleSlug::PremisesStaff] as $role) {
            $staff = $this->user($role);
            $requestor = $this->user(RoleSlug::Requestor);
            $quotation = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForProcurement, 'Quotation still being prepared');
            $quotation->update(['assigned_user_id' => $staff->id, 'request_type' => $role === RoleSlug::ItStaff ? 'IT' : 'PREMISES']);
            $quotation->screening()->create([
                'screened_by' => $staff->id, 'team' => $quotation->request_type,
                'decision' => 'UNAVAILABLE', 'decided_at' => now(),
            ]);
            $quotation->auditLogs()->create(['user_id' => $staff->id, 'action' => 'INVENTORY_UNAVAILABLE']);
            $available = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForInventoryRelease, 'Waiting for Inventory');
            $available->update(['assigned_user_id' => $staff->id]);
            $available->auditLogs()->create(['user_id' => $staff->id, 'action' => 'INVENTORY_AVAILABLE']);
            $other = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForItScreening, 'Assigned to another staff');
            $other->update(['assigned_user_id' => $this->user($role)->id]);

            $this->actingAs($staff)->get(route('dashboard'))->assertOk()
                ->assertViewHas('myActions', fn ($actions) => $actions->count() === 1
                    && $actions->contains(fn ($action) => $action['request']->is($quotation) && $action['label'] === 'Add quotation'))
                ->assertViewHas('processedRequests', fn ($requests) => $requests->isEmpty())
                ->assertViewHas('processedTabCounts', fn ($counts) => $counts['mine'] === 0)
                ->assertViewHas('kpis', fn ($kpis) => collect($kpis)->firstWhere('label', 'Processed by me')['value'] === 0);

            $quotation->update(['status' => PurchaseRequestStatus::ForCanvassing]);
            $quotation->auditLogs()->create(['user_id' => $staff->id, 'action' => 'SCREENING_QUOTATIONS_RECORDED']);
            $this->get(route('dashboard'))->assertOk()
                ->assertViewHas('myActions', fn ($actions) => $actions->isEmpty())
                ->assertViewHas('processedRequests', fn ($requests) => $requests->modelKeys() === [$quotation->id]);

            $available->update(['status' => PurchaseRequestStatus::Fulfilled]);
            $available->auditLogs()->create(['user_id' => $this->user(RoleSlug::InventoryStaff)->id, 'action' => 'INVENTORY_RELEASED']);
            $this->get(route('dashboard'))->assertOk()
                ->assertViewHas('myActions', fn ($actions) => $actions->isEmpty())
                ->assertViewHas('processedRequests', fn ($requests) => $requests->count() === 2 && $requests->contains(fn ($request) => $request->is($available)))
                ->assertViewHas('processedTabCounts', fn ($counts) => $counts['mine'] === 2)
                ->assertViewHas('kpis', fn ($kpis) => collect($kpis)->firstWhere('label', 'Processed by me')['value'] === 2);
        }
    }

    public function test_requestor_dashboard_uses_only_owned_requests_and_owned_actions(): void
    {
        $campaign = Campaign::query()->create(['name' => 'Operations', 'is_active' => true]);
        $requestor = $this->user(RoleSlug::Requestor, $campaign);
        $otherRequestor = $this->user(RoleSlug::Requestor, $campaign);
        $ownDraft = $this->purchaseRequest($requestor, PurchaseRequestStatus::Draft, 'Owned Draft');
        $ownApproved = $this->purchaseRequest($requestor, PurchaseRequestStatus::Approved, 'Owned Approved');
        $privateDraft = $this->purchaseRequest($otherRequestor, PurchaseRequestStatus::Draft, 'Private Draft');
        $ownApproved->auditLogs()->create(['user_id' => $requestor->id, 'action' => 'SUBMITTED_FOR_OM_APPROVAL', 'previous_status' => 'DRAFT', 'new_status' => 'FOR_OM_APPROVAL']);
        $privateDraft->auditLogs()->create(['user_id' => $otherRequestor->id, 'action' => 'SUBMITTED_FOR_OM_APPROVAL', 'previous_status' => 'DRAFT', 'new_status' => 'FOR_OM_APPROVAL']);

        $response = $this->actingAs($requestor)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Good ')
            ->assertSee($requestor->name)
            ->assertSee('dashboard-page', false)
            ->assertSee('dashboard-control-action', false)
            ->assertSee('dashboard-create-action', false)
            ->assertSee('href="'.route('purchase-requests.create').'"', false)
            ->assertSee('href="'.route('request-flow.index').'"', false)
            ->assertSee('Operations')
            ->assertSee($ownDraft->request_number)
            ->assertSee('My processed requests')
            ->assertSee($ownApproved->request_number)
            ->assertSee('Submitted to OM')
            ->assertDontSee($privateDraft->request_number)
            ->assertSee('Complete draft')
            ->assertDontSee('System request activity')
            ->assertViewHas('myActions', function ($actions) use ($ownDraft) {
                return $actions->count() === 1
                    && $actions->first()['request']->is($ownDraft);
            });
    }

    public function test_workflow_dashboards_only_queue_statuses_executable_by_each_role(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $forOm = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForOmApproval, 'OM Queue');
        $forSupplier = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForSupplierApproval, 'Supplier Queue');
        $forFinal = $this->purchaseRequest($requestor, PurchaseRequestStatus::AwaitingPresidentFinalApproval, 'Final Queue');

        $this->actingAs($this->user(RoleSlug::OmSmtsApprover))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->pluck('request.id')->all() === [$forOm->id]);

        $this->actingAs($this->user(RoleSlug::President))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->pluck('request.id')->all() === [$forSupplier->id]);

        $this->actingAs($this->user(RoleSlug::Purchasing))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->contains(fn ($action) => $action['request']->is($forFinal)));

        $this->actingAs($this->user(RoleSlug::Om))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->isEmpty());
    }

    public function test_purchase_order_approvers_only_see_their_assigned_po_step(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $purchasing = $this->user(RoleSlug::PoWorkflow);
        $teamLead = $this->user(RoleSlug::PoWorkflow);
        $operationsManager = $this->user(RoleSlug::PoWorkflow);
        $smt = $this->user(RoleSlug::PoWorkflow);
        foreach ([
            [PurchaseOrderApprovalStage::PreparedBy, $purchasing],
            [PurchaseOrderApprovalStage::CheckedBy, $teamLead],
            [PurchaseOrderApprovalStage::NotedBy, $operationsManager],
            [PurchaseOrderApprovalStage::ApprovedBy, $smt],
        ] as [$stage, $user]) {
            PoRoleAssignment::query()->create(['stage' => $stage, 'user_id' => $user->id]);
        }
        $supplier = Supplier::query()->create(['name' => 'PO Approval Queue Supplier', 'is_active' => true]);
        $forChecking = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForBudgetProcessing, 'PO awaiting TL checking');
        $forNotation = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForBudgetProcessing, 'PO awaiting OM notation');
        $forApproval = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForBudgetProcessing, 'PO awaiting SMT approval');
        $unrelated = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForPayment, 'Unrelated payment task');
        $forPreparation = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForBudgetProcessing, 'PO awaiting preparation');
        $forPreparation->forceFill(['selected_supplier_id' => $supplier->id])->save();
        $forPreparation->quotations()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $purchasing->id,
            'item_or_service' => $forPreparation->title,
            'price' => 100,
            'is_approved' => true,
            'approved_at' => now(),
        ]);
        $this->actingAs($purchasing)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Create purchase order')
            ->assertSee($forPreparation->request_number)
            ->assertDontSee($unrelated->request_number)
            ->assertViewHas('myActions', fn ($actions) => $actions->count() === 1
                && $actions->contains(fn ($action) => $action['request']->is($forPreparation)
                    && $action['label'] === 'Create purchase order'));

        $this->get(route('purchase-requests.create'))->assertForbidden();
        $this->get(route('request-flow.index', ['stage' => 'payment']))->assertForbidden();
        $this->post(route('payments.store', $unrelated), ['payment_method' => 'ONLINE', 'amount' => 100, 'account_type' => 'ACA', 'fund_source' => 'MAIN_FUND', 'main_fund' => 'Operations'])->assertForbidden();

        foreach ([
            [$forChecking, 'PO-TL-QUEUE-001', 'FOR_CHECKING'],
            [$forNotation, 'PO-OM-QUEUE-001', 'FOR_ACKNOWLEDGEMENT'],
            [$forApproval, 'PO-SMT-QUEUE-001', 'FOR_APPROVAL'],
        ] as [$purchaseRequest, $number, $status]) {
            $order = PurchaseOrder::query()->create([
                'po_number' => $number,
                'purchase_request_id' => $purchaseRequest->id,
                'supplier_id' => $supplier->id,
                'created_by' => $purchasing->id,
                'issued_at' => now()->toDateString(),
                'status' => $status,
                'subtotal' => 100,
                'tax_amount' => 0,
                'total_amount' => 100,
            ]);
            $completedStages = match ($status) {
                'FOR_CHECKING' => [PurchaseOrderApprovalStage::PreparedBy],
                'FOR_ACKNOWLEDGEMENT' => [PurchaseOrderApprovalStage::PreparedBy, PurchaseOrderApprovalStage::CheckedBy],
                default => [PurchaseOrderApprovalStage::PreparedBy, PurchaseOrderApprovalStage::CheckedBy, PurchaseOrderApprovalStage::NotedBy],
            };
            foreach (PurchaseOrderApprovalStage::cases() as $stage) {
                $actor = match ($stage) {
                    PurchaseOrderApprovalStage::PreparedBy => $purchasing,
                    PurchaseOrderApprovalStage::CheckedBy => $teamLead,
                    PurchaseOrderApprovalStage::NotedBy => $operationsManager,
                    PurchaseOrderApprovalStage::ApprovedBy => $smt,
                };
                $completed = in_array($stage, $completedStages, true);
                $order->approvals()->create([
                    'stage' => $stage,
                    'assigned_user_id' => $actor->id,
                    'acted_by' => $completed ? $actor->id : null,
                    'status' => $completed ? 'APPROVED' : 'PENDING',
                    'decision' => $completed ? 'APPROVED' : null,
                    'signature_name' => $completed ? $actor->name : null,
                    'signature_data' => $completed ? $this->signatureImage() : null,
                    'signed_at' => $completed ? now() : null,
                    'acted_at' => $completed ? now() : null,
                ]);
            }
        }

        $this->actingAs($teamLead)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Check purchase order')
            ->assertDontSee('Note purchase order')
            ->assertDontSee('Approve purchase order')
            ->assertSee($forChecking->request_number)
            ->assertViewHas('myActions', fn ($actions) => $actions->count() === 1
                && $actions->first()['request']->is($forChecking)
                && $actions->first()['label'] === 'Check purchase order');

        $this->actingAs($operationsManager)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Note purchase order')
            ->assertDontSee('Check purchase order')
            ->assertDontSee('Approve purchase order')
            ->assertSee($forNotation->request_number)
            ->assertViewHas('myActions', fn ($actions) => $actions->count() === 1
                && $actions->first()['request']->is($forNotation)
                && $actions->first()['label'] === 'Note purchase order');

        $this->actingAs($smt)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Approve purchase order')
            ->assertDontSee('Check purchase order')
            ->assertDontSee('Note purchase order')
            ->assertSee($forApproval->request_number)
            ->assertViewHas('myActions', fn ($actions) => $actions->count() === 1
                && $actions->first()['request']->is($forApproval)
                && $actions->first()['label'] === 'Approve purchase order');

        $this->actingAs($operationsManager)->post(route('purchase-orders.check', $forChecking))->assertForbidden();
        $this->actingAs($smt)->post(route('purchase-orders.acknowledge', $forChecking))->assertForbidden();
        $this->actingAs($teamLead)->post(route('purchase-orders.approve', $forChecking), ['decision' => 'APPROVE'])->assertForbidden();

        $signature = $this->signatureImage();
        $this->actingAs($teamLead)->post(route('purchase-orders.signature', [$forChecking, PurchaseOrderApprovalStage::CheckedBy->value]), ['signature_name' => $teamLead->name, 'signature_data' => $signature])->assertSessionHasNoErrors();
        $this->actingAs($teamLead)->post(route('purchase-orders.check', $forChecking))->assertSessionHasNoErrors();
        $poNumber = $forChecking->purchaseOrder->po_number;

        // A completed step stays in the signer's history only until the next stage signs.
        $this->actingAs($teamLead)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('My PO review history')
            ->assertSee($poNumber)
            ->assertSee('Checked');

        $this->actingAs($operationsManager)->post(route('purchase-orders.signature', [$forChecking, PurchaseOrderApprovalStage::NotedBy->value]), ['signature_name' => $operationsManager->name, 'signature_data' => $signature])->assertSessionHasNoErrors();
        $this->actingAs($operationsManager)->post(route('purchase-orders.acknowledge', $forChecking))->assertSessionHasNoErrors();

        $this->actingAs($teamLead)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('purchaseOrderReviewActions', fn ($actions) => $actions->where('purchase_order_id', $forChecking->purchaseOrder->id)->isEmpty());
        $this->actingAs($operationsManager)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('My PO review history')
            ->assertSee($poNumber)
            ->assertSee('Noted');

        $this->actingAs($smt)->post(route('purchase-orders.signature', [$forChecking, PurchaseOrderApprovalStage::ApprovedBy->value]), ['signature_name' => $smt->name, 'signature_data' => $signature])->assertSessionHasNoErrors();
        $this->actingAs($smt)->post(route('purchase-orders.approve', $forChecking), ['decision' => 'APPROVE'])->assertSessionHasNoErrors();

        $this->actingAs($operationsManager)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('purchaseOrderReviewActions', fn ($actions) => $actions->where('purchase_order_id', $forChecking->purchaseOrder->id)->isEmpty());

        $this->actingAs($smt)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('My PO review history')
            ->assertSee($forChecking->purchaseOrder->po_number)
            ->assertSee('Approved');
    }
    public function test_po_queue_waits_for_prepared_signature_and_explicit_checker_handoff(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $preparer = $this->user(RoleSlug::PoWorkflow);
        $checker = $this->user(RoleSlug::PoWorkflow);
        $noter = $this->user(RoleSlug::PoWorkflow);
        $approver = $this->user(RoleSlug::PoWorkflow);

        foreach ([
            [PurchaseOrderApprovalStage::PreparedBy, $preparer],
            [PurchaseOrderApprovalStage::CheckedBy, $checker],
            [PurchaseOrderApprovalStage::NotedBy, $noter],
            [PurchaseOrderApprovalStage::ApprovedBy, $approver],
        ] as [$stage, $user]) {
            PoRoleAssignment::query()->create(['stage' => $stage, 'user_id' => $user->id]);
        }

        $supplier = Supplier::query()->create(['name' => 'Stage-gated PO Supplier', 'is_active' => true]);
        $purchaseRequest = $this->purchaseRequest(
            $requestor,
            PurchaseRequestStatus::ForBudgetProcessing,
            'Stage-gated Purchase Order',
        );
        $order = PurchaseOrder::query()->create([
            'po_number' => 'PO-STAGE-GATE-001',
            'purchase_request_id' => $purchaseRequest->id,
            'supplier_id' => $supplier->id,
            'created_by' => $preparer->id,
            'issued_at' => now()->toDateString(),
            'status' => 'FOR_CHECKING',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
        ]);

        foreach (PurchaseOrderApprovalStage::cases() as $stage) {
            $assigned = match ($stage) {
                PurchaseOrderApprovalStage::PreparedBy => $preparer,
                PurchaseOrderApprovalStage::CheckedBy => $checker,
                PurchaseOrderApprovalStage::NotedBy => $noter,
                PurchaseOrderApprovalStage::ApprovedBy => $approver,
            };
            $prepared = $stage === PurchaseOrderApprovalStage::PreparedBy;
            $order->approvals()->create([
                'stage' => $stage,
                'assigned_user_id' => $assigned->id,
                'acted_by' => $prepared ? $preparer->id : null,
                'status' => $prepared ? 'APPROVED' : 'PENDING',
                'decision' => $prepared ? 'APPROVED' : null,
                'acted_at' => $prepared ? now() : null,
            ]);
        }

        $this->actingAs($checker)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('myActions', fn ($actions) => $actions->isEmpty());
        $this->actingAs($checker)
            ->get(route('request-flow.index', ['stage' => 'purchase-order']))
            ->assertOk()
            ->assertDontSee($purchaseRequest->request_number);
        $this->actingAs($checker)
            ->post(route('purchase-orders.check', $purchaseRequest))
            ->assertForbidden();

        $this->actingAs($preparer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Sign Prepared By')
            ->assertDontSee('Move to Checker')
            ->assertViewHas('myActions', fn ($actions) => $actions->isEmpty());

        $page = $this->actingAs($preparer)->get(route('purchase-requests.show', $purchaseRequest));
        $page->assertOk()->assertSee('Move to Checker')->assertSee('data-signature-canvas', false);
        $this->assertSame(1, substr_count($page->getContent(), 'data-po-preview-url="'.route('purchase-orders.preview', $order).'"'));
        $this->assertSame(1, substr_count($page->getContent(), 'data-bs-target="#edit-purchase-order"'));
        $this->assertSame(1, substr_count($page->getContent(), 'Print after approval'));

        $signature = $this->signatureImage();
        $this->actingAs($preparer)
            ->post(route('purchase-orders.signature', [$purchaseRequest, PurchaseOrderApprovalStage::PreparedBy->value]), [
                'signature_name' => $preparer->name,
                'signature_data' => $signature,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($checker)
            ->get(route('dashboard'))
            ->assertViewHas('myActions', fn ($actions) => $actions->isEmpty());
        $this->actingAs($preparer)
            ->get(route('dashboard'))
            ->assertDontSee('Sign Prepared By')
            ->assertDontSee('Move to Checker')
            ->assertViewHas('myActions', fn ($actions) => $actions->isEmpty());
        $this->actingAs($preparer)
            ->get(route('purchase-requests.show', $purchaseRequest))
            ->assertOk()
            ->assertSee('Move to Checker');
        $this->actingAs($checker)
            ->post(route('purchase-orders.move-to-checker', $purchaseRequest))
            ->assertForbidden();

        $this->actingAs($preparer)
            ->post(route('purchase-orders.move-to-checker', $purchaseRequest))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $purchaseRequest->id,
            'action' => 'PURCHASE_ORDER_MOVED_TO_CHECKER',
        ]);

        $closedPage = $this->actingAs($preparer)->get(route('purchase-requests.show', $purchaseRequest));
        $closedPage
            ->assertOk()
            ->assertSee('Submitted to Checker.')
            ->assertSee('Your preparation stage is closed')
            ->assertSee($checker->name)
            ->assertSee('Back')
            ->assertSee('data-app-page-back-destination="'.route('dashboard').'"', false)
            ->assertDontSee('Purchase Order Approval History')
            ->assertDontSee('Workflow and Audit Timeline')
            ->assertDontSee('Checked By signature required.')
            ->assertDontSee('data-po-preview-url', false)
            ->assertDontSee('data-bs-target="#edit-purchase-order"', false)
            ->assertDontSee('id="move-to-checker-btn"', false);
        $this->assertFalse($preparer->can('update', $order->fresh('approvals')));
        $this->actingAs($preparer)
            ->patch(route('purchase-orders.update', $purchaseRequest), [
                'account_number' => 'CLOSED-STAGE-UPDATE',
            ])
            ->assertForbidden();

        $this->actingAs($preparer)
            ->get(route('dashboard'))
            ->assertViewHas('myActions', fn ($actions) => $actions->isEmpty());
        $this->actingAs($checker)
            ->get(route('dashboard'))
            ->assertViewHas('myActions', fn ($actions) => $actions->count() === 1
                && $actions->first()['request']->is($purchaseRequest)
                && $actions->first()['label'] === 'Check purchase order');
        $this->actingAs($checker)
            ->get(route('request-flow.index', ['stage' => 'purchase-order']))
            ->assertOk()
            ->assertSee($purchaseRequest->request_number);
    }
    public function test_tl_requestor_dashboard_remains_unchanged_without_a_po_assignment(): void
    {
        $teamLead = $this->user(RoleSlug::Tl);
        $draft = $this->purchaseRequest($teamLead, PurchaseRequestStatus::Draft, 'TL Draft');

        $this->actingAs($teamLead)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($draft->request_number)
            ->assertSee('Complete draft')
            ->assertDontSee('My PO review history')
            ->assertViewHas('myActions', fn ($actions) => $actions->count() === 1
                && $actions->first()['request']->is($draft));
    }
    public function test_admin_dashboard_includes_system_totals_and_authorized_audit_activity(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $admin = $this->user(RoleSlug::Admin);
        $purchaseRequest = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForPayment, 'Payment Queue');
        $purchaseRequest->auditLogs()->create([
            'user_id' => $admin->id,
            'action' => 'ADMIN_REVIEWED_REQUEST',
            'previous_status' => PurchaseRequestStatus::AwaitingPresidentFinalApproval->value,
            'new_status' => PurchaseRequestStatus::ForPayment->value,
            'remarks' => 'Reviewed in a dashboard feature test.',
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('System overview')
            ->assertSee('Active Users')
            ->assertSee('System request activity')
            ->assertSee('Admin Reviewed Request')
            ->assertSee($purchaseRequest->request_number)
            ->assertSee('Record payment')
            ->assertViewHas('recentActivity', fn ($activity) => $activity->count() === 1);
    }

    public function test_dashboard_omits_an_action_when_its_policy_denies_the_request(): void
    {
        $approver = $this->user(RoleSlug::OmSmtsApprover);
        $this->purchaseRequest($approver, PurchaseRequestStatus::ForOmApproval, 'Policy mismatch');

        Gate::shouldReceive('forUser')->andReturnSelf();
        Gate::shouldReceive('allows')->andReturnFalse();
        Gate::shouldReceive('check')->andReturnTrue();

        $this->actingAs($approver)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Policy mismatch')
            ->assertViewHas('myActions', fn ($actions) => $actions->isEmpty());
    }

    public function test_finance_dashboard_lists_processed_payments_with_codes_search_and_filters(): void
    {
        $finance = $this->user(RoleSlug::Finance);
        $otherFinance = $this->user(RoleSlug::Finance);
        $requestor = $this->user(RoleSlug::Requestor);
        $paid = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForReceiving, 'Paid laptops');
        $budget = BudgetRequest::query()->create([
            'budget_request_number' => 'BR-DASH-PAY', 'purchase_request_id' => $paid->id, 'requested_by' => $requestor->id,
            'payment_method' => 'ONLINE', 'amount' => 100, 'status' => 'PAID', 'requested_at' => now()->toDateString(),
        ]);
        $payment = fn (User $recorder, string $method, string $reference, string $date) => Payment::query()->create([
            'budget_request_id' => $budget->id, 'purchase_request_id' => $paid->id, 'recorded_by' => $recorder->id,
            'payment_type' => 'PROCUREMENT', 'payment_method' => $method, 'reference_number' => $reference,
            'amount' => 100, 'paid_at' => $date, 'status' => 'PAID', 'payment_status' => 'PAID',
        ]);
        $online = $payment($finance, 'ONLINE', 'REF-ONLINE-1', '2026-10-01');
        $cash = $payment($finance, 'CASH', 'REF-CASH-2', '2026-10-15');
        $foreign = $payment($otherFinance, 'ONLINE', 'REF-OTHER-3', '2026-10-15');

        $this->assertSame('PAY-'.str_pad((string) $online->id, 6, '0', STR_PAD_LEFT), $online->payment_code);

        $this->actingAs($finance)->get(route('dashboard'))->assertOk()
            ->assertSee('Processed payment')
            ->assertSee('data-payment-view', false)
            ->assertSee('payment-view-modal', false)
            ->assertSee($online->payment_code)->assertSee($cash->payment_code)
            ->assertDontSee($foreign->payment_code)
            ->assertDontSee('Recent requests');

        $this->get(route('dashboard', ['payments_search' => $cash->payment_code]))->assertOk()
            ->assertSee('REF-CASH-2')->assertDontSee('REF-ONLINE-1');
        $this->get(route('dashboard', ['payments_status' => 'ONLINE']))->assertOk()
            ->assertSee('REF-ONLINE-1')->assertDontSee('REF-CASH-2');
        $this->get(route('dashboard', ['payments_from' => '2026-10-10', 'payments_to' => '2026-10-20']))->assertOk()
            ->assertSee('REF-CASH-2')->assertDontSee('REF-ONLINE-1');
    }

    public function test_processed_by_me_table_numbers_rows_and_filters_independently(): void
    {
        $om = $this->user(RoleSlug::OmSmtsApprover);
        $requestor = $this->user(RoleSlug::Requestor);
        $inProgress = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForTeamScreening, 'Screening chairs');
        $rejected = $this->purchaseRequest($requestor, PurchaseRequestStatus::Rejected, 'Rejected monitors');
        foreach ([$inProgress, $rejected] as $purchaseRequest) {
            $purchaseRequest->auditLogs()->create([
                'user_id' => $om->id,
                'action' => array_key_first(RequestStageReceipt::handoffLabels()),
                'previous_status' => PurchaseRequestStatus::ForOmApproval->value,
                'new_status' => $purchaseRequest->status->value,
            ]);
        }

        $this->actingAs($om)->get(route('dashboard'))->assertOk()
            ->assertSee('processed_search', false)
            ->assertSee('table-row-number', false)
            ->assertSee($inProgress->request_number)->assertSee($rejected->request_number);

        $this->get(route('dashboard', ['processed_search' => 'monitors']))->assertOk()
            ->assertSee($rejected->request_number)->assertDontSee($inProgress->request_number);
        $this->get(route('dashboard', ['processed_status' => 'pending']))->assertOk()
            ->assertSee($inProgress->request_number)->assertDontSee($rejected->request_number);
    }

    public function test_po_review_history_tabs_show_completed_steps_of_the_users_pos_read_only(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $preparer = $this->user(RoleSlug::PoWorkflow);
        $checker = $this->user(RoleSlug::PoWorkflow);
        $noter = $this->user(RoleSlug::PoWorkflow);
        $approver = $this->user(RoleSlug::PoWorkflow);
        $outsider = $this->user(RoleSlug::PoWorkflow);
        PoRoleAssignment::query()->create(['stage' => PurchaseOrderApprovalStage::PreparedBy, 'user_id' => $preparer->id]);
        PoRoleAssignment::query()->create(['stage' => PurchaseOrderApprovalStage::CheckedBy, 'user_id' => $outsider->id]);
        $supplier = Supplier::query()->create(['name' => 'PO History Tabs Supplier', 'is_active' => true]);
        $signers = [
            PurchaseOrderApprovalStage::PreparedBy->value => $preparer,
            PurchaseOrderApprovalStage::CheckedBy->value => $checker,
            PurchaseOrderApprovalStage::NotedBy->value => $noter,
            PurchaseOrderApprovalStage::ApprovedBy->value => $approver,
        ];
        // One PO checked only, one checked and noted.
        foreach (['PO-TAB-CHECKED' => 2, 'PO-TAB-NOTED' => 3] as $number => $completedCount) {
            $order = PurchaseOrder::query()->create([
                'po_number' => $number, 'purchase_request_id' => $this->purchaseRequest($requestor, PurchaseRequestStatus::ForBudgetProcessing, $number)->id,
                'supplier_id' => $supplier->id, 'created_by' => $preparer->id, 'issued_at' => now()->toDateString(),
                'status' => $completedCount === 2 ? 'FOR_ACKNOWLEDGEMENT' : 'FOR_APPROVAL', 'subtotal' => 100, 'tax_amount' => 0, 'total_amount' => 100,
            ]);
            foreach (PurchaseOrderApprovalStage::cases() as $index => $stage) {
                $actor = $signers[$stage->value];
                $completed = $index < $completedCount;
                $order->approvals()->create([
                    'stage' => $stage, 'assigned_user_id' => $actor->id, 'acted_by' => $completed ? $actor->id : null,
                    'status' => $completed ? 'APPROVED' : 'PENDING', 'decision' => $completed ? 'APPROVED' : null,
                    'signature_name' => $completed ? $actor->name : null,
                    'signature_data' => $completed ? $this->signatureImage() : null,
                    'signed_at' => $completed ? now() : null, 'acted_at' => $completed ? now() : null,
                ]);
            }
        }

        $this->actingAs($preparer)->get(route('dashboard'))->assertOk()
            ->assertSee('po-history-tabs', false)
            ->assertSee('My reviews')->assertSee('PO Checked');

        $this->get(route('dashboard', ['po_history_tab' => 'checked']))->assertOk()
            ->assertSee('PO-TAB-CHECKED')->assertSee('PO-TAB-NOTED')->assertSee($checker->name)
            ->assertDontSee('Check purchase order</a>', false);
        // Stage tabs list signed and still-pending rows; pending ones are labelled as such.
        $this->get(route('dashboard', ['po_history_tab' => 'noted']))->assertOk()
            ->assertSee('PO-TAB-NOTED')->assertSee($noter->name)
            ->assertSee('PO-TAB-CHECKED')->assertSee('Pending signature');
        $this->get(route('dashboard', ['po_history_tab' => 'approved']))->assertOk()
            ->assertSee('Pending signature');

        // POs the user is not part of never appear in the view-only tabs.
        $this->actingAs($outsider)->get(route('dashboard', ['po_history_tab' => 'checked']))->assertOk()
            ->assertDontSee('PO-TAB-CHECKED')->assertDontSee('PO-TAB-NOTED');

        // The Purchasing Team prepares every PO as a team: it sees all of them and may preview them.
        $team = $this->user(RoleSlug::PurchasingTeam);
        $this->actingAs($team)->get(route('dashboard', ['workflow' => 'purchase-order', 'po_history_tab' => 'checked']))->assertOk()
            ->assertSee('PO-TAB-CHECKED')->assertSee('PO-TAB-NOTED');
        $this->actingAs($team)->get(route('dashboard', ['workflow' => 'purchase-order']))->assertOk()
            ->assertSee('PO-TAB-CHECKED')->assertSee('PO-TAB-NOTED');
        $this->getJson(route('purchase-orders.preview', PurchaseOrder::query()->where('po_number', 'PO-TAB-CHECKED')->firstOrFail()))
            ->assertOk()->assertJsonPath('can_edit', false);
    }

    public function test_purchasing_team_records_are_separated_by_workflow(): void
    {
        $team = $this->user(RoleSlug::PurchasingTeam);
        $requestor = $this->user(RoleSlug::Requestor);
        $supplier = Supplier::query()->create(['name' => 'Created PO supplier', 'is_active' => true]);
        $createdRequest = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForBudgetProcessing, 'Created PO request');
        $createdPo = $createdRequest->purchaseOrder()->create([
            'po_number' => 'PO-TAB-CREATED',
            'supplier_id' => $supplier->id,
            'created_by' => $team->id,
            'issued_at' => now()->toDateString(),
            'status' => 'FOR_CHECKING',
            'subtotal' => 500,
            'tax_amount' => 0,
            'total_amount' => 500,
        ]);
        foreach (['BR-TAB-CREATED' => null, 'BR-TAB-APPROVED' => 'APPROVED'] as $number => $finalStatus) {
            BudgetRequest::query()->create([
                'budget_request_number' => $number,
                'purchase_request_id' => $this->purchaseRequest($requestor, PurchaseRequestStatus::ForBudgetMaker, $number)->id,
                'requested_by' => $requestor->id, 'payment_method' => 'ONLINE', 'amount' => 100,
                'status' => 'PROCEEDER_APPROVED', 'final_approval_status' => $finalStatus,
                'maker_approved_at' => $finalStatus ? now() : null, 'requested_at' => now()->toDateString(),
            ]);
        }

        $this->actingAs($team)->get(route('dashboard', ['workflow' => 'canvassing']))->assertOk()
            ->assertSee('Processed')
            ->assertDontSee('Budget Approved')
            ->assertDontSee('processed_tab=created')
            ->assertSee('<h2 id="processed-requests-heading">Canvassing processed</h2>', false)
            ->assertDontSee('PO-TAB-CREATED')
            ->assertDontSee('BR-TAB-CREATED');

        $this->get(route('dashboard', ['workflow' => 'canvassing', 'processed_tab' => 'created']))->assertOk()
            ->assertDontSee('PO-TAB-CREATED')
            ->assertDontSee('BR-TAB-CREATED')
            ->assertViewHas('processedTab', 'processed')
            ->assertViewHas('processedTabCounts', fn ($counts) => array_keys($counts) === ['processed']);
        $this->get(route('dashboard', ['workflow' => 'purchase-order']))->assertOk()
            ->assertSee('<h2 id="processed-requests-heading">Purchase Orders processed</h2>', false)
            ->assertSee('PO-TAB-CREATED')
            ->assertSee('Created PO supplier')
            ->assertSee(route('purchase-orders.preview', $createdPo), false)
            ->assertSee('id="purchase-order-review-modal"', false)
            ->assertDontSee('Purchase Order Review History')
            ->assertDontSee('BR-TAB-CREATED')
            ->assertViewHas('processedTabCounts', fn ($counts) => $counts['processed'] === 1 && count($counts) === 1);
        $this->get(route('dashboard', ['workflow' => 'purchase-order', 'created_po_page' => 2]))->assertOk()
            ->assertDontSee('processed_tab=processed&amp;created_po_page=2', false);
        $this->get(route('dashboard', ['workflow' => 'budget-request', 'budget_view' => 'processed']))->assertOk()
            ->assertSee('<h2 id="processed-requests-heading">Final Budget Request</h2>', false)
            ->assertSee('br-controls', false)
            ->assertSee('po-history-tabs--large', false)
            ->assertSee('BR-TAB-APPROVED')->assertDontSee('BR-TAB-CREATED')->assertDontSee('PO-TAB-CREATED');
    }

    public function test_purchasing_team_can_monitor_purchase_order_workflow_without_action_controls(): void
    {
        $team = $this->user(RoleSlug::PurchasingTeam);
        $requestor = $this->user(RoleSlug::Requestor);
        $request = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForBudgetProcessing, 'Workflow monitor request');
        $supplier = Supplier::query()->create(['name' => 'Tracker supplier', 'is_active' => true]);
        $order = $request->purchaseOrder()->create([
            'po_number' => 'PO-TRACK-001',
            'supplier_id' => $supplier->id,
            'created_by' => $team->id,
            'issued_at' => now()->toDateString(),
            'status' => 'FOR_CHECKING',
        ]);
        $checker = $this->user(RoleSlug::Tl);
        $approval = $order->approvals()->create([
            'stage' => PurchaseOrderApprovalStage::CheckedBy,
            'assigned_user_id' => $checker->id,
            'status' => 'PENDING',
        ]);

        $this->actingAs($team)->get(route('dashboard'))->assertOk()
            ->assertSee(route('workflow-tracker.index'), false)
            ->assertSee('Workflow Tracker');
        $this->get(route('workflow-tracker.index'))->assertOk()
            ->assertSee('PO-TRACK-001')
            ->assertSee('Workflow monitor request')
            ->assertSee('Awaiting PO checking')
            ->assertSee(route('workflow-tracker.show', $order), false)
            ->assertSee('View timeline');
        $this->get(route('workflow-tracker.index', ['stage' => 'checking']))->assertOk()
            ->assertSee('PO-TRACK-001')
            ->assertSee('name="stage" value="checking"', false)
            ->assertSee(route('workflow-tracker.show', ['purchaseOrder' => $order, 'stage' => 'checking']), false)
            ->assertViewHas('stageCounts', fn ($counts) => $counts['checking'] === 1 && $counts['noting'] === 0);
        $this->get(route('workflow-tracker.index', ['stage' => 'noting']))->assertOk()
            ->assertDontSee('PO-TRACK-001');
        $this->get(route('workflow-tracker.show', $order))->assertOk()
            ->assertSee('Inventory logging')
            ->assertDontSee('name="decision"', false);
        $this->get(route('workflow-tracker.index', ['search' => 'missing-po']))->assertOk()
            ->assertDontSee('PO-TRACK-001');

        $approval->update(['status' => 'APPROVED', 'acted_by' => $checker->id, 'acted_at' => now()]);
        $order->update(['status' => 'FOR_ACKNOWLEDGEMENT']);
        $this->get(route('workflow-tracker.show', $order))->assertOk()
            ->assertSee('Awaiting PO noting')
            ->assertSee($checker->name);
        $this->get(route('workflow-tracker.index', ['stage' => 'noting']))->assertOk()
            ->assertSee('PO-TRACK-001');
        $this->get(route('workflow-tracker.index', ['stage' => 'checking']))->assertOk()
            ->assertDontSee('PO-TRACK-001');

        $order->update(['status' => 'APPROVED', 'approved_at' => now()]);
        $maker = $this->user(RoleSlug::BudgetMaker);
        $budget = BudgetRequest::query()->create([
            'budget_request_number' => 'BR-TRACK-001',
            'purchase_request_id' => $request->id,
            'purchase_order_id' => $order->id,
            'requested_by' => $requestor->id,
            'maker_user_id' => $maker->id,
            'maker_approved_at' => now(),
            'payment_method' => 'ONLINE',
            'amount' => 100,
            'status' => 'PENDING_FINAL_APPROVAL',
            'requested_at' => now()->toDateString(),
        ]);
        $budget->allocations()->create([
            'purchase_request_id' => $request->id,
            'purchase_order_id' => $order->id,
            'amount' => 100,
        ]);
        $this->get(route('workflow-tracker.show', $order))->assertOk()
            ->assertSee('Awaiting Budget final approval')
            ->assertSee('BR-TRACK-001');
        $this->get(route('workflow-tracker.index', ['stage' => 'budget-approval']))->assertOk()
            ->assertSee('PO-TRACK-001');

        $finalApprover = $this->user(RoleSlug::Purchasing);
        $budget->update([
            'final_approval_status' => 'APPROVED',
            'final_approval_by' => $finalApprover->id,
            'final_approval_at' => now(),
            'status' => 'APPROVED',
        ]);
        $finance = $this->user(RoleSlug::Finance);
        $request->payments()->create([
            'budget_request_id' => $budget->id,
            'purchase_order_id' => $order->id,
            'recorded_by' => $finance->id,
            'payment_type' => 'PROCUREMENT',
            'payment_method' => 'ONLINE',
            'amount' => 100,
            'paid_at' => now()->toDateString(),
            'status' => 'PAID',
        ]);
        $inventoryUser = $this->user(RoleSlug::InventoryStaff);
        $receipt = $request->goodsReceipts()->create([
            'receipt_number' => 'GR-TRACK-001',
            'purchase_order_id' => $order->id,
            'received_by' => $inventoryUser->id,
            'received_at' => now(),
            'status' => 'RECEIVED',
        ]);
        $receiptItem = $receipt->items()->create([
            'description' => 'Tracked item',
            'quantity_received' => 1,
            'unit' => 'unit',
        ]);
        $request->inventoryItems()->create([
            'inventory_code' => 'INV-TRACK-001',
            'goods_receipt_item_id' => $receiptItem->id,
            'logged_by' => $inventoryUser->id,
            'description' => 'Tracked item',
            'quantity' => 1,
            'unit' => 'unit',
            'logged_at' => now(),
        ]);
        $this->get(route('workflow-tracker.show', $order))->assertOk()
            ->assertSee('Completed through inventory')
            ->assertSee('GR-TRACK-001');
        $this->get(route('workflow-tracker.index', ['stage' => 'completed']))->assertOk()
            ->assertSee('PO-TRACK-001');
        $this->get(route('workflow-tracker.index', ['stage' => 'budget-approval']))->assertOk()
            ->assertDontSee('PO-TRACK-001');

        $this->actingAs($requestor)->get(route('workflow-tracker.index'))->assertForbidden();
        $this->get(route('workflow-tracker.show', $order))->assertForbidden();
    }

    public function test_team_leader_returned_tab_shows_latest_approver_and_only_own_returned_requests(): void
    {
        $leader = $this->user(RoleSlug::TeamLeader);
        $approver = $this->user(RoleSlug::Om);
        $returned = $this->purchaseRequest($leader, PurchaseRequestStatus::Returned, 'Correct laptop specifications');
        $pending = $this->purchaseRequest($leader, PurchaseRequestStatus::ForOmApproval, 'Still pending');
        $completed = $this->purchaseRequest($leader, PurchaseRequestStatus::Completed, 'Already completed');
        $other = $this->purchaseRequest($this->user(RoleSlug::TeamLeader), PurchaseRequestStatus::Returned, 'Private other request');
        $returned->approvals()->create([
            'user_id' => $leader->id, 'approval_level' => 'OM', 'action' => 'OM_RETURNED',
            'new_status' => PurchaseRequestStatus::Returned->value,
            'remarks' => 'Old return remarks', 'acted_at' => now()->subDay(),
        ]);
        $returned->approvals()->create([
            'user_id' => $approver->id, 'approval_level' => 'OM', 'action' => 'OM_RETURNED',
            'new_status' => PurchaseRequestStatus::Returned->value,
            'remarks' => 'Please specify the required memory.', 'acted_at' => now(),
        ]);
        // A later unrelated approval must not replace the recorded returner.
        $returned->approvals()->create([
            'user_id' => $leader->id, 'approval_level' => 'TL', 'action' => 'SUBMITTED',
            'new_status' => PurchaseRequestStatus::ForOmApproval->value, 'acted_at' => now()->addMinute(),
        ]);

        $this->actingAs($leader)->get(route('dashboard', ['request_tab' => 'returned']))
            ->assertOk()->assertSee('Returned by')->assertSee($approver->name)
            ->assertSee($approver->role->name)->assertSee('Please specify the required memory.')
            ->assertSee('Old return remarks')->assertDontSee($other->title)->assertDontSee($completed->title)
            ->assertViewHas('returnedCount', 1)
            ->assertViewHas('pendingRequests', fn ($requests) => $requests->modelKeys() === [$pending->id])
            ->assertViewHas('returnedRequests', fn ($requests) => $requests->modelKeys() === [$returned->id]);

        $this->get(route('dashboard', ['request_tab' => 'returned', 'returned_search' => 'no match']))
            ->assertOk()->assertSee('No returned requests found');
        $this->get(route('dashboard'))->assertOk()->assertSee($completed->title)
            ->assertDontSee($returned->title);

        $returned->update(['status' => PurchaseRequestStatus::ForOmApproval]);
        $this->get(route('dashboard', ['request_tab' => 'returned']))->assertOk()
            ->assertViewHas('returnedCount', 0)->assertSee('No returned requests found');
    }

    public function test_requestor_can_see_own_om_return_in_returned_tab(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $leader = $this->user(RoleSlug::TeamLeader);
        $om = $this->user(RoleSlug::Om);
        $returned = $this->purchaseRequest($requestor, PurchaseRequestStatus::Returned, 'Requestor OM return');
        $returned->approvals()->create([
            'user_id' => $om->id, 'approval_level' => 'OM_REVIEW', 'action' => 'OM_RETURNED',
            'new_status' => PurchaseRequestStatus::Returned->value,
            'remarks' => 'Correct the specifications.', 'acted_at' => now(),
        ]);

        $this->actingAs($requestor)->get(route('dashboard', ['request_tab' => 'returned']))
            ->assertOk()->assertSee($returned->request_number)->assertSee($om->name)
            ->assertSee('Correct the specifications.')->assertViewHas('returnedCount', 1);
        $this->actingAs($leader)->get(route('dashboard', ['request_tab' => 'returned']))
            ->assertOk()->assertDontSee($returned->request_number)->assertViewHas('returnedCount', 0);
    }

    public function test_team_leader_returned_tab_handles_missing_approval_history(): void
    {
        $leader = $this->user(RoleSlug::TeamLeader);
        $this->purchaseRequest($leader, PurchaseRequestStatus::Returned, 'Legacy returned request');

        $this->actingAs($leader)->get(route('dashboard', ['request_tab' => 'returned']))
            ->assertOk()->assertSee('Legacy returned request')->assertSee('Approver not recorded');
    }

    private function user(RoleSlug $role, ?Campaign $campaign = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role->value)->value('id'),
            'campaign_id' => $campaign?->id,
            'department' => $campaign?->name ?? 'Operations',
        ]);
    }

    private function purchaseRequest(
        User $requestor,
        PurchaseRequestStatus $status,
        string $title,
    ): PurchaseRequest {
        $this->requestSequence++;

        return PurchaseRequest::query()->create([
            'request_number' => 'DASH'.str_pad((string) $this->requestSequence, 5, '0', STR_PAD_LEFT),
            'title' => $title,
            'requestor_id' => $requestor->id,
            'department_campaign' => $requestor->campaign?->name ?? 'Operations',
            'request_type' => 'OTHER',
            'purpose' => 'Dashboard feature verification',
            'required_date' => now()->addWeek(),
            'delivery_location' => 'Main Office',
            'status' => $status,
            'current_responsible_role_id' => $requestor->role_id,
            'total_amount' => 100,
        ]);
    }
}
