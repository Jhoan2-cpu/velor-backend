<?php

namespace Tests\Feature\Focus;

use App\Events\Focus\TaskCardCrudEvent;
use App\Models\FocusTask;
use App\Models\FocusTimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    public function test_start_sets_task_to_working_and_returns_updated_task(): void
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
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.task.id', (string) $task->id)
            ->assertJsonPath('data.task.state', 'working')
            ->assertJsonPath('data.task.active_mode', 'timer')
            ->assertJsonPath('data.task.timer_started_at_utc', fn (?string $value): bool => $value !== null)
            ->assertJsonPath('data.active_focus_session.task_id', (string) $task->id)
            ->assertJsonPath('data.active_focus_session.session_state', 'working')
            ->assertJsonPath('data.task.version', 2);

        $this->assertDatabaseHas('focus_tasks', [
            'id' => $task->id,
            'state' => 'working',
            'active_mode' => 'timer',
        ]);
    }

    public function test_start_returns_409_if_another_task_is_already_working(): void
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
        ])->assertStatus(200);

        $this->postJson('/api/v1/focus-sessions/start', [
            'task_id' => (string) $taskB->id,
            'timer_mode' => 'stopwatch',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'ACTIVE_SESSION_CONFLICT')
            ->assertJsonPath('data.active_focus_session.task_id', (string) $taskA->id)
            ->assertJsonPath('data.active_focus_session.session_state', 'working');
    }

    public function test_start_returns_409_if_user_has_paused_session(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $pausedTask = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Paused Task',
            'state' => 'paused',
            'active_mode' => 'timer',
            'version' => 4,
            'timer_initial_seconds' => 1500,
            'timer_remaining_seconds' => 900,
        ]);

        $newTask = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'New Task',
            'state' => 'stopped',
            'active_mode' => 'stopwatch',
            'version' => 1,
        ]);

        $this->postJson('/api/v1/focus-sessions/start', [
            'task_id' => (string) $newTask->id,
            'timer_mode' => 'stopwatch',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'ACTIVE_SESSION_CONFLICT')
            ->assertJsonPath('data.active_focus_session.task_id', (string) $pausedTask->id)
            ->assertJsonPath('data.active_focus_session.session_state', 'paused');
    }

    public function test_active_returns_session_from_focus_task_when_no_time_entry_exists(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $now = CarbonImmutable::now('UTC');

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Task Active Snapshot',
            'state' => 'working',
            'active_mode' => 'timer',
            'version' => 6,
            'timer_initial_seconds' => 1500,
            'timer_remaining_seconds' => 1200,
            'timer_started_at_utc' => $now->subSeconds(10),
            'timer_ended_at_utc' => null,
        ]);

        $response = $this->getJson('/api/v1/focus-sessions/active');

        $response->assertStatus(200)
            ->assertJsonPath('data.active_focus_session.task_id', (string) $task->id)
            ->assertJsonPath('data.active_focus_session.session_state', 'working')
            ->assertJsonPath('data.active_focus_session.timer_mode', 'timer')
            ->assertJsonPath('data.active_focus_session.version', 6)
            ->assertJsonPath('data.active_focus_session.target_seconds', 1500)
            ->assertJsonPath('data.active_focus_session.elapsed_seconds_total', fn (int $value): bool => $value >= 0);
    }

    public function test_start_emits_taskcard_updated_once_and_second_start_conflicts(): void
    {
        Event::fake([TaskCardCrudEvent::class]);

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

        $first = $this->postJson('/api/v1/focus-sessions/start', [
            'task_id' => (string) $task->id,
            'timer_mode' => 'timer',
        ])->assertStatus(200);

        $task->refresh();
        $versionAfterFirstStart = (int) $task->version;
        $updatedAtAfterFirstStart = $task->updated_at?->toISOString();

        $second = $this->postJson('/api/v1/focus-sessions/start', [
            'task_id' => (string) $task->id,
            'timer_mode' => 'timer',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'ACTIVE_SESSION_CONFLICT');

        $task->refresh();
        $this->assertSame($versionAfterFirstStart, (int) $task->version);
        $this->assertSame($updatedAtAfterFirstStart, $task->updated_at?->toISOString());

        Event::assertDispatchedTimes(TaskCardCrudEvent::class, 1);

        Event::assertDispatched(TaskCardCrudEvent::class, function (TaskCardCrudEvent $event): bool {
            $payload = $event->broadcastWith();
            return $payload['type'] === 'taskcard.updated'
                && isset($payload['data']['task']['state'])
                && $payload['data']['task']['state'] === 'working';
        });

        $this->assertNotNull($first->json('data.task.id'));
        $this->assertNotNull($second->json('data.active_focus_session.task_id'));
    }

    public function test_pause_endpoint_pauses_current_working_task(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Task Pause',
            'state' => 'stopped',
            'active_mode' => 'timer',
            'version' => 1,
            'timer_initial_seconds' => 1500,
            'timer_remaining_seconds' => 1500,
        ]);

        $this->postJson('/api/v1/focus-sessions/start', [
            'task_id' => (string) $task->id,
            'timer_mode' => 'timer',
        ])->assertStatus(200);

        $task->refresh();

        $pauseResponse = $this->postJson('/api/v1/focus-sessions/pause', [
            'expected_version' => (int) $task->version,
        ]);

        $pauseResponse->assertStatus(200)
            ->assertJsonPath('data.task.id', (string) $task->id)
            ->assertJsonPath('data.task.state', 'paused')
            ->assertJsonPath('data.active_focus_session.task_id', (string) $task->id)
            ->assertJsonPath('data.active_focus_session.session_state', 'paused')
            ->assertJsonPath('data.task.version', 3);
    }

    public function test_resume_resumes_paused_task_using_focus_tasks_source_of_truth(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $now = CarbonImmutable::now('UTC');

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Task Resume',
            'state' => 'paused',
            'active_mode' => 'stopwatch',
            'version' => 7,
            'stopwatch_elapsed_seconds' => 42,
            'stopwatch_started_at_utc' => null,
            'stopwatch_ended_at_utc' => $now->subSeconds(5),
        ]);

        $response = $this->postJson('/api/v1/focus-sessions/resume', [
            'expected_version' => 7,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.active_focus_session.task_id', (string) $task->id)
            ->assertJsonPath('data.active_focus_session.session_state', 'working')
            ->assertJsonPath('data.active_focus_session.version', 8);

        $task->refresh();
        $this->assertSame('working', $task->state);
        $this->assertSame(8, (int) $task->version);
    }

    public function test_resume_returns_no_active_focus_session_conflict_when_runtime_session_does_not_exist(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/focus-sessions/resume', [
            'expected_version' => 1,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'FOCUS_RUNTIME_CONFLICT')
            ->assertJsonPath('message', 'No active focus session.')
            ->assertJsonPath('data.active_focus_session', null);
    }

    public function test_resume_returns_version_mismatch_conflict_when_expected_version_is_stale(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Task Resume Version',
            'state' => 'paused',
            'active_mode' => 'timer',
            'version' => 11,
            'timer_initial_seconds' => 1500,
            'timer_remaining_seconds' => 1000,
            'timer_started_at_utc' => null,
            'timer_ended_at_utc' => CarbonImmutable::now('UTC'),
        ]);

        $response = $this->postJson('/api/v1/focus-sessions/resume', [
            'expected_version' => 10,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'VERSION_MISMATCH')
            ->assertJsonPath('message', 'Runtime version conflict.')
            ->assertJsonPath('data.server_task.id', (string) $task->id)
            ->assertJsonPath('data.server_task.version', 11)
            ->assertJsonPath('data.active_focus_session.task_id', (string) $task->id);
    }

    public function test_reset_endpoint_resets_task_to_idle(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Task Reset',
            'state' => 'stopped',
            'active_mode' => 'stopwatch',
            'version' => 1,
            'stopwatch_elapsed_seconds' => 0,
        ]);

        $this->postJson('/api/v1/focus-sessions/start', [
            'task_id' => (string) $task->id,
            'timer_mode' => 'stopwatch',
        ])->assertStatus(200);

        $task->refresh();

        $this->postJson('/api/v1/focus-sessions/pause', [
            'expected_version' => (int) $task->version,
        ])->assertStatus(200);

        $task->refresh();

        $resetResponse = $this->postJson('/api/v1/focus-sessions/reset', [
            'task_id' => (string) $task->id,
            'expected_version' => (int) $task->version,
        ]);

        $resetResponse->assertStatus(200)
            ->assertJsonPath('data.task.id', (string) $task->id)
            ->assertJsonPath('data.task.state', 'idle')
            ->assertJsonPath('data.active_focus_session', null)
            ->assertJsonPath('data.task.version', 4)
            ->assertJsonPath('data.transitioned_to_idle', true);
    }

    public function test_stop_increments_version_and_emits_single_taskcard_update(): void
    {
        Event::fake([TaskCardCrudEvent::class]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $now = CarbonImmutable::now('UTC');

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Task Stop',
            'state' => 'working',
            'active_mode' => 'stopwatch',
            'version' => 10,
            'stopwatch_elapsed_seconds' => 30,
            'stopwatch_started_at_utc' => $now->subSeconds(15),
        ]);

        FocusTimeEntry::query()->create([
            'user_id' => $user->id,
            'focus_task_id_nullable' => $task->id,
            'task_title_snapshot' => $task->name,
            'task_icon_snapshot' => $task->icon_tag,
            'task_color_snapshot' => $task->color_tag,
            'timer_target_snapshot_seconds' => null,
            'mode_snapshot' => 'stopwatch',
            'started_at_utc' => $now->subSeconds(45),
            'ended_at_utc' => null,
            'elapsed_seconds' => null,
            'stop_reason' => null,
        ]);

        $response = $this->postJson('/api/v1/focus-sessions/stop', [
            'expected_version' => 10,
            'stop_reason' => 'manual',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.active_focus_session', null)
            ->assertJsonPath('data.stopped_session_summary.stop_reason', 'manual');

        $task->refresh();
        $this->assertSame('stopped', $task->state);
        $this->assertSame(11, (int) $task->version);

        Event::assertDispatchedTimes(TaskCardCrudEvent::class, 1);
        Event::assertDispatched(TaskCardCrudEvent::class, function (TaskCardCrudEvent $event): bool {
            $payload = $event->broadcastWith();
            return $payload['type'] === 'taskcard.updated'
                && ($payload['data']['task']['state'] ?? null) === 'stopped';
        });
    }

    public function test_reset_increments_version_once_and_emits_taskcard_update_once_per_transition(): void
    {
        Event::fake([TaskCardCrudEvent::class]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $now = CarbonImmutable::now('UTC');

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Task Reset Transition',
            'state' => 'paused',
            'active_mode' => 'timer',
            'version' => 20,
            'timer_initial_seconds' => 1500,
            'timer_remaining_seconds' => 1200,
            'timer_started_at_utc' => null,
            'timer_ended_at_utc' => $now,
        ]);

        $first = $this->postJson('/api/v1/focus-sessions/reset', [
            'task_id' => (string) $task->id,
            'expected_version' => 20,
        ]);

        $first->assertStatus(200)
            ->assertJsonPath('data.transitioned_to_idle', true)
            ->assertJsonPath('data.active_focus_session', null);

        $task->refresh();
        $this->assertSame('idle', $task->state);
        $this->assertSame(21, (int) $task->version);

        $second = $this->postJson('/api/v1/focus-sessions/reset', [
            'task_id' => (string) $task->id,
            'expected_version' => 21,
        ]);

        $second->assertStatus(200)
            ->assertJsonPath('data.transitioned_to_idle', false)
            ->assertJsonPath('data.active_focus_session', null);

        $task->refresh();
        $this->assertSame(21, (int) $task->version);

        Event::assertDispatchedTimes(TaskCardCrudEvent::class, 1);
        Event::assertDispatched(TaskCardCrudEvent::class, function (TaskCardCrudEvent $event): bool {
            $payload = $event->broadcastWith();
            return $payload['type'] === 'taskcard.updated'
                && ($payload['data']['task']['state'] ?? null) === 'idle';
        });
    }
}
