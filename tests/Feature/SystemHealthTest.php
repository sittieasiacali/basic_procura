<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\SystemHealthAlert;
use App\Services\SystemHealthCheck;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private string $logDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->logDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'procura-health-'.uniqid();
        mkdir($this->logDirectory);
        config(['procurement.health.log_directory' => $this->logDirectory]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->logDirectory.DIRECTORY_SEPARATOR.'*') ?: []);
        @rmdir($this->logDirectory);
        parent::tearDown();
    }

    public function test_errors_from_the_last_day_are_counted_in_plain_words(): void
    {
        $this->assertSame(SystemHealthCheck::OK, $this->check('errors')['status']);

        file_put_contents($this->logDirectory.DIRECTORY_SEPARATOR.'laravel.log', implode("\n", [
            '['.now()->subDays(2)->format('Y-m-d H:i:s').'] production.ERROR: Old problem {"exception":"x"}',
            '['.now()->subHour()->format('Y-m-d H:i:s').'] production.ERROR: SQLSTATE[HY000] Connection refused {"exception":"[object] (PDOException)"}',
            '#0 stack trace line',
            '['.now()->subMinute()->format('Y-m-d H:i:s').'] testing.ERROR: Raised by the test suite, not by users',
            '['.now()->format('Y-m-d H:i:s').'] production.INFO: Just information',
        ]));

        $errors = $this->check('errors');
        $this->assertSame(SystemHealthCheck::WARN, $errors['status']);
        $this->assertCount(1, $errors['details']);
        $this->assertSame('SQLSTATE[HY000] Connection refused', $errors['details'][0]['value']);
    }

    public function test_only_admin_can_open_system_health(): void
    {
        $this->actingAs($this->user(RoleSlug::Purchasing))->get(route('admin.system-health'))->assertForbidden();

        $this->actingAs($this->user(RoleSlug::Admin))
            ->get(route('admin.system-health'))
            ->assertOk()
            ->assertSee('System Health')
            ->assertSee('data-check="database"', false)
            ->assertSee('data-check="stuck"', false);
    }

    public function test_request_waiting_longer_than_threshold_is_flagged_as_stuck(): void
    {
        config(['procurement.health.stuck_days' => 3]);
        $requestor = $this->user(RoleSlug::Requestor);
        $old = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForCanvassing);
        $this->activity($old, $requestor, now()->subDays(5));
        $fresh = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForCanvassing);
        $this->activity($fresh, $requestor, now()->subDay());
        $finished = $this->purchaseRequest($requestor, PurchaseRequestStatus::Completed);
        $finished->forceFill(['updated_at' => now()->subDays(30)])->saveQuietly();

        $stuck = $this->check('stuck');

        $this->assertSame(SystemHealthCheck::WARN, $stuck['status']);
        $labels = array_column($stuck['details'], 'label');
        $this->assertSame([$old->request_number], $labels);
    }

    public function test_recent_failed_email_turns_queue_red(): void
    {
        config(['queue.default' => 'database']);
        $this->assertSame(SystemHealthCheck::OK, $this->check('queue')['status']);

        DB::table('failed_jobs')->insert([
            'uuid' => 'test-uuid', 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => "Symfony\\Component\\Mailer\\Exception\\TransportException: Connection refused\n#0 trace", 'failed_at' => now(),
        ]);

        $queue = $this->check('queue');
        $this->assertSame(SystemHealthCheck::FAIL, $queue['status']);
        $this->assertStringContainsString('Connection refused', $queue['details'][0]['value']);
    }

    public function test_inconsistent_records_are_reported(): void
    {
        $requestor = $this->user(RoleSlug::Requestor);
        $this->assertSame(SystemHealthCheck::OK, $this->check('data')['status']);

        $broken = $this->purchaseRequest($requestor, PurchaseRequestStatus::ForReceiving);
        $data = $this->check('data');
        $this->assertSame(SystemHealthCheck::WARN, $data['status']);
        $this->assertSame($broken->request_number, $data['details'][0]['label']);

        DB::table('purchase_requests')->where('id', $broken->id)->update(['status' => 'NOT_A_STATUS']);
        $this->assertSame(SystemHealthCheck::FAIL, $this->check('data')['status']);
    }

    public function test_debug_in_production_is_a_problem(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true, 'app.url' => 'https://procura.example.test', 'procurement.health.alert_email' => 'ops@example.test']);

        $this->assertSame(SystemHealthCheck::FAIL, $this->check('config')['status']);

        config(['app.debug' => false]);
        $this->assertSame(SystemHealthCheck::OK, $this->check('config')['status']);
    }

    public function test_command_emails_only_when_something_is_red(): void
    {
        Notification::fake();
        config(['procurement.health.alert_email' => 'ops@example.test']);
        $health = new class extends SystemHealthCheck
        {
            public array $results = [['key' => 'database', 'label' => 'Database', 'status' => self::OK, 'summary' => 'Connected.', 'help' => null, 'details' => []]];

            public function run(): array
            {
                return $this->results;
            }
        };
        $this->app->instance(SystemHealthCheck::class, $health);

        $health->results[] = ['key' => 'stuck', 'label' => 'Stuck requests', 'status' => SystemHealthCheck::WARN, 'summary' => '1 waiting.', 'help' => 'Ask.', 'details' => []];
        $this->artisan('health:check', ['--notify' => true])->assertSuccessful();
        Notification::assertNothingSent();

        $health->results[] = ['key' => 'queue', 'label' => 'Email queue', 'status' => SystemHealthCheck::FAIL, 'summary' => '2 failed today.', 'help' => 'Retry.', 'details' => []];
        $this->artisan('health:check', ['--notify' => true])->assertFailed();
        Notification::assertSentOnDemand(SystemHealthAlert::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'ops@example.test');
    }

    private function activity(PurchaseRequest $purchaseRequest, User $user, \DateTimeInterface $at): void
    {
        $log = $purchaseRequest->auditLogs()->create(['user_id' => $user->id, 'action' => 'TEST', 'new_status' => $purchaseRequest->status->value]);
        DB::table('audit_logs')->where('id', $log->id)->update(['created_at' => $at]);
    }

    private function check(string $key): array
    {
        return collect(app(SystemHealthCheck::class)->run())->firstWhere('key', $key);
    }

    private function user(RoleSlug $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role->value)->value('id'),
            'department' => 'Operations',
        ]);
    }

    private function purchaseRequest(User $requestor, PurchaseRequestStatus $status): PurchaseRequest
    {
        $this->sequence++;

        return PurchaseRequest::query()->create([
            'request_number' => 'HEALTH'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'title' => 'Health check request '.$this->sequence,
            'requestor_id' => $requestor->id,
            'department_campaign' => 'Operations',
            'request_type' => 'OTHER',
            'purpose' => 'System health verification',
            'status' => $status,
            'current_responsible_role_id' => $requestor->role_id,
            'total_amount' => 100,
            'required_date' => now()->addWeek(),
            'delivery_location' => 'Main Office',
        ]);
    }
}
