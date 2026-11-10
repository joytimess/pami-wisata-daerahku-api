<?php

namespace App\Services\Midtrans;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns a Midtrans transaction response (Charge, authenticated Status or, later, a notification) into one normalized outcome.
 *
 * The mapper trusts nothing in the payload: every identity field must equal the stored attempt, otherwise the result is
 * `unknown` and carries no instruction and no paid evidence. Money is integer IDR; Midtrans times are GMT+7.
 */
class MidtransStatusMapper
{
    public const PROVIDER_TIMEZONE = 'Asia/Jakarta';

    public function __construct(private MidtransChannelRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $response
     * @param  'charge'|'status'|'notification'  $evidenceSource
     * @return array<string, mixed>
     */
    public function normalize(array $response, object $attempt, string $evidenceSource): array
    {
        $transactionId = $response['transaction_id'] ?? null;
        $amount = $this->amount($response['gross_amount'] ?? null);
        if (($response['order_id'] ?? null) !== $attempt->merchant_reference || ! is_string($transactionId) || $transactionId === '' || strlen($transactionId) > 128
            || $amount === null || $amount !== (int) $attempt->amount || ($response['currency'] ?? null) !== $attempt->currency) {
            return $this->unknown('provider_response_invalid');
        }
        $merchant = trim((string) config('midtrans.merchant_id'));
        $responseMerchant = $response['merchant_id'] ?? null;
        if (($responseMerchant !== null && (! is_string($responseMerchant) || ($merchant !== '' && $responseMerchant !== $merchant)))
            || ($responseMerchant === null && $this->registry->environment() === 'production')) {
            return $this->unknown('merchant_mismatch');
        }
        if (! $this->bound($response, $attempt)) {
            return $this->unknown('provider_response_invalid');
        }

        $base = ['merchant_reference' => $attempt->merchant_reference, 'provider_reference' => $transactionId, 'amount' => $amount,
            'currency' => $attempt->currency, 'method' => $attempt->method, 'channel' => $attempt->channel];
        $status = $response['transaction_status'] ?? null;
        if ($evidenceSource === 'charge') {
            return $status === 'pending' && ($response['status_code'] ?? null) === '201'
                ? $this->pendingCharge($response, $attempt, $base)
                : $this->unknown('provider_response_invalid');
        }

        return match ($status) {
            'pending' => ['status' => 'pending'] + $base + $this->occurred($response['transaction_time'] ?? null),
            'settlement' => $this->settlement($response, $base),
            'expire' => ['status' => 'expired', 'reason' => 'provider_expire'] + $base + $this->occurred($response['transaction_time'] ?? null),
            'deny', 'cancel', 'failure' => ['status' => 'failed', 'reason' => 'provider_'.$status] + $base + $this->occurred($response['transaction_time'] ?? null),
            'refund' => $this->refund($response, $base),
            'partial_refund', 'chargeback', 'partial_chargeback' => $this->unknown('partial_adjustment_review'),
            default => $this->unknown('provider_response_invalid'),
        };
    }

    /** @return array<string, mixed> */
    private function settlement(array $response, array $base): array
    {
        $fraud = $response['fraud_status'] ?? null;
        if ($fraud !== null && $fraud !== 'accept') {
            return $this->unknown('fraud_status_review');
        }
        $occurred = $this->occurred($response['settlement_time'] ?? null);

        return $occurred === [] ? $this->unknown('provider_response_invalid') : ['status' => 'paid'] + $base + $occurred;
    }

    /** @return array<string, mixed> */
    private function refund(array $response, array $base): array
    {
        $refunds = $response['refunds'] ?? null;
        if (! is_array($refunds) || $refunds === []) {
            return $this->unknown('provider_response_invalid');
        }
        $total = 0;
        $latest = null;
        foreach ($refunds as $refund) {
            $refunded = is_array($refund) ? $this->amount($refund['refund_amount'] ?? null) : null;
            $at = is_array($refund) ? $this->time($refund['created_at'] ?? null) : null;
            if ($refunded === null || $at === null) {
                return $this->unknown('provider_response_invalid');
            }
            $total += $refunded;
            $latest = $latest === null || $at->gt($latest) ? $at : $latest;
        }

        return $total === $base['amount']
            ? ['status' => 'refunded'] + $base + ['occurred_at' => $latest->toIso8601String()]
            : $this->unknown('partial_adjustment_review');
    }

    /** @return array<string, mixed> */
    private function pendingCharge(array $response, object $attempt, array $base): array
    {
        $expiry = $this->time($response['expiry_time'] ?? null);
        $occurred = $this->occurred($response['transaction_time'] ?? null);
        if ($expiry === null || $occurred === [] || $expiry->lte(now()) || $expiry->gt(CarbonImmutable::parse($attempt->instruction_expires_at, 'UTC'))) {
            return $this->unknown('provider_expiry_invalid');
        }
        $instruction = $attempt->method === 'qris' ? $this->qrisInstruction($response, $base['provider_reference']) : $this->vaInstruction($response, $attempt);
        if ($instruction === null) {
            return $this->unknown('provider_response_invalid');
        }

        return ['status' => 'pending'] + $base + $occurred + ['expires_at' => $expiry->toIso8601String(),
            'presentation_type' => $instruction['presentation_type'], 'instruction' => $instruction];
    }

    /** @return array<string, mixed>|null */
    private function vaInstruction(array $response, object $attempt): ?array
    {
        $number = $response['va_numbers'][0]['va_number'] ?? null;

        return is_string($number) && preg_match('/^\d{5,24}$/D', $number) === 1
            ? ['presentation_type' => 'virtual_account', 'bank_code' => $attempt->channel, 'va_number' => $number, 'copyable' => true] : null;
    }

    /** @return array<string, mixed>|null */
    private function qrisInstruction(array $response, string $transactionId): ?array
    {
        $url = null;
        foreach ((array) ($response['actions'] ?? []) as $action) {
            if (is_array($action) && in_array($action['name'] ?? null, ['generate-qr-code', 'generate-qr-code-v2'], true) && is_string($action['url'] ?? null)) {
                $url = $action['url'];
                break;
            }
        }
        $parts = $url === null ? false : parse_url($url);
        $host = parse_url((string) $this->registry->baseUrl(), PHP_URL_HOST);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! is_string($host) || ($parts['host'] ?? null) !== $host
            || array_intersect_key($parts, array_flip(['user', 'pass', 'port', 'query', 'fragment'])) !== []
            || ($parts['path'] ?? null) !== '/v2/qris/'.$transactionId.'/qr-code') {
            return null;
        }

        return ['presentation_type' => 'qr_image', 'acquirer' => (string) config('midtrans.qris_acquirer'), 'image_available' => true,
            'image_content_type' => 'image/png', '_provider' => ['image_url' => $url]];
    }

