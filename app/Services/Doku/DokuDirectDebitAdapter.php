<?php

namespace App\Services\Doku;

use App\Exceptions\DokuRequestException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use JsonException;
use RuntimeException;

/**
 * One-time e-wallet payments over DOKU SNAP direct debit (DANA, ShopeePay).
 *
 * Verified against the official pages: payment-host-to-host request/response, the shared
 * `/orders/v1.0/debit/status` query and its latestTransactionStatus values. The notification signature string is not
 * documented, so these channels settle only from authenticated status queries; redirects and return URLs never do.
 * OVO is intentionally absent: its binding and SALE field lists are not readable in the official page.
 */
class DokuDirectDebitAdapter
{
    private const PAYMENT_PATH_V1 = '/direct-debit/core/v1/debit/payment-host-to-host';

    private const PAYMENT_PATH_V2 = '/direct-debit/core/v2/debit/payment-host-to-host';

    private const STATUS_PATH = '/orders/v1.0/debit/status';

    /** @var array<string, string> */
    private const DOKU_CHANNELS = ['dana' => 'EMONEY_DANA_SNAP', 'shopeepay' => 'EMONEY_SHOPEE_PAY_SNAP'];

    public function merchantConfigured(string $wallet): bool
    {
        return isset(self::DOKU_CHANNELS[$wallet]) && $this->returnUrl() !== null && $this->redirectHosts() !== []
            && ($wallet !== 'shopeepay' || $this->paymentPath('shopeepay') !== null);
    }

