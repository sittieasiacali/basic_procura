<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isAdministrator() || Supplier::workspaceTeam($user) !== null);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user) && ($user->isAdministrator() || $supplier->team === Supplier::workspaceTeam($user));
    }
}
