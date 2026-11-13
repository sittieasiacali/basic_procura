<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Schema;

class NotificationSoundSetting extends Model
{
    public const TONES = [
        'chime' => 'Gentle chime', 'bell' => 'Bell', 'pulse' => 'Double beep',
        'marimba' => 'Marimba', 'sparkle' => 'Sparkle', 'doorbell' => 'Doorbell',
        'melody' => 'Morning melody', 'sonar' => 'Soft sonar',
    ];

    protected $fillable = ['tone', 'updated_by'];

    public static function currentTone(): string
    {
        // Pages remain usable while the additive migration is being deployed.
        if (! Schema::hasTable('notification_sound_settings')) {
            return 'chime';
        }

        $tone = static::query()->whereKey(1)->value('tone');

        return array_key_exists($tone ?? '', self::TONES) ? $tone : 'chime';
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }
}
