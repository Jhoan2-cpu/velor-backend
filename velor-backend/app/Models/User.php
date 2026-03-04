<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'display_name',
        'email',
        'password_hash',
        'locale',
        'email_verified_at',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    /**
     * Override for Sanctum/Auth to use password_hash column.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash ?? '';
    }

    // ─── Relations ─────────────────────────────────────────────────────────────

    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    public function oauthIdentities(): HasMany
    {
        return $this->hasMany(UserOauthIdentity::class);
    }
}
