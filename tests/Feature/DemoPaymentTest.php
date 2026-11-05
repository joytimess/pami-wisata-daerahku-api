<?php

use App\Services\BookingService;
use App\Services\DemoPaymentService;
use App\Services\PaymentEligibility;
use App\Support\BookingRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 08:00:00', 'UTC'));
    config(['operations.demo_payments_enabled' => true]);
    Http::preventStrayRequests();
});

function demoBooking(int $price = 10000, int $days = 7, bool $approved = true): array
{
    [$destination, $date] = seedDestination(capacity: 5, price: $price, days: $days, destination: ['timezone' => 'Asia/Jayapura']);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => (string) Str::uuid()]);
    if ($approved) {
        $booking = app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
    }

    return [$owner, $booking, $destination, $date];
}

function demoStart(object $owner, object $booking, string $key = 'demo-init'): array
{
    return test()->postJson('/api/v1/bookings/'.$booking->guid.'/demo-payment', [], bearer($owner) + ['Idempotency-Key' => $key])
        ->assertOk()->json('data');
}

function demoOutcome(object $owner, object $booking, string $attempt, string $outcome = 'success', string $key = 'demo-outcome'): object
{
    return test()->postJson('/api/v1/bookings/'.$booking->guid.'/demo-payment/simulate', ['attempt_guid' => $attempt, 'outcome' => $outcome], bearer($owner) + ['Idempotency-Key' => $key]);
}

it('returns 401 without authentication and 422 without an idempotency key', function () {
    [$owner, $booking] = demoBooking();
    $path = '/api/v1/bookings/'.$booking->guid.'/demo-payment';

    $this->postJson($path)->assertUnauthorized();
    $this->postJson($path, [], bearer($owner))->assertUnprocessable();
    $this->postJson($path.'/simulate', [], bearer($owner) + ['Idempotency-Key' => 'invalid'])->assertUnprocessable();
    $this->assertDatabaseCount('payment_intents', 0);
});

it('returns 404 without the demo gate and in production even when the flag is enabled', function (string $environment, bool $enabled) {
    [$owner, $booking] = demoBooking();
    config(['operations.demo_payments_enabled' => $enabled]);
    $this->app->instance('env', $environment);

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/demo-payment', [], bearer($owner) + ['Idempotency-Key' => 'gated'])->assertNotFound();

    $this->assertDatabaseCount('payment_intents', 0);
    $this->assertDatabaseCount('payment_events', 0);
    expect(app(PaymentEligibility::class)->status($booking))->toBe('unverified');
})->with(['disabled' => ['testing', false], 'production' => ['production', true], 'staging' => ['staging', true]]);

it('returns 404 for foreign owners and staff', function (int $role) {
    [, $booking] = demoBooking();

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/demo-payment', [], bearer(makeUser($role)) + ['Idempotency-Key' => 'foreign'])->assertNotFound();

    $this->assertDatabaseCount('payment_intents', 0);
})->with([0, 1, 2]);

it('rejects client payment authority fields with 422 before any ledger write', function (string $field, mixed $value) {
    [$owner, $booking] = demoBooking();

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/demo-payment', [$field => $value], bearer($owner) + ['Idempotency-Key' => 'untrusted'])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('payment_intents', 0);
})->with(['amount' => ['amount', 1], 'currency' => ['currency', 'USD'], 'provider' => ['provider', 'doku'], 'environment' => ['environment', 'production'], 'paid_at' => ['paid_at', '2026-01-01'], 'status' => ['status', 'paid']]);

it('creates one pending DEMO attempt using the booking snapshot without payment or QR', function () {
    [$owner, $booking] = demoBooking();

    $pending = demoStart($owner, $booking);

    expect($pending)->toMatchArray(['provider' => 'demo', 'environment' => 'demo', 'label' => 'DEMO', 'test_only' => true,
        'amount' => 20000, 'currency' => 'IDR', 'status' => 'pending', 'booking_status' => 'awaiting_payment', 'payment_status' => 'unverified']);
    $this->assertDatabaseCount('payment_intents', 1);
    $this->assertDatabaseCount('payment_attempts', 1);
    $this->assertDatabaseCount('payment_events', 0);
    $this->assertDatabaseHas('payment_attempts', ['guid' => $pending['attempt_guid'], 'provider' => 'demo', 'environment' => 'demo', 'status' => 'pending', 'amount' => 20000]);
    $this->getJson('/api/v1/bookings/'.$booking->guid.'/tickets', bearer($owner))->assertOk()->assertJsonPath('data.payment_status', 'unverified');
    expect(DB::table('booking_tickets')->whereNotNull('qr_token_hash')->count())->toBe(0);
});

