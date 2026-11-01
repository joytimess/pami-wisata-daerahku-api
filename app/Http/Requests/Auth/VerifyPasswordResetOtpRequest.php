<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPasswordResetOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:255'], 'otp' => ['required', 'string', 'regex:/^[0-9]{6}$/D']];
    }
}
