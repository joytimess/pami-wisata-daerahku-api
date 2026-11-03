<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AvatarPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role === 0;
    }

    public function rules(): array
    {
        return [
            'source' => ['required', 'in:default,google'],
            'avatar_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
