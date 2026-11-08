<?php

namespace App\Services\Doku;

/**
 * Server-side catalogue of DOKU payment channels and the gates that decide whether each one may issue payments.
 *
 * A channel is payable only when every gate passes: the adapter exists, the notification contract is verified,
 * the merchant explicitly marked it verified, it is in the operator allowlist and its merchant parameters are valid.
 * `DOKU_CHANNELS` is an allowlist, never proof of provider activation.
 */
class DokuChannelRegistry
{
    /**
     * `implemented`: transport, normalizer and tests exist in this codebase.
     * `settles`: a verified provider notification/inquiry contract exists, so a paid outcome may be applied. `null` means the
     *   contract is documented but the operator must confirm the bank's notification CHANNEL-ID in configuration first.
     * `grandfathered`: enabled before DOKU_VERIFIED_CHANNELS existed; stays compatible with current deployments.
     *
     * @return array<string, array{code: string, method: string, channel: string, presentation_type: string, implemented: bool, settles: bool, grandfathered: bool}>
     */
    public function all(): array
    {
        $definitions = [
            ['va_bni', 'virtual_account', 'bni', 'virtual_account', true, true, true],
            ['va_bca', 'virtual_account', 'bca', 'virtual_account', true, null, false],
            ['va_bri', 'virtual_account', 'bri', 'virtual_account', true, null, false],
            ['va_mandiri', 'virtual_account', 'mandiri', 'virtual_account', true, null, false],
            ['qris', 'qris', 'qris', 'qr_payload', true, false, false],
            ['ewallet_dana', 'ewallet', 'dana', 'ewallet_redirect', true, true, false],
            ['ewallet_shopeepay', 'ewallet', 'shopeepay', 'ewallet_redirect', true, true, false],
            ['ewallet_ovo', 'ewallet', 'ovo', 'ewallet_redirect', false, false, false],
        ];

        $channels = [];
        foreach ($definitions as [$code, $method, $channel, $presentation, $implemented, $settles, $grandfathered]) {
            $channels[$code] = ['code' => $code, 'method' => $method, 'channel' => $channel, 'presentation_type' => $presentation,
                'implemented' => $implemented, 'settles' => $settles ?? $this->notificationConfigured($channel), 'grandfathered' => $grandfathered];
        }

        return $channels;
    }

    private function notificationConfigured(string $bank): bool
    {
        return preg_match('/^[A-Za-z0-9]{2,16}$/D', (string) config('doku.va_banks.'.$bank.'.notification_channel_id')) === 1;
    }

    /** @return array<string, string> method code => family presentation type, in public order */
    public function families(): array
    {
        return ['qris' => 'qr_payload', 'virtual_account' => 'virtual_account', 'ewallet' => 'ewallet_redirect'];
    }

    /** @return array<string, mixed>|null */
    public function find(string $method, string $channel): ?array
    {
        foreach ($this->all() as $definition) {
            if ($definition['method'] === $method && $definition['channel'] === $channel) {
                return $definition;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function channelsFor(string $method): array
    {
        return array_values(array_map(fn (array $definition): string => $definition['channel'],
            array_filter($this->all(), fn (array $definition): bool => $definition['method'] === $method)));
    }

    /** @return list<array{0: string, 1: string}> method/channel pairs whose verified paid outcome may settle a booking */
    public function settlingPairs(): array
    {
        return array_values(array_map(fn (array $definition): array => [$definition['method'], $definition['channel']],
            array_filter($this->all(), fn (array $definition): bool => $definition['settles'])));
    }

    /** Gates evaluated without touching merchant configuration; stable disabled reasons. */
    public function staticReason(string $code): ?string
    {
        $definition = $this->all()[$code] ?? null;

        return match (true) {
            $definition === null => 'channel_unavailable',
            ! $definition['implemented'] => 'channel_not_implemented',
            ! $definition['settles'] => 'channel_contract_unverified',
            ! $definition['grandfathered'] && ! in_array($code, (array) config('doku.verified_channels', []), true) => 'channel_not_verified',
            default => null,
        };
    }

    /** Full fail-closed decision for one channel: static gates, then global/merchant readiness. */
    public function reason(string $code): ?string
    {
        return $this->staticReason($code) ?? app(DokuClient::class)->readinessReason($code);
    }

    /** @return array<string, string|null> channel code => disabled reason (null when payable) */
    public function reasons(): array
    {
        return array_map(fn (array $definition): ?string => $this->reason($definition['code']), $this->all());
    }
}
