<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['email' => ['required', 'email', 'max:255'], 'reset_ticket' => ['required', 'string', 'size:64'], 'password' => ['required', 'string', 'min:8', 'confirmed']];
    }
}
