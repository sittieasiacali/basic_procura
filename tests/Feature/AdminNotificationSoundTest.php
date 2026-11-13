<?php

namespace Tests\Feature;

use App\Models\NotificationSoundSetting;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNotificationSoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_preview_and_save_a_shared_ringtone_with_an_audit_record(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->get(route('admin.notification-sound.edit'))->assertOk()
            ->assertSee('data-admin-notification-tone', false)->assertSee('Test sound');
        $this->put(route('admin.notification-sound.update'), ['tone' => 'bell'])
            ->assertRedirect(route('admin.notification-sound.edit'));
        $this->assertSame('bell', NotificationSoundSetting::currentTone());
        $this->assertDatabaseHas('notification_sound_settings', ['id' => 1, 'tone' => 'bell', 'updated_by' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'NOTIFICATION_RINGTONE_UPDATED']);
        $this->put(route('admin.notification-sound.update'), ['tone' => 'bell'])->assertRedirect();
        $this->assertSame(1, NotificationSoundSetting::findOrFail(1)->auditLogs()->count());
    }

    public function test_users_receive_the_admin_tone_and_cannot_change_it(): void
    {
        NotificationSoundSetting::findOrFail(1)->update(['tone' => 'pulse']);
        foreach (['requestor', 'om', 'smt', 'purchasing-team'] as $slug) {
            $this->actingAs($this->user($slug));
            $this->get(route('admin.notification-sound.edit'))->assertForbidden();
            $this->put(route('admin.notification-sound.update'), ['tone' => 'bell'])->assertForbidden();
            $this->getJson(route('notifications.summary'))->assertOk()->assertJsonPath('ringtone', 'pulse');
            $this->get(route('notifications.index'))->assertOk()->assertSee('Double beep')->assertSee('Set by Admin')
                ->assertDontSee('data-admin-notification-tone', false)->assertDontSee('data-notification-sound-tone', false)
                ->{in_array($slug, ['om', 'smt'], true) ? 'assertDontSee' : 'assertSee'}('data-notification-sound-toggle', false);
        }
        $this->assertSame('pulse', NotificationSoundSetting::currentTone());
    }

    public function test_admin_can_save_every_available_four_second_ringtone(): void
    {
        $this->actingAs($this->user('admin'));
        $page = $this->get(route('admin.notification-sound.edit'))->assertOk()->assertSee('four seconds');
        foreach (NotificationSoundSetting::TONES as $tone => $label) {
            $page->assertSee($label);
            $this->put(route('admin.notification-sound.update'), ['tone' => $tone])->assertRedirect();
            $this->assertSame($tone, NotificationSoundSetting::currentTone());
            $this->getJson(route('notifications.summary'))->assertOk()->assertJsonPath('ringtone', $tone);
        }
    }

    public function test_unknown_tones_are_rejected_and_guests_cannot_save(): void
    {
        $this->put(route('admin.notification-sound.update'), ['tone' => 'bell'])->assertRedirect(route('login'));
        $this->actingAs($this->user('admin'))->put(route('admin.notification-sound.update'), ['tone' => 'custom-url'])
            ->assertSessionHasErrors('tone');
        $this->assertSame('chime', NotificationSoundSetting::currentTone());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function user(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id'), 'must_change_password' => false]);
    }
}
