<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAdminAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 2;
    }

    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'integer', Rule::in([0, 1, 2])],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