it('labels demo read contracts and closes can pay after success or when the gate is disabled', function () {
    [$owner, $booking] = demoBooking();
    $headers = bearer($owner);
    $path = '/api/v1/bookings/'.$booking->guid.'/payment-intent';
    $this->getJson('/api/v1/payments/methods?booking_guid='.$booking->guid, $headers)->assertOk()
        ->assertJsonPath('data.provider', 'demo')->assertJsonPath('data.environment', 'demo')
        ->assertJsonPath('data.label', 'DEMO')->assertJsonPath('data.test_only', true)->assertJsonPath('data.can_pay', true);
    $pending = demoStart($owner, $booking);
    $this->getJson($path, $headers)->assertOk()->assertJsonPath('data.last_attempt.status', 'pending')
        ->assertJsonPath('data.instruction.presentation_type', 'demo_simulator')->assertJsonPath('data.can_pay', true);
    demoOutcome($owner, $booking, $pending['attempt_guid'])->assertOk();
    $this->getJson($path, $headers)->assertOk()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.can_pay', false);
    config(['operations.demo_payments_enabled' => false]);
    $this->getJson($path, $headers)->assertOk()->assertJsonPath('data.provider', 'demo')->assertJsonPath('data.label', 'DEMO')
        ->assertJsonPath('data.test_only', true)->assertJsonPath('data.can_pay', false)->assertJsonPath('data.payment_status', 'unverified')
        ->assertJsonPath('data.instruction.presentation_type', 'unavailable');
});

it('does not overwrite an existing DOKU intent with a demo intent', function () {
    [$owner, $booking] = demoBooking();
    $guid = (string) Str::uuid();
    DB::table('payment_intents')->insert(['guid' => $guid, 'booking_guid' => $booking->guid,
        'provider' => 'doku', 'environment' => 'sandbox', 'currency' => 'IDR', 'amount' => 20000,
        'merchant_reference' => 'sandbox-fixture', 'expires_at' => CarbonImmutable::parse($booking->payment_expires_at),
        'created_at' => now(), 'updated_at' => now()]);

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/demo-payment', [], bearer($owner) + ['Idempotency-Key' => 'conflict'])
        ->assertConflict()->assertJsonPath('code', 'demo_payment_conflict');

    $this->assertDatabaseHas('payment_intents', ['guid' => $guid, 'provider' => 'doku', 'environment' => 'sandbox']);
    $this->assertDatabaseCount('payment_intents', 1);
    $this->assertDatabaseCount('payment_attempts', 0);
    $this->getJson('/api/v1/payments/methods?booking_guid='.$booking->guid, bearer($owner))->assertOk()->assertJsonPath('data.can_pay', false);
});

it('returns 409 for a different booking attempt even when both bookings have the same owner', function () {
    [$owner, $booking, $destination, $date] = demoBooking();
    demoStart($owner, $booking);
    $other = app(BookingService::class)->create($owner, bookingPayload($destination, $date) + ['idempotency_key' => 'second-booking']);
    $other = app(BookingService::class)->decide(makeUser(2), $other->guid, true);
    $otherAttempt = demoStart($owner, $other, 'other-init');

    demoOutcome($owner, $booking, $otherAttempt['attempt_guid'])->assertConflict();

    $this->assertDatabaseCount('payment_events', 0);
    $this->assertDatabaseCount('payment_attempts', 2);
});

