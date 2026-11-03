<?php

namespace App\Policies;

use App\Enums\RoleSlug;
use App\Models\InventoryItem;
use App\Models\InventoryRelease;
use App\Models\User;

/**
 * Received goods enter the inventory automatically (Received status). Finance can only view it.
 * IT and Premises each manage their own stock; central inventory roles can see both.
 */
class InventoryItemPolicy
{
    private const RECEIVERS = [
        RoleSlug::Finance->value,
        RoleSlug::Inventory->value,
        RoleSlug::InventoryStaff->value,
        RoleSlug::Admin->value,
    ];

    private const RELEASERS = [
        RoleSlug::Inventory->value,
        RoleSlug::InventoryStaff->value,
        RoleSlug::ItStaff->value,
        RoleSlug::PremisesStaff->value,
        RoleSlug::Admin->value,
    ];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(array_unique([...self::RECEIVERS, ...self::RELEASERS]));
    }

    /** Manual stock entry (items that did not arrive through a purchase). */
    public function create(User $user): bool
    {
        return $user->hasAnyRole([RoleSlug::ItStaff->value, RoleSlug::PremisesStaff->value, RoleSlug::Admin->value]);
    }

    public function release(User $user, InventoryItem $inventoryItem): bool
    {
        $prefix = InventoryItem::prefixForUser($user);

        return $user->hasAnyRole(self::RELEASERS)
            && ($prefix === null || str_starts_with((string) $inventoryItem->sku, $prefix.'-'))
            && (float) $inventoryItem->quantity > 0;
    }

    public function linkRecipient(User $user, InventoryRelease $release): bool
    {
        $prefix = InventoryItem::prefixForUser($user);

        return $user->hasAnyRole(self::RELEASERS)
            && ($prefix === null || str_starts_with((string) $release->inventoryItem->sku, $prefix.'-'));
    }

}