    /** The response must belong to the attempt's payment type, bank or acquirer. */
    private function bound(array $response, object $attempt): bool
    {
        if ($attempt->method === 'virtual_account') {
            return ($response['payment_type'] ?? null) === 'bank_transfer' && ($response['va_numbers'][0]['bank'] ?? null) === $attempt->channel;
        }

        return $attempt->method === 'qris' && ($response['payment_type'] ?? null) === 'qris'
            && (! isset($response['acquirer']) || $response['acquirer'] === config('midtrans.qris_acquirer'));
    }

    /** IDR integer from "20000", 20000 or "20000.00"; anything fractional or malformed is rejected. */
    private function amount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && preg_match('/^([1-9]\d{0,14})(\.0{1,2})?$/D', $value, $matches) === 1 ? (int) $matches[1] : null;
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            $time = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, self::PROVIDER_TIMEZONE);
        } catch (Throwable) {
            return null;
        }

        return $time === false ? null : $time->utc();
    }

    /** @return array{occurred_at?: string} */
    private function occurred(mixed $value): array
    {
        $time = $this->time($value);

        return $time === null ? [] : ['occurred_at' => $time->toIso8601String()];
    }

    /** @return array{status: string, reason: string} */
    private function unknown(string $reason): array
    {
        return ['status' => 'unknown', 'reason' => $reason];
    }
}
