<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GoogleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['id_token' => ['required', 'string', 'max:8192'], 'device_name' => ['nullable', 'string', 'max:255']];
    }
}