it('applies a simulated success once with an auditable DEMO capture and deterministic replay', function () {
    [$owner, $booking] = demoBooking();
    $pending = demoStart($owner, $booking);
    $notifications = DB::table('notifications')->count();
    $deliveries = DB::table('notification_deliveries')->count();

    $first = demoOutcome($owner, $booking, $pending['attempt_guid'])->assertOk()->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.booking_status', 'confirmed')->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.label', 'DEMO')->assertJsonPath('data.test_only', true)->json();
    demoOutcome($owner, $booking, $pending['attempt_guid'])->assertExactJson($first);
    demoOutcome($owner, $booking, $pending['attempt_guid'], key: 'duplicate-outcome')->assertOk();

    $this->assertDatabaseCount('payment_events', 1);
    $this->assertDatabaseHas('payment_events', ['payment_attempt_guid' => $pending['attempt_guid'], 'provider' => 'demo', 'environment' => 'demo', 'event_type' => 'capture', 'disposition' => 'applied', 'amount' => 20000]);
    expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count())->toBe(1);
    $this->assertDatabaseCount('notifications', $notifications + 1);
    $this->assertDatabaseCount('notification_deliveries', $deliveries);
    expect(DB::table('notifications')->where('booking_guid', $booking->guid)->latest('created_at')->pluck('title')->implode(' '))->toContain('DEMO');
    expect(demoStart($owner, $booking))->toBe($pending);
});

it('keeps failed or expired attempts from releasing held quota and permits a safe retry', function (string $outcome) {
    [$owner, $booking] = demoBooking();
    $pending = demoStart($owner, $booking);

    $terminal = demoOutcome($owner, $booking, $pending['attempt_guid'], $outcome)->assertOk()
        ->assertJsonPath('data.status', $outcome)->assertJsonPath('data.booking_status', 'awaiting_payment')->json();
    demoOutcome($owner, $booking, $pending['attempt_guid'], $outcome)->assertExactJson($terminal);
    $retry = demoStart($owner, $booking, 'retry');

    expect($retry['attempt_guid'])->not->toBe($pending['attempt_guid']);
    $this->assertDatabaseCount('payment_intents', 1);
    $this->assertDatabaseCount('payment_attempts', 2);
    expect(DB::table('bookings')->where('destination_quota_guid', $booking->destination_quota_guid)->whereIn('status', BookingRules::HELD)->sum('quantity'))->toBe(2);
    demoOutcome($owner, $booking, $retry['attempt_guid'], key: 'retry-success')->assertOk()->assertJsonPath('data.status', 'paid');
    expect(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(1);
})->with(['failed', 'expired']);

it('returns 409 for contradictory terminal outcomes and reused keys with different payloads', function () {
    [$owner, $booking] = demoBooking();
    $pending = demoStart($owner, $booking);
    demoOutcome($owner, $booking, $pending['attempt_guid'])->assertOk();

    demoOutcome($owner, $booking, $pending['attempt_guid'], 'failed')->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    demoOutcome($owner, $booking, $pending['attempt_guid'], 'failed', 'contradictory')->assertConflict();

    $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'confirmed']);
    $this->assertDatabaseCount('payment_events', 1);
});

it('returns 409 when an initiation key is reused for another booking', function () {
    [$owner, $booking, $destination, $date] = demoBooking();
    demoStart($owner, $booking);
    $other = app(BookingService::class)->create($owner, bookingPayload($destination, $date) + ['idempotency_key' => 'second-booking']);
    app(BookingService::class)->decide(makeUser(2), $other->guid, true);

    $this->postJson('/api/v1/bookings/'.$other->guid.'/demo-payment', [], bearer($owner) + ['Idempotency-Key' => 'demo-init'])
        ->assertConflict()->assertJsonPath('code', 'idempotency_conflict');

    $this->assertDatabaseCount('payment_intents', 1);
    $this->assertDatabaseCount('payment_attempts', 1);
});

it('does not allow an unknown attempt to be bypassed by a new initiation key', function () {
    [$owner, $booking] = demoBooking();
    $pending = demoStart($owner, $booking);
    DB::table('payment_attempts')->where('guid', $pending['attempt_guid'])->update(['status' => 'unknown']);

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/demo-payment', [], bearer($owner) + ['Idempotency-Key' => 'retry-unknown'])->assertConflict();

    $this->assertDatabaseCount('payment_attempts', 1);
    $this->assertDatabaseCount('payment_events', 0);
});

