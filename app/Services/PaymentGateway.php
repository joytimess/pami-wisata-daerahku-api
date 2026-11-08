<?php

namespace App\Services;

use App\Services\Doku\DokuClient;

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

        $reason = app(DokuClient::class)->readinessReason();

        return [
            'provider' => 'doku', 'environment' => config('doku.environment', 'sandbox'), 'test_only' => config('doku.environment') !== 'production',
            'integration_status' => $reason ? 'not_configured' : 'ready',
            'reason' => $reason, 'can_pay' => $reason === null,
            'methods' => [
                ['code' => 'qris', 'available' => false, 'presentation_type' => 'qr_payload', 'reason' => 'doku_not_configured'],
                ['code' => 'virtual_account', 'channel' => 'bni', 'available' => $reason === null, 'presentation_type' => 'virtual_account', 'reason' => $reason],
                ['code' => 'ewallet', 'available' => false, 'presentation_type' => 'ewallet_redirect', 'reason' => 'doku_not_configured'],
            ],
        ];
    }
}
