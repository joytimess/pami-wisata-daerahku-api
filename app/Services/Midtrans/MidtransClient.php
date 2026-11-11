<?php

namespace App\Services\Midtrans;

use App\Exceptions\MidtransRequestException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Midtrans Core API (standard) transport: Basic Auth with the Server Key as username and a blank password.
 *
 * A Charge is sent at most once. Neither timeouts nor 5xx responses are retried here: an ambiguous outcome is returned as
 * `unknown` and recovered through a Status lookup of the SAME order, never by creating another order.
 */
class MidtransClient
{
    /** HTTP answers that definitively refuse a request (Charge or Status); every other 3xx/4xx is ambiguous. */
    private const REFUSAL_HTTP_STATUSES = [400, 402, 404, 405, 422];

    /** The subset that definitively refuses a refund. */
    private const REFUND_REFUSAL_STATUSES = [400, 402, 405, 422];

    public function __construct(private MidtransChannelRegistry $registry, private MidtransStatusMapper $mapper) {}

    public function environment(): string
    {
        return $this->registry->environment();
    }

    public function configured(): bool
    {
        return $this->registry->configurationReason() === null;
    }

    public function readinessReason(string $code): ?string
    {
        return $this->registry->reason($code);
    }

    /** The provider rejects very short expiries and its expiry scheduler may lag, so a minimum remaining window is enforced. */
    public function expiryWindowReason(CarbonImmutable $expiresAt): ?string
    {
        return $expiresAt->lte(now()->addSeconds((int) config('midtrans.minimum_expiry_seconds', 30))) ? 'payment_window_closed' : null;
    }

    /**
     * Sends POST /v2/charge for a reserved attempt.
     *
     * `$booking->payment_approved_at` is the authoritative approval time (the start of the one absolute deadline) and is sent as
     * the GMT+7 `order_time` of `custom_expiry`; the duration is truncated to whole minutes so the provider can never expire later
     * than the booking deadline.
     *
     * @return array<string, mixed> normalized outcome: status pending|failed|unknown plus reason, instruction and expiry
     */
    public function charge(object $attempt, object $booking): array
    {
        $definition = $this->registry->find($attempt->method, (string) $attempt->channel);
        if ($reason = $definition ? $this->registry->reason($definition['code']) : 'channel_unavailable') {
            return ['status' => 'failed', 'reason' => $reason];
        }
        $expiresAt = CarbonImmutable::parse($attempt->instruction_expires_at, 'UTC');
        $approvedAt = $booking->payment_approved_at ?? null;
        if (! $approvedAt instanceof CarbonImmutable) {
            return ['status' => 'failed', 'reason' => 'payment_window_unavailable'];
        }
        $minutes = intdiv($expiresAt->getTimestamp() - $approvedAt->getTimestamp(), 60);
        if ($reason = $this->expiryWindowReason($expiresAt) ?? ($minutes < 1 ? 'payment_window_closed' : null)) {
            return ['status' => 'failed', 'reason' => $reason];
        }
        $payload = [
            'transaction_details' => ['order_id' => $attempt->merchant_reference, 'gross_amount' => (int) $attempt->amount],
            'custom_expiry' => ['order_time' => $approvedAt->setTimezone(MidtransStatusMapper::PROVIDER_TIMEZONE)->format('Y-m-d H:i:s O'),
                'expiry_duration' => $minutes, 'unit' => 'minute'],
        ] + ($attempt->method === 'qris'
            ? ['payment_type' => 'qris', 'qris' => ['acquirer' => (string) config('midtrans.qris_acquirer')]]
            : ['payment_type' => 'bank_transfer', 'bank_transfer' => ['bank' => $attempt->channel]]);

        try {
            $body = $this->decode($this->send('charge', 'POST', '/v2/charge', $payload), 'charge');
        } catch (MidtransRequestException $exception) {
            return $this->failure($attempt, $exception);
        } catch (Throwable $exception) {
            return $this->failure($attempt, new MidtransRequestException('charge', 'midtrans_internal_exception'));
        }
        $status = (string) ($body['status_code'] ?? '');
        if ($status !== '201') {
            return $this->failure($attempt, new MidtransRequestException('charge', $this->classify($status), null, $status));
        }
        $outcome = $this->mapper->normalize($body, $attempt, 'charge');
        if ($outcome['status'] !== 'pending') {
            $this->log($attempt, 'midtrans_response_invalid', null, $status);
        }

        return $outcome;
    }

