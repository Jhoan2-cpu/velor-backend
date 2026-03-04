<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FocusTask */
class FocusTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => (string) $this->user_id,
            'name' => $this->name,
            'icon_tag' => $this->icon_tag,
            'color_tag' => $this->color_tag,
            'alarm_time_local' => $this->alarm_time_local,
            'timer_initial_seconds' => $this->timer_initial_seconds !== null ? (int) $this->timer_initial_seconds : null,
            'timer_remaining_seconds' => $this->timer_remaining_seconds !== null ? (int) $this->timer_remaining_seconds : null,
            'timer_started_at_utc' => $this->timer_started_at_utc?->toISOString(),
            'timer_ended_at_utc' => $this->timer_ended_at_utc?->toISOString(),
            'stopwatch_elapsed_seconds' => (int) $this->stopwatch_elapsed_seconds,
            'stopwatch_started_at_utc' => $this->stopwatch_started_at_utc?->toISOString(),
            'stopwatch_ended_at_utc' => $this->stopwatch_ended_at_utc?->toISOString(),
            'total_tracked_seconds' => (int) $this->total_tracked_seconds,
            'active_mode' => $this->active_mode,
            'state' => $this->state,
            'version' => (int) $this->version,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