it('rejects a foreign or superseded attempt without applying another outcome', function () {
    [$owner, $booking] = demoBooking();
    $pending = demoStart($owner, $booking);
    demoOutcome($owner, $booking, $pending['attempt_guid'], 'failed')->assertOk();
    $retry = demoStart($owner, $booking, 'retry');

    demoOutcome($owner, $booking, (string) Str::uuid(), key: 'foreign-attempt')->assertConflict();
    demoOutcome($owner, $booking, $pending['attempt_guid'], key: 'old-attempt')->assertConflict();

    $this->assertDatabaseHas('payment_attempts', ['guid' => $retry['attempt_guid'], 'status' => 'pending']);
    expect(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(0);
});

it('rejects expired idempotency keys instead of opening another payment attempt', function () {
    [$owner, $booking] = demoBooking();
    demoStart($owner, $booking);
    DB::table('idempotency_records')->where('operation', 'demo_payment.initiate')->update(['expires_at' => now()->subSecond()]);

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/demo-payment', [], bearer($owner) + ['Idempotency-Key' => 'demo-init'])
        ->assertConflict()->assertJsonPath('code', 'idempotency_key_expired');

    $this->assertDatabaseCount('payment_attempts', 1);
});

it('rejects unsupported currency past visits and an awaiting label without approval history', function (string $condition) {
    [$owner, $booking] = demoBooking();
    match ($condition) {
        'currency' => DB::table('bookings')->where('guid', $booking->guid)->update(['currency' => 'USD']),
        'past_visit' => DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->update(['visit_date' => now()->subDay()->toDateString()]),
        'approval_history' => DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'awaiting_payment')->delete(),
    };

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/demo-payment', [], bearer($owner) + ['Idempotency-Key' => 'not-payable'])->assertConflict();

    $this->assertDatabaseCount('payment_intents', 0);
})->with(['currency', 'past_visit', 'approval_history']);

it('returns 409 for ineligible unapproved free or terminal bookings', function (int $price, bool $approved, ?string $status) {
    [$owner, $booking] = demoBooking($price, approved: $approved);
    if ($status) {
        DB::table('bookings')->where('guid', $booking->guid)->update(['status' => $status]);
    }

    $this->postJson('/api/v1/bookings/'.$booking->guid.'/demo-payment', [], bearer($owner) + ['Idempotency-Key' => 'ineligible'])->assertConflict();

    $this->assertDatabaseCount('payment_intents', 0);
})->with(['unapproved' => [10000, false, null], 'free' => [0, true, null], 'cancelled' => [10000, true, 'cancelled'], 'rejected' => [10000, true, 'rejected'], 'review' => [10000, true, 'payment_review']]);

it('rejects simulated success at the real cutoff and preserves expiry grace and quota', function () {
    [$owner, $booking] = demoBooking();
    $pending = demoStart($owner, $booking);
    $this->travelTo(CarbonImmutable::parse($booking->payment_expires_at, 'UTC'));

    demoOutcome($owner, $booking, $pending['attempt_guid'])->assertConflict();
    app(BookingService::class)->expireDue();

    $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'payment_verification',
        'payment_expires_at' => CarbonImmutable::parse($booking->payment_expires_at)->toDateTimeString(),
        'notification_grace_expires_at' => CarbonImmutable::parse($booking->notification_grace_expires_at)->toDateTimeString()]);
    expect(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(0);
    expect(DB::table('bookings')->where('destination_quota_guid', $booking->destination_quota_guid)->whereIn('status', BookingRules::HELD)->sum('quantity'))->toBe(2);
    $this->travelTo(CarbonImmutable::parse($booking->notification_grace_expires_at, 'UTC'));
    app(BookingService::class)->expireDue();
    $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'payment_review']);
});

