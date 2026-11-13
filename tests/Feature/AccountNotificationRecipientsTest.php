<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountNotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->actingAs(User::factory()->create(['role_id' => $this->roleId(RoleSlug::Admin)]));
    }

    public function test_add_user_defaults_to_tl_requestor_with_visible_required_recipient_fields(): void
    {
        foreach (['admin.accounts.index', 'dashboard'] as $route) {
            $response = $this->get(route($route))->assertOk();
            $xpath = $this->xpath($response->getContent());
            $requestorRoleId = $this->roleId(RoleSlug::Requestor);
            $this->assertSame(1, $xpath->query('//input[@name="role_ids[]" and @value="'.$requestorRoleId.'" and @checked]')->length);
            $this->assertSame(1, $xpath->query('//fieldset[@data-new-user-requestor-fields and not(@hidden) and not(@disabled)]')->length);
            foreach (['new-user-om-email', 'new-user-smt-email'] as $id) {
                $this->assertSame(1, $xpath->query('//input[@id="'.$id.'" and @type="email" and @required]')->length);
            }
        }
    }

    public function test_a_tl_account_cannot_be_created_without_both_management_emails(): void
    {
        $payload = $this->payload();
        unset($payload['om_notification_email'], $payload['smt_notification_email']);
        $this->from(route('admin.accounts.index'))->post(route('admin.accounts.store'), $payload)
            ->assertRedirect(route('admin.accounts.index'))
            ->assertSessionHasErrors(['om_notification_email', 'smt_notification_email']);
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
        $xpath = $this->xpath($this->get(route('admin.accounts.index'))->assertOk()->getContent());
        $this->assertSame(2, $xpath->query('//fieldset[@data-new-user-requestor-fields]//input[contains(@class,"is-invalid")]')->length);
        $this->assertSame(1, $xpath->query('//div[@id="add-new-user-modal" and @data-new-user-auto-open]')->length);
    }

    public function test_both_recipient_fields_reject_invalid_email_addresses(): void
    {
        foreach (['om_notification_email', 'smt_notification_email'] as $field) {
            $payload = [...$this->payload(), $field => 'not-an-email'];
            $this->post(route('admin.accounts.store'), $payload)->assertSessionHasErrors($field);
            $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
        }
    }

    public function test_recipient_addresses_are_saved_normalized_on_the_tl_account(): void
    {
        $payload = [...$this->payload(), 'om_notification_email' => ' OM@Example.Test ', 'smt_notification_email' => ' SMT@Example.Test '];
        $this->post(route('admin.accounts.store'), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', [
            'email' => $payload['email'], 'role_id' => $this->roleId(RoleSlug::Requestor),
            'om_notification_email' => 'om@example.test', 'smt_notification_email' => 'smt@example.test',
        ]);
    }

    public function test_editing_a_tl_account_cannot_clear_its_recipients(): void
    {
        $payload = $this->payload();
        $this->post(route('admin.accounts.store'), $payload)->assertSessionHasNoErrors();
        $account = User::query()->where('email', $payload['email'])->firstOrFail();
        unset($payload['password']);
        $this->put(route('admin.accounts.update', $account), [...$payload, 'om_notification_email' => '', 'smt_notification_email' => ''])
            ->assertSessionHasErrors(['om_notification_email', 'smt_notification_email']);
        $this->assertSame('om@example.test', $account->fresh()->om_notification_email);
        $this->assertSame('smt@example.test', $account->fresh()->smt_notification_email);
    }

    public function test_other_staff_roles_do_not_require_tl_notification_recipients(): void
    {
        $payload = [...$this->payload(), 'role_id' => $this->roleId(RoleSlug::PurchasingTeam)];
        unset($payload['campaign_id'], $payload['om_notification_email'], $payload['smt_notification_email']);
        $this->post(route('admin.accounts.store'), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => $payload['email'], 'om_notification_email' => null, 'smt_notification_email' => null]);
        $response = $this->withSession(['_old_input' => ['role_id' => $payload['role_id']]])->get(route('admin.accounts.index'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//fieldset[@data-new-user-requestor-fields and @disabled and @hidden]')->length);
        $this->assertSame(0, $xpath->query('//fieldset[@data-new-user-requestor-fields]//input[@required]')->length);
    }

    public function test_management_only_roles_hide_tl_recipients_but_keep_campaign_required(): void
    {
        foreach ([[RoleSlug::Om], [RoleSlug::Smt], [RoleSlug::Om, RoleSlug::Smt], [RoleSlug::Om, RoleSlug::Requestor]] as $roles) {
            $requiresRecipients = in_array(RoleSlug::Requestor, $roles, true);
            foreach (['admin.accounts.index', 'dashboard'] as $route) {
                $response = $this->withSession(['_old_input' => [
                    'role_selection' => 1,
                    'role_ids' => array_map(fn ($role) => $this->roleId($role), $roles),
                ]])->get(route($route))->assertOk();
                $xpath = $this->xpath($response->getContent());
                $condition = $requiresRecipients ? 'not(@hidden) and not(@disabled)' : '@hidden and @disabled';
                $this->assertSame(1, $xpath->query('//fieldset[@data-new-user-requestor-fields and '.$condition.']')->length);
                $this->assertSame(1, $xpath->query('//select[@id="new-user-campaign" and @required]')->length);
                $this->assertSame($requiresRecipients ? 2 : 0, $xpath->query('//fieldset[@data-new-user-requestor-fields]//input[@required]')->length);
            }
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }

    private function roleId(RoleSlug $role): int
    {
        return Role::query()->where('slug', $role->value)->value('id');
    }

    private function payload(): array
    {
        return [
            'employee_id' => 'TL-RECIPIENT-TEST', 'name' => 'Recipient Test TL', 'email' => 'tl@example.test',
            'role_id' => $this->roleId(RoleSlug::Requestor),
            'campaign_id' => Campaign::query()->where('name', 'AC MOBILITY - EVRO')->firstOrFail()->id,
            'om_notification_email' => 'om@example.test', 'smt_notification_email' => 'smt@example.test',
            'password' => 'TemporaryTest123!', 'is_active' => true, 'create_account_modal' => 1, 'account_origin' => 'accounts',
        ];
    }
}
