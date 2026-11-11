<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Operator recovery input. The operator names an action and corroborating evidence; the outcome is never part of the payload, and the
 * server decides from its own verified facts. Fields that would dictate a result are prohibited outright.
 */
class OperatorRecoveryRequest extends FormRequest
{
    public const PAYMENT_ACTIONS = ['reverify', 'note', 'confirm_paid', 'close_unpaid'];

    public const REFUND_ACTIONS = ['reverify', 'resume_provider', 'route_manual', 'reject'];

    /** Evidence types each decision accepts; the service applies the stricter per-case rules on top. */
    private const EVIDENCE = [
        'confirm_paid' => ['provider_dashboard_settlement', 'provider_support_confirmation'],
        'close_unpaid' => ['verified_terminal_status', 'provider_dashboard_no_transaction', 'provider_support_confirmation'],
        'route_manual' => ['provider_dashboard_no_refund', 'provider_support_confirmation'],
        'reject' => ['ticket_used_confirmation', 'customer_withdrawal_confirmation', 'provider_dashboard_no_refund', 'provider_support_confirmation'],
    ];

    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role >= 1 && $this->user()->status === 'active';
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $action = (string) $this->input('action');
        $decides = array_key_exists($action, self::EVIDENCE);

        return [
            'action' => ['required', 'string', Rule::in($this->route('refund') !== null ? self::REFUND_ACTIONS : self::PAYMENT_ACTIONS)],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'attempt_guid' => [Rule::requiredIf($action === 'confirm_paid'), 'nullable', 'uuid'],
            'evidence_type' => [Rule::requiredIf($decides), 'nullable', 'string', Rule::in(self::EVIDENCE[$action] ?? [])],
            'evidence_reference' => [Rule::requiredIf(in_array($action, ['confirm_paid', 'close_unpaid', 'route_manual'], true)), 'nullable', 'string', 'min:6', 'max:128',
                'regex:/^[A-Za-z0-9._\/:-]+$/'],
            'evidence_note' => [Rule::requiredIf($action !== 'reverify'), 'nullable', 'string', 'min:10', 'max:500'],
            'status' => ['prohibited'], 'paid' => ['prohibited'], 'refunded' => ['prohibited'], 'outcome' => ['prohibited'], 'amount' => ['prohibited'],
            'actor' => ['prohibited'], 'actor_guid' => ['prohibited'], 'provider_correlation' => ['prohibited'], 'terminal' => ['prohibited'],
        ];
    }
}
