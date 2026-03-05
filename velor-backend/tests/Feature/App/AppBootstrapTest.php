<?php

namespace Tests\Feature\App;

use App\Models\FocusTask;
use App\Models\FocusTimeEntry;
use App\Models\IdleTimeEntry;
use App\Models\User;
use App\Models\UserSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_requires_authentication(): void
    {
        $this->get('/api/v1/app/bootstrap')->assertStatus(401);
    }

    public function test_bootstrap_returns_requested_sections(): void
    {
        $user = User::factory()->create();
        UserSetting::query()->create([
            'user_id' => $user->id,
            'locale' => 'es',
            'time_zone_name' => 'America/Lima',
        ]);
        Sanctum::actingAs($user);

        FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Bootstrap Task',
            'state' => 'stopped',
            'active_mode' => 'stopwatch',
            'version' => 1,
        ]);

        $response = $this->getJson('/api/v1/app/bootstrap?include=tasks,preferences,daily_log,dashboard_stats,active_focus_session');

        $response->assertStatus(200)
            ->assertJsonPath('data.user.id', (string) $user->id)
            ->assertJsonPath('data.tasks.0.name', 'Bootstrap Task')
            ->assertJsonPath('data.preferences.locale', 'es')
            ->assertJsonPath('data.daily_log.entries', [])
            ->assertJsonPath('data.dashboard_stats.tracked_seconds_today', 0)
            ->assertJsonPath('data.active_focus_session', null);
    }

    public function test_bootstrap_returns_422_for_unknown_include_values(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/app/bootstrap?include=tasks,unknown_section')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['include']);
    }

    public function test_bootstrap_daily_log_entries_have_unique_prefixed_ids(): void
    {
        $user = User::factory()->create();
        UserSetting::query()->create([
            'user_id' => $user->id,
            'locale' => 'es',
            'time_zone_name' => 'America/Lima',
        ]);
        Sanctum::actingAs($user);

        $task = FocusTask::query()->create([
            'user_id' => $user->id,
            'name' => 'Task',
            'state' => 'stopped',
            'active_mode' => 'timer',
            'version' => 1,
            'timer_initial_seconds' => 1500,
            'timer_remaining_seconds' => 0,
        ]);

        $startedAt = CarbonImmutable::parse('2026-03-05 10:00:00', 'UTC');

        FocusTimeEntry::query()->create([
            'user_id' => $user->id,
            'focus_task_id_nullable' => $task->id,
            'task_title_snapshot' => $task->name,
            'mode_snapshot' => 'timer',
            'timer_target_snapshot_seconds' => 1500,
            'started_at_utc' => $startedAt,
            'ended_at_utc' => $startedAt->addMinutes(25),
            'elapsed_seconds' => 1500,
            'stop_reason' => 'timer_completed',
        ]);

        IdleTimeEntry::query()->create([
            'user_id' => $user->id,
            'started_at_utc' => $startedAt->addMinutes(30),
            'ended_at_utc' => $startedAt->addMinutes(35),
            'elapsed_seconds' => 300,
            'reason' => 'break',
        ]);

        $response = $this->getJson('/api/v1/app/bootstrap?include=daily_log');
        $response->assertStatus(200)
            ->assertJsonPath('data.daily_log.tracked_seconds', 1500)
            ->assertJsonPath('data.daily_log.untracked_seconds', 300);

        $entries = $response->json('data.daily_log.entries');
        $ids = array_column($entries, 'id');

        $this->assertContains('focus:1', $ids);
        $this->assertContains('idle:1', $ids);
        $this->assertCount(count(array_unique($ids)), $ids);
    }
}
