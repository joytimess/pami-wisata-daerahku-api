<?php

use App\Jobs\ProcessMidtransPaymentEvent;
use App\Jobs\ReconcileMidtransPayment;
use App\Models\Destination;
use App\Services\BookingService;
use App\Services\MidtransPaymentService;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Shared Phase 3 fixtures. Everything is local data: no helper here talks to a provider. Names carry the `mtx` prefix so they
 * never collide with the helpers of the Phase 2 test files.
 */

if (! function_exists('mtxConfig')) {
    function mtxConfig(array $override = []): void
    {
        config(['operations.demo_payments_enabled' => false, 'doku.enabled' => false, 'midtrans' => array_replace_recursive([
            'enabled' => true, 'environment' => 'sandbox', 'server_key' => 'SB-Mid-server-FIXTURE0000000000', 'merchant_id' => 'G000000001',
            'channels' => ['va_bca', 'va_bni', 'qris'], 'verified_channels' => [], 'production_approved' => false,
            'base_urls' => ['sandbox' => 'https://api.sandbox.midtrans.com', 'production' => 'https://api.midtrans.com'],
            'http_timeout_seconds' => 10, 'minimum_expiry_seconds' => 30, 'qris_acquirer' => 'gopay',
        ], $override)]);
    }
}

if (! function_exists('mtxPayable')) {
    /** @return array{0: object, 1: object} owner and an approved (awaiting_payment) Rp20.000 booking */
    function mtxPayable(int $quantity = 2): array
    {
        [$destination, $date] = seedDestination(capacity: 10, price: 10000, days: 5);
        $owner = makeUser();
        $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, $quantity) + ['idempotency_key' => (string) Str::uuid()]);
        $booking = app(BookingService::class)->decide(makeUser(2), $booking->guid, true);

        return [$owner, app(TicketService::class)->booking($booking->guid)];
    }
}

if (! function_exists('mtxAttempt')) {
    /**
     * A Midtrans attempt exactly as the reservation + Charge would have stored it (no HTTP involved).
     *
     * @param  array<string, mixed>  $override  attempt column overrides
     */
    function mtxAttempt(object $booking, string $state = 'pending', string $method = 'virtual_account', string $channel = 'bca', array $override = []): object
    {
        $intentGuid = DB::table('payment_intents')->where('booking_guid', $booking->guid)->value('guid');
        if (! $intentGuid) {
            $intentGuid = (string) Str::uuid();
            DB::table('payment_intents')->insert(['guid' => $intentGuid, 'booking_guid' => $booking->guid, 'provider' => 'midtrans', 'environment' => 'sandbox',
                'amount' => (int) $booking->total_amount, 'currency' => 'IDR', 'status' => 'processing', 'merchant_reference' => (string) Str::uuid(),
                'expires_at' => $booking->payment_expires_at, 'created_at' => now(), 'updated_at' => now()]);
        }
        $guid = (string) Str::uuid();
        $instruction = $method === 'qris'
            ? ['presentation_type' => 'qr_image', 'acquirer' => 'gopay', 'image_available' => true, 'image_content_type' => 'image/png',
                '_provider' => ['image_url' => 'https://api.sandbox.midtrans.com/v2/qris/trx-'.$guid.'/qr-code']]
            : ['presentation_type' => 'virtual_account', 'bank_code' => $channel, 'va_number' => '81234500001', 'copyable' => true];
        $row = array_replace([
            'guid' => $guid, 'payment_intent_guid' => $intentGuid, 'provider' => 'midtrans', 'environment' => 'sandbox',
            'attempt_number' => (int) DB::table('payment_attempts')->where('payment_intent_guid', $intentGuid)->max('attempt_number') + 1,
            'method' => $method, 'channel' => $channel, 'merchant_reference' => 'PAMI-'.$guid, 'merchant_id' => 'G000000001',
            'provider_request_id' => (string) Str::uuid(), 'provider_reference' => $state === 'initiating' ? null : 'trx-'.$guid,
            'request_hash' => str_repeat('a', 64), 'amount' => (int) $booking->total_amount, 'currency' => 'IDR', 'status' => $state,
            'presentation_type' => $state === 'pending' ? $instruction['presentation_type'] : 'unavailable',
            'instruction_ciphertext' => $state === 'pending' ? Crypt::encryptString(json_encode($instruction)) : null,
            'instruction_expires_at' => $booking->payment_expires_at, 'started_at' => now(),
            'next_reconcile_at' => now()->addMinutes(2), 'created_at' => now(), 'updated_at' => now(),
        ], $override);
        DB::table('payment_attempts')->insert($row);

        return DB::table('payment_attempts')->where('guid', $guid)->first();
    }
}

