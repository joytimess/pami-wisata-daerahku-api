<?php

namespace App\Http\Requests;

use App\Services\PaymentService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BookingRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(PaymentService::class)->ownerBooking($this->user(), $this->route('booking'));

        return true;
    }

    /**
     * Amount, currency, provider and status are never accepted from the client: a refund is always the full captured amount.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:128'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'amount' => ['prohibited'], 'currency' => ['prohibited'], 'provider' => ['prohibited'], 'status' => ['prohibited'],
            'refunded' => ['prohibited'], 'payment_attempt_guid' => ['prohibited'], 'booking_guid' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
