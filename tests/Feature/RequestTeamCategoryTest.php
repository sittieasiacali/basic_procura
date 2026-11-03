<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Models\Campaign;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CampaignSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestTeamCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, CampaignSeeder::class]);
    }

    public function test_create_form_requires_a_screening_team_and_title_category(): void
    {
        $this->actingAs($this->user())->get(route('purchase-requests.create'))->assertOk()
            ->assertSee('name="requested_team"', false)
            ->assertDontSee('Not sure')
            ->assertSee('Request title category')
            ->assertDontSee('name="title"', false)
            ->assertSee('<optgroup label="IT" data-team="IT"', false)
            ->assertSee('<optgroup label="Premises" data-team="PREMISES"', false)
            ->assertDontSee('data-team="GENERAL"', false)
            ->assertSee('Computers and laptops')
            ->assertSee('Furniture and fixtures')
            ->assertDontSee('name="request_scope"', false)->assertDontSee('Purchase type');
    }

    public function test_team_and_category_are_saved_and_the_category_becomes_the_title(): void
    {
        $this->actingAs($this->user())
            ->post(route('purchase-requests.store'), $this->payload(['requested_team' => '', 'request_category' => '']))
            ->assertSessionHasErrors('request_category');
        $this->post(route('purchase-requests.store'), $this->payload(['requested_team' => '', 'request_category' => 'COMPUTERS']))
            ->assertSessionHasErrors('requested_team');
        $this->post(route('purchase-requests.store'), $this->payload(['requested_team' => 'IT', 'request_category' => 'FURNITURE']))
            ->assertSessionHasErrors('request_category');
        $this->post(route('purchase-requests.store'), $this->payload(['requested_team' => 'FINANCE', 'request_category' => 'COMPUTERS']))
            ->assertSessionHasErrors('requested_team');
        $this->assertSame(0, PurchaseRequest::query()->count());

        $this->post(route('purchase-requests.store'), $this->payload(['requested_team' => 'PREMISES', 'request_category' => 'FURNITURE', 'request_scope' => 'SET']))
            ->assertSessionHasNoErrors();
        $saved = PurchaseRequest::query()->sole();
        // The purchase type is no longer asked; a stray value is ignored.
        $this->assertSame(['PREMISES', 'FURNITURE', null], [$saved->requested_team, $saved->request_category, $saved->request_scope]);
        $this->assertSame('Furniture and fixtures', $saved->title);
        $this->get(route('purchase-requests.show', $saved))->assertOk()->assertSee('Premises · Furniture and fixtures');

        $this->post(route('purchase-requests.store'), $this->payload(['requested_team' => 'IT', 'request_category' => 'PERIPHERALS']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Peripherals and accessories', PurchaseRequest::query()->latest('id')->first()->title);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides): array
    {
        return [
            'action' => 'save_draft',
            'department_campaign' => 'Operations',
            'purpose' => 'Testing the team dropdown',
            'required_date' => now()->addWeek()->format('Y-m-d'),
            'delivery_location' => 'Main Office',
            'items' => [['description' => 'Office chair', 'quantity' => 1, 'unit' => 'pc', 'estimated_unit_price' => 0]],
            ...$overrides,
        ];
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', RoleSlug::TeamLeader->value)->value('id'),
            'campaign_id' => Campaign::query()->value('id'),
        ]);
    }
}
