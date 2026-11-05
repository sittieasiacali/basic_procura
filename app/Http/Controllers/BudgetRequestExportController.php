<?php

namespace App\Http\Controllers;

use App\Models\BudgetRequest;
use App\Models\PurchaseRequest;
use App\Support\WorkflowRemarks;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export of the Budget Request register: every row of the current tab and filters.
 * Excel opens it directly.
 */
class BudgetRequestExportController extends Controller
{
    public function __invoke(Request $request, WorkflowRemarks $workflowRemarks): StreamedResponse
    {
        $filters = $request->validate([
            'stage' => ['nullable', Rule::in(['finance', 'finalized'])],
            'register_tab' => ['nullable', Rule::in(['created', 'approved'])],
            'maker_only' => ['nullable', 'boolean'],
            'br_search' => ['nullable', 'string', 'max:200'],
            'transaction_type' => ['nullable', Rule::in(['ONLINE', 'CASH', 'CHECK'])],
        ]);

        $budgetRequests = BudgetRequest::query()
            ->with(['campaign', 'custodian', 'lines.purchaseOrder.supplier', 'lines.purchaseOrder.purchaseRequest', 'lines.campaign', 'purchaseRequest'])
            ->when(($filters['register_tab'] ?? null) === 'approved', fn ($query) => $query->finallyApproved())
            ->when($filters['maker_only'] ?? false, fn ($query) => $query->whereNotNull('maker_approved_at'))
            ->forStage($filters['stage'] ?? null)
            ->when($filters['transaction_type'] ?? null, fn ($query, string $type) => $query->where('payment_method', $type))
            ->registerSearch(trim($filters['br_search'] ?? ''))
            ->latest('created_at')
            ->get();

        $label = fn (?string $value) => $value ? (string) str($value)->replace('_', ' ')->title() : '';
        $workflowRemarks->prepareRecords($budgetRequests);
        $preparationExport = ($filters['stage'] ?? null) === 'finalized' || ($filters['register_tab'] ?? null) === 'approved';

        return response()->streamDownload(function () use ($budgetRequests, $label, $preparationExport, $workflowRemarks): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel keeps names intact.
            if ($preparationExport) {
                fputcsv($out, ['Priority Level', 'Campaign', 'Details', 'Amount', 'Campaign2', 'Mode of Payment', 'Bank Details (for Deposit)', 'Requesting Team', 'Date to Pay', 'Remarks', 'BR Number']);
                foreach ($budgetRequests as $budgetRequest) {
                    foreach ($budgetRequest->lines as $line) {
                        $line->setRelation('budgetRequest', $budgetRequest);
                        $preparation = $line->preparationData();
                        // One Priority Level per BR, as on screen and in the document.
                        $budgetPriority = $budgetRequest->priorityLevel();
                        $preparation['priority'] = $budgetPriority === 'NORMAL' ? 'Medium' : PurchaseRequest::priorityLabel($budgetPriority);
                        $preparation['remarks'] = $workflowRemarks->textForRequest($line->purchaseOrder?->purchase_request_id) ?: $preparation['remarks'];
                        $row = [...array_values($preparation), $budgetRequest->displayNumber()];
                        // Manual text must stay text when the CSV is opened in Excel.
                        fputcsv($out, array_map(fn ($value) => preg_match('/^[=+@\-\t\r]/', (string) $value) ? "'".$value : $value, $row));
                    }
                }
                fclose($out);

                return;
            }
            fputcsv($out, [
                'BR Number', 'Stage', 'PO Number', 'Supplier', 'Purchase Request', 'Title',
                'Campaign Source Account', 'Account Number', 'Account Type', 'Transaction Type',
                'Fund Source', 'Main Fund', 'When Can Pay', 'Amount',
                'Prepared At', 'Finalized At', 'Remarks',
            ]);

            foreach ($budgetRequests as $budgetRequest) {
                $orders = $budgetRequest->lines->pluck('purchaseOrder')->filter()->unique('id');
                $row = [
                    $budgetRequest->budget_request_number,
                    $budgetRequest->stage()[0],
                    $orders->pluck('po_number')->join('; '),
                    $orders->map(fn ($order) => $order->supplier?->name)->filter()->unique()->join('; '),
                    $budgetRequest->purchaseRequest?->request_number,
                    $budgetRequest->purchaseRequest?->title,
                    $budgetRequest->lines->map(fn ($line) => $line->campaign?->name ?? $budgetRequest->campaign?->name)->filter()->unique()->join('; '),
                    $budgetRequest->lines->map(fn ($line) => $line->source_bank_account ?: $budgetRequest->campaign_account_number)->filter()->unique()->join('; ') ?: $budgetRequest->campaign_account_number,
                    $label($budgetRequest->account_type),
                    $label($budgetRequest->payment_method),
                    $label($budgetRequest->fund_source),
                    $budgetRequest->main_fund,
                    $budgetRequest->payable_on?->format('Y-m-d'),
                    number_format((float) $budgetRequest->amount, 2, '.', ''),
                    $budgetRequest->maker_approved_at?->format('Y-m-d H:i'),
                    $budgetRequest->final_approval_at?->format('Y-m-d H:i'),
                    collect($budgetRequest->lines->all())->map(fn ($line) => $line->purchaseOrder?->purchase_request_id)
                        ->push($budgetRequest->purchase_request_id)->filter()->unique()
                        ->map(fn ($id) => $workflowRemarks->textForRequest((int) $id))->filter()->join("\n\n"),
                ];
                fputcsv($out, array_map(fn ($value) => preg_match('/^[=+@\-\t\r]/', (string) $value) ? "'".$value : $value, $row));
            }

            fclose($out);
        }, 'budget-requests-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
