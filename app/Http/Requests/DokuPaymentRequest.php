<?php

namespace App\Http\Requests;

use App\Services\PaymentService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DokuPaymentRequest extends FormRequest
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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:128'],
            'method_code' => ['required', 'in:virtual_account'], 'channel' => ['required', 'in:bni'],
            'amount' => ['prohibited'], 'currency' => ['prohibited'], 'provider' => ['prohibited'],
            'environment' => ['prohibited'], 'status' => ['prohibited'], 'paid_at' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
