<?php

namespace App\Http\Requests;

use App\Services\Midtrans\MidtransChannelRegistry;
use App\Services\PaymentService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MidtransPaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        app(PaymentService::class)->ownerBooking($this->user(), $this->route('booking'));

        return true;
    }

    /**
     * The client only chooses a channel. Amount, order identity, expiry, provider and environment are server authority.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:128'],
            'method_code' => ['required', 'in:virtual_account,qris'],
            'channel' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || app(MidtransChannelRegistry::class)->find((string) $this->input('method_code'), $value) === null) {
                    $fail('The selected channel is invalid for this payment method.');
                }
            }],
            'amount' => ['prohibited'], 'currency' => ['prohibited'], 'provider' => ['prohibited'],
            'environment' => ['prohibited'], 'status' => ['prohibited'], 'paid_at' => ['prohibited'],
            'merchant_id' => ['prohibited'], 'expiry' => ['prohibited'], 'fee' => ['prohibited'],
            'total_amount' => ['prohibited'], 'invoice_id' => ['prohibited'], 'booking_guid' => ['prohibited'], 'paid' => ['prohibited'],
            'order_id' => ['prohibited'], 'custom_expiry' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
