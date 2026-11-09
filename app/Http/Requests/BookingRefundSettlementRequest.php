<?php

namespace App\Http\Requests;

use App\Services\BookingRefundService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookingRefundSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role >= 1 && $this->user()->status === 'active';
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'settlement_method' => ['required', 'string', Rule::in(BookingRefundService::SETTLEMENT_METHODS)],
            'settlement_reference' => ['required', 'string', 'min:6', 'max:128', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'settlement_amount' => ['required', 'integer', 'min:1'],
            // ISO-8601 with an explicit offset so the recorded instant is never guessed (e.g. 2026-11-09T14:00:00+07:00).
            'settled_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'evidence_note' => ['required', 'string', 'min:10', 'max:500'],
            'proof_verified' => ['required', 'accepted'],
            'status' => ['prohibited'], 'refunded' => ['prohibited'], 'amount' => ['prohibited'], 'provider_correlation' => ['prohibited'],
            'actor' => ['prohibited'], 'settled_by_user_guid' => ['prohibited'], 'refund_channel' => ['prohibited'],
        ];
    }

    /** @return array<string, mixed> */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);
        if ($key === null && is_array($data)) {
            unset($data['proof_verified']);
        }

        return $data;
    }
}
