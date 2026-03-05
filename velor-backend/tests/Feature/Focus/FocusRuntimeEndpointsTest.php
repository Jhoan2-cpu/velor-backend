<?php

namespace Tests\Feature\Focus;

use App\Models\FocusTask;
use App\Models\FocusTimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FocusRuntimeEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_routes_require_authentication(): void
    {
        $this->get('/api/v1/focus-sessions/active')->assertStatus(401);
        $this->post('/api/v1/focus-sessions/start')->assertStatus(401);
    }

    public function test_start_creates_active_focus_session(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Runtime Task',
            'state' => 'stopped',
            'active_mode' => 'timer',
            'version' => 1,
            'timer_initial_seconds' => 1500,
            'timer_remaining_seconds' => 1500,
        ]);

        $response = $this->postJson('/api/v1/focus-sessions/start', [
            'task_id' => (string) $task->id,
            'timer_mode' => 'timer',
            'target_seconds' => 1500,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.active_focus_session.task_id', (string) $task->id)
            ->assertJsonPath('data.active_focus_session.timer_mode', 'timer')
            ->assertJsonPath('data.active_focus_session.session_state', 'running')
            ->assertJsonPath('data.created_time_entry_id', fn (string $value): bool => $value !== '');

        $this->assertDatabaseHas('focus_time_entries', [
            'user_id' => $user->id,
            'focus_task_id_nullable' => $task->id,
            'ended_at_utc' => null,
        ]);

        $this->assertDatabaseHas('focus_tasks', [
            'id' => $task->id,
            'state' => 'working',
        ]);
    }

    public function test_start_returns_409_if_focus_session_already_active(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $taskA = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Task A',
            'state' => 'stopped',
            'active_mode' => 'timer',
            'version' => 1,
            'timer_initial_seconds' => 1500,
            'timer_remaining_seconds' => 1500,
        ]);

        $taskB = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Task B',
            'state' => 'stopped',
            'active_mode' => 'stopwatch',
            'version' => 1,
        ]);

        $this->postJson('/api/v1/focus-sessions/start', [
            'task_id' => (string) $taskA->id,
            'timer_mode' => 'timer',
            'target_seconds' => 1500,
        ])->assertStatus(200);

        $this->postJson('/api/v1/focus-sessions/start', [
            'task_id' => (string) $taskB->id,
            'timer_mode' => 'stopwatch',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'ACTIVE_SESSION_CONFLICT');
    }

    public function test_pause_resume_stop_flow_updates_runtime(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Task',
            'state' => 'stopped',
            'active_mode' => 'timer',
            'version' => 1,
            'timer_initial_seconds' => 120,
            'timer_remaining_seconds' => 120,
        ]);

        $this->postJson('/api/v1/focus-sessions/start', [
            'task_id' => (string) $task->id,
            'timer_mode' => 'timer',
            'target_seconds' => 120,
        ])->assertStatus(200);

        $task->refresh();

        $pause = $this->postJson('/api/v1/focus-sessions/pause', [
            'expected_version' => $task->version,
        ]);
        $pause->assertStatus(200)
            ->assertJsonPath('data.active_focus_session.session_state', 'paused');

        $task->refresh();

        $resume = $this->postJson('/api/v1/focus-sessions/resume', [
            'expected_version' => $task->version,
        ]);
        $resume->assertStatus(200)
            ->assertJsonPath('data.active_focus_session.session_state', 'running');

        $task->refresh();

        $stop = $this->postJson('/api/v1/focus-sessions/stop', [
            'expected_version' => $task->version,
            'stop_reason' => 'manual',
        ]);

        $stop->assertStatus(200)
            ->assertJsonPath('data.active_focus_session', null)
            ->assertJsonPath('data.stopped_session_summary.stop_reason', 'manual');

        $activeEntry = FocusTimeEntry::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activeEntry?->ended_at_utc);
        $this->assertDatabaseHas('idle_time_entries', [
            'user_id' => $user->id,
            'ended_at_utc' => null,
            'reason' => 'break',
        ]);
    }
}
