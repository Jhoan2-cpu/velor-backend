<?php

namespace App\Http\Requests\Focus;

use Illuminate\Foundation\Http\FormRequest;

class FocusDailyLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time_zone_name' => ['nullable', 'timezone'],
        ];
    }
}
