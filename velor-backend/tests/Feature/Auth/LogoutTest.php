<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_logout_and_gets_200(): void
    {
        $user = User::factory()->create([
            'active_session_expires_at' => now('UTC')->addMinutes(30),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Logged out.']);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'active_session_expires_at' => null,
        ]);
    }

    public function test_unauthenticated_request_to_logout_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
    }
}
