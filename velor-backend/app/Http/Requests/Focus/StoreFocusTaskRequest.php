<?php

namespace App\Http\Requests\Focus;

use Illuminate\Foundation\Http\FormRequest;

class StoreFocusTaskRequest extends FormRequest
{
    private const RUNTIME_FIELDS = [
        'state',
        'active_mode',
        'timer_initial_seconds',
        'timer_remaining_seconds',
        'timer_started_at_utc',
        'timer_ended_at_utc',
        'stopwatch_elapsed_seconds',
        'stopwatch_started_at_utc',
        'stopwatch_ended_at_utc',
        'total_tracked_seconds',
        'version',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'icon_tag' => ['nullable', 'string', 'max:255'],
            'color_tag' => ['nullable', 'string', 'max:255'],
            'alarm_time_local' => ['nullable', 'regex:/^([01]\d|2[0-3]):([0-5]\d)$/'],
        ];

        foreach (self::RUNTIME_FIELDS as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [
            'alarm_time_local.regex' => 'The alarm_time_local format is invalid. Use HH:MM.',
        ];

        foreach (self::RUNTIME_FIELDS as $field) {
            $messages[$field . '.prohibited'] = "The {$field} field is not allowed in this endpoint.";
        }

        return $messages;
    }
}
