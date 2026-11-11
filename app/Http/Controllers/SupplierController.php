<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Supplier::class);
        $search = trim((string) $request->query('search', ''));

        $teams = $this->teams($request);
        $request->validate(['team' => ['nullable', 'string', Rule::in(array_keys($teams))]]);
        $team = $request->query('team') ?: Supplier::workspaceTeam($request->user());

        return view('suppliers.index', [
            'suppliers' => Supplier::query()->forWorkspace($request->user())
                ->when($team, fn ($query) => $query->where('team', $team))
                ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                ->withCount('quotations')->orderBy('name')->paginate(20)->withQueryString(),
            'search' => $search,
            'team' => $team,
            'teams' => $teams,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);
        $values = $this->validated($request);
        DB::transaction(function () use ($request, $values): void {
            $supplier = Supplier::create($values);
            $supplier->auditLogs()->create(['user_id' => $request->user()->id, 'action' => 'SUPPLIER_CREATED', 'remarks' => 'Supplier created.']);
        });

        return back()->with('success', 'Supplier added.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);
        $values = $this->validated($request, $supplier);
        DB::transaction(function () use ($request, $supplier, $values): void {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $this->authorize('update', $supplier);
            $before = $supplier->only(array_keys($values));
            $supplier->update($values);
            $supplier->auditLogs()->create(['user_id' => $request->user()->id, 'action' => 'SUPPLIER_UPDATED', 'remarks' => 'Supplier details or availability updated.', 'metadata' => ['before' => $before, 'after' => $values]]);
        });

        return back()->with('success', 'Supplier updated.');
    }

    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $team = $supplier?->team ?? $request->input('team', Supplier::workspaceTeam($request->user()) ?? 'PURCHASING');
        $request->merge(['name' => trim((string) $request->input('name')), 'team' => $request->input('team', $team)]);

        $validatedTeam = $request->validate([
            'team' => ['required', 'string', Rule::in($supplier ? [$supplier->team] : array_keys($this->teams($request)))],
        ]);

        return [...$validatedTeam, ...$request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers')->where('team', $team)->ignore($supplier?->id)],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'tax_id' => ['nullable', 'string', 'max:255'],
            'bank_details' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ])];
    }

    private function teams(Request $request): array
    {
        return $request->user()->isAdministrator()
            ? Supplier::TEAMS
            : array_intersect_key(Supplier::TEAMS, array_flip([Supplier::workspaceTeam($request->user())]));
    }
}