    /** @return array<string, mixed> */
    public function create(DokuClient $client, object $attempt, object $booking): array
    {
        $wallet = (string) $attempt->channel;
        if (! isset(self::DOKU_CHANNELS[$wallet]) || ($attempt->method ?? '') !== 'ewallet') {
            return ['status' => 'unknown', 'reason' => 'channel_not_implemented'];
        }
        if ($reason = $client->readinessReason('ewallet_'.$wallet)) {
            return ['status' => 'unknown', 'reason' => $reason];
        }
        $expiry = CarbonImmutable::parse($attempt->instruction_expires_at ?? $booking->payment_expires_at, 'UTC');
        if ($wallet === 'shopeepay') {
            $expiry = $expiry->min(now()->toImmutable()->addDay()); // Documented maximum validity of the payment URL.
        }
        $body = $this->createBody($wallet, $attempt, $expiry);
        try {
            $response = $client->signedRequest($this->paymentPath($wallet), $body, $attempt->provider_request_id, null, $client->diagnosticContext($attempt));
        } catch (ConnectionException) {
            return ['status' => 'unknown', 'reason' => 'provider_timeout'];
        } catch (DokuRequestException $e) {
            // DANA documents HTTP 500 / 5005400 as a non-retryable General Error: the payment was refused, so it is final.
            // Every other failure (5005401, 429, other 5xx, token faults) can still resolve and stays unknown.
            if ($wallet === 'dana' && $this->isDanaGeneralError($e)) {
                return ['status' => 'failed', 'reason' => 'provider_general_error'];
            }

            return ['status' => 'unknown', 'reason' => 'provider_unavailable'];
        } catch (RuntimeException|JsonException) {
            return ['status' => 'unknown', 'reason' => 'provider_unavailable'];
        }
        $context = $client->diagnosticContext($attempt);
        if (($response['responseCode'] ?? null) !== '2005400') {
            $client->recordFailure($context, 'payment_create', 'doku_payment_not_accepted', ['http_status' => 200, 'provider_response_code' => $response['responseCode'] ?? null]);
            $client->recordCreateSnapshot($context, 'doku_payment_not_accepted');

            return ['status' => 'unknown', 'reason' => 'provider_create_unresolved'];
        }
        $redirect = $this->safeRedirect($response['webRedirectUrl'] ?? null);
        if ($redirect === null) {
            $client->recordFailure($context, 'payment_create', 'doku_redirect_host_rejected', ['http_status' => 200, 'provider_response_code' => $response['responseCode'] ?? null]);
            $client->recordCreateSnapshot($context, 'doku_redirect_host_rejected');

            return ['status' => 'unknown', 'reason' => 'provider_response_mismatch'];
        }
        if (isset($response['partnerReferenceNo']) && $response['partnerReferenceNo'] !== $attempt->merchant_reference) {
            $client->recordFailure($context, 'payment_create', 'doku_payment_response_invalid', ['http_status' => 200, 'provider_response_code' => $response['responseCode'] ?? null]);
            $client->recordCreateSnapshot($context, 'doku_payment_response_invalid');

            return ['status' => 'unknown', 'reason' => 'provider_response_mismatch'];
        }
        $reference = $response['referenceNo'] ?? null;

        return ['status' => 'pending', 'provider_reference' => is_string($reference) && preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $reference) ? $reference : null,
            'expires_at' => $expiry->utc()->toDateTimeString(), 'presentation_type' => 'ewallet_redirect',
            'instruction' => ['presentation_type' => 'ewallet_redirect', 'redirect_url' => $redirect, 'requires_external_authorization' => true]];
    }

    /**
     * Authenticated status query. Only documented latestTransactionStatus values are mapped.
     *
     * @return array<string, mixed>
     */
    public function status(DokuClient $client, object $attempt): array
    {
        $wallet = (string) $attempt->channel;
        if (! isset(self::DOKU_CHANNELS[$wallet])) {
            return ['status' => 'unknown', 'reason' => 'channel_not_implemented'];
        }
        if (! $client->configured()) {
            return ['status' => 'unknown', 'reason' => 'doku_not_configured'];
        }
        try {
            $response = $client->signedRequest(self::STATUS_PATH, [
                'serviceCode' => '55', 'originalPartnerReferenceNo' => $attempt->merchant_reference,
                'originalExternalId' => $attempt->provider_request_id,
                'amount' => ['value' => $attempt->amount.'.00', 'currency' => $attempt->currency], 'additionalInfo' => (object) [],
            ] + (empty($attempt->provider_reference) ? [] : ['originalReferenceNo' => $attempt->provider_reference]),
                (string) random_int(100000000000000000, 999999999999999999), null, $client->diagnosticContext($attempt));
        } catch (ConnectionException) {
            return ['status' => 'unknown', 'reason' => 'provider_timeout'];
        } catch (RuntimeException|JsonException) {
            return ['status' => 'unknown', 'reason' => 'provider_unavailable'];
        }
        if (($response['responseCode'] ?? null) !== '2005500') {
            return ['status' => 'unknown', 'reason' => 'provider_status_unresolved'];
        }
        if (($response['originalPartnerReferenceNo'] ?? '') !== $attempt->merchant_reference
            || ! in_array($response['serviceCode'] ?? null, $wallet === 'shopeepay' ? ['54', '55'] : ['55'], true)
            || (isset($response['originalExternalId']) && $response['originalExternalId'] !== $attempt->provider_request_id)
            || (! empty($attempt->provider_reference) && isset($response['originalReferenceNo'])
                && $response['originalReferenceNo'] !== $attempt->provider_reference)) {
            return ['status' => 'unknown', 'reason' => 'provider_response_mismatch'];
        }
        $base = ['method' => 'ewallet', 'channel' => $wallet, 'merchant_reference' => $attempt->merchant_reference,
            'provider_reference' => is_string($response['originalReferenceNo'] ?? null) ? $response['originalReferenceNo'] : $attempt->provider_reference,
            'currency' => $attempt->currency];

        return match ((string) ($response['latestTransactionStatus'] ?? '')) {
            '01', '02', '03' => ['status' => 'pending'],
            '00' => $this->paid($client, $response, $base, $attempt),
            '05', '06' => $base + ['status' => 'failed', 'amount' => (int) $attempt->amount, 'occurred_at' => now()->toIso8601String()],
            '04' => ['status' => 'unknown', 'reason' => 'provider_adjustment_unverified'],
            '07' => ['status' => 'unknown', 'reason' => 'provider_status_not_found'],
            default => ['status' => 'unknown', 'reason' => 'provider_terminal_contract_unverified'],
        };
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function paid(DokuClient $client, array $response, array $base, object $attempt): array
    {
        $amount = $client->parseAmount($response['transAmount'] ?? []);
        $paidTime = $client->parseInstant($response['paidTime'] ?? null);
        if ($amount === null || $paidTime === null || empty($base['provider_reference'])) {
            return ['status' => 'unknown', 'reason' => 'provider_response_mismatch'];
        }

        return $base + ['status' => 'paid', 'amount' => $amount, 'occurred_at' => $paidTime->utc()->toIso8601String(),
            'event_key' => 'debit:'.hash('sha256', $attempt->merchant_reference.':paid'), 'payload_hash' => hash('sha256', json_encode($response, JSON_THROW_ON_ERROR))];
    }

    /**
     * The exact create body sent to DOKU. Shared with the sandbox probe so what it dumps is what production code builds.
     *
     * @return array<string, mixed>
     */
    public function createBody(string $wallet, object $attempt, CarbonImmutable $expiry): array
    {
        $urlParam = ['url' => $this->returnUrl(), 'type' => 'PAY_RETURN', 'isDeepLink' => 'N'];

        return [
            'partnerReferenceNo' => $attempt->merchant_reference,
            'validUpTo' => $expiry->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i:sP'),
            'pointOfInitiation' => 'app',
            'amount' => ['value' => $attempt->amount.'.00', 'currency' => $attempt->currency],
            'additionalInfo' => ['channel' => self::DOKU_CHANNELS[$wallet]],
        ] + ($wallet === 'shopeepay' ? ['urlParams' => [$urlParam]] : ['urlParam' => $urlParam]);
    }

    private function isDanaGeneralError(DokuRequestException $exception): bool
    {
        return $exception->phase === 'payment_create' && $exception->httpStatus === 500 && $exception->providerResponseCode === '5005400';
    }

    public function paymentPath(string $wallet): ?string
    {
        if ($wallet === 'dana') {
            return self::PAYMENT_PATH_V1;
        }
        $configured = (string) config('doku.ewallet.shopeepay_payment_path');

        return in_array($configured, [self::PAYMENT_PATH_V1, self::PAYMENT_PATH_V2], true) ? $configured : null;
    }

    private function returnUrl(): ?string
    {
        $url = (string) config('doku.ewallet.return_url');
        $parts = parse_url($url);

        return ($parts['scheme'] ?? '') === 'https' && ! empty($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['fragment']) && strlen($url) <= 255 ? $url : null;
    }

    /** @return list<string> */
    private function redirectHosts(): array
    {
        return array_values(array_filter(array_map(fn ($host) => is_string($host) ? strtolower(trim($host)) : '', (array) config('doku.ewallet.redirect_hosts', [])),
            fn (string $host) => preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$/D', $host) === 1));
    }

    /** Only an HTTPS URL on an operator-confirmed host may reach the customer. */
    private function safeRedirect(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) && $parts['port'] !== 443) {
            return null;
        }

        return in_array(strtolower($parts['host']), $this->redirectHosts(), true) ? $url : null;
    }
}
