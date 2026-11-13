<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\PurchaseRequestStatus as Status;
use App\Enums\RoleSlug;
use App\Jobs\CheckProcuraPushReceipt;
use App\Jobs\SendProcuraChatPush;
use App\Models\Campaign;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ProcuraChat;
use App\Services\PurchaseRequestWorkflow;
use App\Services\WorkflowEmailNotifier;
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
use PHPUnit\Framework\Attributes\TestWith;
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

    public function test_web_notifications_include_campaign_smt_and_om_without_contacting_native_push_services(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->request($owner);
        $om = $this->user(RoleSlug::Om);
        $smt = $this->user(RoleSlug::Smt);
        $foreign = $this->user(RoleSlug::Smt, Campaign::create(['name' => 'Foreign', 'is_active' => true])->id);
        $message = $request->chatMessages()->create(['user_id' => $owner->id, 'kind' => 'workflow', 'body' => 'For OM approval']);
        config(['procura_chat.push_enabled' => true]); // Old server settings cannot reactivate the retired transport.
        Http::fake();
        (new SendProcuraChatPush($message->id))->handle(app(ProcuraChat::class));
        (new SendProcuraChatPush($message->id))->handle(app(ProcuraChat::class));
        Http::assertNothingSent();
        $this->assertSame(1, $om->notifications()->count());
        $this->assertSame(1, $smt->notifications()->count());
        $this->assertSame(0, $foreign->notifications()->count());
    }

    public function test_retired_device_registration_and_receipt_jobs_do_not_send_or_store_native_push_credentials(): void
    {
        $this->mobile($this->user(RoleSlug::Om));
        Http::fake();
        config(['procura_chat.push_enabled' => true]);
        $this->postJson('/api/mobile/v1/devices', ['push_token' => 'ExpoPushToken[retired-device]', 'platform' => 'ios'])->assertStatus(410);
        (new CheckProcuraPushReceipt(999, 'retired-receipt'))->handle();
        Http::assertNothingSent();
        $this->assertDatabaseCount('mobile_devices', 0);
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

    public function test_om_rejection_requires_reason_and_signature_and_closes_the_same_conversation(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->request($owner);
        $first = $request->chatMessages()->create(['user_id' => $owner->id, 'body' => 'Original request discussion']);
        $om = $this->user(RoleSlug::Om);
        $payload = ['remarks' => 'The equipment is not required.', 'signature_name' => $om->name, 'signature_data' => $this->signature()];
        $url = route('om-approvals.reject', $request);
        $foreign = $this->user(RoleSlug::Om, Campaign::create(['name' => 'Other', 'is_active' => true])->id);
        $this->actingAs($foreign)->postJson($url, $payload)->assertForbidden();
        $this->actingAs($om)->postJson($url, array_diff_key($payload, ['remarks' => true]))->assertUnprocessable();
        $this->postJson($url, [...$payload, 'signature_data' => 'invalid'])->assertUnprocessable();
        $this->assertSame(Status::ForOmApproval, $request->fresh()->status);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('status', Status::Rejected->value);
        $this->assertNull($request->fresh()->current_responsible_role_id);
        $this->assertSame([], $request->fresh()->status->allowedTransitions());
        $this->assertDatabaseHas('request_approvals', ['purchase_request_id' => $request->id, 'action' => 'OM_REJECTED', 'remarks' => $payload['remarks']]);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $request->id, 'action' => 'OM_REJECTED', 'new_status' => 'REJECTED']);
        $this->getJson(route('chat.show', $request))->assertOk()->assertJsonPath('messages.0.id', $first->id)
            ->assertJsonPath('permissions.send', false)->assertJsonPath('permissions.approve_om', false);
        $this->assertSame(2, $request->chatMessages()->count());
        $this->postJson($url, $payload)->assertForbidden();
        $this->postJson(route('om-approvals.approve', $request), $payload)->assertForbidden();
        foreach ([$owner, $om, $this->user(RoleSlug::Admin)] as $reader) {
            $this->actingAs($reader)->getJson(route('chat.show', $request))->assertOk()
                ->assertJsonPath('permissions.send', false)->assertJsonPath('permissions.approve_om', false)
                ->assertJsonPath('permissions.approve_president', false);
            $this->postJson(route('chat.store', $request), ['body' => 'No new messages', 'client_id' => Str::uuid()])->assertForbidden();
        }
        $this->actingAs($owner)->postJson(route('purchase-requests.submit', $request), $payload)->assertForbidden();
        $this->actingAs($this->user(RoleSlug::Smt))->getJson(route('chat.show', $request))->assertOk()->assertJsonPath('permissions.acknowledge', false);
        $this->postJson(route('chat.acknowledge', $request), ['message_id' => $request->chatMessages()->max('id')])->assertForbidden();
    }

    public function test_native_chat_uses_the_same_terminal_om_rejection(): void
    {
        $om = $this->user(RoleSlug::Om);
        $request = $this->request($this->user(RoleSlug::Requestor));
        $this->mobile($om);
        $this->postJson('/api/mobile/v1/requests/'.$request->id.'/om/reject', [
            'remarks' => 'Rejected by campaign OM.', 'signature_name' => $om->name, 'signature_data' => $this->signature(),
        ])->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->getJson('/api/mobile/v1/requests/'.$request->id)->assertOk()->assertJsonPath('permissions.send', false);
    }

    public function test_it_premises_purchasing_and_president_share_history_across_multiple_handoffs(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->request($owner, Status::ForItScreening);
        $it = $this->user(RoleSlug::ItStaff);
        $premises = $this->user(RoleSlug::PremisesStaff);
        $purchasing = $this->user(RoleSlug::PurchasingTeam);
        $president = $this->user(RoleSlug::President);
        $request->forceFill(['current_responsible_role_id' => $it->role_id])->save();
        $workflow = app(PurchaseRequestWorkflow::class);
        $steps = [
            [$it, Status::ForPremisesScreening],
            [$premises, Status::ForProcurement],
            [$premises, Status::ForCanvassing],
            [$purchasing, Status::ForSupplierApproval],
            [$president, Status::ForBudgetProcessing],
        ];
        foreach ($steps as [$actor, $next]) {
            $this->actingAs($actor)->getJson(route('chat.show', $request))->assertOk()->assertJsonPath('permissions.send', true);
            $this->postJson(route('chat.store', $request), ['body' => 'Handoff from '.$actor->name, 'client_id' => Str::uuid()])->assertCreated();
            if ($next === Status::ForPremisesScreening) {
                $request->forceFill(['request_type' => 'PREMISES', 'requested_team' => 'PREMISES'])->save();
            }
            $workflow->transition($request, $next, $actor, 'CHAT_HANDOFF_TEST', 'Continue processing.');
        }
        foreach ([$it, $premises, $purchasing, $president, $owner] as $reader) {
            $this->actingAs($reader)->getJson(route('chat.show', $request))->assertOk()
                ->assertJsonPath('request.id', $request->id)->assertJsonPath('request.campaign', $this->campaign->name)
                ->assertJsonPath('request.requestor', $owner->name)->assertJsonCount(10, 'messages');
        }
        $this->actingAs($it)->getJson(route('chat.show', $request))->assertOk()->assertJsonPath('permissions.send', false);
        $this->actingAs($this->user(RoleSlug::ItStaff))->getJson(route('chat.show', $request))->assertForbidden();
    }

    public function test_workflow_tracker_reports_recorded_handoffs_without_completing_skipped_teams(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->request($owner);
        app(PurchaseRequestWorkflow::class)->transition($request, Status::ForItScreening, $this->user(RoleSlug::Om), 'OM_APPROVED');
        $response = $this->actingAs($owner)->getJson(route('chat.show', $request))->assertOk();
        $response->assertJsonPath('workflow.1.state', 'visited')
            ->assertJsonPath('workflow.2.state', 'current')
            ->assertJsonPath('workflow.3.state', 'not_visited')
            ->assertJsonPath('workflow.8.state', 'not_visited');
        $request->forceFill(['status' => Status::Rejected])->save();
        $this->getJson(route('chat.show', $request))->assertOk()
            ->assertJsonPath('workflow.1.state', 'stopped')->assertJsonPath('permissions.send', false);
    }

    public function test_campaign_workspace_renders_assigned_campaign_and_keeps_request_threads(): void
    {
        $this->actingAs($this->user(RoleSlug::Om))->get(route('chat.index'))->assertOk()
            ->assertSee('data-campaign-home="true"', false)->assertSee('Campaign A')
            ->assertSee('pc-open-campaign')->assertSee('pc-request-summary');
        $this->actingAs($this->user(RoleSlug::Requestor))->get(route('chat.index'))->assertOk()
            ->assertSee('data-campaign-home="false"', false)->assertDontSee('id="pc-campaign-home"', false);
    }

    public function test_department_om_and_smt_see_cross_campaign_requests_for_their_screening_team(): void
    {
        $itCampaign = Campaign::firstOrCreate(['name' => 'IT DEPARTMENT'], ['is_active' => true]);
        $premisesCampaign = Campaign::firstOrCreate(['name' => 'PREMISES TEAM'], ['is_active' => true]);
        $origin = $this->user(RoleSlug::Requestor);
        $this->request($origin, Status::Draft); // Department scope never exposes another account's draft.
        $itRequest = $this->request($origin);
        $itRequest->forceFill(['chat_campaign_id' => $this->campaign->id])->save();
        $premisesRequest = $this->request($origin);
        $premisesRequest->forceFill(['requested_team' => 'PREMISES', 'request_type' => 'PREMISES'])->save();
        foreach ([RoleSlug::Om, RoleSlug::Smt] as $role) {
            foreach ([[$itCampaign, $itRequest, $premisesRequest], [$premisesCampaign, $premisesRequest, $itRequest]] as [$campaign, $visible, $hidden]) {
                $manager = $this->user($role, $campaign->id);
                $this->actingAs($manager)->getJson(route('chat.requests'))->assertOk()
                    ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
                $this->getJson(route('chat.show', $visible))->assertOk();
                $this->getJson(route('chat.show', $hidden))->assertForbidden();
                $this->assertTrue(app(ProcuraChat::class)->recipients($visible->chatMessages()->create([
                    'user_id' => $origin->id, 'body' => 'Department update',
                ]))->contains('id', $manager->id));
            }
        }
        $third = Campaign::create(['name' => 'Unrelated campaign', 'is_active' => true]);
        $this->actingAs($this->user(RoleSlug::Om, $third->id))->getJson(route('chat.requests'))->assertJsonCount(0, 'data');
        $this->actingAs($this->user(RoleSlug::Om))->getJson(route('chat.requests'))->assertJsonCount(2, 'data');
    }

    public function test_department_om_approves_another_campaign_and_keeps_visibility_after_later_handoffs(): void
    {
        $campaign = Campaign::firstOrCreate(['name' => 'IT DEPARTMENT'], ['is_active' => true]);
        $om = $this->user(RoleSlug::Om, $campaign->id);
        $request = $this->request($this->user(RoleSlug::Requestor));
        $this->actingAs($om)->postJson(route('om-approvals.approve', $request), [
            'signature_name' => $om->name, 'signature_data' => $this->signature(),
        ])->assertOk()->assertJsonPath('status', 'FOR_IT_SCREENING');
        foreach ([Status::ForCanvassing, Status::ForSupplierApproval, Status::ForBudgetProcessing, Status::ForPayment, Status::Completed, Status::Rejected] as $stage) {
            $request->forceFill(['status' => $stage, 'request_type' => 'PREMISES', 'requested_team' => 'PREMISES'])->save();
            $this->getJson(route('chat.requests'))->assertJsonCount(1, 'data');
            $this->getJson(route('chat.show', $request))->assertOk()->assertJsonPath('request.id', $request->id);
        }
        $campaign->update(['is_active' => false]);
        $this->actingAs($om->fresh());
        $this->getJson(route('chat.requests'))->assertJsonCount(0, 'data');
        $this->getJson(route('chat.show', $request))->assertForbidden();
    }

    public function test_separate_accounts_receive_each_handoff_and_screening_claim_does_not_follow_them(): void
    {
        $request = $this->request($this->user(RoleSlug::Requestor), Status::ForItScreening);
        $it = $this->user(RoleSlug::ItStaff);
        $canvasser = $this->user(RoleSlug::CanvassingStaff);
        $president = $this->user(RoleSlug::President);
        $purchasing = $this->user(RoleSlug::PurchasingTeam);
        $finance = $this->user(RoleSlug::Finance);
        $inventory = $this->user(RoleSlug::InventoryStaff);
        $request->forceFill(['assigned_user_id' => $it->id, 'current_responsible_role_id' => $it->role_id])->save();
        $workflow = app(PurchaseRequestWorkflow::class);
        $workflow->transition($request, Status::ForProcurement, $it, 'INVENTORY_UNAVAILABLE');
        $this->assertSame($it->id, $request->fresh()->assigned_user_id);
        $steps = [
            [Status::ForCanvassing, $it, $canvasser],
            [Status::ForSupplierApproval, $canvasser, $president],
            [Status::ForBudgetProcessing, $president, $purchasing],
        ];
        foreach ($steps as [$status, $actor, $receiver]) {
            $workflow->transition($request, $status, $actor, 'SEPARATE_ACCOUNT_HANDOFF');
            $this->assertNull($request->fresh()->assigned_user_id);
            $this->actingAs($receiver)->getJson(route('chat.requests'))->assertOk()->assertJsonPath('data.0.id', $request->id);
            // A PO must be prepared before budget processing becomes actionable.
            if ($status !== Status::ForBudgetProcessing) {
                $this->getJson(route('chat.show', $request))->assertOk()->assertJsonPath('permissions.send', true);
                $this->postJson(route('chat.store', $request), ['body' => 'Received by separate account', 'client_id' => Str::uuid()])->assertCreated();
                $this->assertTrue(app(ProcuraChat::class)->recipients($request->chatMessages()->where('kind', 'workflow')->latest('id')->first())->contains('id', $receiver->id));
            }
        }
        $request->forceFill(['status' => Status::ForBudgetMaker])->save();
        $workflow->transition($request, Status::AwaitingPresidentFinalApproval, $purchasing, 'BUDGET_PREPARED');
        $this->actingAs($purchasing)->getJson(route('chat.show', $request))->assertJsonPath('permissions.send', true);
        $workflow->transition($request, Status::ForPayment, $purchasing, 'BUDGET_FINALIZED');
        $this->actingAs($finance)->getJson(route('chat.show', $request))->assertJsonPath('permissions.send', true);
        $this->assertTrue(app(ProcuraChat::class)->recipients($request->chatMessages()->where('kind', 'workflow')->latest('id')->first())->contains('id', $finance->id));
        $workflow->transition($request, Status::ForReceiving, $finance, 'PAYMENT_RECORDED');
        $this->actingAs($inventory)->getJson(route('chat.show', $request))->assertJsonPath('permissions.send', true);
        $this->assertTrue(app(ProcuraChat::class)->recipients($request->chatMessages()->where('kind', 'workflow')->latest('id')->first())->contains('id', $inventory->id));
    }

    public function test_old_screening_claim_does_not_block_current_role_accounts_or_notify_old_claimant_forever(): void
    {
        $it = $this->user(RoleSlug::ItStaff);
        foreach ([
            [Status::ForCanvassing, RoleSlug::CanvassingStaff],
            [Status::ForSupplierApproval, RoleSlug::President],
            [Status::AwaitingPresidentFinalApproval, RoleSlug::PurchasingTeam],
            [Status::ForPayment, RoleSlug::Finance],
            [Status::ForReceiving, RoleSlug::InventoryStaff],
        ] as [$status, $role]) {
            $receiver = $this->user($role);
            $request = $this->request($this->user(RoleSlug::Requestor), $status);
            $handler = $role === RoleSlug::PurchasingTeam ? Role::where('slug', RoleSlug::Purchasing->value)->value('id') : $receiver->role_id;
            $request->forceFill(['assigned_user_id' => $it->id, 'current_responsible_role_id' => $handler])->save();
            $this->actingAs($receiver)->getJson(route('chat.show', $request))->assertOk()->assertJsonPath('permissions.send', true);
            $message = $request->chatMessages()->create(['body' => 'Legacy handoff update']);
            $recipients = app(ProcuraChat::class)->recipients($message);
            $this->assertTrue($recipients->contains('id', $receiver->id), $status->value);
            $this->assertFalse($recipients->contains('id', $it->id), $status->value);
        }
    }

    public function test_separate_po_role_accounts_see_current_queue_without_old_named_assignments(): void
    {
        $owner = $this->user(RoleSlug::Requestor);
        $request = $this->request($owner, Status::ForBudgetProcessing);
        $preparer = $this->user(RoleSlug::PurchasingTeam);
        $supplier = Supplier::create(['name' => 'Routing supplier', 'is_active' => true]);
        $order = PurchaseOrder::create([
            'po_number' => 'CHAT-PO-ROUTING', 'issued_at' => now(), 'purchase_request_id' => $request->id,
            'supplier_id' => $supplier->id, 'created_by' => $preparer->id, 'status' => 'FOR_CHECKING',
            'subtotal' => 100, 'tax_amount' => 0, 'total_amount' => 100,
        ]);
        $stages = [
            [PurchaseOrderApprovalStage::PreparedBy, $preparer],
            [PurchaseOrderApprovalStage::CheckedBy, $this->user(RoleSlug::PoChecker)],
            [PurchaseOrderApprovalStage::NotedBy, $this->user(RoleSlug::PoNoted)],
            [PurchaseOrderApprovalStage::ApprovedBy, $this->user(RoleSlug::PoApprover)],
        ];
        foreach ($stages as [$stage, $assigned]) {
            $order->approvals()->create(['stage' => $stage, 'assigned_user_id' => $assigned->id, 'status' => 'PENDING']);
        }
        $order->approvals()->where('stage', 'PO_PREPARED_BY')->update([
            'status' => 'APPROVED', 'acted_by' => $preparer->id, 'acted_at' => now(), 'signed_at' => now()->subMinute(),
            'signature_name' => $preparer->name, 'signature_data' => $this->signature(),
        ]);
        foreach ([
            ['FOR_CHECKING', RoleSlug::PoChecker, 'PO_CHECKED_BY'],
            ['FOR_ACKNOWLEDGEMENT', RoleSlug::PoNoted, 'PO_NOTED_BY'],
            ['FOR_APPROVAL', RoleSlug::PoApprover, 'PO_APPROVED_BY'],
        ] as [$status, $role, $stage]) {
            $order->update(['status' => $status]);
            $receiver = $this->user($role); // Not the account named in the legacy approval row.
            $this->actingAs($receiver)->getJson(route('chat.requests'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $request->id);
            $this->getJson(route('chat.show', $request))->assertOk()->assertJsonPath('permissions.send', true);
            app(WorkflowEmailNotifier::class)->purchaseOrderMoved($order->fresh(), $preparer);
            $message = $request->chatMessages()->latest('id')->firstOrFail();
            $this->assertSame('activity', $message->kind);
            $this->assertTrue(app(ProcuraChat::class)->recipients($message)->contains('id', $receiver->id));
            $ownerAlertCount = $owner->notifications()->count();
            (new SendProcuraChatPush($message->id))->handle(app(ProcuraChat::class));
            (new SendProcuraChatPush($message->id))->handle(app(ProcuraChat::class));
            $this->assertSame(1, $receiver->notifications()->where('data->chat_message_id', $message->id)->count());
            $this->assertSame($ownerAlertCount, $owner->notifications()->count());
            $order->approvals()->where('stage', $stage)->update(['status' => 'APPROVED']);
        }
    }

    #[TestWith(['IT DEPARTMENT', 'IT'])]
    #[TestWith(['PREMISES TEAM', 'PREMISES'])]
    public function test_department_requests_and_screening_are_separate_server_filtered_lists(string $name, string $team): void
    {
        $department = Campaign::firstOrCreate(['name' => $name], ['is_active' => true]);
        $manager = $this->user(RoleSlug::Om, $department->id);
        $own = $this->request($this->user(RoleSlug::Requestor, $department->id));
        $own->forceFill(['requested_team' => $team === 'IT' ? 'PREMISES' : 'IT',
            'request_type' => $team === 'IT' ? 'PREMISES' : 'IT', 'chat_campaign_id' => $department->id])->save();
        $foreign = $this->request($this->user(RoleSlug::Requestor));
        $foreign->forceFill(['requested_team' => $team, 'request_type' => $team,
            'chat_campaign_id' => $this->campaign->id])->save();
        $this->actingAs($manager)->getJson(route('chat.requests', ['scope' => 'department']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson(route('chat.requests', ['scope' => 'screening']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $foreign->id);
        $this->getJson(route('chat.requests', ['scope' => 'screening', 'filter' => 'approval']))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $foreign->id);
        $this->getJson(route('chat.requests', ['scope' => 'department', 'search' => $foreign->request_number]))
            ->assertJsonCount(0, 'data');
        $this->getJson(route('chat.requests', ['scope' => 'screening', 'search' => $own->request_number]))
            ->assertJsonCount(0, 'data');
        $this->getJson(route('chat.show', $foreign))->assertOk()->assertJsonPath('request.id', $foreign->id);
        $this->get(route('chat.index'))->assertOk()->assertSee($team === 'IT' ? 'IT Requests' : 'Premises Requests')
            ->assertSee($team === 'IT' ? 'IT Screening' : 'Premises Screening');
    }

    public function test_list_scopes_preserve_permissions_and_share_one_thread_for_department_screening_requests(): void
    {
        $department = Campaign::firstOrCreate(['name' => 'IT DEPARTMENT'], ['is_active' => true]);
        $owner = $this->user(RoleSlug::Requestor, $department->id);
        $request = $this->request($owner);
        $request->forceFill(['chat_campaign_id' => $department->id])->save();
        $manager = $this->user(RoleSlug::Om, $department->id);
        foreach (['department', 'screening'] as $scope) {
            $this->actingAs($manager)->getJson(route('chat.requests', ['scope' => $scope]))
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $request->id);
            $this->actingAs($this->user(RoleSlug::Om))->getJson(route('chat.requests', ['scope' => $scope]))
                ->assertJsonCount(0, 'data');
        }
        $this->actingAs($manager)->getJson(route('chat.requests', ['scope' => 'unknown']))->assertUnprocessable();
        $this->actingAs($owner)->getJson(route('chat.requests', ['scope' => 'screening']))->assertJsonCount(0, 'data');
    }

    public function test_legacy_campaign_tag_and_request_tab_stay_aligned_after_requestor_department_changes(): void
    {
        $department = Campaign::firstOrCreate(['name' => 'IT DEPARTMENT'], ['is_active' => true]);
        $owner = $this->user(RoleSlug::Requestor);
        $legacy = $this->request($owner);
        $this->assertNull($legacy->chat_campaign_id);
        $originalManager = $this->user(RoleSlug::Om);
        $itManager = $this->user(RoleSlug::Om, $department->id);
        $owner->update(['campaign_id' => $department->id]);
        $owner->refresh();

        $this->actingAs($itManager)->getJson(route('chat.requests', ['scope' => 'department']))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('chat.requests', ['scope' => 'screening']))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $legacy->id)
            ->assertJsonPath('data.0.campaign', $this->campaign->name);
        $this->actingAs($originalManager)->getJson(route('chat.requests'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $legacy->id);
        $new = $this->request($owner, Status::Draft);
        app(PurchaseRequestWorkflow::class)->submitForScreening($new, $owner, null, $owner->name, $this->signature());
        $this->actingAs($itManager)->getJson(route('chat.requests', ['scope' => 'department']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.0.campaign', 'IT DEPARTMENT');
    }

    public function test_saved_legacy_department_matching_accepts_case_and_spacing_without_using_new_account_campaign(): void
    {
        $department = Campaign::firstOrCreate(['name' => 'IT DEPARTMENT'], ['is_active' => true]);
        $owner = $this->user(RoleSlug::Requestor);
        $legacy = $this->request($owner);
        $legacy->forceFill(['department_campaign' => '  it department  ', 'request_type' => 'PREMISES', 'requested_team' => 'PREMISES'])->save();
        $this->actingAs($this->user(RoleSlug::Om, $department->id))
            ->getJson(route('chat.requests', ['scope' => 'department']))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $legacy->id);
        $this->actingAs($this->user(RoleSlug::Om))->getJson(route('chat.requests'))
            ->assertJsonCount(0, 'data');
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
