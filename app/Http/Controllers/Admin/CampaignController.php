<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCampaignRequest;
use App\Http\Requests\UpdateCampaignRequest;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CampaignController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Campaign::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', Rule::in(['0', '1'])],
        ]);

        $campaigns = Campaign::query()
            ->withCount(['users', 'activeUsers'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when(array_key_exists('is_active', $filters), fn ($query) => $query->where('is_active', $filters['is_active']))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.campaigns.index', compact('campaigns'));
    }

    public function create(): View
    {
        $this->authorize('create', Campaign::class);

        return view('admin.campaigns.create');
    }

    public function store(StoreCampaignRequest $request): RedirectResponse
    {
        $campaign = DB::transaction(function () use ($request) {
            $campaign = Campaign::query()->create($request->validated());
            $this->audit($campaign, $request->user(), 'CAMPAIGN_CREATED', null, $campaign->is_active ? 'ACTIVE' : 'INACTIVE', [
                'name' => $campaign->name,
            ]);

            return $campaign;
        });

        return redirect()->route('admin.campaigns.show', $campaign)
            ->with('success', 'Campaign created successfully.');
    }

    public function show(Campaign $campaign): View
    {
        $this->authorize('view', $campaign);
        $campaign->load(['auditLogs.user'])->loadCount(['users', 'activeUsers']);
        $users = $campaign->users()->with('role')->orderBy('name')->paginate(15);

        return view('admin.campaigns.show', compact('campaign', 'users'));
    }

    public function edit(Campaign $campaign): View
    {
        $this->authorize('update', $campaign);
        $campaign->loadCount('activeUsers');

        return view('admin.campaigns.edit', compact('campaign'));
    }

    public function update(UpdateCampaignRequest $request, Campaign $campaign): RedirectResponse
    {
        DB::transaction(function () use ($request, $campaign) {
            $lockedCampaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $this->authorize('update', $lockedCampaign);
            $validated = $request->validated();
            $before = $lockedCampaign->only(['name', 'account_number', 'description', 'is_active']);

            if ($lockedCampaign->is_active && ! $validated['is_active']) {
                $this->ensureCampaignCanBeDeactivated($lockedCampaign);
            }

            $lockedCampaign->update($validated);
            $after = $lockedCampaign->only(['name', 'account_number', 'description', 'is_active']);
            $this->audit($lockedCampaign, $request->user(), 'CAMPAIGN_UPDATED', null, null, [
                'changed_fields' => array_keys(array_diff_assoc($after, $before)),
            ]);

            if ($before['is_active'] !== $after['is_active']) {
                $this->audit(
                    $lockedCampaign,
                    $request->user(),
                    $after['is_active'] ? 'CAMPAIGN_ACTIVATED' : 'CAMPAIGN_DEACTIVATED',
                    $before['is_active'] ? 'ACTIVE' : 'INACTIVE',
                    $after['is_active'] ? 'ACTIVE' : 'INACTIVE',
                );
            }
        });

        return redirect()->route('admin.campaigns.show', $campaign)
            ->with('success', 'Campaign updated successfully.');
    }

    public function status(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorize('update', $campaign);

        DB::transaction(function () use ($request, $campaign) {
            $lockedCampaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $newStatus = ! $lockedCampaign->is_active;

            if (! $newStatus) {
                $this->ensureCampaignCanBeDeactivated($lockedCampaign);
            }

            $previous = $lockedCampaign->is_active ? 'ACTIVE' : 'INACTIVE';
            $lockedCampaign->forceFill(['is_active' => $newStatus])->save();
            $this->audit(
                $lockedCampaign,
                $request->user(),
                $newStatus ? 'CAMPAIGN_ACTIVATED' : 'CAMPAIGN_DEACTIVATED',
                $previous,
                $newStatus ? 'ACTIVE' : 'INACTIVE',
            );
        });

        return back()->with('success', $campaign->is_active ? 'Campaign deactivated.' : 'Campaign activated.');
    }

    private function ensureCampaignCanBeDeactivated(Campaign $campaign): void
    {
        $activeUsers = $campaign->activeUsers()->count();

        if ($activeUsers > 0) {
            throw ValidationException::withMessages([
                'is_active' => "Reassign or deactivate the {$activeUsers} active account(s) assigned to this campaign before deactivating it.",
            ]);
        }
    }

    /** @param array<string, mixed>|null $metadata */
    private function audit(
        Campaign $campaign,
        User $actor,
        string $action,
        ?string $previousStatus = null,
        ?string $newStatus = null,
        ?array $metadata = null,
    ): void {
        $campaign->auditLogs()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'remarks' => "{$action}: {$campaign->name}",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => $metadata,
        ]);
    }
}
