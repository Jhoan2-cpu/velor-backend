<?php

namespace Tests\Feature\Focus;

use App\Models\FocusTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskCardsCrudBasicTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/tasks')
            ->assertStatus(401);
    }

    public function test_plain_get_without_accept_header_returns_401_instead_of_redirect_or_500(): void
    {
        $this->get('/api/v1/tasks')->assertStatus(401);
        $this->get('/api/v1/focus/tasks')->assertStatus(401);
    }

    public function test_create_without_accept_header_still_returns_json_validation_errors(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->post('/api/v1/focus/tasks', [
            'name' => 'Task',
            'icon_tag' => 'code',
            'color_tag' => 'blue',
            'active_mode' => 'stopwatch',
        ]);

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors(['active_mode'])
            ->assertJsonPath('errors.active_mode.0', 'The active_mode field is not allowed in this endpoint.');
    }

    public function test_list_returns_only_authenticated_user_tasks_and_server_time_meta(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Own task',
            'icon_tag' => 'learning',
            'color_tag' => 'green',
            'version' => 2,
        ]);

        FocusTask::query()->create([
            'user_id' => $otherUser->id,
            'name' => 'Other task',
            'icon_tag' => 'book',
            'color_tag' => 'blue',
            'version' => 7,
        ]);

        $response = $this->getJson('/api/v1/tasks');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Own task');

        $this->assertIsString($response->json('data.0.id'));
        $this->assertIsString($response->json('data.0.user_id'));
        $this->assertNotNull($response->json('meta.server_now_utc'));
    }

    public function test_create_task_returns_201_with_runtime_defaults(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/tasks', [
            'name' => 'Study English',
            'icon_tag' => 'learning',
            'color_tag' => 'green',
            'alarm_time_local' => '20:30',
            'timer_initial_seconds' => 1500,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Study English')
            ->assertJsonPath('data.icon_tag', 'learning')
            ->assertJsonPath('data.color_tag', 'green')
            ->assertJsonPath('data.timer_initial_seconds', 1500)
            ->assertJsonPath('data.timer_remaining_seconds', 1500)
            ->assertJsonPath('data.stopwatch_elapsed_seconds', 0)
            ->assertJsonPath('data.total_tracked_seconds', 0)
            ->assertJsonPath('data.active_mode', 'timer')
            ->assertJsonPath('data.state', 'stopped')
            ->assertJsonPath('data.version', 1);

        $this->assertIsString($response->json('data.id'));
        $this->assertIsString($response->json('data.user_id'));
    }

    public function test_create_task_accepts_freeform_icon_and_color_values(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tasks', [
            'name' => 'Study English',
            'icon_tag' => 'invalid_icon',
            'color_tag' => 'invalid_color',
        ])->assertStatus(201);
    }

    public function test_create_task_accepts_frontend_alias_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/focus/tasks', [
            'title' => 'Alias title',
            'icon' => 'briefcase',
            'color' => '#1A73E8',
            'alarmTimeLocal' => '08:30',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Alias title')
            ->assertJsonPath('data.icon_tag', 'briefcase')
            ->assertJsonPath('data.color_tag', '#1A73E8')
            ->assertJsonPath('data.alarm_time_local', '08:30');
    }

    public function test_create_task_accepts_api_payload_wrapper(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/focus/tasks', [
            'apiPayload' => [
                'title' => 'Wrapped title',
                'icon' => 'code',
                'color' => '#2E7D32',
                'alarmTimeLocal' => '07:45',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Wrapped title')
            ->assertJsonPath('data.icon_tag', 'code')
            ->assertJsonPath('data.color_tag', '#2E7D32')
            ->assertJsonPath('data.alarm_time_local', '07:45');
    }

    public function test_create_task_accepts_api_payload_wrapper_as_json_string_in_form_body(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ])
            ->post('/api/v1/focus/tasks', [
                'editingTaskId' => null,
                'apiPayload' => json_encode([
                    'name' => 'Wrapped from form',
                    'icon_tag' => 'briefcase',
                    'color_tag' => '#1A73E8',
                    'alarm_time_local' => null,
                ], JSON_THROW_ON_ERROR),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Wrapped from form')
            ->assertJsonPath('data.icon_tag', 'briefcase')
            ->assertJsonPath('data.color_tag', '#1A73E8')
            ->assertJsonPath('data.alarm_time_local', null);
    }

    public function test_patch_updates_task_with_version_and_increments_it(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Study English',
            'icon_tag' => 'learning',
            'color_tag' => 'green',
            'alarm_time_local' => '20:30',
            'timer_initial_seconds' => 1500,
            'timer_remaining_seconds' => 1500,
            'state' => 'stopped',
            'active_mode' => 'timer',
            'version' => 4,
        ]);

        $response = $this->patchJson("/api/v1/tasks/{$task->id}", [
            'name' => 'Study English Advanced',
            'color_tag' => 'violet',
            'alarm_time_local' => '21:00',
            'timer_initial_seconds' => 1800,
            'version' => 4,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Study English Advanced')
            ->assertJsonPath('data.color_tag', 'violet')
            ->assertJsonPath('data.timer_initial_seconds', 1800)
            ->assertJsonPath('data.timer_remaining_seconds', 1800)
            ->assertJsonPath('data.version', 5);
    }

    public function test_patch_rejects_runtime_fields_with_422(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Initial',
            'icon_tag' => 'learning',
            'color_tag' => 'green',
            'version' => 1,
        ]);

        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'version' => 1,
            'state' => 'working',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['state'])
            ->assertJsonPath('errors.state.0', 'The state field is not allowed in this endpoint.');
    }

    public function test_patch_returns_409_on_version_conflict(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Initial',
            'icon_tag' => 'learning',
            'color_tag' => 'green',
            'version' => 5,
        ]);

        $response = $this->patchJson("/api/v1/tasks/{$task->id}", [
            'name' => 'Will fail',
            'version' => 4,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'TASK_VERSION_CONFLICT')
            ->assertJsonPath('data.server_task.version', 5);

        $this->assertIsString($response->json('data.server_task.id'));
    }

    public function test_patch_accepts_frontend_alias_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Initial',
            'icon_tag' => 'learning',
            'color_tag' => 'green',
            'version' => 3,
        ]);

        $response = $this->patchJson("/api/v1/focus/tasks/{$task->id}", [
            'ifVersion' => 3,
            'title' => 'Renamed by alias',
            'icon' => 'code',
            'color' => '#FF5733',
            'alarmTimeLocal' => '10:15',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Renamed by alias')
            ->assertJsonPath('data.icon_tag', 'code')
            ->assertJsonPath('data.color_tag', '#FF5733')
            ->assertJsonPath('data.alarm_time_local', '10:15')
            ->assertJsonPath('data.version', 4);
    }

    public function test_delete_uses_body_version_and_returns_204(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Delete me',
            'icon_tag' => 'learning',
            'color_tag' => 'green',
            'version' => 5,
        ]);

        $response = $this->deleteJson("/api/v1/tasks/{$task->id}", [
            'version' => 5,
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseMissing('focus_tasks', [
            'id' => $task->id,
        ]);
    }

    public function test_delete_requires_version(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Delete me',
            'icon_tag' => 'learning',
            'color_tag' => 'green',
            'version' => 2,
        ]);

        $this->deleteJson("/api/v1/tasks/{$task->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['version']);
    }
}