    /**
     * Instruction safe to show the owner: the provider-only part (such as the QR image URL) is never returned.
     *
     * @return array<string, mixed>
     */
    public function instruction(object $attempt): array
    {
        if ($attempt->instruction_ciphertext === null) {
            return ['presentation_type' => 'unavailable'];
        }
        try {
            $instruction = json_decode(Crypt::decryptString($attempt->instruction_ciphertext), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['presentation_type' => 'unavailable'];
        }
        unset($instruction['_provider']);

        return is_array($instruction) ? $instruction : ['presentation_type' => 'unavailable'];
    }

    /**
     * Authenticated lookup of a stored order: GET /v2/{order_id}/status.
     *
     * The order id comes only from the stored attempt, never from a notification or the client, and the host is the pinned one
     * of the attempt's own environment. The new-charge kill switch does not apply: orders the provider already holds must stay
     * verifiable. Anything that is not a clean, fully bound response is `unknown` (never paid), with a classified reason.
     *
     * @return array<string, mixed> normalized outcome, plus `payload_hash` (sha256 of the raw response body) when evidence exists
     */
    public function status(object $attempt): array
    {
        if ($reason = $this->statusPrecondition($attempt)) {
            return ['status' => 'unknown', 'reason' => $reason];
        }
        try {
            $response = $this->send('status', 'GET', '/v2/'.rawurlencode($attempt->merchant_reference).'/status');
            $body = $this->decode($response, 'status');
        } catch (MidtransRequestException $exception) {
            $this->log($attempt, $exception->diagnosticCode, $exception->httpStatus, $exception->providerStatusCode, 'Midtrans Status lookup did not produce evidence.');

            return ['status' => 'unknown', 'reason' => match ($exception->diagnosticCode) {
                'midtrans_provider_unauthorized' => 'provider_unauthorized',
                'midtrans_response_invalid' => 'provider_response_invalid',
                default => 'provider_unavailable',
            }];
        } catch (Throwable) {
            $this->log($attempt, 'midtrans_internal_exception', null, null, 'Midtrans Status lookup did not produce evidence.');

            return ['status' => 'unknown', 'reason' => 'provider_unavailable'];
        }
        // The provider answers an unknown order, and some failures, in the body of an HTTP 200.
        $bodyCode = (string) ($body['status_code'] ?? '');
        if (in_array($bodyCode, ['401', '403', '404'], true)) {
            $this->log($attempt, 'midtrans_status_rejected', null, $bodyCode, 'Midtrans Status lookup did not produce evidence.');

            return ['status' => 'unknown', 'reason' => $bodyCode === '404' ? 'provider_order_not_found' : 'provider_unauthorized'];
        }
        $outcome = $this->mapper->normalize($body, $attempt, 'status');
        if ($outcome['status'] === 'unknown') {
            return $outcome;
        }

        return $outcome + ['payload_hash' => hash('sha256', (string) $response->body())];
    }

    /**
     * Why a provider-API refund of this paid attempt cannot be dispatched, or null when it can: the attempt's immutable binding,
     * the channel capability, the evidence of a verified payment and the refund window all have to hold.
     */
    public function refundReason(object $attempt): ?string
    {
        if ($reason = $this->statusPrecondition($attempt)) {
            return $reason;
        }
        if ($reason = $this->registry->refundReason((string) $attempt->method, (string) $attempt->channel)) {
            return $reason;
        }
        if (($attempt->verified_paid_at ?? null) === null || ! is_string($attempt->provider_reference ?? null) || $attempt->provider_reference === '') {
            return 'refund_evidence_missing';
        }
        if (CarbonImmutable::parse($attempt->verified_paid_at, 'UTC')->addDays($this->registry->refundWindowDays())->lte(now())) {
            return 'refund_window_closed';
        }

        return null;
    }

    /**
     * Sends POST /v2/{order_id}/refund for the FULL amount with the persisted refund key. At most one request per call, never with a
     * different key, and no automatic retry here: a repeat uses the same key and is decided by the caller.
     *
     * Outcomes: accepted (the provider took it - NOT proof that funds returned), rejected (definitively refused, nothing moved),
     * review (an answer that is not the full refund we asked for) or unknown (ambiguous; reconcile by Status).
     *
     * @return array<string, mixed>
     */
    public function refund(object $attempt, object $refund): array
    {
        $amount = (int) $refund->amount;
        if ($amount <= 0 || $amount !== (int) $attempt->amount || ! is_string($refund->refund_key ?? null) || $refund->refund_key === '') {
            return ['status' => 'rejected', 'reason' => 'refund_not_full'];
        }
        try {
            $response = $this->send('refund', 'POST', '/v2/'.rawurlencode($attempt->merchant_reference).'/refund',
                ['refund_key' => $refund->refund_key, 'amount' => $amount, 'reason' => 'Full refund']);
            $body = $this->decode($response, 'refund');
        } catch (MidtransRequestException $exception) {
            $this->log($attempt, $exception->diagnosticCode, $exception->httpStatus, $exception->providerStatusCode, 'Midtrans refund did not produce an accepted refund.');

            return match ($exception->diagnosticCode) {
                'midtrans_provider_unauthorized' => ['status' => 'rejected', 'reason' => 'provider_unauthorized'],
                // A refund refusal must be unambiguous: 404/412 can also mean the refund already exists, so they are reconciled instead.
                'midtrans_provider_rejected' => in_array($exception->httpStatus, self::REFUND_REFUSAL_STATUSES, true)
                    ? ['status' => 'rejected', 'reason' => 'provider_rejected'] : ['status' => 'unknown', 'reason' => 'provider_unavailable'],
                'midtrans_response_invalid' => ['status' => 'unknown', 'reason' => 'provider_response_invalid'],
                default => ['status' => 'unknown', 'reason' => 'provider_unavailable'],
            };
        } catch (Throwable) {
            $this->log($attempt, 'midtrans_internal_exception', null, null, 'Midtrans refund did not produce an accepted refund.');

            return ['status' => 'unknown', 'reason' => 'provider_unavailable'];
        }
        $code = (string) ($body['status_code'] ?? '');
        if ($code !== '200') {
            $this->log($attempt, 'midtrans_refund_not_accepted', null, $code, 'Midtrans refund did not produce an accepted refund.');

            return match (true) {
                in_array($code, ['401', '403'], true) => ['status' => 'rejected', 'reason' => 'provider_unauthorized'],
                in_array($code, ['400', '402', '405'], true) => ['status' => 'rejected', 'reason' => 'provider_rejected'],
                default => ['status' => 'unknown', 'reason' => 'provider_unavailable'],
            };
        }
        $bound = $attempt->provider_reference ?? null;
        $reference = $body['refund_chargeback_id'] ?? null;
        if (($body['order_id'] ?? null) !== $attempt->merchant_reference || ($body['refund_key'] ?? null) !== $refund->refund_key
            || (is_string($bound) && $bound !== '' && ($body['transaction_id'] ?? null) !== $bound)
            || ! (is_int($reference) || is_string($reference)) || (string) $reference === '' || strlen((string) $reference) > 128) {
            return ['status' => 'unknown', 'reason' => 'provider_response_invalid'];
        }
        $refunded = $body['refund_amount'] ?? null;
        $refunded = is_int($refunded) ? $refunded : (is_string($refunded) && preg_match('/^([1-9]\d{0,14})(\.0{1,2})?$/D', $refunded, $matches) === 1 ? (int) $matches[1] : null);
        if ($refunded !== $amount) {
            return ['status' => 'review', 'reason' => 'partial_adjustment_review'];
        }

        return ['status' => 'accepted', 'provider_refund_reference' => (string) $reference];
    }

    /** The attempt's own immutable binding decides whether, and with which credentials, a lookup may be sent at all. */
    private function statusPrecondition(object $attempt): ?string
    {
        $boundMerchant = trim((string) ($attempt->merchant_id ?? ''));

        return match (true) {
            ($attempt->provider ?? null) !== 'midtrans' => 'provider_mismatch',
            $this->registry->verificationReason() !== null => 'midtrans_not_configured',
            ($attempt->environment ?? null) !== $this->registry->environment() => 'environment_mismatch',
            $boundMerchant !== '' && $boundMerchant !== trim((string) config('midtrans.merchant_id')) => 'attempt_merchant_mismatch',
            ! is_string($attempt->merchant_reference ?? null) || $attempt->merchant_reference === '' => 'attempt_invalid',
            default => null,
        };
    }

    /**
     * One request to the pinned host. Redirects are never followed, so credentials cannot be replayed elsewhere.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function send(string $phase, string $method, string $path, ?array $payload = null): Response
    {
        try {
            $request = Http::withBasicAuth((string) config('midtrans.server_key'), '')->acceptJson()->withoutRedirecting()
                ->timeout((int) config('midtrans.http_timeout_seconds', 10));
            $response = $method === 'GET'
                ? $request->get($this->registry->baseUrl().$path)
                : $request->asJson()->post($this->registry->baseUrl().$path, $payload ?? []);
        } catch (ConnectionException) {
            throw new MidtransRequestException($phase, 'midtrans_connection_error');
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw new MidtransRequestException($phase, 'midtrans_provider_unauthorized', $response->status());
        }
        if ($response->status() === 429 || $response->status() >= 500) {
            throw new MidtransRequestException($phase, 'midtrans_http_error', $response->status());
        }
        // Only an explicit client-error answer is a definitive refusal. Redirects, timeouts (408), conflicts (409) and the like may come
        // from a proxy after the provider already acted, so they stay ambiguous and are reconciled by Status.
        if (in_array($response->status(), self::REFUSAL_HTTP_STATUSES, true)) {
            throw new MidtransRequestException($phase, 'midtrans_provider_rejected', $response->status());
        }
        if ($response->status() >= 300) {
            throw new MidtransRequestException($phase, 'midtrans_http_error', $response->status());
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response, string $phase): array
    {
        $body = $response->json();
        if (! is_array($body) || array_is_list($body)) {
            throw new MidtransRequestException($phase, 'midtrans_response_invalid', $response->status());
        }

        return $body;
    }

    /** Body status codes: only a definitive pre-order rejection is a failure; duplicates, 5xx and anything else stay ambiguous. */
    private function classify(string $statusCode): string
    {
        return match (true) {
            $statusCode === '401' || $statusCode === '403' => 'midtrans_provider_unauthorized',
            in_array($statusCode, ['400', '402', '404', '405'], true) => 'midtrans_provider_rejected',
            default => 'midtrans_http_error',
        };
    }

    /** @return array{status: string, reason: string} */
    private function failure(object $attempt, MidtransRequestException $exception): array
    {
        $this->log($attempt, $exception->diagnosticCode, $exception->httpStatus, $exception->providerStatusCode);

        return match ($exception->diagnosticCode) {
            'midtrans_provider_unauthorized' => ['status' => 'failed', 'reason' => 'provider_unauthorized'],
            'midtrans_provider_rejected' => ['status' => 'failed', 'reason' => 'provider_rejected'],
            default => ['status' => 'unknown', 'reason' => 'provider_unavailable'],
        };
    }

    /** Diagnostics carry classified codes only: never the Server Key, the request, the provider message or the response body. */
    private function log(object $attempt, string $diagnosticCode, ?int $httpStatus, ?string $providerStatusCode): void
    {
        Log::warning('Midtrans Charge did not produce a payable instruction.', ['attempt_guid' => $attempt->guid, 'environment' => $this->environment(),
            'channel' => $attempt->channel, 'diagnostic_code' => $diagnosticCode, 'http_status' => $httpStatus, 'provider_status_code' => $providerStatusCode]);
    }
}
