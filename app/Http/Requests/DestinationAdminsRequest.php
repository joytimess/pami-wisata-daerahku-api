<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DestinationAdminsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 2 && $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return [
            'user_guids' => ['present', 'array', 'max:100'],
            'user_guids.*' => ['required', 'uuid', 'distinct', Rule::exists('users', 'guid')->where('role', 1)->where('status', 'active')],
        ];
    }
}
