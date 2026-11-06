<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminRecordDeletion;
use App\Support\AdminRecordCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DataManagementController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdministrator(), 403);
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(array_keys(AdminRecordCatalog::models()))],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $type = $filters['type'] ?? 'purchase-requests';
        $model = AdminRecordCatalog::model($type);
        $query = $model->newQuery();
        if (filled($search = $filters['search'] ?? null)) {
            $columns = array_intersect(Schema::getColumnListing($model->getTable()), [
                'name', 'title', 'description', 'request_number', 'po_number', 'budget_request_number',
                'email', 'sku', 'original_name', 'action',
            ]);
            $query->where(function ($query) use ($columns, $search, $model): void {
                $query->where($model->getKeyName(), $search);
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        return view('admin.data.index', [
            'type' => $type,
            'types' => AdminRecordCatalog::labels(),
            'records' => $query->orderByDesc($model->getKeyName())->paginate(20)->withQueryString(),
        ]);
    }

    public function destroy(Request $request, string $type, string $id, AdminRecordDeletion $deletion): RedirectResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'confirmation' => ['required', 'accepted'],
        ]);
        $record = AdminRecordCatalog::model($type)->newQuery()->findOrFail($id);
        try {
            $result = $deletion->delete($record, $request->user(), $validated['reason']);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23503'], true)) {
                throw ValidationException::withMessages(['record' => 'This record is still in use. Delete or reassign its linked records first.']);
            }
            throw $exception;
        }

        return redirect()->route('admin.data.index', ['type' => $type])
            ->with('success', $result['label'].' deleted.'.($result['email_count'] ? ' Deletion notifications queued for the requestor and assigned OM/SMT.' : ''));
    }
}
