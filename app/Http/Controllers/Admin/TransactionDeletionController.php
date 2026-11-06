<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Services\AdminRecordDeletion;
use App\Services\AdminTransactionRecords;
use App\Support\AdminRecordCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TransactionDeletionController extends Controller
{
    public const RETURN_ROUTES = [
        'dashboard', 'purchase-requests.index', 'president-approvals.index', 'budget.index',
        'workflow-tracker.index', 'voided-monitoring.index', 'inventory.index', 'admin.data.index',
    ];

    public function show(Request $request, PurchaseRequest $purchaseRequest, AdminTransactionRecords $transactions): View
    {
        abort_unless($request->user()->isAdministrator(), 403);
        $validated = $request->validate(['return_to' => ['nullable', Rule::in(self::RETURN_ROUTES)]]);
        $blocked = null;
        $records = collect();
        try {
            $records = $transactions->collect($purchaseRequest);
        } catch (ValidationException $exception) {
            $blocked = $exception->validator->errors()->first();
        }
        $labels = AdminRecordCatalog::labels();
        $types = array_flip(AdminRecordCatalog::models());

        return view('admin.data.delete-transaction', [
            'purchaseRequest' => $purchaseRequest,
            'counts' => $records->groupBy(fn ($record) => $labels[$types[$record::class]])->map->count(),
            'stockRestorations' => $transactions->stockRestorations($records),
            'planToken' => $transactions->token($records),
            'blocked' => $blocked,
            'returnTo' => $validated['return_to'] ?? 'purchase-requests.index',
        ]);
    }

    public function destroy(Request $request, PurchaseRequest $purchaseRequest, AdminRecordDeletion $deletion): RedirectResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'confirmation' => ['required', 'accepted'],
            'plan_token' => ['required', 'string', 'size:64'],
            'return_to' => ['required', Rule::in(self::RETURN_ROUTES)],
        ]);
        try {
            $result = $deletion->delete($purchaseRequest, $request->user(), $validated['reason'], true, $validated['plan_token']);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23503'], true)) {
                throw ValidationException::withMessages(['record' => 'Linked data is still used by another transaction. Separate those records before deleting.']);
            }
            throw $exception;
        }

        return redirect()->route($validated['return_to'])
            ->with('success', $result['label'].' and its linked workflow records deleted. Notifications queued for the requestor and assigned OM/SMT.');
    }
}