if (! function_exists('mtxWib')) {
    /** Midtrans clock: GMT+7, second precision. */
    function mtxWib(mixed $time = null): string
    {
        return CarbonImmutable::parse($time ?? now(), 'UTC')->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s');
    }
}

if (! function_exists('mtxStatusBody')) {
    /**
     * Provider body of GET /v2/{order_id}/status for an attempt row or object.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    function mtxStatusBody(object $attempt, string $state = 'settlement', array $override = []): array
    {
        $transactionId = $attempt->provider_reference ?? 'trx-'.$attempt->guid;
        $common = [
            'status_message' => 'Success, transaction found', 'transaction_id' => $transactionId, 'order_id' => $attempt->merchant_reference,
            'merchant_id' => 'G000000001', 'gross_amount' => number_format((int) $attempt->amount, 2, '.', ''), 'currency' => 'IDR',
            'transaction_time' => mtxWib($attempt->started_at ?? now()), 'fraud_status' => 'accept',
        ];
        $payment = $attempt->method === 'qris'
            ? ['payment_type' => 'qris', 'acquirer' => 'gopay']
            : ['payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => $attempt->channel, 'va_number' => '81234500001']]];
        $expiry = ['expiry_time' => mtxWib($attempt->instruction_expires_at ?? now()->addMinutes(15))];
        $specific = match ($state) {
            'pending' => ['status_code' => '201', 'transaction_status' => 'pending'] + $expiry
                + ($attempt->method === 'qris' ? ['actions' => [['name' => 'generate-qr-code', 'method' => 'GET',
                    'url' => 'https://api.sandbox.midtrans.com/v2/qris/'.$transactionId.'/qr-code']]] : []),
            'settlement' => ['status_code' => '200', 'transaction_status' => 'settlement', 'settlement_time' => mtxWib()] + $expiry,
            'expire' => ['status_code' => '407', 'transaction_status' => 'expire'] + $expiry,
            'deny' => ['status_code' => '202', 'transaction_status' => 'deny'],
            'cancel' => ['status_code' => '200', 'transaction_status' => 'cancel'],
            'refund' => ['status_code' => '200', 'transaction_status' => 'refund', 'settlement_time' => mtxWib(now()->subMinute()),
                'refunds' => [['refund_amount' => number_format((int) $attempt->amount, 2, '.', ''), 'created_at' => mtxWib()]]],
            'partial_refund' => ['status_code' => '200', 'transaction_status' => 'partial_refund', 'settlement_time' => mtxWib(now()->subMinute()),
                'refunds' => [['refund_amount' => '1000.00', 'created_at' => mtxWib()]]],
            'chargeback' => ['status_code' => '200', 'transaction_status' => 'chargeback'],
        };

        return array_replace($common + $payment, $specific, $override);
    }
}

if (! function_exists('mtxStatusUrl')) {
    function mtxStatusUrl(object $attempt): string
    {
        return 'https://api.sandbox.midtrans.com/v2/'.$attempt->merchant_reference.'/status';
    }
}

if (! function_exists('mtxSignature')) {
    /** The documented notification signature: SHA512(order_id + status_code + gross_amount + ServerKey). */
    function mtxSignature(string $orderId, string $statusCode, string $grossAmount, ?string $key = null): string
    {
        return hash('sha512', $orderId.$statusCode.$grossAmount.($key ?? config('midtrans.server_key')));
    }
}

if (! function_exists('mtxNotification')) {
    /**
     * Signed HTTP(S) notification body. The signature never covers the status, which is why a notification is only a trigger.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    function mtxNotification(object $attempt, string $state = 'settlement', array $override = []): array
    {
        $body = mtxStatusBody($attempt, $state);
        $payload = array_replace(array_intersect_key($body, array_flip(['transaction_id', 'order_id', 'merchant_id', 'gross_amount', 'currency',
            'payment_type', 'transaction_time', 'transaction_status', 'status_code', 'fraud_status', 'settlement_time', 'va_numbers'])), $override);
        $payload['signature_key'] ??= mtxSignature($payload['order_id'], $payload['status_code'], $payload['gross_amount']);

        return $payload;
    }
}

if (! function_exists('mtxFakeStatus')) {
    /**
     * Serves a Status body (array, string, Throwable to throw, or a closure producing one) for any order and counts the lookups.
     * Calling it again replaces the answer while the request history of the test is kept (stacked Http::fake stubs would not).
     */
    function mtxFakeStatus(mixed $body, int $httpStatus = 200): object
    {
        $counter = (object) ['calls' => 0, 'urls' => []];
        $GLOBALS['mtx_status_responder'] = [$body, $httpStatus, $counter];
        $factory = Http::getFacadeRoot();
        if (($GLOBALS['mtx_status_factory'] ?? null) !== $factory) {
            $GLOBALS['mtx_status_factory'] = $factory;
            Http::fake(['https://api.sandbox.midtrans.com/v2/*/status' => function (Request $request) {
                [$body, $httpStatus, $counter] = $GLOBALS['mtx_status_responder'];
                $counter->calls++;
                $counter->urls[] = $request->url();
                $payload = $body instanceof Closure ? $body($request) : $body;

                if ($payload instanceof Throwable) {
                    throw $payload;
                }

                return Http::response($payload, $httpStatus);
            }]);
        }

        return $counter;
    }
}

