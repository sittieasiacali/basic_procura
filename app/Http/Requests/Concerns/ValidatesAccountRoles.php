<?php

namespace App\Http\Requests\Concerns;

use App\Enums\RoleSlug;
use App\Models\Role;

trait ValidatesAccountRoles
{
    protected function prepareRoleSelection(): void
    {
        // Keep compatibility with existing single-role clients.
        $ids = $this->input('role_ids', $this->has('role_selection') ? [] : [$this->input('role_id')]);
        $primary = is_array($ids) ? ($ids[0] ?? null) : null;
        $existing = $this->route('account')?->role_id;
        if (is_array($ids) && $existing && in_array((string) $existing, array_map(fn ($id) => is_scalar($id) ? (string) $id : '', $ids), true)) {
            $primary = $existing;
        }
        $this->merge(['role_ids' => $ids, 'role_id' => $primary]);
    }

    private function roleRequiresCampaign(): bool
    {
        $ids = $this->input('role_ids');

        return is_array($ids) && Role::query()->whereIn('id', array_filter($ids, 'is_scalar'))
            ->whereIn('slug', [RoleSlug::Requestor->value, RoleSlug::TeamLeader->value])->exists();
    }
}
