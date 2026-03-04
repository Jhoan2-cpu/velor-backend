<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FocusTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'icon_tag',
        'color_tag',
        'alarm_time_local',
        'timer_initial_seconds',
        'timer_remaining_seconds',
        'timer_started_at_utc',
        'timer_ended_at_utc',
        'stopwatch_elapsed_seconds',
        'stopwatch_started_at_utc',
        'stopwatch_ended_at_utc',
        'total_tracked_seconds',
        'active_mode',
        'state',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'timer_started_at_utc' => 'datetime',
            'timer_ended_at_utc' => 'datetime',
            'stopwatch_started_at_utc' => 'datetime',
            'stopwatch_ended_at_utc' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
