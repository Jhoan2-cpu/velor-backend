<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AppBootstrapRequest extends FormRequest
{
    public const ALLOWED_INCLUDES = [
        'tasks',
        'preferences',
        'daily_log',
        'dashboard_stats',
        'active_focus_session',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'include' => ['nullable', 'string'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time_zone_name' => ['nullable', 'timezone'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unknown = array_values(array_diff($this->includes(), self::ALLOWED_INCLUDES));
            if (count($unknown) === 0) {
                return;
            }

            $validator->errors()->add(
                'include',
                'Unsupported include values: ' . implode(', ', $unknown),
            );
        });
    }

    /**
     * @return array<int, string>
     */
    public function includes(): array
    {
        $raw = trim((string) $this->query('include', ''));
        if ($raw === '') {
            return self::ALLOWED_INCLUDES;
        }

        $tokens = array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $raw),
        ));

        return array_values(array_unique($tokens));
    }
}
