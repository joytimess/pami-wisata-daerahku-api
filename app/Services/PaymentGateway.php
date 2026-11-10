<?php

namespace App\Services;

use App\Services\Doku\DokuChannelRegistry;
use App\Services\Doku\DokuClient;
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
     * The one gateway that may create NEW payments: DEMO (non-production only), else Midtrans once its kill switch is on, else the
     * legacy DOKU integration. A Midtrans deployment that is misconfigured stays Midtrans and fails closed; it never falls back.
     */
    public function activeProvider(): string
    {
        return match (true) {
            $this->demoEnabled() => 'demo',
            config('midtrans.enabled') === true => 'midtrans',
            default => 'doku',
        };
    }

    public function activeEnvironment(): string
    {
        return match ($this->activeProvider()) {
            'demo' => 'demo',
            'midtrans' => (string) config('midtrans.environment', 'sandbox'),
            default => (string) config('doku.environment', 'sandbox'),
        };
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

        if ($this->activeProvider() === 'midtrans') {
            return $this->midtransCapabilities($expiresAt);
        }

        $registry = app(DokuChannelRegistry::class);
        $reasons = $registry->reasons();
        $methods = [];
        foreach ($registry->families() as $method => $presentation) {
            $channels = [];
            foreach ($registry->all() as $definition) {
                if ($definition['method'] === $method) {
                    $reasons[$definition['code']] ??= app(DokuClient::class)->expiryWindowReason($definition['code'], $expiresAt ?? CarbonImmutable::now()->addMinutes(30));
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
        foreach (['midtrans_not_configured', 'midtrans_disabled', 'midtrans_production_not_approved', 'doku_not_configured', 'doku_disabled', 'channel_unavailable'] as $preferred) {
            if (in_array($preferred, $reasons, true)) {
                return $preferred;
            }
        }

        return $reasons[0] ?? null;
    }
}
