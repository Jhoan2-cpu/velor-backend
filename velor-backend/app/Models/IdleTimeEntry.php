<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdleTimeEntry extends Model
{
    protected $fillable = [
        'user_id',
        'started_at_utc',
        'ended_at_utc',
        'elapsed_seconds',
        'reason',
    ];

    protected function casts(): array
    {
        return [
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
}

