<?php

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\AppBootstrapRequest;
use App\Http\Resources\FocusTaskResource;
use App\Http\Resources\UserResource;
use App\Services\Focus\FocusDailyLogService;
use App\Services\Focus\FocusRuntimeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Throwable;

class AppBootstrapController extends Controller
{
    public function __construct(
        private readonly FocusRuntimeService $runtimeService,
        private readonly FocusDailyLogService $dailyLogService,
    ) {
    }

    public function show(AppBootstrapRequest $request): JsonResponse
    {
        try {
            $user = $request->user()->loadMissing('settings');
            $includes = $request->includes();
            $userId = (int) $user->id;

            $timeZoneName = (string) ($request->input('time_zone_name')
                ?? $user->settings?->time_zone_name
                ?? 'UTC');
            $date = (string) ($request->input('date')
                ?? CarbonImmutable::now($timeZoneName)->format('Y-m-d'));

            $tasks = null;
            $dailyLog = null;
            $runtime = null;

            $data = [
                'server_now_utc' => now('UTC')->toISOString(),
                'user' => (new UserResource($user))->resolve(),
            ];

            if (in_array('tasks', $includes, true) || in_array('dashboard_stats', $includes, true)) {
                $tasks = $user->focusTasks()->orderByDesc('updated_at')->get();
            }

            if (in_array('daily_log', $includes, true) || in_array('dashboard_stats', $includes, true)) {
                $dailyLog = $this->dailyLogService->buildForUser($userId, $date, $timeZoneName);
            }

            if (in_array('active_focus_session', $includes, true) || in_array('dashboard_stats', $includes, true)) {
                $runtime = $this->runtimeService->active($userId);
            }

            if (in_array('tasks', $includes, true)) {
                $data['tasks'] = FocusTaskResource::collection($tasks ?? collect())->resolve();
            }

            if (in_array('preferences', $includes, true)) {
                $settings = $user->settings;
                $data['preferences'] = [
                    'locale' => $settings?->locale ?? $user->locale,
                    'time_zone_name' => $settings?->time_zone_name ?? 'UTC',
                    'time_zone_auto_detect' => true,
                    'ui_sounds_enabled' => $settings?->ui_sounds_enabled ?? true,
                    'background_music_enabled' => $settings?->background_music_enabled ?? false,
                    'background_music_volume_percent' => $settings?->background_music_volume_percent ?? 50,
                    'confirm_task_switch_enabled' => $settings?->confirm_task_switch_enabled ?? true,
                    'sign_out_confirmation_enabled' => $settings?->sign_out_confirmation_enabled ?? true,
                ];
            }

            if (in_array('active_focus_session', $includes, true)) {
                $data['active_focus_session'] = $runtime['active_focus_session'] ?? null;
            }

            if (in_array('daily_log', $includes, true)) {
                $data['daily_log'] = [
                    'date_local' => $dailyLog['date_local'] ?? $date,
                    'tracked_seconds' => (int) ($dailyLog['tracked_seconds'] ?? 0),
                    'untracked_seconds' => (int) ($dailyLog['untracked_seconds'] ?? 0),
                    'entries' => $dailyLog['entries'] ?? [],
                ];
            }

            if (in_array('dashboard_stats', $includes, true)) {
                $focusSeconds = (int) ($dailyLog['tracked_seconds'] ?? 0);
                $idleSeconds = (int) ($dailyLog['untracked_seconds'] ?? 0);
                $trackedSessionsCountToday = collect($dailyLog['focus_time_entries'] ?? [])
                    ->whereNotNull('ended_at_utc')
                    ->count();
                $focusTimeTotalSeconds = (int) ($tasks?->sum('total_tracked_seconds') ?? 0);

                $data['dashboard_stats'] = [
                    'tracked_seconds_today' => $focusSeconds,
                    'untracked_seconds_today' => $idleSeconds,
                    'tracked_sessions_count_today' => $trackedSessionsCountToday,
                    'focus_time_total_seconds' => $focusTimeTotalSeconds,
                ];
            }

            return response()->json([
                'data' => $data,
            ]);
        } catch (Throwable $exception) {
            $errorId = 'err_' . Str::lower((string) Str::ulid());
            report($exception);

            return response()->json([
                'message' => 'Bootstrap failed.',
                'error_id' => $errorId,
            ], 500);
        }
    }
}
