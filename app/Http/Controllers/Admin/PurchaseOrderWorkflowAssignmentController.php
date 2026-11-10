<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PurchaseOrderApprovalStage;
use App\Http\Controllers\Controller;
use App\Models\PoRoleAssignment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchaseOrderWorkflowAssignmentController extends Controller
{
    public function index(): View
    {
        return view('admin.purchase-order-workflow-assignments', [
            'assignments' => PoRoleAssignment::query()->with('user')->get()->keyBy('stage'),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $stages = PurchaseOrderApprovalStage::cases();
        $validated = $request->validate([
            'assignments' => ['required', 'array'],
            'assignments.*' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
        ]);

        foreach ($stages as $stage) {
            abort_unless(array_key_exists($stage->value, $validated['assignments']), 422);
        }

        DB::transaction(function () use ($validated, $stages): void {
            foreach ($stages as $stage) {
                PoRoleAssignment::query()->updateOrCreate(
                    ['stage' => $stage->value],
                    ['user_id' => $validated['assignments'][$stage->value]],
                );
            }
        });

        return back()->with('success', 'PO-only workflow assignments saved. Existing Purchase Request roles were not changed.');
    }
}
