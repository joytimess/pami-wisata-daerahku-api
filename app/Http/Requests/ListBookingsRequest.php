<?php

namespace App\Http\Requests;

use App\Support\BookingRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBookingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->routeIs('admin.*')) {
            return $this->user() !== null && (int) $this->user()->role >= 1;
        }

        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(BookingRules::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
