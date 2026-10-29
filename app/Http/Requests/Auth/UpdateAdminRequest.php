<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 2;
    }

    public function rules(): array
    {
        return ['role' => ['sometimes', 'integer', Rule::in([0, 1, 2])], 'status' => ['sometimes', Rule::in(['active', 'suspended'])]];
    }
}
