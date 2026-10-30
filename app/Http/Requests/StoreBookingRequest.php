<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._~-]+$/'],
            'destination_guid' => ['required', 'uuid'],
            'visit_date' => ['required', 'date_format:Y-m-d'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'visitor_name' => ['required', 'string', 'max:255'],
            'visitor_email' => ['required', 'email', 'max:255'],
            'visitor_phone' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
