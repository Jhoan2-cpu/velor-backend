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
        ]);
    }

    public function store(StoreFocusTaskRequest $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $originDeviceId = $this->originDeviceId($request);
        $task = null;

        DB::transaction(function () use ($request, $userId, $originDeviceId, &$task): void {
            $task = FocusTask::query()->create([
                'user_id' => $userId,
                ...$request->safe()->only([
                    'name',
                    'icon_tag',
                    'color_tag',
                    'alarm_time_local',
                ]),
                'version' => 1,
            ]);

            $task->refresh();

            DB::afterCommit(function () use ($task, $originDeviceId): void {
                event(new TaskCardCrudEvent(
                    userId: (int) $task->user_id,
                    eventName: 'focus.task.created',
                    task: $this->taskPayload($task),
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

        $result = DB::transaction(function () use ($taskId, $userId, $validated, $originDeviceId): array {
            $task = FocusTask::query()
                ->where('user_id', $userId)
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first();

            if (!$task) {
                return ['response' => $this->taskNotFoundResponse()];
            }

            if ((int) $task->version !== (int) $validated['if_version']) {
                return ['response' => $this->versionConflictResponse($task)];
            }

            $updates = Arr::only($validated, [
                'name',
                'icon_tag',
                'color_tag',
                'alarm_time_local',
            ]);

            $task->fill($updates);
            $task->version = (int) $task->version + 1;
            $task->save();
            $task->refresh();

            DB::afterCommit(function () use ($task, $originDeviceId): void {
                event(new TaskCardCrudEvent(
                    userId: (int) $task->user_id,
                    eventName: 'focus.task.updated',
                    task: $this->taskPayload($task),
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
        $ifVersion = $this->resolveDeleteIfVersion($request);

        if ($ifVersion === null) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'if_version' => ['The if_version field is required via If-Match header or if_version query parameter.'],
                ],
            ], 422);
        }

        $userId = (int) $request->user()->id;
        $originDeviceId = $this->originDeviceId($request);

        $result = DB::transaction(function () use ($taskId, $userId, $ifVersion, $originDeviceId): array {
            $task = FocusTask::query()
                ->where('user_id', $userId)
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first();

            if (!$task) {
                return ['response' => $this->taskNotFoundResponse()];
            }

            if ((int) $task->version !== $ifVersion) {
                return ['response' => $this->versionConflictResponse($task)];
            }

            $deletedPayload = [
                'id' => (string) $task->id,
                'user_id' => (string) $task->user_id,
                'version' => (int) $task->version,
            ];

            $task->delete();

            DB::afterCommit(function () use ($task, $deletedPayload, $originDeviceId): void {
                event(new TaskCardCrudEvent(
                    userId: (int) $task->user_id,
                    eventName: 'focus.task.deleted',
                    task: $deletedPayload,
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
            'message' => 'Version conflict.',
            'code' => 'VERSION_CONFLICT',
            'data' => [
                'current' => [
                    'id' => (string) $task->id,
                    'version' => (int) $task->version,
                    'updated_at' => $task->updated_at?->toISOString(),
                ],
            ],
        ], 409);
    }

    private function originDeviceId(Request $request): string
    {
        $value = trim((string) $request->header('X-Origin-Device-Id', ''));

        return $value !== '' ? $value : 'server';
    }

    private function resolveDeleteIfVersion(Request $request): ?int
    {
        $ifMatch = trim((string) $request->header('If-Match', ''));

        if ($ifMatch !== '') {
            if (preg_match('/^(?:W\/)?"?(\d+)"?$/', $ifMatch, $matches) === 1) {
                $version = (int) $matches[1];

                return $version >= 1 ? $version : null;
            }

            return null;
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
