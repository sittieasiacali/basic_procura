<?php

namespace App\Policies;

use App\Enums\RoleSlug;
use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole(RoleSlug::Admin->value) ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return false;
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return false;
    }
}
