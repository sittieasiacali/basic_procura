<?php

namespace Tests\Feature;

use App\Enums\PurchaseRequestStatus;
use App\Models\Campaign;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\SupplierQuotation;
use App\Models\User;
use App\Notifications\ProcurementUpdate;
use App\Support\MobileNavigation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignMobileWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_campaign_roles_get_chat_updates_and_account_on_mobile_while_desktop_dashboard_remains_available(): void
    {
        foreach (['om', 'om-smts-approver', 'smt'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get(route('dashboard'))->assertOk()
                ->assertSee('name="mobile-campaign-workspace" data-allowed="false"', false);
            $this->get(route('chat.index'))->assertOk()
                ->assertSee('name="mobile-campaign-workspace" data-allowed="true"', false)
                ->assertDontSee('data-filter="mine"', false)
                ->assertSee('data-campaign-notification-alert', false)
                ->assertDontSee('data-campaign-vibrate', false)
                ->assertDontSee('data-campaign-test-alert', false)
                ->assertSee('Enable ringtone and vibration?')
                ->assertSee('data-consent-auto-open', false)
                ->assertSee('Not now')
                ->assertSee('aria-label="Open menu"', false)
                ->assertDontSee('<nav class="mobile-bottom-navigation"', false);
            $navigation = app(MobileNavigation::class)->forUser($user);
            $this->assertSame(['Chats', 'Updates', 'Account'], array_column($navigation['tabs'], 'label'));
            $this->assertEmpty($navigation['quickActions']);
            $this->get(route('mobile.menu'))->assertOk()->assertDontSee('New request')
                ->assertDontSee('My items')->assertSee('Sign out');
            $this->get(route('notifications.index'))->assertOk()
                ->assertSee('name="mobile-campaign-workspace" data-allowed="true"', false)
                ->assertDontSee('data-consent-auto-open', false);
        }
    }

    public function test_other_workspaces_keep_their_existing_mobile_access(): void
    {
        $this->actingAs($this->user('purchasing-team'))->get(route('dashboard'))->assertOk()
            ->assertDontSee('name="mobile-campaign-workspace"', false)
            ->assertDontSee('data-campaign-notification-alert', false);
    }

    public function test_campaign_notifications_and_counts_exclude_other_campaigns_and_follow_current_assignment(): void
    {
        $campaign = Campaign::create(['name' => 'Operations', 'is_active' => true]);
        $foreign = Campaign::create(['name' => 'Other campaign', 'is_active' => true]);
        $manager = $this->user('om', $campaign);
        $ownRequest = $this->purchaseRequest($campaign);
        $foreignRequest = $this->purchaseRequest($foreign);
        $this->notify($manager, $ownRequest, 'Operations alert');
        $this->notify($manager, $foreignRequest, 'Foreign alert');
        $this->actingAs($manager)->getJson(route('notifications.summary'))->assertOk()
            ->assertJsonPath('unread_count', 1)->assertJsonCount(1, 'recent_unread')
            ->assertJsonPath('recent_unread.0.title', 'Operations alert')
            ->assertJsonPath('recent_unread.0.url', route('chat.index', ['request' => $ownRequest->id]))
            ->assertJsonPath('recent_unread.0.id', $manager->notifications()->where('data->title', 'Operations alert')->sole()->id);
        $this->get(route('notifications.index'))->assertOk()->assertSee('Operations alert')
            ->assertDontSee('Foreign alert')->assertSee('Open conversation')
            ->assertDontSee('data-notification-sound-toggle', false)->assertDontSee('data-notification-sound-test', false)
            ->assertSee('name="notification-sound-user" content="'.$manager->id.'"', false)
            ->assertSee(route('chat.index', ['request' => $ownRequest->id]), false);
        $manager->update(['campaign_id' => $foreign->id]);
        $this->get(route('notifications.index'))->assertOk()->assertSee('Foreign alert')->assertDontSee('Operations alert');
        $manager->update(['campaign_id' => null]);
        $this->getJson(route('notifications.summary'))->assertOk()->assertJsonPath('unread_count', 0);
    }

    public function test_opening_chat_clears_loaded_message_alerts_without_clearing_newer_or_unrelated_alerts(): void
    {
        $campaign = Campaign::create(['name' => 'Operations', 'is_active' => true]);
        $manager = $this->user('smt', $campaign);
        $purchaseRequest = $this->purchaseRequest($campaign);
        $otherRequest = $this->purchaseRequest($campaign);
        $loaded = $purchaseRequest->chatMessages()->create(['kind' => 'workflow', 'body' => 'Submitted']);
        $newer = $purchaseRequest->chatMessages()->create(['kind' => 'workflow', 'body' => 'Reviewed']);
        $this->notify($manager, $purchaseRequest, 'Loaded message', $loaded->id);
        $this->notify($manager, $purchaseRequest, 'New message', $newer->id);
        $this->notify($manager, $otherRequest, 'Other request', $loaded->id);
        $this->actingAs($manager)->postJson(route('chat.read', $purchaseRequest), ['message_id' => $loaded->id])->assertOk();
        $this->getJson(route('notifications.summary'))->assertOk()->assertJsonPath('unread_count', 2);
        $this->assertSame('Loaded message', $manager->notifications()->whereNotNull('read_at')->sole()->data['title']);
    }

    public function test_mark_all_read_only_changes_current_campaign_notifications(): void
    {
        $campaign = Campaign::create(['name' => 'Operations', 'is_active' => true]);
        $other = Campaign::create(['name' => 'Other campaign', 'is_active' => true]);
        $manager = $this->user('smt', $campaign);
        $this->notify($manager, $this->purchaseRequest($campaign), 'Own alert');
        $this->notify($manager, $this->purchaseRequest($other), 'Other alert');
        $foreignNotification = $manager->notifications()->where('data->title', 'Other alert')->sole();
        $this->actingAs($manager)->post(route('notifications.read', $foreignNotification->id))->assertNotFound();
        $this->post(route('notifications.read-all'))->assertRedirect();
        $this->assertSame('Other alert', $manager->unreadNotifications()->sole()->data['title']);
    }

    private function user(string $role, ?Campaign $campaign = null): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->value('id'),
            'campaign_id' => $campaign?->id, 'must_change_password' => false,
        ]);
    }

    private function purchaseRequest(Campaign $campaign): PurchaseRequest
    {
        $owner = $this->user('requestor', $campaign);

        return PurchaseRequest::create([
            'request_number' => 'CAMPAIGN-'.$owner->id, 'title' => 'Campaign equipment',
            'requestor_id' => $owner->id, 'chat_campaign_id' => $campaign->id,
            'department_campaign' => $campaign->name, 'purpose' => 'Operations',
            'request_type' => 'IT', 'delivery_location' => SupplierQuotation::DELIVERY_LOCATIONS[0], 'status' => PurchaseRequestStatus::ForOmApproval,
        ]);
    }

    private function notify(User $user, PurchaseRequest $purchaseRequest, string $title, ?int $messageId = null): void
    {
        $user->notify(new ProcurementUpdate(array_filter([
            'title' => $title, 'purchase_request_id' => $purchaseRequest->id,
            'chat_message_id' => $messageId,
        ])));
    }
}
