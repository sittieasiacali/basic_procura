<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AccountMonitoring
{
    /** @return array<string, mixed>|null */
    public static function for(User $user, Request $request): ?array
    {
        if ($user->cannot('viewAny', User::class)) {
            return null;
        }

        $filters = $request->validate([
            'accounts_search' => ['nullable', 'string', 'max:200'],
            'accounts_role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'accounts_status' => ['nullable', Rule::in(['0', '1'])],
        ]);
        $search = trim($filters['accounts_search'] ?? '');
        $roles = Role::query()->withCount([
            'users as total_accounts',
            'users as active_accounts' => fn (Builder $query) => $query->where('is_active', true),
        ])->orderBy('name')->orderBy('id')->get();

        $totals = User::query()->selectRaw('COUNT(*) AS total_accounts, COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_accounts')->first();
        $accounts = User::query()
            ->with(['role:id,name', 'campaign:id,name'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $query) => $query->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('employee_id', 'like', $term)->orWhere('username', 'like', $term));
            })
            ->when($filters['accounts_role_id'] ?? null, fn (Builder $query, $roleId) => $query->where('role_id', $roleId))
            ->when(filled($filters['accounts_status'] ?? null), fn (Builder $query) => $query->where('is_active', $filters['accounts_status']))
            ->orderBy('name')->orderBy('id')
            ->paginate(8, ['*'], 'accounts_page')
            ->withQueryString();

        return [
            'roles' => $roles,
            'accounts' => $accounts,
            'filters' => $filters,
            'total' => (int) $totals->total_accounts,
            'active' => (int) $totals->active_accounts,
            'inactive' => (int) $totals->total_accounts - (int) $totals->active_accounts,
            'rolesInUse' => $roles->where('total_accounts', '>', 0)->count(),
        ];
    }
}