it('rejects mismatched server snapshots without a capture or booking confirmation', function (string $table, string $field, mixed $value) {
    [$owner, $booking] = demoBooking();
    $pending = demoStart($owner, $booking);
    DB::table($table)->where('guid', $table === 'bookings' ? $booking->guid : $pending['attempt_guid'])->update([$field => $value]);

    demoOutcome($owner, $booking, $pending['attempt_guid'])->assertConflict();

    $this->assertDatabaseHas('bookings', ['guid' => $booking->guid, 'status' => 'awaiting_payment']);
    expect(DB::table('payment_events')->where('event_type', 'capture')->count())->toBe(0);
})->with(['booking amount' => ['bookings', 'total_amount', 1], 'attempt amount' => ['payment_attempts', 'amount', 1], 'attempt currency' => ['payment_attempts', 'currency', 'USD']]);

it('revalidates the current owner role and status inside the service', function (string $field, mixed $value) {
    [$owner, $booking] = demoBooking();
    DB::table('users')->where('guid', $owner->guid)->update([$field => $value]);

    expect(fn () => app(DemoPaymentService::class)->initiate($owner, $booking->guid, ['idempotency_key' => 'stale-owner']))->toThrow(HttpException::class);

    $this->assertDatabaseCount('payment_intents', 0);
})->with(['promoted' => ['role', 1], 'inactive' => ['status', 'inactive']]);

it('uses the real demo ledger for H5 QR and local visit day check in then fails closed outside demo mode', function () {
    [$owner, $booking, $destination, $date] = demoBooking();
    $pending = demoStart($owner, $booking);
    demoOutcome($owner, $booking, $pending['attempt_guid'])->assertOk();
    $path = '/api/v1/bookings/'.$booking->guid.'/tickets';
    $visit = CarbonImmutable::parse($date.' 00:05:00', $destination->timezone);
    $this->travelTo($visit->subDays(6));
    $this->getJson($path, bearer($owner))->assertOk()->assertJsonPath('data.ticket_state', 'scheduled');
    expect(DB::table('booking_tickets')->whereNotNull('qr_token_hash')->count())->toBe(0);
    $this->travelTo($visit->subDays(5));
    $this->getJson($path, bearer($owner))->assertOk()->assertJsonPath('data.ticket_state', 'available')
        ->assertJsonPath('data.payment_provider', 'demo')->assertJsonPath('data.payment_environment', 'demo')
        ->assertJsonPath('data.payment_test_only', true)->assertJsonPath('data.payment_label', 'DEMO');
    $ticket = DB::table('booking_tickets')->where('booking_guid', $booking->guid)->orderBy('sequence')->first();
    $payload = Crypt::decryptString($ticket->qr_token_encrypted);
    $staff = makeUser(2);
    $this->postJson('/api/v1/admin/ticket-check-ins/preview', ['qr_payload' => $payload], bearer($staff))->assertConflict()->assertJsonPath('code', 'wrong_visit_date');
    $this->travelTo($visit);
    expect(now()->utc()->toDateString())->not->toBe($date);
    $this->postJson('/api/v1/admin/ticket-check-ins/confirm', ['qr_payload' => $payload], bearer($staff) + ['Idempotency-Key' => (string) Str::uuid()])->assertOk()
        ->assertJsonPath('data.checked_in_count', 1)->assertJsonPath('data.payment_test_only', true)->assertJsonPath('data.payment_label', 'DEMO');
    $otherTicket = DB::table('booking_tickets')->where('booking_guid', $booking->guid)->orderByDesc('sequence')->first();
    $otherPayload = Crypt::decryptString($otherTicket->qr_token_encrypted);
    config(['operations.demo_payments_enabled' => false]);
    $this->getJson($path, bearer($owner))->assertOk()->assertJsonPath('data.payment_status', 'unverified');
    $this->get('/api/v1/bookings/'.$booking->guid.'/tickets/'.$otherTicket->guid.'/qr', bearer($owner))->assertConflict();
    $this->postJson('/api/v1/admin/ticket-check-ins/preview', ['qr_payload' => $otherPayload], bearer($staff))->assertConflict();
    config(['operations.demo_payments_enabled' => true]);
    $this->app->instance('env', 'production');
    $this->postJson('/api/v1/admin/ticket-check-ins/confirm', ['qr_payload' => $otherPayload], bearer($staff) + ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
    $this->assertDatabaseCount('ticket_check_ins', 1);
});

it('fails demo eligibility closed when matching capture evidence is removed or reversed', function (string $condition) {
    [$owner, $booking] = demoBooking(days: 3);
    $pending = demoStart($owner, $booking);
    demoOutcome($owner, $booking, $pending['attempt_guid'])->assertOk();
    $path = '/api/v1/bookings/'.$booking->guid.'/tickets';
    $this->getJson($path, bearer($owner))->assertOk()->assertJsonPath('data.ticket_state', 'available');
    if ($condition === 'reversal') {
        $capture = (array) DB::table('payment_events')->where('payment_attempt_guid', $pending['attempt_guid'])->first();
        DB::table('payment_events')->insert(array_replace($capture, ['guid' => (string) Str::uuid(), 'provider_event_key' => 'demo-test-reversal', 'event_type' => 'reversal']));
    } else {
        DB::table('payment_events')->where('payment_attempt_guid', $pending['attempt_guid'])->update(['metadata' => null]);
    }

    $this->getJson($path, bearer($owner))->assertOk()->assertJsonPath('data.payment_status', 'unverified')->assertJsonPath('data.tickets.0.qr_available', false);

    $this->assertDatabaseCount('ticket_check_ins', 0);
    expect(DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'confirmed')->count())->toBe(1);
})->with(['missing_demo_source', 'reversal']);

