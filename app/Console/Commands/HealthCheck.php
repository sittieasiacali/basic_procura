<?php

namespace App\Console\Commands;

use App\Notifications\SystemHealthAlert;
use App\Services\SystemHealthCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class HealthCheck extends Command
{
    protected $signature = 'health:check {--notify : Email HEALTH_ALERT_EMAIL when any check is red}';

    protected $description = 'Run the read-only System Health checks and optionally email an alert when something is red.';

    public function handle(SystemHealthCheck $health): int
    {
        $checks = $health->run();

        $this->table(['Check', 'Status', 'Summary'], array_map(fn ($c) => [
            $c['label'],
            ['ok' => 'OK', 'warn' => 'ATTENTION'][$c['status']] ?? 'PROBLEM',
            $c['summary'],
        ], $checks));

        $problems = array_values(array_filter($checks, fn ($c) => $c['status'] === SystemHealthCheck::FAIL));

        if ($this->option('notify') && $problems !== []) {
            $email = config('procurement.health.alert_email');
            if ($email === null) {
                $this->warn('HEALTH_ALERT_EMAIL is not set, so no alert was sent.');
            } else {
                // Sent directly, not queued: a broken queue is one of the things being reported.
                Notification::route('mail', $email)->notifyNow(new SystemHealthAlert($problems));
                $this->info("Alert sent to {$email}.");
            }
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
