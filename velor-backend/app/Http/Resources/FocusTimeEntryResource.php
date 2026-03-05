<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FocusTimeEntry */
class FocusTimeEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => (string) $this->user_id,
            'focus_task_id_nullable' => $this->focus_task_id_nullable !== null ? (string) $this->focus_task_id_nullable : null,
            'task_title_snapshot' => $this->task_title_snapshot,
            'task_icon_snapshot' => $this->task_icon_snapshot,
            'task_color_snapshot' => $this->task_color_snapshot,
            'timer_target_snapshot_seconds' => $this->timer_target_snapshot_seconds !== null ? (int) $this->timer_target_snapshot_seconds : null,
            'mode_snapshot' => $this->mode_snapshot,
            'started_at_utc' => $this->started_at_utc?->toISOString(),
            'ended_at_utc' => $this->ended_at_utc?->toISOString(),
            'elapsed_seconds' => $this->elapsed_seconds !== null ? (int) $this->elapsed_seconds : null,
            'stop_reason' => $this->stop_reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
