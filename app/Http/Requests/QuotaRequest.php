<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->routeIs('destinations.availability')) {
            return $this->user() !== null;
        }

        return $this->user() && (int) $this->user()->role >= 1;
    }

    public function rules(): array
    {
        if ($this->routeIs('destinations.availability')) {
            return [
                'visit_date' => ['sometimes', 'date_format:Y-m-d'],
                'from' => ['sometimes', 'date_format:Y-m-d'],
                'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            ];
        }
        if ($this->isMethod('GET')) {
            return ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
        }
        // Timestamps must carry an explicit offset (e.g. Z or +07:00) so the instant is unambiguous.
        $instant = ['required', 'date', 'regex:/(Z|[+-]\d{2}:?\d{2})$/i'];

        return [
            'visit_date' => ['required', 'date_format:Y-m-d'], 'capacity' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'booking_opens_at' => $instant, 'booking_closes_at' => [...$instant, 'after:booking_opens_at'],
        ];
    }
}
