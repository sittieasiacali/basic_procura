<?php

namespace App\Http\Middleware;

use App\Models\BudgetRequest;
use App\Models\PurchaseRequest;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class FlashWorkflowSubmission
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->isMethodSafe() || ! $request->hasSession()
            || ! $request->routeIs('purchase-requests.*', 'request-flow.*', 'canvassing.*', 'president-approvals.*', 'purchase-orders.*', 'budget.*', 'payments.*', 'receiving.*')
            || ! ($response instanceof RedirectResponse || ($response instanceof JsonResponse && $response->isSuccessful()))
            || in_array('errors', $request->session()->get('_flash.new', []), true)
            || in_array('error', $request->session()->get('_flash.new', []), true)) {
            return $response;
        }

        if ($request->routeIs('purchase-requests.store', 'purchase-requests.update') && $request->input('action') !== 'submit') {
            return $response;
        }

        // This decision opens quotation entry for the same screener; a success
        // popup would close the iframe before they can enter their quotations.
        if ($request->routeIs('request-flow.screening.store') && $request->input('decision') === 'UNAVAILABLE') {
            return $response;
        }

        $requests = $this->requests($request);
        if ($requests->isEmpty()) {
            return $response;
        }
        $first = $requests->first();
        $newFlash = $request->session()->get('_flash.new', []);
        $stage = in_array('workflow_next_stage', $newFlash, true)
            ? $request->session()->get('workflow_next_stage')
            : $this->stage($first);
        $notice = [
            'id' => (string) Str::uuid(),
            'user_id' => $request->user()?->id,
            'title' => 'Submission saved',
            'message' => in_array('success', $newFlash, true) ? $request->session()->get('success') : 'Your action was saved successfully.',
            'stage' => $requests->count() > 1 ? 'Workflow updated for '.$requests->count().' requests.' : $stage,
            'requests' => $requests->map(fn (PurchaseRequest $record) => [
                'code' => $record->request_number,
                'url' => route('purchase-requests.show', ['purchaseRequest' => $record->id, 'preview' => 1]),
                'stage' => $this->stage($record),
            ])->values()->all(),
        ];
        $request->session()->flash('workflow_submission_notice', $notice);
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data)) {
                $response->setData([...$data, 'workflow_submission_notice' => $notice]);
            }
        }

        return $response;
    }

    private function requests(Request $request): Collection
    {
        $records = collect();
        $bound = $request->route('purchaseRequest');
        if ($bound instanceof PurchaseRequest) {
            $records->push($bound->fresh(['purchaseOrder']));
        }
        $code = in_array('workflow_request_code', $request->session()->get('_flash.new', []), true)
            ? $request->session()->get('workflow_request_code') : null;
        if ($code) {
            $records->push(PurchaseRequest::with('purchaseOrder')->where('request_number', $code)->first());
        }
        if ($request->routeIs('budget.store') && $request->filled('purchase_request_id')) {
            $records->push(PurchaseRequest::with('purchaseOrder')->find($request->input('purchase_request_id')));
        }
        if ($request->routeIs('payments.batch.store') && is_array($request->input('ids'))) {
            $records = $records->concat(PurchaseRequest::with('purchaseOrder')->whereKey($request->input('ids'))->get());
        }
        if ($request->routeIs('budget.finalize.store') && is_array($request->input('ids'))) {
            foreach (BudgetRequest::whereKey($request->input('ids'))->with(['purchaseRequest.purchaseOrder', 'purchaseRequests.purchaseOrder'])->get() as $budget) {
                $records->push($budget->purchaseRequest);
                $records = $records->concat($budget->purchaseRequests);
            }
        }

        return $records->filter()->unique('id')->values();
    }

    private function stage(PurchaseRequest $request): string
    {
        if ($request->purchaseOrder && $request->status->value === 'FOR_BUDGET_PROCESSING') {
            return match ($request->purchaseOrder->status) {
                'FOR_CHECKING' => 'Next: Purchase Order checking',
                'FOR_ACKNOWLEDGEMENT' => 'Next: Purchase Order notation',
                'FOR_APPROVAL' => 'Next: Purchase Order approval',
                'APPROVED' => 'Next: Budget Request preparation',
                default => 'Purchase Order: '.str($request->purchaseOrder->status)->replace('_', ' ')->title(),
            };
        }

        return 'Current stage: '.str($request->status->value)->replace('_', ' ')->title();
    }
}
