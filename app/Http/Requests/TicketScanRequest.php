<?php

namespace App\Http\Requests;

use App\Exceptions\TicketException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class TicketScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [1, 2], true) && $this->user()?->status === 'active';
    }

    protected function prepareForValidation(): void
    {
        if ($this->routeIs('admin.ticket-check-ins.confirm')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function rules(): array
    {
        return [
            'qr_payload' => ['required', 'string', 'regex:/\AWDR1\.[A-Za-z0-9_-]{43}\z/D'],
            'idempotency_key' => [$this->routeIs('admin.ticket-check-ins.confirm') ? 'required' : 'sometimes', 'uuid'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($validator->errors()->has('qr_payload')) {
            throw new TicketException('invalid_qr', 'QR tiket tidak valid.', 422);
        }
        parent::failedValidation($validator);
    }
}
