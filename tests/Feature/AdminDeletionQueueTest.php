<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminDeletionQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_worker_processes_deletion_emails_and_the_requestor_bell_notification(): void
    {
        $this->seed(RoleSeeder::class);
        config([
            'queue.default' => 'database',
            'mail.default' => 'array',
            'mail.from.address' => 'no-reply@example.test',
            'mail.from.name' => 'Procura Test',
        ]);
        $admin = User::factory()->create(['role_id' => Role::where('slug', RoleSlug::Admin->value)->value('id')]);
        $owner = User::factory()->create([
            'role_id' => Role::where('slug', RoleSlug::Requestor->value)->value('id'),
            'email' => 'requestor@example.test',
            'om_notification_email' => 'om@example.test',
            'smt_notification_email' => 'smt@example.test',
        ]);
        $purchaseRequest = PurchaseRequest::create([
            'request_number' => 'REQ-QUEUE-1', 'requestor_id' => $owner->id,
            'title' => 'Queue verification', 'department_campaign' => 'Operations',
            'request_type' => 'IT', 'purpose' => 'Verify notifications',
            'required_date' => now()->addWeek(), 'delivery_location' => 'Main office',
            'status' => PurchaseRequestStatus::Draft,
        ]);
        $this->actingAs($admin);
        $review = $this->get(route('admin.transactions.delete', $purchaseRequest))->assertOk();
        $this->delete(route('admin.transactions.destroy', $purchaseRequest), [
            'reason' => 'Remove duplicate request.', 'confirmation' => 1,
            'return_to' => 'purchase-requests.index', 'plan_token' => $review->viewData('planToken'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(4, DB::table('jobs')->count());
        $this->assertSame(0, $owner->notifications()->count());
        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 1])->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(3, $messages);
        $this->assertEqualsCanonicalizing(
            ['requestor@example.test', 'om@example.test', 'smt@example.test'],
            $messages->map(fn ($message) => $message->getOriginalMessage()->getTo()[0]->getAddress())->all(),
        );
        $this->assertStringContainsString('REQ-QUEUE-1', $messages->first()->getOriginalMessage()->getSubject());
        $this->assertSame('REQ-QUEUE-1', $owner->notifications()->sole()->data['request_number']);
    }
}
