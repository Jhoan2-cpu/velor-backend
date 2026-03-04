<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'locale' => ['required', 'in:es,en'],
            'time_zone_name' => ['required', 'timezone'],
        ];
    }

    public function messages(): array
    {
        return [
            'display_name.required' => 'El nombre es obligatorio.',
            'display_name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'El correo no tiene un formato válido.',
            'email.unique' => 'El correo ya está en uso.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'locale.required' => 'El idioma es obligatorio.',
            'locale.in' => 'El idioma debe ser es o en.',
            'time_zone_name.required' => 'La zona horaria es obligatoria.',
            'time_zone_name.timezone' => 'La zona horaria no es válida.',
        ];
    }
}
