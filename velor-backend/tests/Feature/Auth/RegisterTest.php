<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Ensure no auth state leaks to subsequent test classes
        $this->app['auth']->forgetGuards();
        parent::tearDown();
    }

    private array $validPayload = [
        'display_name' => 'Anton Rivera',
        'email' => 'anton@velor.app',
        'password' => 'secret12345',
        'password_confirmation' => 'secret12345',
        'locale' => 'es',
        'time_zone_name' => 'America/Lima',
    ];

    public function test_it_registers_a_user_and_returns_201(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validPayload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'display_name', 'email', 'locale'],
                    'settings' => ['locale', 'time_zone_name'],
                ],
            ]);

        $this->assertIsString($response->json('data.user.id'));

        $this->assertDatabaseHas('users', [
            'email' => 'anton@velor.app',
            'display_name' => 'Anton Rivera',
            'locale' => 'es',
        ]);

        $this->assertDatabaseHas('user_settings', [
            'locale' => 'es',
            'time_zone_name' => 'America/Lima',
        ]);
    }

    public function test_it_returns_422_when_email_is_already_taken(): void
    {
        User::factory()->create(['email' => 'anton@velor.app']);

        $response = $this->postJson('/api/v1/auth/register', $this->validPayload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_it_returns_422_when_locale_is_invalid(): void
    {
        $payload = array_merge($this->validPayload, ['locale' => 'fr']);

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['locale']);
    }

    public function test_it_returns_422_when_timezone_is_invalid(): void
    {
        $payload = array_merge($this->validPayload, ['time_zone_name' => 'Not/AZone']);

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['time_zone_name']);
    }

    public function test_it_returns_422_when_passwords_do_not_match(): void
    {
        $payload = array_merge($this->validPayload, ['password_confirmation' => 'wrongpass']);

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_it_creates_user_settings_in_the_same_transaction(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validPayload);

        $user = User::where('email', 'anton@velor.app')->first();
        $this->assertNotNull($user);
        $this->assertNotNull(UserSetting::where('user_id', $user->id)->first());
    }
}
