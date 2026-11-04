<?php

namespace App\Http\Requests;

use App\Services\AdminDestinationScopeService;
use Illuminate\Foundation\Http\FormRequest;

class ApprovalClaimRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function authorize(): bool
    {
        if (! $this->user()) {
            return false;
        }
        $actor = app(AdminDestinationScopeService::class)->actor($this->user());

        return ! $this->routeIs('superadmin.bookings.reassign') || (int) $actor->role === 2;
    }

    public function rules(): array
    {
        return ['idempotency_key' => ['required', 'string', 'max:128'],
            'admin_guid' => [$this->routeIs('superadmin.bookings.reassign') ? 'required' : 'prohibited', 'uuid'],
            'reason' => [$this->routeIs('superadmin.bookings.reassign') ? 'required' : 'nullable', 'string', 'max:255']];
    }
}
