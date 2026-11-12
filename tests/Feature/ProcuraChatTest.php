<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus as Status;
use App\Enums\RoleSlug;
use App\Jobs\SendProcuraChatPush;
use App\Models\Campaign;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ProcuraChat;
use App\Services\PurchaseRequestWorkflow;
use App\Support\AccountCampaignCatalog;
use App\Support\ProcuraChatAccess;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcuraChatTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->campaign = Campaign::create(['name' => 'Campaign A', 'is_active' => true]);
        Queue::fake();
        Storage::fake('local');
    }

    public function test_mobile_login_is_authenticated_and_uses_revocable_hashed_tokens(): void
    {
        $this->getJson('/api/mobile/v1/requests')->assertUnauthorized();
        $user = $this->user(RoleSlug::Om);
        $this->postJson('/api/mobile/v1/login', ['login' => $user->email, 'password' => 'wrong', 'device_name' => 'Phone'])->assertUnprocessable();
        $this->mobile($user);
        $this->getJson('/api/mobile/v1/me')->assertOk()->assertJsonPath('role', 'om');
        $this->assertNotEquals($this->defaultHeaders['Authorization'] ?? '', DB::table('personal_access_tokens')->value('token'));
        $this->postJson('/api/mobile/v1/logout')->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/mobile/v1/me')->assertUnauthorized();
    }

    public function test_campaign_access_applies_to_lists_details_messages_and_web_documents(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $own = $this->request($owner);
        $otherCampaign = Campaign::create(['name' => 'Campaign B', 'is_active' => true]);
        $other = $this->request($this->user(RoleSlug::Requestor, $otherCampaign->id));
        $om = $this->user(RoleSlug::Om);
        $this->mobile($om);
        $this->getJson('/api/mobile/v1/requests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/mobile/v1/requests/'.$other->id)->assertForbidden();
        $this->postJson('/api/mobile/v1/requests/'.$other->id.'/messages', ['body' => 'hello', 'client_id' => Str::uuid()])->assertForbidden();
        $this->actingAs($om)->get(route('purchase-requests.show', $other))->assertForbidden();
        $this->actingAs($om)->get(route('purchase-requests.document', $other))->assertForbidden();
    }

    public function test_new_submissions_wait_for_om_and_keep_the_campaign_snapshot(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->request($owner, Status::Draft);
        app(PurchaseRequestWorkflow::class)->submitForScreening($request, $owner, null, $owner->name, $this->signature());
        $this->assertSame(Status::ForOmApproval, $request->fresh()->status);
        $this->assertSame($this->campaign->id, (int) $request->fresh()->chat_campaign_id);
        $this->assertDatabaseHas('chat_messages', ['purchase_request_id' => $request->id, 'kind' => 'workflow']);
        $owner->update(['campaign_id' => Campaign::create(['name' => 'New campaign', 'is_active' => true])->id]);
        $this->assertTrue(ProcuraChatAccess::sameCampaign($this->user(RoleSlug::Om), $request->fresh()));
    }

    public function test_om_approval_routes_by_category_without_waiting_for_smt_and_cannot_repeat(): void
    {
        $om = $this->user(RoleSlug::Om);
        $this->mobile($om);
        foreach (['IT' => Status::ForItScreening, 'PREMISES' => Status::ForPremisesScreening] as $team => $expected) {
            $request = $this->request($this->user(RoleSlug::Requestor));
            $request->update(['requested_team' => $team]);
            $url = '/api/mobile/v1/requests/'.$request->id.'/om/approve';
            $payload = ['signature_name' => $om->name, 'signature_data' => $this->signature()];
            $this->postJson($url, $payload)->assertOk()->assertJsonPath('status', $expected->value);
            $this->assertSame($expected, $request->fresh()->status);
            $this->postJson($url, $payload)->assertForbidden();
            $this->assertSame(1, $request->approvals()->count());
        }
        $this->assertDatabaseCount('chat_acknowledgements', 0);
    }

    public function test_smt_can_acknowledge_once_but_cannot_approve_or_write(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->request($owner, Status::Draft);
        app(PurchaseRequestWorkflow::class)->submitForScreening($request, $owner, null, $owner->name, $this->signature());
        $workflowId = $request->chatMessages()->value('id');
        $smt = $this->user(RoleSlug::Smt);
        $this->mobile($smt);
        $prefix = '/api/mobile/v1/requests/'.$request->id;
        $this->getJson($prefix)->assertOk()->assertJsonPath('permissions.send', false)->assertJsonPath('permissions.approve_om', false);
        $this->postJson($prefix.'/om/approve', ['signature_name' => $smt->name, 'signature_data' => $this->signature()])->assertForbidden();
        $this->postJson($prefix.'/messages', ['body' => 'hello', 'client_id' => Str::uuid()])->assertForbidden();
        $this->postJson($prefix.'/acknowledge', ['message_id' => $workflowId])->assertOk();
        $this->postJson($prefix.'/acknowledge', ['message_id' => $workflowId])->assertOk();
        $this->assertDatabaseCount('chat_acknowledgements', 1);
        $this->assertSame(Status::ForOmApproval, $request->fresh()->status);
        $this->assertSame(1, $request->chatMessages()->where('kind', 'acknowledgement')->count());
    }

    public function test_return_requires_remarks_and_signature_then_can_resubmit_to_om(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->request($owner);
        $om = $this->user(RoleSlug::Om);
        $this->mobile($om);
        $url = '/api/mobile/v1/requests/'.$request->id.'/om/return';
        $this->postJson($url, ['signature_name' => $om->name, 'signature_data' => $this->signature()])->assertUnprocessable();
        $this->postJson($url, ['remarks' => 'Correct the quantity.', 'signature_name' => $om->name, 'signature_data' => 'not an image'])->assertUnprocessable();
        $this->postJson($url, ['remarks' => 'Correct the quantity.', 'signature_name' => $om->name, 'signature_data' => $this->signature()])->assertOk();
        $this->assertSame(Status::Returned, $request->fresh()->status);
        app(PurchaseRequestWorkflow::class)->submitForScreening($request->fresh(), $owner, 'Corrected.', $owner->name, $this->signature());
        $this->assertSame(Status::ForOmApproval, $request->fresh()->status);
    }

    public function test_chat_retry_is_idempotent_and_files_are_private(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->request($owner);
        $this->mobile($owner);
        $body = ['body' => 'Please review.', 'client_id' => (string) Str::uuid(), 'attachment' => UploadedFile::fake()->image('details.png')];
        $url = '/api/mobile/v1/requests/'.$request->id.'/messages';
        $response = $this->postJson($url, $body)->assertCreated();
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('id', $response->json('id'));
        $this->assertDatabaseCount('chat_messages', 1);
        $this->assertStringNotContainsString('attachment_path', $response->getContent());
        $this->get($response->json('attachment.url'))->assertOk();
        $this->postJson($url, ['body' => 'bad', 'client_id' => Str::uuid(), 'attachment' => UploadedFile::fake()->create('bad.html', 1, 'text/html')])->assertUnprocessable();
        $this->mobile($this->user(RoleSlug::Requestor));
        $this->getJson($response->json('attachment.url'))->assertForbidden();
        $this->postJson($url, ['body' => 'bad', 'client_id' => Str::uuid(), 'attachment' => UploadedFile::fake()->create('bad.html', 1, 'text/html')])->assertForbidden();
    }

    public function test_read_watermarks_are_monotonic_and_unread_filter_works(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->request($owner);
        $other = $this->user(RoleSlug::Om);
        $a = $request->chatMessages()->create(['user_id' => $other->id, 'body' => 'One']);
        $b = $request->chatMessages()->create(['user_id' => $other->id, 'body' => 'Two']);
        $this->mobile($owner);
        $this->getJson('/api/mobile/v1/requests?filter=unread')->assertOk()->assertJsonPath('data.0.unread_count', 2);
        $this->postJson('/api/mobile/v1/requests/'.$request->id.'/read', ['message_id' => $b->id])->assertOk();
        $this->postJson('/api/mobile/v1/requests/'.$request->id.'/read', ['message_id' => $a->id])->assertOk();
        $this->assertEquals($b->id, DB::table('chat_reads')->value('last_message_id'));
        $this->getJson('/api/mobile/v1/requests?filter=unread')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_initial_history_and_incremental_pages_do_not_skip_messages(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->request($owner);
        for ($i = 1; $i <= 100; $i++) {
            $request->chatMessages()->create(['user_id' => $owner->id, 'body' => 'Message '.$i]);
        }
        $this->mobile($owner);
        $prefix = '/api/mobile/v1/requests/'.$request->id;
        $this->getJson($prefix)->assertOk()->assertJsonCount(80, 'messages')->assertJsonPath('messages.0.body', 'Message 21');
        $this->getJson($prefix.'?before=21')->assertOk()->assertJsonCount(20, 'messages');
        $this->getJson($prefix.'?after=0')->assertOk()->assertJsonPath('messages.0.body', 'Message 1')->assertJsonPath('messages.79.body', 'Message 80');
    }

    public function test_president_mobile_approval_uses_existing_supplier_workflow(): void
    {
        $request = $this->request($this->user(RoleSlug::Requestor), Status::ForSupplierApproval);
        $president = $this->user(RoleSlug::President);
        $supplier = Supplier::create(['name' => 'Supplier', 'is_active' => true]);
        $quote = $request->quotations()->create(['supplier_id' => $supplier->id, 'created_by' => $president->id, 'item_or_service' => 'Equipment', 'price' => 100]);
        $this->mobile($president);
        $url = '/api/mobile/v1/requests/'.$request->id.'/president';
        $payload = ['decision' => 'APPROVE', 'quotation_id' => $quote->id, 'signature_name' => $president->name, 'signature_data' => $this->signature()];
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('status', Status::ForBudgetProcessing->value);
        $this->assertEquals($supplier->id, $request->fresh()->selected_supplier_id);
        $this->postJson($url, $payload)->assertForbidden();
        $this->assertDatabaseHas('request_approvals', ['purchase_request_id' => $request->id, 'action' => 'PRESIDENT_SUPPLIER_APPROVAL_APPROVED']);
    }

    public function test_disabled_expired_and_role_removed_tokens_cannot_access_chat(): void
    {
        $om = $this->user(RoleSlug::Om);
        $this->mobile($om);
        $om->update(['is_active' => false]);
        $this->getJson('/api/mobile/v1/requests')->assertForbidden();
        $om->update(['is_active' => true]);
        $om->tokens()->update(['expires_at' => now()->subMinute()]);
        Auth::forgetGuards();
        $this->getJson('/api/mobile/v1/requests')->assertUnauthorized();
        $this->mobile($om);
        $om->update(['role_id' => Role::where('slug', 'smt')->value('id')]);
        Auth::forgetGuards();
        $this->getJson('/api/mobile/v1/requests')->assertForbidden();
    }

    public function test_temporary_password_must_be_changed_inside_the_app(): void
    {
        $user = $this->user(RoleSlug::Smt);
        $user->update(['must_change_password' => true]);
        $this->mobile($user);
        $this->getJson('/api/mobile/v1/requests')->assertStatus(423);
        $this->postJson('/api/mobile/v1/password', ['current_password' => 'password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/mobile/v1/requests')->assertOk();
    }

    public function test_push_notifies_campaign_smt_and_om_but_excludes_other_campaigns(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->request($owner);
        $om = $this->user(RoleSlug::Om);
        $smt = $this->user(RoleSlug::Smt);
        $foreign = $this->user(RoleSlug::Smt, Campaign::create(['name' => 'Foreign', 'is_active' => true])->id);
        $message = $request->chatMessages()->create(['user_id' => $owner->id, 'kind' => 'workflow', 'body' => 'For OM approval']);
        foreach ([$om, $smt, $foreign] as $user) {
            $token = $user->createToken('Phone', ['procura-chat', 'role:'.$user->role_id], now()->addDay());
            DB::table('mobile_devices')->insert(['user_id' => $user->id, 'personal_access_token_id' => $token->accessToken->id,
                'push_token' => 'ExpoPushToken[test-'.$user->id.']', 'platform' => 'ios', 'created_at' => now(), 'updated_at' => now()]);
        }
        config(['procura_chat.push_enabled' => true]);
        Http::fake(['exp.host/*' => Http::response(['data' => ['status' => 'ok', 'id' => 'receipt-id']])]);
        (new SendProcuraChatPush($message->id))->handle(app(ProcuraChat::class));
        Http::assertSentCount(2);
        Http::assertSent(fn ($http) => $http['sound'] === 'default' && $http['priority'] === 'high');
        $this->assertSame(1, $smt->notifications()->count());
        $this->assertSame(0, $foreign->notifications()->count());
    }

    public function test_web_chat_loads_with_session_authentication(): void
    {
        $this->actingAs($this->user(RoleSlug::Om))->get(route('chat.index'))->assertOk()->assertSee('Procura Chats');
    }

    public function test_admin_can_register_campaign_om_and_smt_without_requestor_email_fields(): void
    {
        $campaign = Campaign::firstOrCreate(['name' => AccountCampaignCatalog::NAMES[0]], ['is_active' => true]);
        $admin = $this->user(RoleSlug::Admin);
        foreach ([RoleSlug::Om, RoleSlug::Smt] as $role) {
            $this->actingAs($admin)->post(route('admin.accounts.store'), [
                'name' => $role->name.' Manager', 'employee_id' => 'CHAT-'.$role->name,
                'email' => $role->value.'@chat.example.test', 'password' => 'initial-password',
                'role_id' => Role::where('slug', $role->value)->value('id'),
                'campaign_id' => $campaign->id, 'is_active' => true,
            ])->assertRedirect()->assertSessionHasNoErrors();
            $manager = User::where('email', $role->value.'@chat.example.test')->firstOrFail();
            $this->assertEquals($campaign->id, $manager->campaign_id);
            $this->assertTrue($manager->must_change_password);
        }
    }

    public function test_previous_handler_can_discuss_handoff_but_other_observers_and_closed_requests_are_read_only(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $canvasser = $this->user(RoleSlug::CanvassingStaff);
        $request = $this->request($owner, Status::ForSupplierApproval);
        $request->auditLogs()->create(['user_id' => $canvasser->id, 'action' => 'CANVASSING_UPDATED',
            'previous_status' => Status::ForCanvassing->value, 'new_status' => Status::ForSupplierApproval->value]);
        $this->mobile($canvasser);
        $this->getJson('/api/mobile/v1/requests/'.$request->id)->assertOk()->assertJsonPath('permissions.send', true);
        $this->mobile($this->user(RoleSlug::PurchasingTeam));
        $this->getJson('/api/mobile/v1/requests/'.$request->id)->assertOk()->assertJsonPath('permissions.send', false);
        $this->mobile($owner);
        $request->update(['status' => Status::Completed]);
        $this->postJson('/api/mobile/v1/requests/'.$request->id.'/messages', ['body' => 'Closed', 'client_id' => Str::uuid()])->assertForbidden();
    }

    public function test_president_return_is_for_correction_and_smt_cannot_acknowledge_an_old_stage(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->request($owner, Status::ForSupplierApproval);
        $old = $request->chatMessages()->create(['user_id' => $owner->id, 'kind' => 'workflow', 'body' => 'Awaiting President']);
        $president = $this->user(RoleSlug::President);
        $this->mobile($president);
        $this->postJson('/api/mobile/v1/requests/'.$request->id.'/president', [
            'decision' => 'REJECT', 'remarks' => 'Get an updated supplier quote.',
            'signature_name' => $president->name, 'signature_data' => $this->signature(),
        ])->assertOk()->assertJsonPath('status', Status::ForCanvassing->value);
        $this->mobile($this->user(RoleSlug::Smt));
        $this->postJson('/api/mobile/v1/requests/'.$request->id.'/acknowledge', ['message_id' => $old->id])->assertUnprocessable();
        $this->assertDatabaseCount('chat_acknowledgements', 0);
    }

    private function user(RoleSlug $role, ?int $campaignId = null): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role->value)->value('id'),
            'campaign_id' => $campaignId ?? $this->campaign->id, 'must_change_password' => false]);
    }

    private function request(User $owner, Status $status = Status::ForOmApproval): PurchaseRequest
    {
        return PurchaseRequest::create(['request_number' => 'CHAT-'.Str::upper(Str::random(8)), 'title' => 'Equipment',
            'requestor_id' => $owner->id, 'status' => $status, 'request_type' => 'IT', 'requested_team' => 'IT',
            'department_campaign' => $owner->campaign->name, 'purpose' => 'Operations',
            'required_date' => now()->addWeek(), 'delivery_location' => 'Office']);
    }

    private function mobile(User $user): void
    {
        Auth::forgetGuards();
        $response = $this->postJson('/api/mobile/v1/login', ['login' => $user->email, 'password' => 'password', 'device_name' => 'Test phone'])->assertOk();
        $this->withToken($response->json('token'));
        Auth::forgetGuards();
    }

    private function signature(): string
    {
        return 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a8S8AAAAASUVORK5CYII='));
    }
}
