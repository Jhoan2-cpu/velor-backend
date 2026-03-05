<?php

namespace App\Http\Requests\Focus;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFocusTaskRequest extends FormRequest
{
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
            'icon_tag' => ['sometimes', 'nullable', 'string', 'max:255'],
            'color_tag' => ['sometimes', 'nullable', 'string', 'max:255'],
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

    protected function prepareForValidation(): void
    {
        $wrappedPayload = $this->extractWrappedPayload();
        if (!empty($wrappedPayload)) {
            $this->merge($wrappedPayload);
        }

        $patch = [];

        // Frontend compatibility aliases.
        $this->mapAlias($patch, 'name', ['title', 'task_name']);
        $this->mapAlias($patch, 'icon_tag', ['icon']);
        $this->mapAlias($patch, 'color_tag', ['color']);
        $this->mapAlias($patch, 'alarm_time_local', ['alarmTimeLocal']);
        $this->mapAlias($patch, 'timer_initial_seconds', ['timerInitialSeconds']);
        $this->mapAlias($patch, 'if_version', ['ifVersion']);

        if (array_key_exists('name', $patch) && is_string($patch['name'])) {
            $patch['name'] = trim($patch['name']);
        }

        foreach (['icon_tag', 'color_tag', 'alarm_time_local'] as $nullableField) {
            if (array_key_exists($nullableField, $patch) && $patch[$nullableField] === '') {
                $patch[$nullableField] = null;
            }
        }

        if (!empty($patch)) {
            $this->merge($patch);
        }
    }

    /**
     * @param array<string, mixed> $patch
     * @param array<int, string> $aliases
     */
    private function mapAlias(array &$patch, string $targetField, array $aliases): void
    {
        if ($this->filled($targetField) || array_key_exists($targetField, $patch)) {
            return;
        }

        foreach ($aliases as $alias) {
            if ($this->exists($alias)) {
                $patch[$targetField] = $this->input($alias);
                return;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractWrappedPayload(): array
    {
        foreach (['apiPayload', 'payload', 'task', 'data'] as $wrapperKey) {
            $candidate = $this->input($wrapperKey);

            if (is_array($candidate)) {
                return $candidate;
            }

            if (is_string($candidate)) {
                $decoded = json_decode($candidate, true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }
}
