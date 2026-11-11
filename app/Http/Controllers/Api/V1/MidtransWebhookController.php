<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Midtrans\MidtransChannelRegistry;
use App\Services\MidtransPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public Midtrans HTTP(S) notification endpoint.
 *
 * A notification is a durable TRIGGER, never evidence: the signature proves the sender knows our Server Key, but it does not cover
 * the status, so nothing here confirms a payment. The body is validated, its signature checked in constant time, a receipt is
 * persisted, and only then is success acknowledged. The authenticated Status lookup happens later, in a worker.
 */
class MidtransWebhookController extends Controller
{
    private const MAX_BYTES = 16384;

    /** Closed vocabulary of Midtrans transaction statuses; anything else is rejected before it can reach the database. */
    private const TRANSACTION_STATUSES = ['authorize', 'capture', 'settlement', 'pending', 'deny', 'cancel', 'expire', 'failure',
        'refund', 'partial_refund', 'chargeback', 'partial_chargeback'];

    private const FRAUD_STATUSES = ['accept', 'challenge', 'deny'];

    public function __invoke(Request $request, MidtransChannelRegistry $registry, MidtransPaymentService $payments): JsonResponse
    {
        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_BYTES) {
            return $this->reply('payload_too_large', 413);
        }
        $payload = json_decode($raw, true, 16);
        if (! is_array($payload) || array_is_list($payload) || ! $this->wellFormed($payload)) {
            return $this->reply('invalid_notification', 400);
        }
        if ($registry->verificationReason() !== null) {
            // Not able to authenticate anything: fail closed with a retryable status. Nothing is stored.
            return $this->reply('service_unavailable', 503);
        }
        $expected = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].config('midtrans.server_key'));
        if (! hash_equals($expected, $payload['signature_key'])) {
            Log::warning('Midtrans notification rejected: signature mismatch.');

            return $this->reply('invalid_signature', 401);
        }
        $payments->receiveNotification($payload, $raw);

        return $this->reply('accepted', 200);
    }

    /** @param array<string, mixed> $payload */
    private function wellFormed(array $payload): bool
    {
        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key', 'transaction_status'] as $field) {
            if (! isset($payload[$field]) || ! is_string($payload[$field])) {
                return false;
            }
        }
        $fraud = $payload['fraud_status'] ?? null;

        return preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $payload['order_id']) === 1
            && preg_match('/^\d{3}$/D', $payload['status_code']) === 1
            && preg_match('/^\d{1,15}(\.\d{1,2})?$/D', $payload['gross_amount']) === 1
            && preg_match('/^[A-Fa-f0-9]{128}$/D', $payload['signature_key']) === 1
            && in_array($payload['transaction_status'], self::TRANSACTION_STATUSES, true)
            && ($fraud === null || (is_string($fraud) && in_array($fraud, self::FRAUD_STATUSES, true)));
    }

    private function reply(string $code, int $status): JsonResponse
    {
        return response()->json($status === 200 ? ['status' => $code] : ['code' => $code], $status)->header('Cache-Control', 'private, no-store');
    }
}
