<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FocusTimeEntry extends Model
{
    protected $fillable = [
        'user_id',
        'focus_task_id_nullable',
        'task_title_snapshot',
        'task_icon_snapshot',
        'task_color_snapshot',
        'timer_target_snapshot_seconds',
        'mode_snapshot',
        'started_at_utc',
        'ended_at_utc',
        'elapsed_seconds',
        'stop_reason',
    ];

    protected function casts(): array
    {
        return [
            'timer_target_snapshot_seconds' => 'integer',
            'elapsed_seconds' => 'integer',
            'started_at_utc' => 'datetime',
            'ended_at_utc' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function focusTask(): BelongsTo
    {
        return $this->belongsTo(FocusTask::class, 'focus_task_id_nullable');
    }
}

