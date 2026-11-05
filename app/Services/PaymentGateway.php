<?php

namespace App\Services;

class PaymentGateway
{
    public function demoEnabled(): bool
    {
        return config('operations.demo_payments_enabled') === true && app()->environment(['local', 'testing', 'demo']);
    }

    /** @return array<string, mixed> */
    public function capabilities(): array
    {
        if ($this->demoEnabled()) {
            return [
                'provider' => 'demo', 'environment' => 'demo', 'test_only' => true, 'label' => 'DEMO',
                'integration_status' => 'demo_simulator', 'reason' => null, 'can_pay' => true,
                'methods' => [['code' => 'demo', 'available' => true, 'presentation_type' => 'demo_simulator', 'reason' => null]],
            ];
        }

        // Credentials alone cannot enable a gateway before its adapter and merchant channels are verified.
        return [
            'provider' => 'doku', 'environment' => 'sandbox', 'integration_status' => 'not_configured',
            'reason' => 'doku_not_configured', 'can_pay' => false,
            'methods' => [
                ['code' => 'qris', 'available' => false, 'presentation_type' => 'qr_payload', 'reason' => 'doku_not_configured'],
                ['code' => 'virtual_account', 'available' => false, 'presentation_type' => 'virtual_account', 'reason' => 'doku_not_configured'],
                ['code' => 'ewallet', 'available' => false, 'presentation_type' => 'ewallet_redirect', 'reason' => 'doku_not_configured'],
            ],
        ];
    }
}
