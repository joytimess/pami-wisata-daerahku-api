<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['token' => ['required', 'string', 'max:255'], 'platform' => [$this->isMethod('DELETE') ? 'sometimes' : 'required', 'in:android,ios']];
    }
}
