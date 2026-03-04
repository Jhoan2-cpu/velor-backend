<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Expose bigint ids as string to avoid JS precision loss on clients.
            'id' => (string) $this->id,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'locale' => $this->locale,
        ];
    }
}
