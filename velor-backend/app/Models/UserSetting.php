<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    protected $fillable = [
        'user_id',
        'locale',
        'time_zone_name',
        'ui_sounds_enabled',
        'background_music_enabled',
        'background_music_volume_percent',
        'confirm_task_switch_enabled',
        'sign_out_confirmation_enabled',
    ];

    protected function casts(): array
    {
        return [
            'ui_sounds_enabled' => 'boolean',
            'background_music_enabled' => 'boolean',
            'background_music_volume_percent' => 'integer',
            'confirm_task_switch_enabled' => 'boolean',
            'sign_out_confirmation_enabled' => 'boolean',
        ];
    }

    // ─── Relations ─────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
