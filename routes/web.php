<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Controllers\Admin\DataManagementController;
use App\Http\Controllers\Admin\PurchaseOrderWorkflowAssignmentController;
use App\Http\Controllers\Admin\SystemHealthController;
use App\Http\Controllers\Admin\TransactionDeletionController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\BudgetRequestExportController;
use App\Http\Controllers\BudgetRequestQueueController;
use App\Http\Controllers\CanvassingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryLocationController;
use App\Http\Controllers\InventoryClaimController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OmApprovalController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PresidentApprovalController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseOrderWorkflowTrackerController;
use App\Http\Controllers\PurchaseRequestController;
use App\Http\Controllers\ReceivingController;
use App\Http\Controllers\RequestFlowController;
use App\Http\Controllers\RequestInventoryReleaseController;
use App\Http\Controllers\RequestWithdrawalController;
use App\Http\Controllers\ReturnedMonitoringController;
use App\Http\Controllers\ScreeningController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\VoidedMonitoringController;
use App\Http\Controllers\WorkflowRemarksFeedController;
use App\Http\Controllers\WorkspaceRoleController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/workspace-role', WorkspaceRoleController::class)->name('workspace-role.update');
    Route::get('/change-password', [PasswordChangeController::class, 'edit'])->name('password.change');
    Route::post('/change-password', [PasswordChangeController::class, 'update'])->name('password.update');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::resource('suppliers', SupplierController::class)
        ->only(['index', 'store', 'update'])->middleware('role:it-staff,premises-staff,purchasing,purchasing-team,procurement-staff,canvassing-staff,admin');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/summary', [NotificationController::class, 'summary'])->name('notifications.summary');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::get('/returned-monitoring', ReturnedMonitoringController::class)
        ->middleware('role:purchasing-team,admin')
        ->name('returned-monitoring.index');
    Route::get('/voided-monitoring', VoidedMonitoringController::class)
        ->middleware('role:purchasing-team,admin')
        ->name('voided-monitoring.index');
    Route::get('/workflow-tracker', PurchaseOrderWorkflowTrackerController::class)
        ->middleware('role:purchasing-team,admin')
        ->name('workflow-tracker.index');
    Route::get('/workflow-tracker/{purchaseOrder}', [PurchaseOrderWorkflowTrackerController::class, 'show'])
        ->middleware('role:purchasing-team,admin')
        ->name('workflow-tracker.show');
    Route::get('/workflow-remarks', WorkflowRemarksFeedController::class)
        ->middleware('role:purchasing-team,admin')
        ->name('workflow-remarks.feed');
    Route::get('/request-flow', RequestFlowController::class)->name('request-flow.index');
    Route::resource('purchase-requests', PurchaseRequestController::class)
        ->parameters(['purchase-requests' => 'purchaseRequest'])
        ->except(['destroy']);
    Route::post('/purchase-requests/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit'])
        ->name('purchase-requests.submit');
    Route::post('/purchase-requests/{purchaseRequest}/withdraw', [RequestWithdrawalController::class, 'store'])
        ->name('purchase-requests.withdraw');
    Route::post('/purchase-requests/{purchaseRequest}/withdrawal/acknowledge', [RequestWithdrawalController::class, 'acknowledge'])
        ->name('purchase-requests.withdrawal.acknowledge');
    Route::get('/purchase-requests/{purchaseRequest}/preview', [PurchaseRequestController::class, 'preview'])
        ->name('purchase-requests.preview');
    Route::get('/purchase-requests/{purchaseRequest}/document', [PurchaseRequestController::class, 'document'])
        ->name('purchase-requests.document');
    Route::get('/budget-requests/{budgetRequest}/document', [BudgetController::class, 'document'])
        ->name('budget-requests.document');
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->name('attachments.download');
    Route::get('/attachments/{attachment}/view', [AttachmentController::class, 'view'])
        ->name('attachments.view');
    Route::get('/delivery-locations', [DeliveryLocationController::class, 'index'])->name('delivery-locations.index');
    Route::post('/delivery-locations', [DeliveryLocationController::class, 'store'])->name('delivery-locations.store');
    Route::patch('/delivery-locations/{deliveryLocation}', [DeliveryLocationController::class, 'update'])->name('delivery-locations.update');
    Route::delete('/delivery-locations/{deliveryLocation}', [DeliveryLocationController::class, 'destroy'])->name('delivery-locations.destroy');
    // Inventory register: filled by receiving goods; IT / Premises also add by hand and release (InventoryItemPolicy).
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/inventory', [InventoryController::class, 'store'])->name('inventory.store');
    Route::get('/inventory/import-template', [InventoryController::class, 'importTemplate'])->name('inventory.import-template');
    Route::post('/inventory/import', [InventoryController::class, 'import'])->name('inventory.import');
    Route::post('/inventory/{inventoryItem}/release', [InventoryController::class, 'release'])->name('inventory.release');
    Route::patch('/inventory-releases/{inventoryRelease}/recipient', [InventoryController::class, 'linkRecipient'])->name('inventory.link-recipient');
    Route::get('/my-items', [InventoryClaimController::class, 'index'])->name('inventory.my-items');
    Route::post('/inventory-releases/{inventoryRelease}/claim', [InventoryClaimController::class, 'store'])->name('inventory.claim');
    Route::get('/inventory-claim-proofs/{proof}', [InventoryClaimController::class, 'proof'])->name('inventory.proofs.show');
    Route::post('/purchase-requests/{purchaseRequest}/inventory-release', [RequestInventoryReleaseController::class, 'store'])
        ->name('purchase-requests.inventory-release');

    Route::prefix('om-approvals')
        ->name('om-approvals.')
        ->middleware('role:om-smts-approver,admin')
        ->group(function () {
            Route::post('/{purchaseRequest}/approve', [OmApprovalController::class, 'approve'])->name('approve');
            Route::post('/{purchaseRequest}/return', [OmApprovalController::class, 'return'])->name('return');
        });

    Route::post('/request-flow/{purchaseRequest}/screening', [ScreeningController::class, 'store'])
        ->middleware('role:it-staff,premises-staff')
        ->name('request-flow.screening.store');
    Route::delete('/request-flow/{purchaseRequest}/screening', [ScreeningController::class, 'undo'])
        ->middleware('role:it-staff,premises-staff')
        ->name('request-flow.screening.undo');
    Route::post('/request-flow/{purchaseRequest}/claim', [RequestFlowController::class, 'claim'])
        ->middleware('role:it-staff,premises-staff')
        ->name('request-flow.claim');
    Route::delete('/request-flow/{purchaseRequest}/claim', [RequestFlowController::class, 'unclaim'])
        ->middleware('role:it-staff,premises-staff')
        ->name('request-flow.unclaim');
    Route::patch('/request-flow/{purchaseRequest}/priority', [BudgetRequestQueueController::class, 'updatePriority'])
        ->middleware('role:budget-maker,admin,it-staff,premises-staff')
        ->name('request-flow.priority.update');
    Route::get('/purchase-orders/{purchaseRequest}/document', [PurchaseOrderController::class, 'document'])
        ->name('purchase-orders.document');
    Route::get('/purchase-orders/{purchaseOrder}/preview', [PurchaseOrderController::class, 'preview'])
        ->name('purchase-orders.preview');

    Route::prefix('canvassing')
        ->name('canvassing.')
        ->group(function () {
            Route::post('/{purchaseRequest}/quotations', [CanvassingController::class, 'storeQuotations'])
                ->middleware('role:canvassing-staff,purchasing,purchasing-team,procurement-staff,it-staff,premises-staff,admin')
                ->name('quotations.store');
            Route::post('/{purchaseRequest}/without-quotation', [CanvassingController::class, 'submitWithoutQuotation'])
                ->middleware('role:canvassing-staff,purchasing,purchasing-team,procurement-staff,admin')
                ->name('without-quotation');
            Route::patch('/{purchaseRequest}/quotations/{quotation}', [CanvassingController::class, 'updateQuotation'])
                ->middleware('role:canvassing-staff,purchasing,purchasing-team,procurement-staff,it-staff,premises-staff,admin')
                ->name('quotations.update');
            Route::post('/{purchaseRequest}/quotations/{quotation}/recommend', [CanvassingController::class, 'recommendQuotation'])
                ->middleware('role:canvassing-staff,purchasing,purchasing-team,procurement-staff,it-staff,premises-staff,admin')
                ->name('quotations.recommend');
            Route::post('/{purchaseRequest}/supplier-approval', [CanvassingController::class, 'approveSupplier'])
                ->middleware('role:purchasing,procurement-staff,admin')
                ->name('supplier.approve');
        });

    Route::prefix('purchase-orders')
        ->name('purchase-orders.')
        ->group(function () {
            Route::post('/{purchaseRequest}', [PurchaseOrderController::class, 'store'])->name('store');
            Route::post('/{purchaseRequest}/return-to-requestor', [PurchaseOrderController::class, 'returnToRequestor'])->name('return-to-requestor');
            Route::post('/{purchaseRequest}/skip', [PurchaseOrderController::class, 'skip'])->name('skip');
            Route::patch('/{purchaseRequest}', [PurchaseOrderController::class, 'update'])->name('update');
            Route::patch('/{purchaseRequest}/task', [PurchaseOrderController::class, 'updateTask'])->name('task.update');
            Route::post('/{purchaseRequest}/move-to-checker', [PurchaseOrderController::class, 'moveToChecker'])->name('move-to-checker');
            Route::post('/{purchaseRequest}/check', [PurchaseOrderController::class, 'check'])->name('check');
            Route::post('/{purchaseRequest}/acknowledge', [PurchaseOrderController::class, 'acknowledge'])->name('acknowledge');
            Route::post('/{purchaseRequest}/approve', [PurchaseOrderController::class, 'approve'])->name('approve');
            Route::post('/{purchaseRequest}/prepare-signature', [PurchaseOrderController::class, 'prepareSignature'])->name('prepare-signature');
            Route::post('/{purchaseRequest}/signatures/{stage}', [PurchaseOrderController::class, 'signature'])->name('signature');
        });

    Route::prefix('budget')
        ->name('budget.')
        ->middleware('role:purchasing,purchasing-team,budget-maker,admin')
        ->group(function () {
            Route::get('/', [BudgetRequestQueueController::class, 'index'])->name('index');
            Route::get('/create', [BudgetController::class, 'create'])->name('create');
            Route::get('/export', BudgetRequestExportController::class)->name('export');
            Route::post('/', [BudgetController::class, 'store'])->name('store');
            Route::get('/{purchaseRequest}/po-details/edit', [BudgetController::class, 'editPoDetails'])->name('po-details.edit');
            Route::patch('/{purchaseRequest}/po-details', [BudgetController::class, 'updatePoDetails'])->name('po-details.update');
            Route::patch('/{purchaseRequest}/priority', [BudgetRequestQueueController::class, 'updatePriority'])->name('priority.update');
        });

    Route::middleware('role:purchasing,purchasing-team,procurement-staff,admin')->group(function () {
        Route::get('/budget/finalize', [BudgetController::class, 'finalizeForm'])->name('budget.finalize');
        Route::post('/budget/finalize', [BudgetController::class, 'finalizeBatch'])->name('budget.finalize.store');
    });

    // Landing page for forms submitted inside the dashboard action modal: closes the modal.
    Route::view('/workflow/done', 'partials.workflow-done')->name('workflow.done');

    Route::prefix('president-approvals')
        ->name('president-approvals.')
        ->middleware('role:president,admin')
        ->group(function () {
            Route::get('/', [PresidentApprovalController::class, 'index'])->name('index');
            Route::post('/{purchaseRequest}/supplier-decision', [PresidentApprovalController::class, 'supplierDecision'])
                ->name('supplier.decision');
        });

    Route::prefix('payments')
        ->name('payments.')
        ->middleware('role:finance,purchasing,admin')
        ->group(function () {
            Route::get('/batch', [PaymentController::class, 'batchForm'])->name('batch');
            Route::post('/batch', [PaymentController::class, 'batchStore'])->name('batch.store');
            Route::get('/{payment}/sheet', [PaymentController::class, 'sheet'])->name('sheet');
            Route::post('/{purchaseRequest}', [PaymentController::class, 'store'])->name('store');
        });

    Route::prefix('receiving')
        ->name('receiving.')
        ->middleware('role:inventory,inventory-staff,finance,purchasing,admin')
        ->group(function () {
            Route::post('/{purchaseRequest}', [ReceivingController::class, 'store'])->name('store');
        });

    Route::prefix('admin')
        ->name('admin.')
        ->middleware('role:admin')
        ->group(function () {
            Route::get('/data', [DataManagementController::class, 'index'])->name('data.index');
            Route::get('/transactions/{purchaseRequest}/delete', [TransactionDeletionController::class, 'show'])->name('transactions.delete');
            Route::delete('/transactions/{purchaseRequest}', [TransactionDeletionController::class, 'destroy'])->name('transactions.destroy');
            Route::delete('/data/{type}/{id}', [DataManagementController::class, 'destroy'])->name('data.destroy');
            Route::resource('accounts', AccountController::class)->except(['destroy']);
            Route::patch('/accounts/{account}/status', [AccountController::class, 'status'])
                ->name('accounts.status');
            Route::get('/po-workflow-assignments', [PurchaseOrderWorkflowAssignmentController::class, 'index'])->name('po-workflow-assignments.index');
            Route::put('/po-workflow-assignments', [PurchaseOrderWorkflowAssignmentController::class, 'update'])->name('po-workflow-assignments.update');
            Route::resource('campaigns', CampaignController::class)->except(['destroy']);
            Route::patch('/campaigns/{campaign}/status', [CampaignController::class, 'status'])
                ->name('campaigns.status');
            Route::get('/system-health', SystemHealthController::class)->name('system-health');
        });

    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
