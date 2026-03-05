<?php

namespace App\Http\Controllers\Api\V1\Focus;

use App\Http\Controllers\Controller;
use App\Http\Requests\Focus\FocusDailyLogRequest;
use App\Services\Focus\FocusDailyLogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Throwable;

class FocusDailyLogController extends Controller
{
    public function __construct(
        private readonly FocusDailyLogService $dailyLogService,
    ) {
    }

    public function show(FocusDailyLogRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            $timeZoneName = (string) ($request->input('time_zone_name')
                ?? $user->settings?->time_zone_name
                ?? 'UTC');

            $date = (string) ($request->input('date')
                ?? CarbonImmutable::now($timeZoneName)->format('Y-m-d'));

            $data = $this->dailyLogService->buildForUser((int) $user->id, $date, $timeZoneName);

            return response()->json([
                'data' => $data,
            ]);
        } catch (Throwable $exception) {
            $errorId = 'err_' . Str::lower((string) Str::ulid());
            report($exception);

            return response()->json([
                'message' => 'Daily log failed.',
                'error_id' => $errorId,
            ], 500);
        }
    }
}
