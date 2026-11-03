<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_active_user_can_log_in_and_log_out(): void
    {
        $role = Role::query()->create([
            'name' => 'Requestor',
            'slug' => RoleSlug::Requestor->value,
        ]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password' => 'Procura123!',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'Procura123!',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Request workspace')
            ->assertSee('Requests for my action');
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'is_active' => false,
            'password' => 'Procura123!',
        ]);

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'Procura123!',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
