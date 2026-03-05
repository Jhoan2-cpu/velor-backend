<?php

namespace App\Http\Requests\Focus;

use Illuminate\Foundation\Http\FormRequest;

class SwitchFocusSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'timer_mode' => ['nullable', 'in:timer,stopwatch'],
            'target_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
        ];
    }
}
