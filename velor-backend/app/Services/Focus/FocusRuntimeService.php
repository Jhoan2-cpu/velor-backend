<?php

namespace App\Services\Focus;

use App\Events\Focus\FocusRuntimeEvent;
use App\Events\Focus\TaskCardCrudEvent;
use App\Exceptions\ActiveSessionConflictException;
use App\Exceptions\FocusRuntimeConflictException;
use App\Http\Resources\FocusTaskResource;
use App\Models\FocusTask;
use App\Models\FocusTimeEntry;
use App\Models\IdleTimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FocusRuntimeService
{
    private const STOP_REASONS = [
        'manual',
        'timer_completed',
        'task_switch',
        'session_end',
        'idle_detected',
    ];

    /**
     * @return array{server_now_utc: string, active_focus_session: ?array<string, mixed>}
     */
    public function active(int $userId): array
    {
        $now = $this->nowUtc();
        $activeTask = $this->activeRuntimeTaskSnapshot($userId);

        return [
            'server_now_utc' => $now->toISOString(),
            'active_focus_session' => $this->buildActiveFocusSessionOrFallback($userId, $now, $activeTask),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   server_now_utc: string,
     *   active_focus_session: ?array<string, mixed>,
     *   task: array<string, mixed>,
     *   transitioned_to_working: bool
     * }
     */
    public function start(int $userId, array $payload, string $originDeviceId = 'server'): array
    {
        return DB::transaction(function () use ($userId, $payload, $originDeviceId): array {
            $now = $this->nowUtc();
            $this->lockUser($userId);

            $task = $this->findTaskForUpdate($userId, (string) $payload['task_id']);
            $activeRuntimeTask = $this->activeRuntimeTaskForUpdate($userId);
            if ($activeRuntimeTask !== null) {
                throw new ActiveSessionConflictException(data: [
                    'server_now_utc' => $now->toISOString(),
                    'active_focus_session' => $this->buildActiveFocusSessionOrFallback($userId, $now, $activeRuntimeTask),
                    'working_task' => [
                        'id' => (string) $activeRuntimeTask->id,
                        'version' => (int) $activeRuntimeTask->version,
                        'updated_at' => $activeRuntimeTask->updated_at?->toISOString(),
                    ],
                ]);
            }

            $timerMode = $this->resolveTimerMode($task, $payload['timer_mode'] ?? null);
            $task->state = 'working';
            $task->active_mode = $timerMode;

            if ($timerMode === 'timer') {
                $task->timer_started_at_utc = $now;
                $task->timer_ended_at_utc = null;
            } else {
                $task->stopwatch_started_at_utc = $now;
                $task->stopwatch_ended_at_utc = null;
            }

            $task->version = (int) $task->version + 1;
            $task->save();
            $task->refresh();

            DB::afterCommit(function () use ($task, $originDeviceId): void {
                $this->dispatchTaskCardUpdatedEvent($task, $originDeviceId);
            });

            return [
                'server_now_utc' => $now->toISOString(),
                'active_focus_session' => $this->buildActiveFocusSessionOrFallback($userId, $now, $task),
                'task' => (new FocusTaskResource($task))->resolve(),
                'transitioned_to_working' => true,
            ];
        });
    }

    /**
     * @return array{
     *   server_now_utc: string,
     *   active_focus_session: ?array<string, mixed>,
     *   task: array<string, mixed>
     * }
     */
    public function pause(int $userId, int $expectedVersion, string $originDeviceId = 'server'): array
    {
        return DB::transaction(function () use ($userId, $expectedVersion, $originDeviceId): array {
            $now = $this->nowUtc();
            $this->lockUser($userId);

            $task = FocusTask::query()
                ->where('user_id', $userId)
                ->where('state', 'working')
                ->lockForUpdate()
                ->first();

            if (!$task) {
                throw new FocusRuntimeConflictException(
                    message: 'No working task to pause.',
                    data: ['server_now_utc' => $now->toISOString()],
                );
            }

            $this->assertExpectedVersion($task, $expectedVersion, $userId, $now);

            if ($task->active_mode === 'timer') {
                $remaining = $this->computeTimerRemainingSeconds($task, $now);
                $task->timer_remaining_seconds = $remaining;
                $task->timer_started_at_utc = null;
                $task->timer_ended_at_utc = $now;
            } else {
                $elapsed = $this->computeStopwatchElapsedSeconds($task, $now);
                $task->stopwatch_elapsed_seconds = $elapsed;
                $task->stopwatch_started_at_utc = null;
                $task->stopwatch_ended_at_utc = $now;
            }

            $task->state = 'paused';
            $task->version = (int) $task->version + 1;
            $task->save();
            $task->refresh();

            DB::afterCommit(function () use ($task, $originDeviceId): void {
                $this->dispatchTaskCardUpdatedEvent($task, $originDeviceId);
            });

            return [
                'server_now_utc' => $now->toISOString(),
                'active_focus_session' => $this->buildActiveFocusSessionOrFallback($userId, $now, $task),
                'task' => (new FocusTaskResource($task))->resolve(),
            ];
        });
    }

    /**
     * @return array{
     *   server_now_utc: string,
     *   active_focus_session: ?array<string, mixed>,
     *   stopped_session_summary: ?array<string, mixed>,
     *   created_time_entry_id: ?string
     * }
     */
    public function resume(int $userId, int $expectedVersion, string $originDeviceId = 'server'): array
    {
        return DB::transaction(function () use ($userId, $expectedVersion, $originDeviceId): array {
            $now = $this->nowUtc();
            $this->lockUser($userId);

            $task = $this->activeRuntimeTaskForUpdate($userId);
            if ($task === null) {
                throw new FocusRuntimeConflictException(
                    message: 'No active focus session.',
                    data: $this->activeConflictData($userId, $now),
                );
            }

            $this->assertExpectedVersion($task, $expectedVersion, $userId, $now);
            if ($task->state !== 'paused') {
                throw new FocusRuntimeConflictException(message: 'Only paused sessions can be resumed.', data: $this->activeConflictData($userId, $now));
            }

            if ($task->active_mode === 'timer') {
                $task->timer_started_at_utc = $now;
                $task->timer_ended_at_utc = null;
            } else {
                $task->stopwatch_started_at_utc = $now;
                $task->stopwatch_ended_at_utc = null;
            }

            $task->state = 'working';
            $task->version = (int) $task->version + 1;
            $task->save();
            $task->refresh();

            $data = $this->runtimePayloadData($userId, $now, fallbackTask: $task);

            DB::afterCommit(function () use ($userId, $originDeviceId, $task, $data): void {
                event(new FocusRuntimeEvent(
                    userId: $userId,
                    type: 'focus_session.updated',
                    data: $data,
                    originDeviceId: $originDeviceId,
                ));

                $this->dispatchTaskCardUpdatedEvent($task, $originDeviceId);
            });

            return $data;
        });
    }

    /**
     * @return array{server_now_utc: string, active_focus_session: ?array<string, mixed>, stopped_session_summary: ?array<string, mixed>, created_time_entry_id: ?string}
     */
    public function stop(int $userId, int $expectedVersion, string $stopReason, string $originDeviceId = 'server'): array
    {
        return DB::transaction(function () use ($userId, $expectedVersion, $stopReason, $originDeviceId): array {
            $now = $this->nowUtc();
            $this->lockUser($userId);

            $context = $this->requireActiveContextForUpdate($userId, $now);
            $task = $context['task'];
            $entry = $context['entry'];

            $this->assertExpectedVersion($task, $expectedVersion, $userId, $now);
            if (!in_array($stopReason, self::STOP_REASONS, true)) {
                throw new FocusRuntimeConflictException(message: 'Invalid stop reason.', data: $this->activeConflictData($userId, $now));
            }

            $stoppedSummary = $this->closeActiveSession($task, $entry, $now, $stopReason);

            $idleReason = $this->mapIdleReasonFromStopReason($stopReason);
            if ($idleReason !== null && !$this->activeIdleEntryForUpdate($userId)) {
                IdleTimeEntry::query()->create([
                    'user_id' => $userId,
                    'started_at_utc' => $now,
                    'ended_at_utc' => null,
                    'elapsed_seconds' => null,
                    'reason' => $idleReason,
                ]);
            }

            $data = $this->runtimePayloadData($userId, $now, stoppedSessionSummary: $stoppedSummary);

            DB::afterCommit(function () use ($userId, $originDeviceId, $task, $data): void {
                event(new FocusRuntimeEvent(
                    userId: $userId,
                    type: 'focus_session.stopped',
                    data: $data,
                    originDeviceId: $originDeviceId,
                ));

                $this->dispatchTaskCardUpdatedEvent($task, $originDeviceId);
            });

            return $data;
        });
    }

    /**
     * @return array{
     *   server_now_utc: string,
     *   active_focus_session: ?array<string, mixed>,
     *   task: array<string, mixed>,
     *   transitioned_to_idle: bool
     * }
     */
    public function reset(int $userId, string $taskId, int $expectedVersion, string $originDeviceId = 'server'): array
    {
        return DB::transaction(function () use ($userId, $taskId, $expectedVersion, $originDeviceId): array {
            $now = $this->nowUtc();
            $this->lockUser($userId);

            $task = $this->findTaskForUpdate($userId, $taskId);
            $this->assertExpectedVersion($task, $expectedVersion, $userId, $now);

            $transitionedToIdle = $task->state !== 'idle'
                || $task->timer_remaining_seconds !== $task->timer_initial_seconds
                || (int) $task->stopwatch_elapsed_seconds !== 0
                || $task->timer_started_at_utc !== null
                || $task->timer_ended_at_utc !== null
                || $task->stopwatch_started_at_utc !== null
                || $task->stopwatch_ended_at_utc !== null;

            if ($transitionedToIdle) {
                $task->state = 'idle';
                $task->timer_remaining_seconds = $task->timer_initial_seconds;
                $task->timer_started_at_utc = null;
                $task->timer_ended_at_utc = null;
                $task->stopwatch_elapsed_seconds = 0;
                $task->stopwatch_started_at_utc = null;
                $task->stopwatch_ended_at_utc = null;
                $task->version = (int) $task->version + 1;
                $task->save();
                $task->refresh();

                DB::afterCommit(function () use ($task, $originDeviceId): void {
                    $this->dispatchTaskCardUpdatedEvent($task, $originDeviceId);
                });
            }

            return [
                'server_now_utc' => $now->toISOString(),
                'active_focus_session' => $this->buildActiveFocusSessionOrFallback($userId, $now, $task),
                'task' => (new FocusTaskResource($task))->resolve(),
                'transitioned_to_idle' => $transitionedToIdle,
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{server_now_utc: string, active_focus_session: ?array<string, mixed>, stopped_session_summary: ?array<string, mixed>, created_time_entry_id: ?string}
     */
    public function switchTask(int $userId, array $payload, string $originDeviceId = 'server'): array
    {
        return DB::transaction(function () use ($userId, $payload, $originDeviceId): array {
            $now = $this->nowUtc();
            $this->lockUser($userId);

            $context = $this->requireActiveContextForUpdate($userId, $now);
            $sourceTask = $context['task'];
            $sourceEntry = $context['entry'];

            $expectedVersion = (int) $payload['expected_version'];
            $this->assertExpectedVersion($sourceTask, $expectedVersion, $userId, $now);

            $targetTask = $this->findTaskForUpdate($userId, (string) $payload['task_id']);
            if ((int) $targetTask->id === (int) $sourceTask->id) {
                throw new FocusRuntimeConflictException(message: 'Task switch target must be different.', data: $this->activeConflictData($userId, $now));
            }

            $stoppedSummary = $this->closeActiveSession($sourceTask, $sourceEntry, $now, 'task_switch');

            $timerMode = $this->resolveTimerMode($targetTask, $payload['timer_mode'] ?? null);
            $targetSeconds = $this->resolveTargetSeconds($timerMode, $targetTask, $payload['target_seconds'] ?? null);
            $this->applyStartState($targetTask, $timerMode, $targetSeconds, $now);

            $newEntry = FocusTimeEntry::query()->create([
                'user_id' => $userId,
                'focus_task_id_nullable' => $targetTask->id,
                'task_title_snapshot' => $targetTask->name,
                'task_icon_snapshot' => $targetTask->icon_tag,
                'task_color_snapshot' => $targetTask->color_tag,
                'timer_target_snapshot_seconds' => $timerMode === 'timer' ? $targetSeconds : null,
                'mode_snapshot' => $timerMode,
                'started_at_utc' => $now,
                'ended_at_utc' => null,
                'elapsed_seconds' => null,
                'stop_reason' => null,
            ]);

            $data = $this->runtimePayloadData(
                $userId,
                $now,
                stoppedSessionSummary: $stoppedSummary,
                createdTimeEntryId: (string) $newEntry->id,
            );

            DB::afterCommit(function () use ($userId, $originDeviceId, $sourceTask, $targetTask, $data): void {
                event(new FocusRuntimeEvent(
                    userId: $userId,
                    type: 'focus_session.updated',
                    data: $data,
                    originDeviceId: $originDeviceId,
                ));

                $this->dispatchTaskCardUpdatedEvent($sourceTask, $originDeviceId);
                $this->dispatchTaskCardUpdatedEvent($targetTask, $originDeviceId);
            });

            return $data;
        });
    }

    /**
     * @return array{server_now_utc: string, active_focus_session: ?array<string, mixed>, stopped_session_summary: ?array<string, mixed>, created_time_entry_id: ?string}
     */
    public function heartbeat(int $userId, int $expectedVersion, string $originDeviceId = 'server'): array
    {
        return DB::transaction(function () use ($userId, $expectedVersion, $originDeviceId): array {
            $now = $this->nowUtc();
            $this->lockUser($userId);

            $context = $this->requireActiveContextForUpdate($userId, $now);
            $task = $context['task'];
            $entry = $context['entry'];

            $this->assertExpectedVersion($task, $expectedVersion, $userId, $now);

            if ($task->active_mode === 'timer' && $task->state === 'working') {
                $remaining = $this->computeTimerRemainingSeconds($task, $now);
                if ($remaining <= 0) {
                    $stoppedSummary = $this->closeActiveSession($task, $entry, $now, 'timer_completed');
                    if (!$this->activeIdleEntryForUpdate($userId)) {
                        IdleTimeEntry::query()->create([
                            'user_id' => $userId,
                            'started_at_utc' => $now,
                            'ended_at_utc' => null,
                            'elapsed_seconds' => null,
                            'reason' => 'break',
                        ]);
                    }

                    $data = $this->runtimePayloadData($userId, $now, stoppedSessionSummary: $stoppedSummary);

                    DB::afterCommit(function () use ($userId, $originDeviceId, $task, $data): void {
                        event(new FocusRuntimeEvent(
                            userId: $userId,
                            type: 'focus_session.stopped',
                            data: $data,
                            originDeviceId: $originDeviceId,
                        ));

                        $this->dispatchTaskCardUpdatedEvent($task, $originDeviceId);
                    });

                    return $data;
                }

                $task->timer_remaining_seconds = $remaining;
                $task->save();
            }

            $data = $this->runtimePayloadData($userId, $now);

            DB::afterCommit(function () use ($userId, $originDeviceId, $data): void {
                event(new FocusRuntimeEvent(
                    userId: $userId,
                    type: 'focus_session.updated',
                    data: $data,
                    originDeviceId: $originDeviceId,
                ));
            });

            return $data;
        });
    }

    /**
     * @return array{entry: FocusTimeEntry, task: FocusTask}|null
     */
    private function activeFocusContextForUpdate(int $userId): ?array
    {
        $entry = $this->activeFocusEntryForUpdate($userId);
        if (!$entry || $entry->focus_task_id_nullable === null) {
            return null;
        }

        $task = FocusTask::query()
            ->where('user_id', $userId)
            ->whereKey($entry->focus_task_id_nullable)
            ->lockForUpdate()
            ->first();

        if (!$task) {
            return null;
        }

        return [
            'entry' => $entry,
            'task' => $task,
        ];
    }

    /**
     * @return array{entry: FocusTimeEntry, task: FocusTask}
     */
    private function requireActiveContextForUpdate(int $userId, CarbonInterface $now): array
    {
        $context = $this->activeFocusContextForUpdate($userId);
        if ($context !== null) {
            return $context;
        }

        throw new FocusRuntimeConflictException(
            message: 'No active focus session.',
            data: $this->activeConflictData($userId, $now),
        );
    }

    private function applyStartState(FocusTask $task, string $timerMode, ?int $targetSeconds, CarbonInterface $now): void
    {
        if ($timerMode === 'timer') {
            $task->timer_remaining_seconds = $targetSeconds;
            $task->timer_started_at_utc = $now;
            $task->timer_ended_at_utc = null;
            $task->stopwatch_elapsed_seconds = 0;
            $task->stopwatch_started_at_utc = null;
            $task->stopwatch_ended_at_utc = null;
        } else {
            $task->stopwatch_elapsed_seconds = 0;
            $task->stopwatch_started_at_utc = $now;
            $task->stopwatch_ended_at_utc = null;
            $task->timer_started_at_utc = null;
            $task->timer_ended_at_utc = null;
            $task->timer_remaining_seconds = null;
        }

        $task->active_mode = $timerMode;
        $task->state = 'working';
        $task->version = (int) $task->version + 1;
        $task->save();
        $task->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function closeActiveSession(FocusTask $task, FocusTimeEntry $entry, CarbonInterface $now, string $stopReason): array
    {
        if ($task->active_mode === 'timer') {
            $remaining = $task->state === 'working'
                ? $this->computeTimerRemainingSeconds($task, $now)
                : (int) ($task->timer_remaining_seconds ?? 0);
            $target = $entry->timer_target_snapshot_seconds ?? $task->timer_initial_seconds ?? 0;
            $elapsed = max(0, (int) $target - $remaining);

            $task->timer_remaining_seconds = $remaining;
            $task->timer_started_at_utc = null;
            $task->timer_ended_at_utc = $now;
        } else {
            $elapsed = $task->state === 'working'
                ? $this->computeStopwatchElapsedSeconds($task, $now)
                : (int) $task->stopwatch_elapsed_seconds;

            $task->stopwatch_elapsed_seconds = $elapsed;
            $task->stopwatch_started_at_utc = null;
            $task->stopwatch_ended_at_utc = $now;
        }

        $task->state = 'stopped';
        $task->total_tracked_seconds = (int) $task->total_tracked_seconds + $elapsed;
        $task->version = (int) $task->version + 1;
        $task->save();
        $task->refresh();

        $entry->ended_at_utc = $now;
        $entry->elapsed_seconds = $elapsed;
        $entry->stop_reason = $stopReason;
        $entry->save();
        $entry->refresh();

        return [
            'id' => (string) $entry->id,
            'task_id' => $entry->focus_task_id_nullable !== null ? (string) $entry->focus_task_id_nullable : null,
            'mode_snapshot' => $entry->mode_snapshot,
            'stop_reason' => $stopReason,
            'elapsed_seconds' => $elapsed,
            'started_at_utc' => $entry->started_at_utc?->toISOString(),
            'ended_at_utc' => $entry->ended_at_utc?->toISOString(),
        ];
    }

    private function closeIdleEntry(IdleTimeEntry $idleEntry, CarbonInterface $now): void
    {
        $elapsed = max(0, $idleEntry->started_at_utc?->diffInSeconds($now) ?? 0);
        $idleEntry->ended_at_utc = $now;
        $idleEntry->elapsed_seconds = $elapsed;
        $idleEntry->save();
    }

    private function computeTimerRemainingSeconds(FocusTask $task, CarbonInterface $now): int
    {
        $baseRemaining = (int) ($task->timer_remaining_seconds ?? 0);
        if (!$task->timer_started_at_utc) {
            return max(0, $baseRemaining);
        }

        $elapsedSinceResume = max(0, $task->timer_started_at_utc->diffInSeconds($now));

        return max(0, $baseRemaining - $elapsedSinceResume);
    }

    private function computeStopwatchElapsedSeconds(FocusTask $task, CarbonInterface $now): int
    {
        $baseElapsed = (int) $task->stopwatch_elapsed_seconds;
        if (!$task->stopwatch_started_at_utc) {
            return max(0, $baseElapsed);
        }

        $elapsedSinceResume = max(0, $task->stopwatch_started_at_utc->diffInSeconds($now));

        return max(0, $baseElapsed + $elapsedSinceResume);
    }

    private function resolveTimerMode(FocusTask $task, mixed $requestedTimerMode): string
    {
        if (is_string($requestedTimerMode) && in_array($requestedTimerMode, ['timer', 'stopwatch'], true)) {
            return $requestedTimerMode;
        }

        return ((int) ($task->timer_initial_seconds ?? 0) > 0) ? 'timer' : 'stopwatch';
    }

    private function resolveTargetSeconds(string $timerMode, FocusTask $task, mixed $requestedTargetSeconds): ?int
    {
        if ($timerMode !== 'timer') {
            return null;
        }

        if ($requestedTargetSeconds !== null && $requestedTargetSeconds !== '') {
            $target = (int) $requestedTargetSeconds;
            if ($target < 1) {
                throw ValidationException::withMessages([
                    'target_seconds' => ['The target_seconds field must be at least 1.'],
                ]);
            }

            return $target;
        }

        if ($task->timer_initial_seconds !== null && (int) $task->timer_initial_seconds > 0) {
            return (int) $task->timer_initial_seconds;
        }

        throw ValidationException::withMessages([
            'target_seconds' => ['The target_seconds field is required when timer mode is timer and task has no timer_initial_seconds.'],
        ]);
    }

    /**
     * @return array{server_now_utc: string, active_focus_session: ?array<string, mixed>, stopped_session_summary: ?array<string, mixed>, created_time_entry_id: ?string}
     */
    private function runtimePayloadData(
        int $userId,
        CarbonInterface $now,
        ?array $stoppedSessionSummary = null,
        ?string $createdTimeEntryId = null,
        ?FocusTask $fallbackTask = null,
    ): array {
        return [
            'server_now_utc' => $now->toISOString(),
            'active_focus_session' => $this->buildActiveFocusSessionOrFallback($userId, $now, $fallbackTask),
            'stopped_session_summary' => $stoppedSessionSummary,
            'created_time_entry_id' => $createdTimeEntryId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activeConflictData(int $userId, CarbonInterface $now): array
    {
        $activeTask = $this->activeRuntimeTaskSnapshot($userId);

        return [
            'server_now_utc' => $now->toISOString(),
            'active_focus_session' => $this->buildActiveFocusSessionOrFallback($userId, $now, $activeTask),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildActiveFocusSession(int $userId, CarbonInterface $now): ?array
    {
        $entry = FocusTimeEntry::query()
            ->where('user_id', $userId)
            ->whereNull('ended_at_utc')
            ->latest('started_at_utc')
            ->first();

        if (!$entry) {
            return null;
        }

        $task = null;
        if ($entry->focus_task_id_nullable !== null) {
            $task = FocusTask::query()
                ->where('user_id', $userId)
                ->whereKey($entry->focus_task_id_nullable)
                ->first();
        }

        $timerMode = $task?->active_mode ?? $entry->mode_snapshot;
        $targetSeconds = $timerMode === 'timer'
            ? ($entry->timer_target_snapshot_seconds ?? $task?->timer_initial_seconds)
            : null;

        $elapsedSecondsTotal = $timerMode === 'timer'
            ? $this->timerElapsedForSnapshot($task, $targetSeconds, $now)
            : $this->stopwatchElapsedForSnapshot($task, $now);

        return [
            'id' => (string) $entry->id,
            'task_id' => $entry->focus_task_id_nullable !== null ? (string) $entry->focus_task_id_nullable : null,
            'timer_mode' => $timerMode,
            'session_state' => $this->mapSessionState($task?->state),
            'target_seconds' => $targetSeconds !== null ? (int) $targetSeconds : null,
            'started_at_utc' => $entry->started_at_utc?->toISOString(),
            'last_resumed_at_utc' => $timerMode === 'timer'
                ? $task?->timer_started_at_utc?->toISOString()
                : $task?->stopwatch_started_at_utc?->toISOString(),
            'last_paused_at_utc' => $timerMode === 'timer'
                ? $task?->timer_ended_at_utc?->toISOString()
                : $task?->stopwatch_ended_at_utc?->toISOString(),
            'elapsed_seconds_total' => $elapsedSecondsTotal,
            'version' => $task ? (int) $task->version : 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildActiveFocusSessionOrFallback(int $userId, CarbonInterface $now, ?FocusTask $fallbackTask = null): ?array
    {
        $activeFocusSession = $this->buildActiveFocusSession($userId, $now);
        if ($activeFocusSession !== null) {
            return $activeFocusSession;
        }

        if ($fallbackTask === null) {
            return null;
        }

        return $this->buildActiveFocusSessionFromTask($fallbackTask, $now);
    }

    /**
     * Fallback session shape for runtime flows that still rely only on focus_tasks.
     *
     * @return array<string, mixed>|null
     */
    private function buildActiveFocusSessionFromTask(FocusTask $task, CarbonInterface $now): ?array
    {
        if (!in_array($task->state, ['working', 'paused'], true)) {
            return null;
        }

        $timerMode = $task->active_mode;
        $targetSeconds = $timerMode === 'timer'
            ? ($task->timer_initial_seconds !== null ? (int) $task->timer_initial_seconds : null)
            : null;
        $elapsedSecondsTotal = $timerMode === 'timer'
            ? $this->timerElapsedForSnapshot($task, $targetSeconds, $now)
            : $this->stopwatchElapsedForSnapshot($task, $now);

        $lastResumedAt = $timerMode === 'timer'
            ? $task->timer_started_at_utc
            : $task->stopwatch_started_at_utc;
        $lastPausedAt = $timerMode === 'timer'
            ? $task->timer_ended_at_utc
            : $task->stopwatch_ended_at_utc;

        return [
            'id' => 'task-runtime:' . (string) $task->id,
            'task_id' => (string) $task->id,
            'timer_mode' => $timerMode,
            'session_state' => $this->mapSessionState($task->state),
            'target_seconds' => $targetSeconds,
            'started_at_utc' => $lastResumedAt?->toISOString(),
            'last_resumed_at_utc' => $lastResumedAt?->toISOString(),
            'last_paused_at_utc' => $lastPausedAt?->toISOString(),
            'elapsed_seconds_total' => $elapsedSecondsTotal,
            'version' => (int) $task->version,
        ];
    }

    private function timerElapsedForSnapshot(?FocusTask $task, ?int $targetSeconds, CarbonInterface $now): int
    {
        if (!$task) {
            return 0;
        }

        $remaining = (int) ($task->timer_remaining_seconds ?? 0);
        if ($task->state === 'working' && $task->timer_started_at_utc) {
            $remaining = max(0, $remaining - $task->timer_started_at_utc->diffInSeconds($now));
        }

        $target = $targetSeconds ?? (int) ($task->timer_initial_seconds ?? 0);

        return max(0, $target - $remaining);
    }

    private function stopwatchElapsedForSnapshot(?FocusTask $task, CarbonInterface $now): int
    {
        if (!$task) {
            return 0;
        }

        $elapsed = (int) $task->stopwatch_elapsed_seconds;
        if ($task->state === 'working' && $task->stopwatch_started_at_utc) {
            $elapsed += $task->stopwatch_started_at_utc->diffInSeconds($now);
        }

        return max(0, $elapsed);
    }

    private function mapSessionState(?string $taskState): string
    {
        return match ($taskState) {
            'working' => 'working',
            'paused' => 'paused',
            'stopped' => 'stopped',
            'idle' => 'idle',
            default => 'idle',
        };
    }

    private function activeRuntimeTaskSnapshot(int $userId): ?FocusTask
    {
        return FocusTask::query()
            ->where('user_id', $userId)
            ->whereIn('state', ['working', 'paused'])
            ->orderByRaw("CASE WHEN state = 'working' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->first();
    }

    private function mapIdleReasonFromStopReason(string $stopReason): ?string
    {
        return match ($stopReason) {
            'manual', 'timer_completed' => 'break',
            'idle_detected' => 'user_idle',
            'session_end' => 'session_end',
            default => null,
        };
    }

    private function assertExpectedVersion(FocusTask $task, int $expectedVersion, int $userId, CarbonInterface $now): void
    {
        if ((int) $task->version === $expectedVersion) {
            return;
        }

        throw new FocusRuntimeConflictException(
            errorCode: 'VERSION_MISMATCH',
            message: 'Runtime version conflict.',
            data: [
                ...$this->activeConflictData($userId, $now),
                'server_task' => [
                    'id' => (string) $task->id,
                    'version' => (int) $task->version,
                    'updated_at' => $task->updated_at?->toISOString(),
                ],
            ],
        );
    }

    private function lockUser(int $userId): User
    {
        $user = User::query()->whereKey($userId)->lockForUpdate()->first();
        if ($user) {
            return $user;
        }

        throw (new ModelNotFoundException())->setModel(User::class, [$userId]);
    }

    private function findTaskForUpdate(int $userId, string $taskId): FocusTask
    {
        $task = FocusTask::query()
            ->where('user_id', $userId)
            ->whereKey($taskId)
            ->lockForUpdate()
            ->first();

        if ($task) {
            return $task;
        }

        throw (new ModelNotFoundException())->setModel(FocusTask::class, [$taskId]);
    }

    private function activeFocusEntryForUpdate(int $userId): ?FocusTimeEntry
    {
        return FocusTimeEntry::query()
            ->where('user_id', $userId)
            ->whereNull('ended_at_utc')
            ->lockForUpdate()
            ->first();
    }

    private function activeRuntimeTaskForUpdate(int $userId): ?FocusTask
    {
        return FocusTask::query()
            ->where('user_id', $userId)
            ->whereIn('state', ['working', 'paused'])
            ->orderByRaw("CASE WHEN state = 'working' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->lockForUpdate()
            ->first();
    }

    private function activeIdleEntryForUpdate(int $userId): ?IdleTimeEntry
    {
        return IdleTimeEntry::query()
            ->where('user_id', $userId)
            ->whereNull('ended_at_utc')
            ->lockForUpdate()
            ->first();
    }

    private function dispatchTaskCardUpdatedEvent(FocusTask $task, string $originDeviceId): void
    {
        event(new TaskCardCrudEvent(
            userId: (int) $task->user_id,
            type: 'taskcard.updated',
            data: ['task' => (new FocusTaskResource($task))->resolve()],
            originDeviceId: $originDeviceId,
        ));
    }

    private function nowUtc(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
