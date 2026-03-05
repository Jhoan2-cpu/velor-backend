<?php

namespace App\Http\Requests\Focus;

use Illuminate\Foundation\Http\FormRequest;

class StopFocusSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'stop_reason' => ['required', 'in:manual,timer_completed,task_switch,session_end,idle_detected'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('stop_reason') && $this->has('stopped_reason')) {
            $this->merge([
                'stop_reason' => $this->input('stopped_reason'),
            ]);
        }
    }
}
