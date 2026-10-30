<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->route('key')]);
    }

    public function rules(): array
    {
        return ['key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._~-]+$/']];
    }
}
