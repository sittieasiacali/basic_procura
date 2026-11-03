<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\RoleSlug;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable
{
    private ?Role $workspaceRole = null;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role_id',
        'campaign_id',
        'name',
        'employee_id',
        'username',
        'team_id',
        'email',
        'om_notification_email',
        'smt_notification_email',
        'email_verified_at',
        'department',
        'is_active',
        'password',
        'must_change_password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** Includes the original role for accounts created before multi-role access. */
    public function assignedRoles(): Collection
    {
        return $this->roles->concat($this->role ? [$this->role] : [])->unique('id')->values();
    }

    public function activeRole(): ?Role
    {
        return $this->workspaceRole ?? $this->role;
    }

    public function useWorkspaceRole(?Role $role): void
    {
        $this->workspaceRole = $role;
    }

    public function hasAssignedRole(string $slug): bool
    {
        return $this->assignedRoles()->contains('slug', $slug);
    }

    public function scopeWithAssignedRole(Builder $query, int $roleId): Builder
    {
        return $query->where(fn (Builder $roles) => $roles->where('role_id', $roleId)
            ->orWhereHas('roles', fn (Builder $role) => $role->where('roles.id', $roleId)));
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class, 'requestor_id');
    }

    public function poRoleAssignments(): HasMany
    {
        return $this->hasMany(PoRoleAssignment::class);
    }

    public function hasPoRole(PurchaseOrderApprovalStage $stage): bool
    {
        $roleStage = match ($this->activeRole()?->slug) {
            RoleSlug::PoChecker->value => PurchaseOrderApprovalStage::CheckedBy,
            RoleSlug::PoNoted->value => PurchaseOrderApprovalStage::NotedBy,
            RoleSlug::PoApprover->value => PurchaseOrderApprovalStage::ApprovedBy,
            default => null,
        };

        if ($roleStage !== null) {
            return $roleStage === $stage;
        }

        if ($stage === PurchaseOrderApprovalStage::PreparedBy && $this->hasRole(RoleSlug::PurchasingTeam->value)) {
            return true;
        }

        // Legacy PO assignments remain available in their original workspace.
        return $this->assignedRoles()->count() === 1
            && $this->activeRole()?->id === $this->role_id
            && $this->poRoleAssignments()->where('stage', $stage->value)->exists();
    }

    public function hasRole(string $role): bool
    {
        return $this->activeRole()?->slug === $role;
    }

    /** @param list<string> $roles */
    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->activeRole()?->slug, $roles, true);
    }

    /** Priority level is a Budget Request and payment concern; other roles never see it. */
    public function canViewPriority(): bool
    {
        return $this->hasAnyRole([
            RoleSlug::ItStaff->value,
            RoleSlug::PremisesStaff->value,
            RoleSlug::BudgetMaker->value,
            RoleSlug::BudgetChecker->value,
            RoleSlug::BudgetProceeder->value,
            RoleSlug::Finance->value,
        ]);
    }
}
