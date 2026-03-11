<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $email = 'anton@velor.app', string $password = 'secret12345'): User
    {
        return User::factory()->create([
            'email' => $email,
            'password_hash' => Hash::make($password),
        ]);
    }

    public function test_logs_in_with_valid_credentials_and_returns_200(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'anton@velor.app',
            'password' => 'secret12345',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'display_name', 'email', 'locale'],
                ],
            ]);

        $this->assertIsString($response->json('data.user.id'));
    }

    public function test_returns_401_with_wrong_password(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'anton@velor.app',
            'password' => 'wrong_password',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Credenciales inválidas.']);
    }

    public function test_returns_401_with_non_existent_email(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@velor.app',
            'password' => 'secret12345',
        ]);

        $response->assertStatus(401);
    }

    public function test_returns_422_when_fields_are_missing(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_blocks_login_when_another_active_session_exists(): void
    {
        $this->createUser();

        User::query()
            ->where('email', 'anton@velor.app')
            ->update([
                'active_session_expires_at' => now('UTC')->addMinutes(30),
            ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'anton@velor.app',
            'password' => 'secret12345',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'SESSION_ALREADY_ACTIVE');
    }

    public function test_allows_login_when_previous_session_lock_is_expired(): void
    {
        $this->createUser();

        User::query()
            ->where('email', 'anton@velor.app')
            ->update([
                'active_session_expires_at' => now('UTC')->subMinute(),
            ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'anton@velor.app',
            'password' => 'secret12345',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.email', 'anton@velor.app');
    }
}
