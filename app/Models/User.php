<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\RoleSlug;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
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
        $roleStage = match ($this->role?->slug) {
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

        return $this->poRoleAssignments()->where('stage', $stage->value)->exists();
    }

    public function hasRole(string $role): bool
    {
        return $this->role?->slug === $role;
    }

    /** @param list<string> $roles */
    public function hasAnyRole(array $roles): bool
    {
        return $this->role !== null && in_array($this->role->slug, $roles, true);
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
