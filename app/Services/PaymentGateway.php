<?php

namespace App\Services;

use App\Services\Midtrans\MidtransChannelRegistry;
use App\Services\Midtrans\MidtransClient;
use Carbon\CarbonImmutable;

class PaymentGateway
{
    public function demoEnabled(): bool
    {
        return config('operations.demo_payments_enabled') === true && app()->environment(['local', 'testing', 'demo']);
    }

    /**
     * The one gateway that may create NEW payments: DEMO (non-production only), else Midtrans. Midtrans is the only real gateway;
     * when it is disabled or misconfigured it stays Midtrans and fails closed. Historical DOKU payments are read-only ledger data.
     */
    public function activeProvider(): string
    {
        return $this->demoEnabled() ? 'demo' : 'midtrans';
    }

    public function activeEnvironment(): string
    {
        return $this->demoEnabled() ? 'demo' : (string) config('midtrans.environment', 'sandbox');
    }

    /** @return array<string, mixed> */
    public function capabilities(?CarbonImmutable $expiresAt = null): array
    {
        if ($this->demoEnabled()) {
            return [
                'provider' => 'demo', 'environment' => 'demo', 'test_only' => true, 'label' => 'DEMO',
                'integration_status' => 'demo_simulator', 'reason' => null, 'can_pay' => true,
                'methods' => [['code' => 'demo', 'available' => true, 'presentation_type' => 'demo_simulator', 'reason' => null]],
            ];
        }

        return $this->midtransCapabilities($expiresAt);
    }

    /** @return array<string, mixed> */
    private function midtransCapabilities(?CarbonImmutable $expiresAt): array
    {
        $registry = app(MidtransChannelRegistry::class);
        $window = app(MidtransClient::class)->expiryWindowReason($expiresAt ?? CarbonImmutable::now()->addHour());
        $methods = [];
        foreach ($registry->families() as $method => $presentation) {
            $channels = [];
            foreach ($registry->all() as $definition) {
                if ($definition['method'] === $method) {
                    $reason = $registry->reason($definition['code']) ?? $window;
                    $channels[] = ['channel' => $definition['channel'], 'available' => $reason === null,
                        'presentation_type' => $definition['presentation_type'], 'reason' => $reason];
                }
            }
            $available = array_values(array_filter($channels, fn (array $channel): bool => $channel['available']));
            $methods[] = ['code' => $method, 'available' => $available !== [], 'presentation_type' => $presentation,
                'reason' => $available !== [] ? null : $this->firstReason($channels), 'channels' => $channels];
        }
        $ready = array_filter($methods, fn (array $method): bool => $method['available']) !== [];
        $environment = (string) config('midtrans.environment', 'sandbox');

        return [
            'provider' => 'midtrans', 'environment' => $environment, 'test_only' => $environment !== 'production',
            'integration_status' => $ready ? 'ready' : 'not_configured',
            'reason' => $ready ? null : $this->firstReason($methods), 'can_pay' => $ready, 'methods' => $methods,
        ];
    }

    /**
     * Prefer the most actionable disabled reason: configuration or kill switch over a per-channel static gate.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function firstReason(array $items): ?string
    {
        $reasons = array_values(array_filter(array_column($items, 'reason')));
        foreach (['midtrans_not_configured', 'midtrans_disabled', 'midtrans_production_not_approved', 'channel_unavailable'] as $preferred) {
            if (in_array($preferred, $reasons, true)) {
                return $preferred;
            }
        }

        return $reasons[0] ?? null;
    }
}
