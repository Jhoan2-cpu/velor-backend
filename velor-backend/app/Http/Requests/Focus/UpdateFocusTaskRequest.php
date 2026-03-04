<?php

namespace App\Http\Requests\Focus;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFocusTaskRequest extends FormRequest
{
    private const ICON_OPTIONS = [
        'briefcase',
        'learning',
        'tools',
        'code',
        'book',
        'pen',
        'cart',
        'game',
    ];

    private const COLOR_OPTIONS = [
        'blue',
        'green',
        'amber',
        'rose',
        'pink',
        'violet',
    ];

    private const RUNTIME_FIELDS = [
        'state',
        'active_mode',
        'timer_remaining_seconds',
        'timer_started_at_utc',
        'timer_ended_at_utc',
        'stopwatch_elapsed_seconds',
        'stopwatch_started_at_utc',
        'stopwatch_ended_at_utc',
        'total_tracked_seconds',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'version' => ['required_without:if_version', 'integer', 'min:1'],
            'if_version' => ['required_without:version', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'icon_tag' => ['sometimes', 'string', 'in:' . implode(',', self::ICON_OPTIONS)],
            'color_tag' => ['sometimes', 'string', 'in:' . implode(',', self::COLOR_OPTIONS)],
            'alarm_time_local' => ['sometimes', 'nullable', 'date_format:H:i'],
            'timer_initial_seconds' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:86400'],
        ];

        foreach (self::RUNTIME_FIELDS as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [
            'alarm_time_local.date_format' => 'The alarm_time_local format is invalid. Use HH:MM.',
        ];

        foreach (self::RUNTIME_FIELDS as $field) {
            $messages[$field . '.prohibited'] = "The {$field} field is not allowed in this endpoint.";
        }

        return $messages;
    }
}
