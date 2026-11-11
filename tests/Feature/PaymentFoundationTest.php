<?php

use App\Exceptions\BookingException;
use App\Services\BookingService;
use App\Services\PaymentEligibility;
use App\Services\PaymentService;
use App\Support\PaymentStates;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
    config(['operations.demo_payments_enabled' => false]);
});

function foundationBooking(int $price = 15000): array
{
    [$destination, $date] = seedDestination(capacity: 2, price: $price);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => 'foundation-booking']);

    return [$owner, $booking->guid, $destination, $date];
}

function foundationLedger(string $bookingGuid): array
{
    $intent = [
        'guid' => (string) Str::uuid(), 'booking_guid' => $bookingGuid, 'provider' => 'doku',
        'environment' => 'sandbox', 'currency' => 'IDR', 'amount' => 30000,
        'merchant_reference' => 'fixture-intent-'.Str::random(8), 'expires_at' => now()->addHour(),
        'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('payment_intents')->insert($intent);
    $attempt = [
        'guid' => (string) Str::uuid(), 'payment_intent_guid' => $intent['guid'],
        'provider' => 'doku', 'environment' => 'sandbox', 'attempt_number' => 1, 'method' => 'checkout',
        'merchant_reference' => 'fixture-attempt-'.Str::random(8), 'provider_request_id' => (string) Str::uuid(),
        'provider_reference' => 'fixture-reference-'.Str::random(8), 'request_hash' => str_repeat('a', 64),
        'amount' => 30000, 'currency' => 'IDR', 'status' => 'unknown', 'started_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('payment_attempts')->insert($attempt);

    return [$intent, $attempt];
}

it('returns 401 without authentication and 422 for malformed methods requests', function () {
    $this->getJson('/api/v1/payments/methods')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    $headers = bearer(makeUser());
    $this->getJson('/api/v1/payments/methods', $headers)->assertUnprocessable()->assertJsonValidationErrors('booking_guid');
    $this->getJson('/api/v1/payments/methods?booking_guid=not-a-uuid', $headers)->assertUnprocessable()->assertJsonValidationErrors('booking_guid');
});

it('returns 404 for foreign owners and staff even when staff own an old booking', function (int $role) {
    [$owner, $guid] = foundationBooking();
    $this->getJson('/api/v1/payments/methods?booking_guid='.$guid, bearer(makeUser()))->assertNotFound();
    $this->getJson('/api/v1/bookings/'.$guid.'/payment-intent', bearer(makeUser()))->assertNotFound();
    DB::table('users')->where('guid', $owner->guid)->update(['role' => $role]);
    $headers = bearer($owner->fresh());

    $this->getJson('/api/v1/payments/methods?booking_guid='.$guid, $headers)->assertNotFound();
    $this->getJson('/api/v1/bookings/'.$guid.'/payment-intent', $headers)->assertNotFound();
})->with(['admin' => 1, 'superadmin' => 2]);

it('keeps methods and status read only using server amounts even when client values and gateway flags are supplied', function () {
    [$owner, $guid] = foundationBooking();
    app(BookingService::class)->decide(makeUser(2), $guid, true);
    $headers = bearer($owner);
    $before = DB::table('bookings')->where('guid', $guid)->first();
    $historyCount = DB::table('booking_status_histories')->count();
    Http::preventStrayRequests();
    Http::fake();

    $this->getJson('/api/v1/payments/methods?booking_guid='.$guid.'&amount=1&currency=USD&payment_status=paid', $headers)
        ->assertOk()->assertJsonPath('data.amount', 30000)->assertJsonPath('data.currency', 'IDR')
        ->assertJsonPath('data.can_pay', false)->assertJsonPath('data.payment_status', 'unverified')
        ->assertJsonCount(2, 'data.methods')->assertJsonPath('data.methods.0.available', false)
        ->assertJsonPath('data.methods.1.available', false)
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('/api/v1/bookings/'.$guid.'/payment-intent?status=paid', $headers)
        ->assertOk()->assertJsonPath('data.intent_guid', null)->assertJsonPath('data.status', 'not_created')
        ->assertJsonPath('data.instruction.presentation_type', 'unavailable')->assertJsonPath('data.payment_status', 'unverified')
        ->assertJsonStructure(['meta' => ['generated_at']])->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('/api/v1/bookings/'.$guid.'/payment-preview', $headers)->assertNotFound();

    expect(DB::table('bookings')->where('guid', $guid)->first())->toEqual($before);
    expect(DB::table('booking_status_histories')->count())->toBe($historyCount);
    $this->assertDatabaseCount('payment_intents', 0);
    $this->assertDatabaseCount('payment_attempts', 0);
    $this->assertDatabaseCount('payment_events', 0);
    expect(DB::table('booking_tickets')->whereNotNull('qr_token_hash')->count())->toBe(0);
    Http::assertNothingSent();
});

it('disables nested channels with their parent booking and exposes only persisted invoice data', function () {
    [$owner, $guid] = foundationBooking();

    $this->getJson('/api/v1/payments/methods?booking_guid='.$guid, bearer($owner))->assertOk()
        ->assertJsonPath('data.methods.1.available', false)->assertJsonPath('data.methods.1.channels.0.available', false)
        ->assertJsonPath('data.can_create', false)->assertJsonPath('data.can_switch', false)
        ->assertJsonPath('data.invoice_number', null)->assertJsonPath('data.subtotal', 30000)
        ->assertJsonPath('data.service_fee', 0)->assertJsonPath('data.receipt_available', false);
    $this->assertDatabaseCount('invoices', 0);
});

it('keeps the 15 minute cutoff without falling back to DOKU and expires an untouched booking while releasing its quota', function () {
    [$owner, $guid, $destination, $date] = foundationBooking();
    $service = app(BookingService::class);
    $service->decide(makeUser(2), $guid, true);
    $headers = bearer($owner);
    $before = DB::table('bookings')->where('guid', $guid)->first();
    expect($before->payment_expires_at)->toBe(now()->addMinutes(15)->format('Y-m-d H:i:s'));
    expect($before->notification_grace_expires_at)->toBe(now()->addMinutes(30)->format('Y-m-d H:i:s'));

    $this->travel(14)->minutes();
    $service->expireDue();
    $this->getJson('/api/v1/bookings/'.$guid.'/payment-intent', $headers)->assertOk()
        ->assertJsonPath('data.booking_status', 'awaiting_payment')->assertJsonPath('data.provider', 'midtrans')->assertJsonPath('data.reason', 'midtrans_disabled');
    $this->travel(1)->minutes();
    $this->getJson('/api/v1/bookings/'.$guid.'/payment-intent', $headers)->assertOk()->assertJsonPath('data.reason', 'payment_window_closed');
    $service->expireDue();
    $this->getJson('/api/v1/bookings/'.$guid.'/payment-intent', $headers)->assertOk()->assertJsonPath('data.booking_status', 'expired');
    expect($service->create(makeUser(), bookingPayload($destination, $date) + ['idempotency_key' => 'released-capacity'])->status)->toBe('pending_approval');
    $after = DB::table('bookings')->where('guid', $guid)->first();
    expect($after->payment_expires_at)->toBe($before->payment_expires_at);
    expect(DB::table('booking_tickets')->whereNotNull('qr_token_hash')->count())->toBe(0);
});

it('holds the quota of a booking with an unresolved legacy attempt through verification and review', function () {
    [$owner, $guid, $destination, $date] = foundationBooking();
    $service = app(BookingService::class);
    $service->decide(makeUser(2), $guid, true);
    foundationLedger($guid);
    $before = DB::table('bookings')->where('guid', $guid)->first();

    // Approval now always uses the 15 minute deadline (DOKU can no longer be the active gateway); the legacy attempt still holds the quota.
    $this->travel(14)->minutes();
    $service->expireDue();
    $this->getJson('/api/v1/bookings/'.$guid.'/payment-intent', bearer($owner))->assertOk()->assertJsonPath('data.booking_status', 'awaiting_payment');
    $this->travel(1)->minutes();
    $service->expireDue();
    $this->getJson('/api/v1/bookings/'.$guid.'/payment-intent', bearer($owner))->assertOk()->assertJsonPath('data.booking_status', 'payment_verification');
    expect(fn () => $service->create(makeUser(), bookingPayload($destination, $date) + ['idempotency_key' => 'verification-capacity']))
        ->toThrow(BookingException::class, 'Insufficient capacity.');
    $this->travel(15)->minutes();
    $service->expireDue();
    $this->getJson('/api/v1/bookings/'.$guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.booking_status', 'payment_review')->assertJsonPath('data.payment_status', 'unverified');
    expect(fn () => $service->create(makeUser(), bookingPayload($destination, $date) + ['idempotency_key' => 'review-capacity']))
        ->toThrow(BookingException::class, 'Insufficient capacity.');
    $after = DB::table('bookings')->where('guid', $guid)->first();
    expect($after->payment_expires_at)->toBe($before->payment_expires_at);
    expect($after->notification_grace_expires_at)->toBe($before->notification_grace_expires_at);
    expect(DB::table('booking_tickets')->whereNotNull('qr_token_hash')->count())->toBe(0);
});

it('does not treat a free confirmed booking as paid or provide a payment instruction', function () {
    [$owner, $guid] = foundationBooking(0);
    app(BookingService::class)->decide(makeUser(2), $guid, true);

    $this->getJson('/api/v1/bookings/'.$guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.booking_status', 'confirmed')->assertJsonPath('data.amount', 0)
        ->assertJsonPath('data.payment_status', 'unverified')->assertJsonPath('data.can_pay', false)
        ->assertJsonPath('data.instruction.presentation_type', 'unavailable');
    expect(DB::table('booking_tickets')->whereNotNull('qr_token_hash')->count())->toBe(0);
});

it('rejects untrusted payment writes and unsupported attempt input', function () {
    [$owner, $guid] = foundationBooking();
    app(BookingService::class)->decide(makeUser(2), $guid, true);
    $headers = bearer($owner) + ['Idempotency-Key' => 'forged-payment'];
    Http::preventStrayRequests();
    Http::fake();

    $this->postJson('/api/v1/bookings/'.$guid.'/payment-intent', ['amount' => 1, 'currency' => 'IDR', 'status' => 'paid'], $headers)->assertMethodNotAllowed();
    $this->postJson('/api/v1/bookings/'.$guid.'/payment-attempts', ['method' => 'qris'], $headers)->assertUnprocessable();

    $this->assertDatabaseHas('bookings', ['guid' => $guid, 'status' => 'awaiting_payment']);
    $this->assertDatabaseCount('payment_intents', 0);
    $this->assertDatabaseCount('payment_attempts', 0);
    $this->assertDatabaseCount('payment_events', 0);
    Http::assertNothingSent();
});

it('reports unsupported booking currency without changing its snapshot', function () {
    [$owner, $guid] = foundationBooking();
    app(BookingService::class)->decide(makeUser(2), $guid, true);
    DB::table('bookings')->where('guid', $guid)->update(['currency' => 'USD']);

    $this->getJson('/api/v1/payments/methods?booking_guid='.$guid, bearer($owner))->assertOk()
        ->assertJsonPath('data.reason', 'unsupported_currency')->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.can_pay', false);

    $this->assertDatabaseHas('bookings', ['guid' => $guid, 'currency' => 'USD', 'total_amount' => 30000]);
    $this->assertDatabaseCount('payment_intents', 0);
});

it('returns only safe persisted attempt metadata without treating a stored paid label as verified eligibility', function () {
    [$owner, $guid] = foundationBooking();
    [$intent, $attempt] = foundationLedger($guid);
    DB::table('payment_attempts')->where('guid', $attempt['guid'])->update([
        'status' => 'paid', 'instruction_ciphertext' => 'private-provider-instruction',
    ]);
    Http::preventStrayRequests();
    Http::fake();

    $response = $this->getJson('/api/v1/bookings/'.$guid.'/payment-intent', bearer($owner))->assertOk()
        ->assertJsonPath('data.intent_guid', $intent['guid'])->assertJsonPath('data.last_attempt.attempt_guid', $attempt['guid'])
        ->assertJsonPath('data.payment_status', 'unverified')->assertJsonPath('data.can_pay', false)
        ->assertJsonPath('data.instruction.presentation_type', 'unavailable');

    expect($response->getContent())->not->toContain('private-provider-instruction', $attempt['provider_reference'], $attempt['provider_request_id']);
    expect(app(PaymentEligibility::class)->status(DB::table('bookings')->where('guid', $guid)->first()))->toBe('unverified');
    $this->assertDatabaseCount('payment_attempts', 1);
    $this->assertDatabaseCount('payment_events', 0);
    Http::assertNothingSent();
});

it('rejects a stale owner role when called outside the HTTP middleware', function () {
    [$owner, $guid] = foundationBooking();
    DB::table('users')->where('guid', $owner->guid)->update(['role' => 1]);

    expect(fn () => app(PaymentService::class)->ownerBooking($owner, $guid))->toThrow(HttpException::class);
});

it('enforces one logical intent per booking', function () {
    [, $guid] = foundationBooking();
    [$intent] = foundationLedger($guid);
    $duplicate = array_replace($intent, ['guid' => (string) Str::uuid(), 'merchant_reference' => 'different-reference']);

    expect(fn () => DB::table('payment_intents')->insert($duplicate))->toThrow(QueryException::class);
});

it('enforces that an applied attempt belongs to the same intent', function () {
    [, $guid] = foundationBooking();
    [$intent] = foundationLedger($guid);
    [$destination, $date] = seedDestination(capacity: 2, price: 15000);
    $booking = app(BookingService::class)->create(makeUser(), bookingPayload($destination, $date) + ['idempotency_key' => 'other-foundation']);
    [, $foreignAttempt] = foundationLedger($booking->guid);

    expect(fn () => DB::table('payment_intents')->where('guid', $intent['guid'])->update(['applied_attempt_guid' => $foreignAttempt['guid']]))
        ->toThrow(QueryException::class);
});

it('blocks invalid intent transitions and paid demotion while allowing review and verified reversal states', function () {
    PaymentStates::assertIntentTransition('requires_payment', 'processing');
    PaymentStates::assertIntentTransition('paid', 'needs_review');
    PaymentStates::assertAttemptTransition('unknown', 'paid');
    PaymentStates::assertAttemptTransition('paid', 'reversed');
    expect(fn () => PaymentStates::assertIntentTransition('requires_payment', 'paid'))->toThrow(DomainException::class);
    expect(fn () => PaymentStates::assertIntentTransition('needs_review', 'paid'))->toThrow(DomainException::class);
    expect(fn () => PaymentStates::assertAttemptTransition('paid', 'failed'))->toThrow(DomainException::class);
    expect(fn () => PaymentStates::assertAttemptTransition('reversed', 'paid'))->toThrow(DomainException::class);
    expect(fn () => PaymentStates::assertAttemptTransition('not_a_state', 'not_a_state'))->toThrow(DomainException::class);
});
