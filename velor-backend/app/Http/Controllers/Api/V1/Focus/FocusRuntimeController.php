<?php

namespace App\Http\Controllers\Api\V1\Focus;

use App\Exceptions\ActiveSessionConflictException;
use App\Exceptions\FocusRuntimeConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Focus\HeartbeatFocusSessionRequest;
use App\Http\Requests\Focus\PauseFocusSessionRequest;
use App\Http\Requests\Focus\ResetFocusSessionRequest;
use App\Http\Requests\Focus\ResumeFocusSessionRequest;
use App\Http\Requests\Focus\StartFocusSessionRequest;
use App\Http\Requests\Focus\StopFocusSessionRequest;
use App\Http\Requests\Focus\SwitchFocusSessionRequest;
use App\Services\Focus\FocusRuntimeService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FocusRuntimeController extends Controller
{
    public function __construct(
        private readonly FocusRuntimeService $runtimeService,
    ) {
    }

    public function active(Request $request): JsonResponse
    {
        $data = $this->runtimeService->active((int) $request->user()->id);

        return response()->json(['data' => $data]);
    }

    public function start(StartFocusSessionRequest $request): JsonResponse
    {
        return $this->handleRuntimeAction(
            fn () => $this->runtimeService->start(
                (int) $request->user()->id,
                $request->validated(),
                $this->originDeviceId($request),
            ),
        );
    }

    public function pause(PauseFocusSessionRequest $request): JsonResponse
    {
        return $this->handleRuntimeAction(
            fn () => $this->runtimeService->pause(
                (int) $request->user()->id,
                (int) $request->integer('expected_version'),
                $this->originDeviceId($request),
            ),
        );
    }

    public function resume(ResumeFocusSessionRequest $request): JsonResponse
    {
        return $this->handleRuntimeAction(
            fn () => $this->runtimeService->resume(
                (int) $request->user()->id,
                (int) $request->integer('expected_version'),
                $this->originDeviceId($request),
            ),
        );
    }

    public function stop(StopFocusSessionRequest $request): JsonResponse
    {
        return $this->handleRuntimeAction(
            fn () => $this->runtimeService->stop(
                (int) $request->user()->id,
                (int) $request->integer('expected_version'),
                (string) $request->input('stop_reason'),
                $this->originDeviceId($request),
            ),
        );
    }

    public function reset(ResetFocusSessionRequest $request): JsonResponse
    {
        return $this->handleRuntimeAction(
            fn () => $this->runtimeService->reset(
                (int) $request->user()->id,
                (string) $request->input('task_id'),
                (int) $request->integer('expected_version'),
                $this->originDeviceId($request),
            ),
        );
    }

    public function switchTask(SwitchFocusSessionRequest $request): JsonResponse
    {
        return $this->handleRuntimeAction(
            fn () => $this->runtimeService->switchTask(
                (int) $request->user()->id,
                $request->validated(),
                $this->originDeviceId($request),
            ),
        );
    }

    public function heartbeat(HeartbeatFocusSessionRequest $request): JsonResponse
    {
        return $this->handleRuntimeAction(
            fn () => $this->runtimeService->heartbeat(
                (int) $request->user()->id,
                (int) $request->integer('expected_version'),
                $this->originDeviceId($request),
            ),
        );
    }

    /**
     * @param callable(): array<string, mixed> $action
     */
    private function handleRuntimeAction(callable $action): JsonResponse
    {
        try {
            $data = $action();

            return response()->json(['data' => $data]);
        } catch (ActiveSessionConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
                'data' => $exception->data,
            ], 409);
        } catch (FocusRuntimeConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
                'data' => $exception->data,
            ], 409);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Task not found.',
            ], 404);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            $errorId = 'err_' . Str::lower((string) Str::ulid());
            report($exception);

            return response()->json([
                'message' => 'Runtime action failed.',
                'error_id' => $errorId,
            ], 500);
        }
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
}
