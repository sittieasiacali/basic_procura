<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RoleSlug;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountRoleCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')],
            'is_active' => ['nullable', Rule::in(['0', '1'])],
        ]);

        $accounts = User::query()
            ->with(['role', 'campaign'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('employee_id', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            })
            ->when($filters['role_id'] ?? null, fn ($query, $roleId) => $query->where('role_id', $roleId))
            ->when($filters['campaign_id'] ?? null, fn ($query, $campaignId) => $query->where('campaign_id', $campaignId))
            ->when(filled($filters['is_active'] ?? null), fn ($query) => $query->where('is_active', $filters['is_active']))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.accounts.index', [
            'accounts' => $accounts,
            'roles' => Role::query()->orderBy('name')->get(),
            'campaigns' => Campaign::query()->orderBy('name')->get(),
        ]);
    }

    public function create(): RedirectResponse
    {
        $this->authorize('create', User::class);

        return redirect()->route('admin.accounts.index', ['create_user' => 1]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $account = DB::transaction(function () use ($request, $validated) {
            $account = User::query()->create([
                'name' => $validated['name'],
                'employee_id' => $validated['employee_id'],
                'username' => $validated['username'] ?? null,
                'team_id' => $validated['team_id'] ?? null,
                'email' => $validated['email'],
                'om_notification_email' => $validated['om_notification_email'] ?? null,
                'smt_notification_email' => $validated['smt_notification_email'] ?? null,
                'password' => Hash::make($validated['password']),
                'must_change_password' => true,
                'role_id' => $validated['role_id'],
                'campaign_id' => $validated['campaign_id'] ?? null,
                'is_active' => $validated['is_active'],
                'email_verified_at' => now(),
            ]);

            $this->audit($account, $request->user(), 'ACCOUNT_CREATED', null, $account->is_active ? 'ACTIVE' : 'INACTIVE', [
                'role_id' => $account->role_id,
                'campaign_id' => $account->campaign_id,
                'om_notification_email' => $account->om_notification_email,
                'smt_notification_email' => $account->smt_notification_email,
            ]);

            return $account;
        });

        $destination = match ($validated['account_origin'] ?? null) {
            'dashboard' => redirect()->route('dashboard')->withFragment('account-monitoring-heading'),
            'accounts' => redirect()->route('admin.accounts.index'),
            default => redirect()->route('admin.accounts.show', $account),
        };

        return $destination->with('success', 'Account created successfully. The user must change their temporary password after signing in.');
    }

    public function show(User $account): View
    {
        $this->authorize('view', $account);
        $account->load(['role', 'campaign', 'auditLogs.user']);

        return view('admin.accounts.show', compact('account'));
    }

    public function edit(User $account): View
    {
        $this->authorize('update', $account);

        return view('admin.accounts.edit', [
            'account' => $account->load(['role', 'campaign']),
            ...$this->formOptions($account),
        ]);
    }

    public function update(UpdateAccountRequest $request, User $account): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($request, $account, $validated) {
            $lockedAccount = User::query()->lockForUpdate()->findOrFail($account->id);
            $this->authorize('update', $lockedAccount);
            $before = $lockedAccount->only(['name', 'email', 'employee_id', 'username', 'team_id', 'role_id', 'campaign_id', 'is_active', 'om_notification_email', 'smt_notification_email']);

            $this->ensureActiveAdminRemains(
                $lockedAccount,
                (int) $validated['role_id'],
                (bool) $validated['is_active'],
            );

            $lockedAccount->fill([
                'employee_id' => $validated['employee_id'] ?? null,
                'username' => $validated['username'] ?? null,
                'team_id' => $validated['team_id'] ?? null,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'om_notification_email' => $validated['om_notification_email'] ?? null,
                'smt_notification_email' => $validated['smt_notification_email'] ?? null,
                'role_id' => $validated['role_id'],
                'campaign_id' => $validated['campaign_id'] ?? null,
                'is_active' => $validated['is_active'],
            ]);

            if (! empty($validated['password'])) {
                $lockedAccount->password = Hash::make($validated['password']);
                $lockedAccount->must_change_password = true;
            }

            $lockedAccount->save();
            $after = $lockedAccount->only(['name', 'email', 'employee_id', 'username', 'team_id', 'role_id', 'campaign_id', 'is_active', 'om_notification_email', 'smt_notification_email']);
            $changedFields = array_keys(array_diff_assoc($after, $before));

            $this->audit($lockedAccount, $request->user(), 'ACCOUNT_UPDATED', null, null, [
                'changed_fields' => $changedFields,
            ]);

            if ($before['role_id'] !== $after['role_id']) {
                $this->audit($lockedAccount, $request->user(), 'ROLE_CHANGED', null, null, [
                    'from_role_id' => $before['role_id'],
                    'to_role_id' => $after['role_id'],
                ]);
            }

            if ($before['campaign_id'] !== $after['campaign_id']) {
                $this->audit($lockedAccount, $request->user(), 'CAMPAIGN_CHANGED', null, null, [
                    'from_campaign_id' => $before['campaign_id'],
                    'to_campaign_id' => $after['campaign_id'],
                ]);
            }

            if ($before['is_active'] !== $after['is_active']) {
                $this->audit(
                    $lockedAccount,
                    $request->user(),
                    $after['is_active'] ? 'ACCOUNT_ACTIVATED' : 'ACCOUNT_DEACTIVATED',
                    $before['is_active'] ? 'ACTIVE' : 'INACTIVE',
                    $after['is_active'] ? 'ACTIVE' : 'INACTIVE',
                );
            }

            if (! empty($validated['password'])) {
                $this->audit($lockedAccount, $request->user(), 'PASSWORD_RESET');
            }
        });

        return redirect()->route('admin.accounts.show', $account)
            ->with('success', 'Account updated successfully.');
    }

    public function status(Request $request, User $account): RedirectResponse
    {
        $this->authorize('update', $account);

        DB::transaction(function () use ($request, $account) {
            $lockedAccount = User::query()->lockForUpdate()->findOrFail($account->id);
            $newStatus = ! $lockedAccount->is_active;

            $this->ensureActiveAdminRemains($lockedAccount, (int) $lockedAccount->role_id, $newStatus);

            $previous = $lockedAccount->is_active ? 'ACTIVE' : 'INACTIVE';
            $lockedAccount->forceFill(['is_active' => $newStatus])->save();
            $this->audit(
                $lockedAccount,
                $request->user(),
                $newStatus ? 'ACCOUNT_ACTIVATED' : 'ACCOUNT_DEACTIVATED',
                $previous,
                $newStatus ? 'ACTIVE' : 'INACTIVE',
            );
        });

        return back()->with('success', $account->is_active ? 'Account deactivated.' : 'Account activated.');
    }

    /** @return array{roles: Collection, campaigns: Collection} */
    private function formOptions(?User $account = null): array
    {
        $campaigns = Campaign::query()
            ->where(function ($query) use ($account) {
                $query->where('is_active', true);

                if ($account?->campaign_id) {
                    $query->orWhere('campaigns.id', $account->campaign_id);
                }
            })
            ->orderBy('name')
            ->get();

        return [
            'roles' => Role::query()->where(function ($query) use ($account) {
                $query->whereIn('slug', array_keys(AccountRoleCatalog::labels()));
                if ($account?->role_id) {
                    $query->orWhere('id', $account->role_id);
                }
            })->orderBy('name')->get(),
            'campaigns' => $campaigns,
        ];
    }

    private function ensureActiveAdminRemains(User $account, int $newRoleId, bool $newActive): void
    {
        if (! $account->is_active || ! $account->hasRole(RoleSlug::Admin->value)) {
            return;
        }

        $adminRoleId = Role::query()->where('slug', RoleSlug::Admin->value)->value('id');
        $removesActiveAdmin = ! $newActive || $newRoleId !== (int) $adminRoleId;

        if ($removesActiveAdmin && User::query()->where('role_id', $adminRoleId)->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages([
                'is_active' => 'The only active Admin cannot be deactivated or assigned a different role.',
            ]);
        }
    }

    /** @param array<string, mixed>|null $metadata */
    private function audit(
        User $account,
        User $actor,
        string $action,
        ?string $previousStatus = null,
        ?string $newStatus = null,
        ?array $metadata = null,
    ): void {
        $account->auditLogs()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'remarks' => "{$action}: {$account->name}",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => $metadata,
        ]);
    }
}
