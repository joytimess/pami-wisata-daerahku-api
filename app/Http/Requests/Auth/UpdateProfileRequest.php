<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:255'], 'phone' => ['sometimes', 'nullable', 'string', 'max:32'], 'email' => ['prohibited'], 'role' => ['prohibited'], 'status' => ['prohibited']];
    }
}
