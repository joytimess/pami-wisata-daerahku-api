<?php

namespace App\Services\Doku;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use JsonException;
use RuntimeException;

/**
 * QRIS MPM generate over DOKU SNAP.
 *
 * BLOCKED_EXTERIOR: the official page documents no notification contract and does not enumerate the QRIS query
 * latestTransactionStatus values, so a QRIS payment can never be proven paid. The channel therefore stays
 * `settles=false` (unavailable) and status inquiry deliberately returns no terminal mapping.
 */
class DokuQrisAdapter
{
    private const GENERATE_PATH = '/snap-adapter/b2b/v1.0/qr/qr-mpm-generate';

    /** @return array<string, mixed> */
    public function create(DokuClient $client, object $attempt, object $booking): array
    {
        if (($attempt->method ?? '') !== 'qris' || $attempt->channel !== 'qris') {
            return ['status' => 'unknown', 'reason' => 'channel_not_implemented'];
        }
        if ($reason = $client->readinessReason('qris')) {
            return ['status' => 'unknown', 'reason' => $reason];
        }
        $expiry = CarbonImmutable::parse($attempt->instruction_expires_at ?? $booking->payment_expires_at, 'UTC');
        $body = [
            'partnerReferenceNo' => $attempt->merchant_reference,
            'amount' => ['value' => $attempt->amount.'.00', 'currency' => $attempt->currency],
            'merchantId' => (string) config('doku.qris.merchant_id'),
            'terminalId' => (string) config('doku.qris.terminal_id'),
            'validityPeriod' => $expiry->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i:sP'),
            'additionalInfo' => ['postalCode' => (string) config('doku.qris.postal_code'), 'feeType' => '1'],
        ];
        try {
            $response = $client->signedRequest(self::GENERATE_PATH, $body, $attempt->provider_request_id, 'H2H', $client->diagnosticContext($attempt));
        } catch (ConnectionException) {
            return ['status' => 'unknown', 'reason' => 'provider_timeout'];
        } catch (RuntimeException|JsonException) {
            return ['status' => 'unknown', 'reason' => 'provider_unavailable'];
        }
        if (preg_match('/^200\d{2}00$/D', (string) ($response['responseCode'] ?? '')) !== 1) {
            $client->recordCreateSnapshot($client->diagnosticContext($attempt), 'doku_payment_not_accepted');

            return ['status' => 'unknown', 'reason' => 'provider_create_unresolved'];
        }
        $payload = $response['qrContent'] ?? null;
        $reference = $response['referenceNo'] ?? null;
        if (! is_string($payload) || $payload === '' || strlen($payload) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $payload)
            || ! is_string($reference) || preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $reference) !== 1
            || ($response['partnerReferenceNo'] ?? null) !== $attempt->merchant_reference
            || (isset($response['terminalId']) && $response['terminalId'] !== config('doku.qris.terminal_id'))) {
            return ['status' => 'unknown', 'reason' => 'provider_response_mismatch'];
        }

        return ['status' => 'pending', 'provider_reference' => $reference, 'expires_at' => $expiry->utc()->toDateTimeString(),
            'presentation_type' => 'qr_payload',
            'instruction' => ['presentation_type' => 'qr_payload', 'qr_content' => $payload, 'copyable' => false]];
    }
}
