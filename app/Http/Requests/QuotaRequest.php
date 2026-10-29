<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && (int) $this->user()->role >= 1;
    }

    public function rules(): array
    {
        return $this->isMethod('GET') ? ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']] : [
            'visit_date' => ['required', 'date_format:Y-m-d'], 'capacity' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'booking_opens_at' => ['required', 'date'], 'booking_closes_at' => ['required', 'date', 'after:booking_opens_at'],
        ];
    }
}
