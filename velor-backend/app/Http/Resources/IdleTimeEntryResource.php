<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\IdleTimeEntry */
class IdleTimeEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => (string) $this->user_id,
            'started_at_utc' => $this->started_at_utc?->toISOString(),
            'ended_at_utc' => $this->ended_at_utc?->toISOString(),
            'elapsed_seconds' => $this->elapsed_seconds !== null ? (int) $this->elapsed_seconds : null,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
