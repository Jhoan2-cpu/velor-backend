<?php

namespace App\Http\Requests\Focus;

use Illuminate\Foundation\Http\FormRequest;

class ResetFocusSessionRequest extends FormRequest
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
        ];
    }
}
