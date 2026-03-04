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
        $this->getJson('/api/v1/focus/tasks')
            ->assertStatus(401);
    }

    public function test_list_returns_only_authenticated_user_tasks(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Own task',
            'version' => 2,
        ]);

        FocusTask::query()->create([
            'user_id' => $otherUser->id,
            'name' => 'Other task',
            'version' => 7,
        ]);

        $response = $this->getJson('/api/v1/focus/tasks');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Own task');

        $this->assertIsString($response->json('data.0.id'));
        $this->assertIsString($response->json('data.0.user_id'));
    }

    public function test_create_task_returns_201_and_serializes_ids_as_string(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/focus/tasks', [
            'name' => 'Deep Work: API',
            'icon_tag' => 'brain',
            'color_tag' => '#1A73E8',
            'alarm_time_local' => '09:00',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Deep Work: API')
            ->assertJsonPath('data.alarm_time_local', '09:00')
            ->assertJsonPath('data.version', 1);

        $this->assertIsString($response->json('data.id'));
        $this->assertIsString($response->json('data.user_id'));

        $this->assertDatabaseHas('focus_tasks', [
            'user_id' => $user->id,
            'name' => 'Deep Work: API',
            'icon_tag' => 'brain',
            'color_tag' => '#1A73E8',
            'alarm_time_local' => '09:00',
            'version' => 1,
        ]);
    }

    public function test_patch_updates_metadata_and_increments_version(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Initial',
            'icon_tag' => 'brain',
            'color_tag' => '#1A73E8',
            'alarm_time_local' => '09:00',
            'version' => 3,
        ]);

        $response = $this->patchJson("/api/v1/focus/tasks/{$task->id}", [
            'if_version' => 3,
            'name' => 'Updated',
            'icon_tag' => 'code',
            'color_tag' => '#0F9D58',
            'alarm_time_local' => '10:00',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated')
            ->assertJsonPath('data.version', 4);

        $this->assertDatabaseHas('focus_tasks', [
            'id' => $task->id,
            'name' => 'Updated',
            'icon_tag' => 'code',
            'color_tag' => '#0F9D58',
            'alarm_time_local' => '10:00',
            'version' => 4,
        ]);
    }

    public function test_patch_rejects_runtime_fields_with_422(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Initial',
            'version' => 1,
        ]);

        $this->patchJson("/api/v1/focus/tasks/{$task->id}", [
            'if_version' => 1,
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
            'version' => 5,
        ]);

        $response = $this->patchJson("/api/v1/focus/tasks/{$task->id}", [
            'if_version' => 4,
            'name' => 'Will fail',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'VERSION_CONFLICT')
            ->assertJsonPath('data.current.version', 5);

        $this->assertIsString($response->json('data.current.id'));
    }

    public function test_delete_uses_if_match_and_returns_204(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Delete me',
            'version' => 4,
        ]);

        $response = $this->withHeaders([
            'If-Match' => '4',
        ])->deleteJson("/api/v1/focus/tasks/{$task->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('focus_tasks', [
            'id' => $task->id,
        ]);
    }

    public function test_delete_supports_if_version_query_fallback(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Delete me',
            'version' => 2,
        ]);

        $this->deleteJson("/api/v1/focus/tasks/{$task->id}?if_version=2")
            ->assertStatus(204);

        $this->assertDatabaseMissing('focus_tasks', [
            'id' => $task->id,
        ]);
    }

    public function test_delete_requires_if_version_from_header_or_query(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Delete me',
            'version' => 2,
        ]);

        $this->deleteJson("/api/v1/focus/tasks/{$task->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['if_version']);
    }
}
