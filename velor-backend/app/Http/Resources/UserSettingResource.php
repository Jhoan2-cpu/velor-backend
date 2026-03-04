<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\UserSetting */
class UserSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'locale' => $this->locale,
            'time_zone_name' => $this->time_zone_name,
        ];
    }
}
