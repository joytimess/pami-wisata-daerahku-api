<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class CloseAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['password' => ['nullable', 'string', 'required_without:id_token', 'prohibited_with:id_token'], 'id_token' => ['nullable', 'string', 'max:8192', 'required_without:password', 'prohibited_with:password']];
    }
}