if (! function_exists('mtxDrain')) {
    /** Runs the Midtrans jobs captured by Queue::fake(), the way a worker would. */
    function mtxDrain(): int
    {
        $ran = 0;
        foreach ([ProcessMidtransPaymentEvent::class, ReconcileMidtransPayment::class] as $class) {
            foreach (Queue::pushed($class) as $job) {
                app()->call([$job, 'handle']);
                $ran++;
            }
        }

        return $ran;
    }
}

if (! function_exists('mtxCharge')) {
    /** Echoes the order a Charge asked for, the way the provider would (Phase 4 tests; never reaches a real host). */
    function mtxCharge(?Closure $before = null, array $override = []): Closure
    {
        return function (Request $request) use ($before, $override) {
            $before?->__invoke($request);
            $order = $request['transaction_details']['order_id'];
            $transactionId = 'trx-'.$order;
            $expiry = CarbonImmutable::parse($request['custom_expiry']['order_time'])->addMinutes($request['custom_expiry']['expiry_duration'])
                ->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s');
            $common = ['status_code' => '201', 'transaction_id' => $transactionId, 'order_id' => $order, 'merchant_id' => 'G000000001',
                'gross_amount' => number_format($request['transaction_details']['gross_amount'], 2, '.', ''), 'currency' => 'IDR',
                'transaction_time' => CarbonImmutable::parse($request['custom_expiry']['order_time'])->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
                'transaction_status' => 'pending', 'fraud_status' => 'accept', 'expiry_time' => $expiry];

            return Http::response(array_replace($request['payment_type'] === 'qris'
                ? $common + ['payment_type' => 'qris', 'acquirer' => 'gopay', 'qr_string' => '00020101FIXTURE',
                    'actions' => [['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/'.$transactionId.'/qr-code']]]
                : $common + ['payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => $request['bank_transfer']['bank'], 'va_number' => '81234500001']]], $override));
        };
    }
}

if (! function_exists('mtxStart')) {
    /** Owner POST /payment-attempts through the public API. */
    function mtxStart(object $owner, object $booking, string $key = 'start', string $method = 'virtual_account', string $channel = 'bca')
    {
        return test()->postJson('/api/v1/bookings/'.$booking->guid.'/payment-attempts', ['method_code' => $method, 'channel' => $channel],
            bearer($owner) + ['Idempotency-Key' => $key]);
    }
}

if (! function_exists('mtxFakeCharge')) {
    /** Fakes POST /v2/charge and counts the calls. */
    function mtxFakeCharge(?Closure $before = null, array $override = []): object
    {
        $counter = (object) ['calls' => 0, 'payloads' => []];
        Http::fake(['https://api.sandbox.midtrans.com/v2/charge' => function (Request $request) use ($counter, $before, $override) {
            $counter->calls++;
            $counter->payloads[] = $request->data();

            return (mtxCharge($before, $override))($request);
        }]);

        return $counter;
    }
}

if (! function_exists('mtxApprovedAt')) {
    function mtxApprovedAt(object $booking): CarbonImmutable
    {
        return CarbonImmutable::parse(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)
            ->where('new_status', 'awaiting_payment')->max('created_at'), 'UTC');
    }
}

if (! function_exists('mtxPaid')) {
    /**
     * A booking confirmed through the real verified-Status path (a pending attempt plus a faked settlement, no shortcut writes).
     *
     * @return array{0: object, 1: object, 2: object, 3: Destination} owner, booking, paid attempt, destination
     */
    function mtxPaid(string $method = 'virtual_account', string $channel = 'bca', int $quantity = 2): array
    {
        [$owner, $booking] = mtxPayable($quantity);
        $attempt = mtxAttempt($booking, 'pending', $method, $channel);
        test()->travelTo(now()->addMinute());
        mtxFakeStatus(mtxStatusBody($attempt, 'settlement'));
        DB::table('payment_attempts')->where('guid', $attempt->guid)->update(['next_reconcile_at' => now()->subSecond()]);
        app(MidtransPaymentService::class)->reconcile($attempt->guid);
        $quota = DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->first();

        return [$owner, app(TicketService::class)->booking($booking->guid), DB::table('payment_attempts')->where('guid', $attempt->guid)->first(),
            Destination::where('guid', $quota->destination_guid)->firstOrFail()];
    }
}

if (! function_exists('mtxFakeRefund')) {
    /**
     * Serves POST /v2/{order}/refund (array, Throwable to throw, or a closure of the request) and records every call.
     * Re-calling replaces the answer and keeps the history.
     */
    function mtxFakeRefund(mixed $body, int $httpStatus = 200): object
    {
        $factory = Http::getFacadeRoot();
        $same = ($GLOBALS['mtx_refund_factory'] ?? null) === $factory;
        $counter = $same ? $GLOBALS['mtx_refund_responder'][2] : (object) ['calls' => 0, 'payloads' => [], 'urls' => []];
        $GLOBALS['mtx_refund_responder'] = [$body, $httpStatus, $counter];
        if (($GLOBALS['mtx_refund_factory'] ?? null) !== $factory) {
            $GLOBALS['mtx_refund_factory'] = $factory;
            Http::fake(['https://api.sandbox.midtrans.com/v2/*/refund' => function (Request $request) {
                [$body, $httpStatus, $counter] = $GLOBALS['mtx_refund_responder'];
                $counter->calls++;
                $counter->payloads[] = $request->data();
                $counter->urls[] = $request->url();
                $payload = $body instanceof Closure ? $body($request) : $body;
                if ($payload instanceof Throwable) {
                    throw $payload;
                }

                return Http::response($payload, $httpStatus);
            }]);
        }

        return $counter;
    }
}

if (! function_exists('mtxRefundAccepted')) {
    /** Provider body of an accepted full refund echoing the request, the way Midtrans answers. */
    function mtxRefundAccepted(object $attempt, array $override = []): Closure
    {
        return fn (Request $request) => array_replace([
            'status_code' => '200', 'status_message' => 'Success, refund request is approved', 'transaction_id' => $attempt->provider_reference,
            'order_id' => $attempt->merchant_reference, 'payment_type' => 'qris', 'gross_amount' => number_format((int) $attempt->amount, 2, '.', ''),
            'refund_amount' => number_format((int) $request['amount'], 2, '.', ''), 'currency' => 'IDR', 'transaction_status' => 'refund',
            'refund_chargeback_id' => 7, 'refund_key' => $request['refund_key'],
        ], $override);
    }
}

if (! function_exists('mtxRefundStatus')) {
    /** Status body once the provider shows the full refund (carrying our refund key). */
    function mtxRefundStatus(object $attempt, ?string $refundKey = null, array $override = []): array
    {
        $body = mtxStatusBody($attempt, 'refund', $override);
        $body['refunds'][0] += ['refund_key' => $refundKey, 'refund_chargeback_id' => 7];

        return $body;
    }
}

if (! function_exists('mtxRefundAsk')) {
    /** Owner asks for the full refund; returns the refund request guid. */
    function mtxRefundAsk(object $owner, object $booking, string $key = 'refund-1'): string
    {
        return test()->postJson('/api/v1/bookings/'.$booking->guid.'/refund-requests', ['reason' => 'Perubahan rencana perjalanan'],
            bearer($owner) + ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.status', 'requested')->json('data.guid');
    }
}

if (! function_exists('mtxRefundDecide')) {
    function mtxRefundDecide(object $admin, object $booking, string $refund, string $decision = 'approve')
    {
        return test()->postJson('/api/v1/admin/bookings/'.$booking->guid.'/refund-requests/'.$refund.'/decision',
            ['decision' => $decision, 'note' => 'Disetujui pengelola'], bearer($admin));
    }
}

if (! function_exists('mtxRefundRow')) {
    function mtxRefundRow(string $refund): object
    {
        return DB::table('booking_refund_requests')->where('guid', $refund)->first();
    }
}

if (! function_exists('mtxVisitDay')) {
    /** Moves the clock to the local visit day and issues the entry QR units; returns the first unit's scanner payload. */
    function mtxVisitDay(object $booking, Destination $destination): string
    {
        $quota = DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->first();
        test()->travelTo(CarbonImmutable::parse(substr($quota->visit_date, 0, 10).' 00:05:00', $destination->timezone));
        app(TicketService::class)->issue($booking->guid);
        $ticket = DB::table('booking_tickets')->where('booking_guid', $booking->guid)->orderBy('sequence')->first();

        return Crypt::decryptString($ticket->qr_token_encrypted);
    }
}

if (! function_exists('mtxCheckIn')) {
    function mtxCheckIn(string $payload)
    {
        return test()->postJson('/api/v1/admin/ticket-check-ins/confirm', ['qr_payload' => $payload],
            bearer(makeUser(2)) + ['Idempotency-Key' => (string) Str::uuid()]);
    }
}
