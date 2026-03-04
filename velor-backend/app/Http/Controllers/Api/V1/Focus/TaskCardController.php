<?php

namespace App\Http\Controllers\Api\V1\Focus;

use App\Events\Focus\TaskCardCrudEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Focus\StoreFocusTaskRequest;
use App\Http\Requests\Focus\UpdateFocusTaskRequest;
use App\Http\Resources\FocusTaskResource;
use App\Models\FocusTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class TaskCardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tasks = FocusTask::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => FocusTaskResource::collection($tasks)->resolve(),
            'meta' => [
                'server_now_utc' => now('UTC')->toISOString(),
            ],
        ]);
    }

    public function store(StoreFocusTaskRequest $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $originDeviceId = $this->originDeviceId($request);
        $task = null;

        DB::transaction(function () use ($request, $userId, $originDeviceId, &$task): void {
            $validated = $request->validated();
            $timerInitialSeconds = $validated['timer_initial_seconds'] ?? null;

            $task = FocusTask::query()->create([
                'user_id' => $userId,
                ...Arr::only($validated, [
                    'name',
                    'icon_tag',
                    'color_tag',
                    'alarm_time_local',
                ]),
                'timer_initial_seconds' => $timerInitialSeconds,
                'timer_remaining_seconds' => $timerInitialSeconds,
                'stopwatch_elapsed_seconds' => 0,
                'total_tracked_seconds' => 0,
                'active_mode' => 'timer',
                'state' => 'stopped',
                'version' => 1,
            ]);

            $task->refresh();

            DB::afterCommit(function () use ($task, $originDeviceId): void {
                event(new TaskCardCrudEvent(
                    userId: (int) $task->user_id,
                    type: 'taskcard.created',
                    data: ['task' => $this->taskPayload($task)],
                    originDeviceId: $originDeviceId,
                ));
            });
        });

        return response()->json([
            'data' => (new FocusTaskResource($task))->resolve(),
        ], 201);
    }

    public function update(UpdateFocusTaskRequest $request, string $taskId): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $originDeviceId = $this->originDeviceId($request);
        $validated = $request->validated();
        $expectedVersion = $this->resolveExpectedVersion($validated);

        $result = DB::transaction(function () use ($taskId, $userId, $validated, $expectedVersion, $originDeviceId): array {
            $task = FocusTask::query()
                ->where('user_id', $userId)
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first();

            if (!$task) {
                return ['response' => $this->taskNotFoundResponse()];
            }

            if ((int) $task->version !== $expectedVersion) {
                return ['response' => $this->versionConflictResponse($task)];
            }

            $updates = Arr::only($validated, [
                'name',
                'icon_tag',
                'color_tag',
                'alarm_time_local',
                'timer_initial_seconds',
            ]);

            if (array_key_exists('timer_initial_seconds', $updates)) {
                $updates['timer_remaining_seconds'] = $updates['timer_initial_seconds'];
            }

            $task->fill($updates);
            $task->version = (int) $task->version + 1;
            $task->save();
            $task->refresh();

            DB::afterCommit(function () use ($task, $originDeviceId): void {
                event(new TaskCardCrudEvent(
                    userId: (int) $task->user_id,
                    type: 'taskcard.updated',
                    data: ['task' => $this->taskPayload($task)],
                    originDeviceId: $originDeviceId,
                ));
            });

            return ['task' => $task];
        });

        if (isset($result['response'])) {
            return $result['response'];
        }

        return response()->json([
            'data' => (new FocusTaskResource($result['task']))->resolve(),
        ]);
    }

    public function destroy(Request $request, string $taskId): JsonResponse
    {
        $expectedVersion = $this->resolveDeleteVersion($request);

        if ($expectedVersion === null) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'version' => ['The version field is required.'],
                ],
            ], 422);
        }

        $userId = (int) $request->user()->id;
        $originDeviceId = $this->originDeviceId($request);

        $result = DB::transaction(function () use ($taskId, $userId, $expectedVersion, $originDeviceId): array {
            $task = FocusTask::query()
                ->where('user_id', $userId)
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first();

            if (!$task) {
                return ['response' => $this->taskNotFoundResponse()];
            }

            if ((int) $task->version !== $expectedVersion) {
                return ['response' => $this->versionConflictResponse($task)];
            }

            $deletedVersion = (int) $task->version;
            $deletedTaskId = (string) $task->id;
            $deletedUserId = (int) $task->user_id;

            $task->delete();

            DB::afterCommit(function () use ($deletedVersion, $deletedTaskId, $deletedUserId, $originDeviceId): void {
                event(new TaskCardCrudEvent(
                    userId: $deletedUserId,
                    type: 'taskcard.deleted',
                    data: [
                        'task_id' => $deletedTaskId,
                        'deleted_version' => $deletedVersion,
                    ],
                    originDeviceId: $originDeviceId,
                ));
            });

            return ['deleted' => true];
        });

        if (isset($result['response'])) {
            return $result['response'];
        }

        return response()->json([], 204);
    }

    private function taskPayload(FocusTask $task): array
    {
        return (new FocusTaskResource($task))->resolve();
    }

    private function taskNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Task not found.',
        ], 404);
    }

    private function versionConflictResponse(FocusTask $task): JsonResponse
    {
        return response()->json([
            'message' => 'Task version conflict.',
            'code' => 'TASK_VERSION_CONFLICT',
            'data' => [
                'server_task' => [
                    'id' => (string) $task->id,
                    'name' => $task->name,
                    'version' => (int) $task->version,
                    'updated_at' => $task->updated_at?->toISOString(),
                ],
            ],
        ], 409);
    }

    private function originDeviceId(Request $request): string
    {
        $primary = trim((string) $request->header('X-Device-Id', ''));
        if ($primary !== '') {
            return $primary;
        }

        $fallback = trim((string) $request->header('X-Origin-Device-Id', ''));

        return $fallback !== '' ? $fallback : 'server';
    }

    private function resolveExpectedVersion(array $validated): int
    {
        if (isset($validated['version'])) {
            return (int) $validated['version'];
        }

        return (int) $validated['if_version'];
    }

    private function resolveDeleteVersion(Request $request): ?int
    {
        $payloadVersion = $request->input('version');
        if ($payloadVersion !== null && $payloadVersion !== '') {
            if (filter_var($payloadVersion, FILTER_VALIDATE_INT) === false) {
                return null;
            }

            $version = (int) $payloadVersion;

            return $version >= 1 ? $version : null;
        }

        $ifMatch = trim((string) $request->header('If-Match', ''));
        if ($ifMatch !== '') {
            if (preg_match('/^(?:W\/)?"?(\d+)"?$/', $ifMatch, $matches) !== 1) {
                return null;
            }

            $version = (int) $matches[1];

            return $version >= 1 ? $version : null;
        }

        $queryVersion = $request->query('if_version');
        if ($queryVersion === null || $queryVersion === '') {
            return null;
        }

        if (filter_var($queryVersion, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $version = (int) $queryVersion;

        return $version >= 1 ? $version : null;
    }
}
