<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use HasFactory, SoftDeletes;

    public const BUDGET_ACCOUNT_OPTIONS = [
        'AC MOBILITY' => '8670030506',
        'AIA 1' => '008678008781',
        'AIA 2' => '8670060898',
        'AXA' => '8670058818',
        'BDO - CCC' => '8670043888',
        'BLUE 3' => '8670078088',
        'BLUE 4' => '8670009108',
        'BPI' => '008670080988',
        'CBC' => '8670047808',
        'E-GOV_AC MOBILITY' => '8670039880',
        'E-GOV_AIA 1' => '8670039880',
        'E-GOV_AIA 2' => '8670039880',
        'E-GOV_AXA' => '8670039880',
        'E-GOV_BDO CCC' => '8670039880',
        'E-GOV_BLUE 3' => '8670039880',
        'E-GOV_BLUE 4' => '8670039880',
        'E-GOV_BPI' => '8670039880',
        'E-GOV_CBC' => '8670039880',
        'E-GOV_GLOBE' => '8670039880',
        'GLOBE' => '008670012788',
        'MB - ALLIANZ' => '008670080880',
        'MEDICARD INBOUND' => '008670080538',
        'RBSC*SLGI' => '8670080554',
        'CBC - CCC' => '008670060898',
    ];

    protected $fillable = ['name', 'account_number', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function activeUsers(): HasMany
    {
        return $this->hasMany(User::class)->where('is_active', true);
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }
}