it('isolates simulated finance from production and preserves financial RBAC and null fee semantics', function () {
    [$owner, $booking, $destination] = demoBooking();
    $pending = demoStart($owner, $booking);
    demoOutcome($owner, $booking, $pending['attempt_guid'])->assertOk();
    $admin = makeUser(1);
    assignTestDestination($admin, $destination);
    assignTestDestination(makeUser(1), $destination);
    $path = '/api/v1/admin/dashboard/financial';

    $this->getJson($path.'?environment=demo', bearer($admin))->assertOk()
        ->assertJsonPath('data.financial.environment', 'demo')->assertJsonPath('data.financial.test_only', true)
        ->assertJsonPath('data.financial.label', 'DEMO')->assertJsonPath('data.financial.source', 'simulated_demo_events')
        ->assertJsonPath('data.financial.gross_collected', 20000)->assertJsonPath('data.financial.gross_revenue', 20000)
        ->assertJsonPath('data.financial.successful_transactions', 1)->assertJsonPath('data.financial.tickets_sold', 2)
        ->assertJsonPath('data.financial.gateway_fees', null)->assertJsonPath('data.financial.net_revenue', null)->assertJsonPath('data.financial.settlement', null);
    $this->getJson($path, bearer(makeUser(2)))->assertOk()->assertJsonPath('data.financial.gross_revenue', null);
    $this->getJson($path.'?environment=demo', bearer($owner))->assertForbidden();
    $this->getJson($path.'?environment=demo&destination_guid='.$destination->guid, bearer(makeUser(1)))->assertNotFound();
    DB::table('destination_admin_assignments')->where('user_guid', $admin->guid)->delete();
    $this->getJson($path.'?environment=demo', bearer($admin))->assertOk()->assertJsonPath('data.financial.gross_collected', 0)
        ->assertJsonPath('data.financial.successful_transactions', 0);
});

it('reports failed demo attempts without inventing collected money or production revenue', function () {
    [$owner, $booking] = demoBooking();
    $pending = demoStart($owner, $booking);
    demoOutcome($owner, $booking, $pending['attempt_guid'], 'failed')->assertOk();
    $headers = bearer(makeUser(2));

    $this->getJson('/api/v1/admin/dashboard/financial?environment=demo', $headers)->assertOk()
        ->assertJsonPath('data.financial.source', 'simulated_demo_events')->assertJsonPath('data.financial.test_only', true)
        ->assertJsonPath('data.financial.failed_attempts', 1)->assertJsonPath('data.financial.payment_attempts', 1)
        ->assertJsonPath('data.financial.gross_collected', 0)->assertJsonPath('data.financial.gross_revenue', 0)
        ->assertJsonPath('data.financial.gateway_fees', null)->assertJsonPath('data.financial.net_revenue', null);
    $this->getJson('/api/v1/admin/dashboard/financial', $headers)->assertOk()->assertJsonPath('data.financial.gross_collected', null);
});
