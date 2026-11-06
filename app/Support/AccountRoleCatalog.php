<?php

namespace App\Support;

use App\Enums\RoleSlug;
use App\Models\Role;
use Illuminate\Support\Collection;

final class AccountRoleCatalog
{
    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            RoleSlug::PurchasingTeam->value => 'PURCHASING',
            RoleSlug::Finance->value => 'FINANCE',
            RoleSlug::Requestor->value => 'TL / REQUESTOR',
            RoleSlug::ItStaff->value => 'IT',
            RoleSlug::PremisesStaff->value => 'PREMISES',
            RoleSlug::PoChecker->value => 'PO CHECKER',
            RoleSlug::PoNoted->value => 'PO NOTED',
            RoleSlug::PoApprover->value => 'PO APPROVER',
            RoleSlug::Admin->value => 'ADMIN',
        ];
    }

    public static function options(): Collection
    {
        $roles = Role::query()->whereIn('slug', array_keys(self::labels()))->get()->keyBy('slug');

        return collect(self::labels())->map(function (string $label, string $slug) use ($roles) {
            $role = $roles->get($slug);

            return $role ? ['id' => $role->id, 'slug' => $slug, 'label' => $label] : null;
        })->filter()->values();
    }

    public static function label(?Role $role): string
    {
        if ($role === null) {
            return 'Unassigned';
        }

        return self::labels()[$role->slug] ?? match ($role->slug) {
            RoleSlug::TeamLeader->value => 'TL / REQUESTOR',
            default => $role->name,
        };
    }
}
