<?php

namespace App\Http\Requests;

use App\Services\PaymentGateway;
use App\Services\PaymentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DemoPaymentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function authorize(): bool
    {
        abort_unless(app(PaymentGateway::class)->demoEnabled(), 404);
        app(PaymentService::class)->ownerBooking($this->user(), $this->route('booking'));

        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:128'],
            'attempt_guid' => [$this->routeIs('payments.demo.simulate') ? 'required' : 'prohibited', 'uuid'],
            'outcome' => [$this->routeIs('payments.demo.simulate') ? 'required' : 'prohibited', Rule::in(['success', 'failed', 'expired'])],
            'amount' => ['prohibited'], 'currency' => ['prohibited'], 'provider' => ['prohibited'],
            'environment' => ['prohibited'], 'status' => ['prohibited'], 'paid_at' => ['prohibited'],
        ];
    }
}
