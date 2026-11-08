<?php

namespace App\Services;

use App\Services\Doku\DokuChannelRegistry;

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

        $registry = app(DokuChannelRegistry::class);
        $reasons = $registry->reasons();
        $methods = [];
        foreach ($registry->families() as $method => $presentation) {
            $channels = [];
            foreach ($registry->all() as $definition) {
                if ($definition['method'] === $method) {
                    $channels[] = ['channel' => $definition['channel'], 'available' => $reasons[$definition['code']] === null,
                        'presentation_type' => $definition['presentation_type'], 'reason' => $reasons[$definition['code']]];
                }
            }
            $available = array_values(array_filter($channels, fn (array $channel): bool => $channel['available']));
            $method = ['code' => $method, 'available' => $available !== [], 'presentation_type' => $presentation,
                'reason' => $available !== [] ? null : $this->firstReason($channels), 'channels' => $channels];
            if ($method['code'] === 'virtual_account') {
                $method['channel'] = 'bni'; // Legacy key kept for existing clients; `channels` is authoritative.
            }
            $methods[] = $method;
        }
        $ready = array_filter($methods, fn (array $method): bool => $method['available']) !== [];
        $reason = $ready ? null : $this->firstReason($methods);

        return [
            'provider' => 'doku', 'environment' => config('doku.environment', 'sandbox'), 'test_only' => config('doku.environment') !== 'production',
            'integration_status' => $ready ? 'ready' : 'not_configured',
            'reason' => $reason, 'can_pay' => $ready, 'methods' => $methods,
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
        foreach (['doku_not_configured', 'doku_disabled', 'channel_unavailable'] as $preferred) {
            if (in_array($preferred, $reasons, true)) {
                return $preferred;
            }
        }

        return $reasons[0] ?? null;
    }
}
