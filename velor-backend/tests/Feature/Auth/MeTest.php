<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_gets_their_data_on_200(): void
    {
        $user = User::factory()->create([
            'display_name' => 'Anton Rivera',
            'email' => 'anton@velor.app',
            'locale' => 'es',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['id', 'display_name', 'email', 'locale'],
            ]);
    }

    public function test_unauthenticated_request_to_me_returns_401(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }
}
