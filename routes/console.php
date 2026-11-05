<?php

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('local:reset-active-test-passwords', function (): int {
    $connection = config('database.default');
    $database = config("database.connections.{$connection}.database");
    $host = config("database.connections.{$connection}.host");
    $port = (int) config("database.connections.{$connection}.port");

    if (! app()->environment('local')
        || $connection !== 'mysql'
        || $host !== '127.0.0.1'
        || $port !== 3306
        || $database !== 'basic_procura') {
        $this->error('Refusing to reset passwords: this command only runs against local basic_procura at 127.0.0.1:3306.');

        return Command::FAILURE;
    }

    $isWindows = DIRECTORY_SEPARATOR === '\\';

    if ($isWindows) {
        $this->warn('Windows PowerShell compatibility mode displays the password in this private local console only; it is not logged or passed as an argument.');
        $password = $this->ask('Shared local test password (minimum 8 characters)');
        $confirmation = $this->ask('Confirm shared local test password');
    } else {
        $password = $this->secret('Shared local test password (minimum 8 characters)');
        $confirmation = $this->secret('Confirm shared local test password');
    }

    $validator = Validator::make([
        'password' => $password,
        'password_confirmation' => $confirmation,
    ], [
        'password' => ['required', 'string', 'min:8', 'same:password_confirmation'],
    ]);

    if ($validator->fails()) {
        $this->error('Password was not updated. It must be at least 8 characters and match the confirmation.');

        return Command::FAILURE;
    }

    $updated = DB::transaction(function () use ($password): int {
        $users = User::query()->where('is_active', true)->lockForUpdate()->get();

        foreach ($users as $user) {
            $user->forceFill(['password' => Hash::make($password)])->save();
        }

        return $users->count();
    });

    $this->info("Updated {$updated} active local test accounts.");

    return Command::SUCCESS;
})->purpose('Set one hidden, administrator-entered password for all active local Basic Procura test accounts.');
// One-off clean-up: Final budgets done before BRs were combined issued one BR per PO.
// Re-groups each Final budget action (same user, same moment) into one group BR.
Artisan::command('budget:combine-finalized {--dry-run : Only list what would be combined}', function (): int {
    $groups = \App\Models\BudgetRequest::query()
        ->where('final_approval_status', 'APPROVED')
        ->where('budget_request_number', 'like', 'BR-____-____')
        ->whereNotNull('final_approval_at')
        ->whereDoesntHave('payments')
        ->with('lines.purchaseOrder:id,po_number')
        ->orderBy('id')
        ->get()
        ->groupBy(fn ($budget) => $budget->final_approval_by.'|'.$budget->final_approval_at->toDateTimeString())
        ->filter(fn ($group) => $group->count() > 1);

    if ($groups->isEmpty()) {
        $this->info('Nothing to combine.');

        return Command::SUCCESS;
    }

    foreach ($groups as $group) {
        $this->line(sprintf('%s  <=  %s  (POs: %s)',
            $group->first()->budget_request_number,
            $group->slice(1)->pluck('budget_request_number')->join(', '),
            $group->flatMap->lines->pluck('purchaseOrder.po_number')->filter()->join(', ')));
    }

    if ($this->option('dry-run')) {
        $this->comment('Dry run: nothing changed.');

        return Command::SUCCESS;
    }

    DB::transaction(fn () => $groups->each(fn ($group) => $group->first()->absorb($group->slice(1))));
    $this->info('Combined '.$groups->count().' group(s).');

    return Command::SUCCESS;
})->purpose('Combine Budget Requests finalized together into one group BR.');

// Daily System Health alert; emails HEALTH_ALERT_EMAIL only when a check is red.
// Requires the Laravel scheduler (php artisan schedule:run every minute, e.g. via Windows Task Scheduler).
Schedule::command('health:check --notify')->dailyAt('07:00');
