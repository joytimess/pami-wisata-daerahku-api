<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BookingRefundDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role >= 1 && $this->user()->status === 'active';
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['required_if:decision,reject', 'nullable', 'string', 'min:3', 'max:500'],
            'status' => ['prohibited'], 'refunded' => ['prohibited'], 'amount' => ['prohibited'], 'provider_correlation' => ['prohibited'],
        ];
    }
}
