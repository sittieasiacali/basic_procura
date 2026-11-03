<?php

namespace App\Policies;

use App\Enums\RoleSlug;
use App\Models\DeliveryLocation;
use App\Models\User;

class DeliveryLocationPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole(RoleSlug::Admin->value) ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, DeliveryLocation $deliveryLocation): bool
    {
        return false;
    }

    public function delete(User $user, DeliveryLocation $deliveryLocation): bool
    {
        return false;
    }
}
