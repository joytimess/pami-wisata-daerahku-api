<?php

namespace App\Services\Midtrans;

/**
 * Server-side catalogue of the three Midtrans Core API channels of the first release and the gates deciding whether
 * each may issue a NEW payment. Fail-closed: every gate must pass.
 */
class MidtransChannelRegistry
{
    private const SANDBOX_KEY_PREFIX = 'SB-Mid-server-';

    private const PRODUCTION_KEY_PREFIX = 'Mid-server-';

    /** @return array<string, array{code: string, method: string, channel: string, presentation_type: string}> */
    public function all(): array
    {
        return [
            'va_bca' => ['code' => 'va_bca', 'method' => 'virtual_account', 'channel' => 'bca', 'presentation_type' => 'virtual_account'],
            'va_bni' => ['code' => 'va_bni', 'method' => 'virtual_account', 'channel' => 'bni', 'presentation_type' => 'virtual_account'],
            'qris' => ['code' => 'qris', 'method' => 'qris', 'channel' => 'qris', 'presentation_type' => 'qr_image'],
        ];
    }

    /**
     * Method/channel pairs whose verified evidence may settle a booking. Static on purpose: a later kill switch, channel allowlist
     * or Production gate must never stop money that was genuinely received on an existing order from being recognised.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function settlingPairs(): array
    {
        return array_values(array_map(fn (array $definition): array => [$definition['method'], $definition['channel']], $this->all()));
    }

    /** @return array<string, string> method code => family presentation type, in public order */
    public function families(): array
    {
        return ['qris' => 'qr_image', 'virtual_account' => 'virtual_account'];
    }

    /** @return array{code: string, method: string, channel: string, presentation_type: string}|null */
    public function find(string $method, string $channel): ?array
    {
        foreach ($this->all() as $definition) {
            if ($definition['method'] === $method && $definition['channel'] === $channel) {
                return $definition;
            }
        }

        return null;
    }

    public function environment(): string
    {
        return (string) config('midtrans.environment');
    }

    /** The pinned Core API host for the configured environment, or null when the configured host is not the expected one. */
    public function baseUrl(): ?string
    {
        $expected = ['sandbox' => 'https://api.sandbox.midtrans.com', 'production' => 'https://api.midtrans.com'][$this->environment()] ?? null;
        $configured = rtrim((string) config('midtrans.base_urls.'.$this->environment()), '/');

        return $expected !== null && $configured === $expected ? $expected : null;
    }

    /** Global gates, independent of any channel. */
    public function configurationReason(): ?string
    {
        if (! config('midtrans.enabled')) {
            return 'midtrans_disabled';
        }
        if ($reason = $this->verificationReason()) {
            return $reason;
        }
        if ($this->environment() === 'production' && ! config('midtrans.production_approved')) {
            return 'midtrans_production_not_approved';
        }

        return null;
    }

    /**
     * Credential, environment and host gates only. This is what notifications, Status lookups and reconciliation of orders the
     * provider already holds need: the new-charge kill switch and the Production approval deliberately do not apply to them.
     */
    public function verificationReason(): ?string
    {
        $environment = $this->environment();
        $key = (string) config('midtrans.server_key');
        $prefix = $environment === 'production' ? self::PRODUCTION_KEY_PREFIX : self::SANDBOX_KEY_PREFIX;
        $keyMatches = str_starts_with($key, $prefix) && strlen($key) > strlen($prefix);
        if (! in_array($environment, ['sandbox', 'production'], true) || ! $keyMatches || $this->baseUrl() === null
            || ($environment === 'production' && trim((string) config('midtrans.merchant_id')) === '')) {
            return 'midtrans_not_configured';
        }

        return null;
    }

    /**
     * Full decision for issuing a NEW payment on one channel. Never used to accept notifications or to recover orders the
     * provider already holds.
     */
    public function reason(string $code): ?string
    {
        if (! isset($this->all()[$code])) {
            return 'channel_unavailable';
        }

        return $this->configurationReason()
            ?? (in_array($code, (array) config('midtrans.channels', []), true) ? null : 'channel_not_enabled')
            ?? ($this->environment() === 'production' && ! in_array($code, (array) config('midtrans.verified_channels', []), true) ? 'channel_not_verified' : null);
    }

    /** @return array<string, string|null> channel code => disabled reason (null when payable) */
    public function reasons(): array
    {
        return array_map(fn (array $definition): ?string => $this->reason($definition['code']), $this->all());
    }
}
