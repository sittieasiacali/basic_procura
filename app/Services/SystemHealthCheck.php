<?php

namespace App\Services;

use App\Enums\PurchaseRequestStatus;
use App\Models\AuditLog;
use App\Models\PurchaseRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Read-only health checks for the System Health page and the daily alert.
 * Nothing here writes to the database, files or queue.
 */
class SystemHealthCheck
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** Statuses that no longer wait on anyone. */
    private const IDLE_STATUSES = [
        PurchaseRequestStatus::Draft,
        PurchaseRequestStatus::Rejected,
        PurchaseRequestStatus::Cancelled,
        PurchaseRequestStatus::Completed,
        PurchaseRequestStatus::Fulfilled,
        PurchaseRequestStatus::Voided,
    ];

    /** Statuses after the President's final budget approval (a Budget Request must exist). */
    private const AFTER_BUDGET = [
        PurchaseRequestStatus::ForPayment,
        PurchaseRequestStatus::PaymentPending,
        PurchaseRequestStatus::PartiallyPaid,
        PurchaseRequestStatus::FullyPaid,
    ];

    /**
     * @return list<array{key: string, label: string, status: string, summary: string, help: ?string, details: list<array<string, mixed>>}>
     */
    public function run(): array
    {
        $checks = [
            'database' => ['Database', fn () => $this->database()],
            'queue' => ['Email queue', fn () => $this->queue()],
            'errors' => ['Errors (last 24 hours)', fn () => $this->errors()],
            'stuck' => ['Stuck requests', fn () => $this->stuckRequests()],
            'data' => ['Data checks', fn () => $this->dataChecks()],
            'storage' => ['Disk and uploads', fn () => $this->storage()],
            'config' => ['Configuration', fn () => $this->configuration()],
        ];

        $results = [];
        foreach ($checks as $key => [$label, $check]) {
            try {
                $result = $check();
            } catch (Throwable $e) {
                $result = $this->result(self::FAIL, 'This check could not run: '.Str::limit($e->getMessage(), 200), 'Either the database is unreachable (fix the Database light first) or a table is missing (run php artisan migrate:status to check for pending migrations).');
            }
            $results[] = ['key' => $key, 'label' => $label, ...$result];
        }

        return $results;
    }

    /** @param list<array{status: string}> $results */
    public static function overall(array $results): string
    {
        $statuses = array_column($results, 'status');

        return in_array(self::FAIL, $statuses, true) ? self::FAIL
            : (in_array(self::WARN, $statuses, true) ? self::WARN : self::OK);
    }

    private function database(): array
    {
        $started = microtime(true);
        DB::select('select 1');
        $ms = (int) round((microtime(true) - $started) * 1000);

        return $ms > 1000
            ? $this->result(self::WARN, "Connected, but slow ({$ms} ms).", 'The database server may be overloaded. Pages will feel slow.')
            : $this->result(self::OK, "Connected ({$ms} ms).");
    }

    private function queue(): array
    {
        $connection = config('queue.default');
        if ($connection === 'sync') {
            return $this->result(self::OK, 'Emails are sent immediately (no background queue).');
        }

        $details = [];
        $status = self::OK;
        $parts = [];
        $help = null;

        if ($connection === 'database' && Schema::hasTable($jobsTable = config('queue.connections.database.table', 'jobs'))) {
            $pending = DB::table($jobsTable)->count();
            $oldest = DB::table($jobsTable)->min('created_at');
            $parts[] = "{$pending} waiting to be sent";
            if ($oldest !== null && now()->timestamp - (int) $oldest > 15 * 60) {
                $status = self::FAIL;
                $help = 'Emails have been waiting more than 15 minutes, so the queue worker is probably not running. Start it with: php artisan queue:work';
                $details[] = ['label' => 'Oldest waiting email', 'value' => CarbonImmutable::createFromTimestamp((int) $oldest)->diffForHumans()];
            }
        }

        $failedTable = config('queue.failed.table', 'failed_jobs');
        if (Schema::hasTable($failedTable)) {
            $failedTotal = DB::table($failedTable)->count();
            $failedRecent = DB::table($failedTable)->where('failed_at', '>=', now()->subDay())->count();
            $parts[] = "{$failedRecent} failed today ({$failedTotal} failed in total)";

            if ($failedRecent > 0) {
                $status = self::FAIL;
                $help ??= 'Some people were not told a request is waiting for them. Check the mail settings in .env, then retry with: php artisan queue:retry all';
            } elseif ($failedTotal > 0 && $status === self::OK) {
                $status = self::WARN;
                $help = 'Older emails failed. Retry them with php artisan queue:retry all, or clear them with php artisan queue:flush.';
            }

            foreach (DB::table($failedTable)->latest('failed_at')->limit(5)->get(['failed_at', 'exception']) as $job) {
                $details[] = ['label' => (string) $job->failed_at, 'value' => Str::limit(Str::before((string) $job->exception, "\n"), 200)];
            }
        }

        return $this->result($status, ucfirst(implode('; ', $parts) ?: 'Queue tables not found.').'.', $help, $details);
    }

    private function errors(): array
    {
        $since = now()->subDay();
        $entries = [];

        $directory = config('procurement.health.log_directory') ?: storage_path('logs');
        foreach (glob($directory.DIRECTORY_SEPARATOR.'laravel*.log') ?: [] as $file) {
            if (filemtime($file) < $since->timestamp) {
                continue;
            }
            foreach ($this->tailLines($file, 512 * 1024) as $line) {
                // Test runs write "testing.ERROR" lines to the same log; they are not user-facing problems.
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] (?!testing\.)\w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/', $line, $m)) {
                    $at = CarbonImmutable::parse($m[1]);
                    if ($at->greaterThanOrEqualTo($since)) {
                        $entries[] = ['at' => $at, 'message' => Str::limit(trim(preg_replace('/\{.*$/s', '', $m[3])), 200)];
                    }
                }
            }
        }

        usort($entries, fn ($a, $b) => $b['at'] <=> $a['at']);
        $count = count($entries);
        $details = array_map(fn ($e) => ['label' => $e['at']->format('M j, H:i'), 'value' => $e['message']], array_slice($entries, 0, 5));

        return match (true) {
            $count === 0 => $this->result(self::OK, 'No errors recorded.'),
            $count < 10 => $this->result(self::WARN, "{$count} error(s) recorded.", 'A few errors. Check whether they keep happening. Full details are in storage/logs/laravel.log.', $details),
            default => $this->result(self::FAIL, "{$count} errors recorded.", 'Many errors: something is probably crashing for users. Full details are in storage/logs/laravel.log.', $details),
        };
    }

    private function stuckRequests(): array
    {
        $days = max(1, (int) config('procurement.health.stuck_days', 3));
        $cutoff = now()->subDays($days);
        $request = new PurchaseRequest;

        $stuck = PurchaseRequest::query()
            ->whereNotIn('status', array_map(fn ($s) => $s->value, self::IDLE_STATUSES))
            ->select(['id', 'request_number', 'title', 'status', 'updated_at'])
            ->addSelect(['last_activity_at' => AuditLog::query()
                ->selectRaw('max(created_at)')
                ->where('auditable_type', $request->getMorphClass())
                ->whereColumn('auditable_id', $request->qualifyColumn('id')),
            ])
            ->get()
            ->map(function (PurchaseRequest $pr) {
                $pr->idle_since = CarbonImmutable::parse($pr->last_activity_at ?? $pr->updated_at);

                return $pr;
            })
            ->filter(fn ($pr) => $pr->idle_since->lessThan($cutoff))
            ->sortBy('idle_since')
            ->values();

        if ($stuck->isEmpty()) {
            return $this->result(self::OK, "No request has waited more than {$days} day(s) at one stage.");
        }

        $byStage = $stuck->countBy(fn ($pr) => $this->stageName($pr->status))
            ->map(fn ($n, $stage) => "{$stage}: {$n}")->values()->join(', ');

        $details = $stuck->take(20)->map(fn ($pr) => [
            'label' => $pr->request_number,
            'value' => $this->stageName($pr->status).' for '.(int) $pr->idle_since->diffInDays(now()).' day(s): '.Str::limit((string) $pr->title, 60),
            'url' => route('purchase-requests.show', $pr),
        ])->all();

        return $this->result(self::WARN, $stuck->count()." request(s) waiting more than {$days} day(s). {$byStage}.", 'Ask the team at that stage to act, or check whether someone is on leave.', $details);
    }

    private function dataChecks(): array
    {
        $problems = [];
        $status = self::OK;

        $known = array_map(fn ($s) => $s->value, PurchaseRequestStatus::cases());
        $unknown = DB::table('purchase_requests')->whereNotIn('status', $known)->get(['request_number', 'status']);
        foreach ($unknown as $row) {
            $status = self::FAIL;
            $problems[] = ['label' => $row->request_number, 'value' => "Unknown status \"{$row->status}\". Pages that list this request will crash."];
        }

        $checks = [
            [self::AFTER_BUDGET, fn ($q) => $q->whereNotExists(fn ($s) => $s->from('budget_request_purchase_orders')->whereColumn('budget_request_purchase_orders.purchase_request_id', 'purchase_requests.id')), 'At the payment stage but has no Budget Request.'],
            [[PurchaseRequestStatus::ForReceiving], fn ($q) => $q->whereDoesntHave('payments'), 'Waiting for receiving but no payment was recorded.'],
            [[PurchaseRequestStatus::Completed], fn ($q) => $q->whereDoesntHave('goodsReceipts'), 'Marked completed but has no goods receipt.'],
        ];

        foreach ($checks as [$statuses, $scope, $message]) {
            $query = PurchaseRequest::query()->whereIn('status', array_map(fn ($s) => $s->value, $statuses));
            foreach ($scope($query)->limit(20)->get(['id', 'request_number']) as $pr) {
                $status = $status === self::FAIL ? self::FAIL : self::WARN;
                $problems[] = ['label' => $pr->request_number, 'value' => $message, 'url' => route('purchase-requests.show', $pr)];
            }
        }

        return $problems === []
            ? $this->result(self::OK, 'No broken records found.')
            : $this->result($status, count($problems).' record(s) look inconsistent.', 'These usually come from manual database edits or old data. Review each request before changing it.', $problems);
    }

    private function storage(): array
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        $uploads = storage_path('app/private');
        $writable = is_dir($uploads) ? is_writable($uploads) : is_writable(storage_path('app'));

        if (! $writable) {
            return $this->result(self::FAIL, 'The uploads folder cannot be written to.', 'Attachments and screening files will fail to upload. Check folder permissions on storage/app.');
        }
        if (! $free || ! $total) {
            return $this->result(self::OK, 'Uploads folder is writable (free disk space unknown).');
        }

        $percent = (int) round($free / $total * 100);
        $summary = sprintf('%s free (%d%%). Uploads folder is writable.', $this->bytes($free), $percent);

        return match (true) {
            $percent < 5 => $this->result(self::FAIL, $summary, 'The disk is almost full. Uploads, logs and the database will start failing.'),
            $percent < 15 => $this->result(self::WARN, $summary, 'The disk is getting full. Free up space or remove old backups.'),
            default => $this->result(self::OK, $summary),
        };
    }

    private function configuration(): array
    {
        $issues = [];
        $status = self::OK;
        $production = app()->environment('production');
        $url = (string) config('app.url');

        if (blank(config('app.key'))) {
            $status = self::FAIL;
            $issues[] = ['label' => 'APP_KEY', 'value' => 'Missing, so logins and sessions will fail. Restore the original key; only generate a new one if the original is truly lost, because encrypted data depends on it.'];
        }
        if ($production && config('app.debug')) {
            $status = self::FAIL;
            $issues[] = ['label' => 'APP_DEBUG', 'value' => 'Debug is on in production, so error pages can show passwords and code. Set APP_DEBUG=false.'];
        }
        if ($production && ! str_starts_with($url, 'https://')) {
            $status = $status === self::FAIL ? $status : self::WARN;
            $issues[] = ['label' => 'APP_URL', 'value' => 'Not HTTPS. Links in emails and redirects may be insecure.'];
        }
        if (str_ends_with(rtrim($url, '/'), '/login')) {
            $status = $status === self::FAIL ? $status : self::WARN;
            $issues[] = ['label' => 'APP_URL', 'value' => 'Ends with /login. Use the site address only.'];
        }
        if ($production && config('procurement.health.alert_email') === null) {
            $status = $status === self::FAIL ? $status : self::WARN;
            $issues[] = ['label' => 'HEALTH_ALERT_EMAIL', 'value' => 'Not set, so nobody receives the daily alert when something is red.'];
        }

        $env = app()->environment();

        return $issues === []
            ? $this->result(self::OK, "Environment: {$env}. No problems found.")
            : $this->result($status, "Environment: {$env}. ".count($issues).' setting(s) need attention.', 'Edit .env on the server, then run: php artisan config:cache', $issues);
    }

    /** @return list<string> */
    private function tailLines(string $file, int $bytes): array
    {
        $handle = @fopen($file, 'rb');
        if (! $handle) {
            return [];
        }
        $size = filesize($file);
        fseek($handle, max(0, $size - $bytes));
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return preg_split('/\r?\n/', $content) ?: [];
    }

    private function stageName(PurchaseRequestStatus $status): string
    {
        return Str::of($status->value)->replace('_', ' ')->lower()->ucfirst()->toString();
    }

    private function bytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1).' '.$units[$i];
    }

    private function result(string $status, string $summary, ?string $help = null, array $details = []): array
    {
        return ['status' => $status, 'summary' => $summary, 'help' => $status === self::OK ? null : $help, 'details' => $details];
    }
}
