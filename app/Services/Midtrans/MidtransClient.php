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
            $body = $this->decode($this->post('/v2/charge', $payload));
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

    /** @param array<string, mixed> $payload */
    private function post(string $path, array $payload): Response
    {
        try {
            $response = Http::withBasicAuth((string) config('midtrans.server_key'), '')->acceptJson()->asJson()
                ->timeout((int) config('midtrans.http_timeout_seconds', 10))->post($this->registry->baseUrl().$path, $payload);
        } catch (ConnectionException) {
            throw new MidtransRequestException('charge', 'midtrans_connection_error');
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw new MidtransRequestException('charge', 'midtrans_provider_unauthorized', $response->status());
        }
        if ($response->status() === 429 || $response->status() >= 500) {
            throw new MidtransRequestException('charge', 'midtrans_http_error', $response->status());
        }
        if ($response->status() >= 400) {
            throw new MidtransRequestException('charge', 'midtrans_provider_rejected', $response->status());
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $body = $response->json();
        if (! is_array($body)) {
            throw new MidtransRequestException('charge', 'midtrans_response_invalid', $response->status());
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
