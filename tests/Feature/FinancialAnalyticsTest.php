<?php

use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Payment outcomes are fixtures only in the isolated test database, never a provider simulation. */
function financialFixture(string $environment = 'sandbox', string $bookingStatus = 'confirmed', int $quantity = 2): array
{
    [$destination, $date] = seedDestination(capacity: 50, price: 10000, days: 20);
    $booking = app(BookingService::class)->create(makeUser(), bookingPayload($destination, $date, $quantity) + ['idempotency_key' => (string) Str::uuid()]);
    app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
    DB::table('bookings')->where('guid', $booking->guid)->update(['status' => $bookingStatus]);
    $intent = [
        'guid' => (string) Str::uuid(), 'booking_guid' => $booking->guid, 'provider' => $environment === 'demo' ? 'demo' : 'doku',
        'environment' => $environment, 'currency' => 'IDR', 'amount' => $quantity * 10000, 'status' => 'paid',
        'merchant_reference' => 'test-intent-'.Str::random(12), 'expires_at' => now()->addHour(),
        'verified_paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('payment_intents')->insert($intent);
    $attempt = [
        'guid' => (string) Str::uuid(), 'payment_intent_guid' => $intent['guid'], 'provider' => $intent['provider'],
        'environment' => $environment, 'attempt_number' => 1, 'method' => 'checkout', 'channel' => 'test-channel',
        'merchant_reference' => 'test-attempt-'.Str::random(12), 'provider_request_id' => (string) Str::uuid(),
        'provider_reference' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
        'amount' => $intent['amount'], 'currency' => 'IDR', 'status' => 'paid',
        'started_at' => now()->subMinutes(10), 'finished_at' => now(), 'verified_paid_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('payment_attempts')->insert($attempt);
    DB::table('payment_intents')->where('guid', $intent['guid'])->update(['applied_attempt_guid' => $attempt['guid']]);
    financialEvent($attempt);

    return [$destination, $booking->guid, $intent, $attempt];
}

function financialEvent(array $attempt, array $changes = []): string
{
    $guid = (string) Str::uuid();
    DB::table('payment_events')->insert(array_replace([
        'guid' => $guid, 'payment_intent_guid' => $attempt['payment_intent_guid'], 'payment_attempt_guid' => $attempt['guid'],
        'provider' => $attempt['provider'], 'environment' => $attempt['environment'], 'provider_event_key' => (string) Str::uuid(),
        'payload_hash' => str_repeat('b', 64), 'verification' => 'verified', 'event_type' => 'capture',
        'disposition' => 'applied', 'amount' => $attempt['amount'], 'currency' => 'IDR',
        'provider_occurred_at' => $attempt['verified_paid_at'], 'received_at' => now(),
    ], $changes));

    return $guid;
}

it('returns 401 without a token and 403 for visitors', function () {
    $this->getJson('/api/v1/admin/dashboard/financial')->assertUnauthorized();
    $this->getJson('/api/v1/admin/dashboard/financial', bearer(makeUser()))->assertForbidden();
});

it('keeps production amounts null without a verified source even when bookings or paid labels exist', function () {
    $this->travelTo('2026-11-05 12:00:00');
    [, , , $attempt] = financialFixture('production');
    DB::table('payment_events')->where('payment_attempt_guid', $attempt['guid'])->update(['verification' => 'unverified']);

    $this->getJson('/api/v1/admin/dashboard/financial', bearer(makeUser(2)))->assertOk()
        ->assertJsonPath('data.financial.availability', 'unavailable')->assertJsonPath('data.financial.test_only', false)
        ->assertJsonPath('data.financial.gross_revenue', null)->assertJsonPath('data.financial.gross_collected', null)
        ->assertJsonPath('data.financial.refunds', null)->assertJsonPath('data.financial.net_revenue', null)
        ->assertJsonPath('data.financial.successful_transactions', null)->assertHeader('Cache-Control', 'no-store, private');
});

it('counts captures once despite duplicate events and shared destination assignments and separates environments', function () {
    $this->travelTo('2026-11-05 12:00:00');
    [$destination, , , $attempt] = financialFixture();
    financialEvent($attempt, ['disposition' => 'duplicate_capture']);
    $admin = makeUser(1);
    assignTestDestination($admin, $destination);
    assignTestDestination(makeUser(1), $destination);
    financialFixture('demo', quantity: 3);
    $headers = bearer($admin);

    $response = $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox', $headers)->assertOk()
        ->assertJsonPath('data.financial.availability', 'partial')->assertJsonPath('data.financial.test_only', true)
        ->assertJsonPath('data.financial.source', 'verified_sandbox_events')->assertJsonPath('data.financial.gross_collected', 20000)
        ->assertJsonPath('data.financial.gross_revenue', 20000)->assertJsonPath('data.financial.unapplied_collected', 0)
        ->assertJsonPath('data.financial.successful_transactions', 1)->assertJsonPath('data.financial.successful_payment_attempts', 1)
        ->assertJsonPath('data.financial.tickets_sold', 2)->assertJsonPath('data.financial.avg_payment_seconds', 600)
        ->assertJsonPath('data.financial.gateway_fees', null)->assertJsonPath('data.financial.net_revenue', null)
        ->assertJsonPath('data.financial.method_mix_truncated', false)
        ->assertJsonPath('data.financial.method_mix.0.gross_collected', 20000);
    expect($response->getContent())->not->toContain($attempt['provider_request_id'], $attempt['provider_reference'], 'visitor@example.com');
    $this->getJson('/api/v1/admin/dashboard/financial', $headers)->assertOk()->assertJsonPath('data.financial.availability', 'unavailable');
    $this->getJson('/api/v1/admin/dashboard/financial?environment=demo', bearer(makeUser(2)))->assertOk()
        ->assertJsonPath('data.financial.gross_collected', 30000)->assertJsonPath('data.financial.test_only', true);
});

it('scopes role 1 to current assignments and returns 404 for a foreign destination', function () {
    $this->travelTo('2026-11-05 12:00:00');
    [$destination] = financialFixture();
    [$foreign] = financialFixture(quantity: 3);
    $admin = makeUser(1);
    assignTestDestination($admin, $destination);
    $headers = bearer($admin);

    $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox', $headers)->assertOk()->assertJsonPath('data.financial.gross_revenue', 20000);
    $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox&destination_guid='.$foreign->guid, $headers)->assertNotFound();
    $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox', bearer(makeUser(2)))->assertOk()->assertJsonPath('data.financial.gross_revenue', 50000);
    DB::table('destination_admin_assignments')->where('user_guid', $admin->guid)->delete();
    $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox', $headers)->assertOk()
        ->assertJsonPath('data.financial.availability', 'unavailable')->assertJsonPath('data.financial.gross_revenue', null);
});

it('separates unapplied late or duplicate captures from recognized bookings and ticket sales', function () {
    $this->travelTo('2026-11-05 12:00:00');
    [, , $intent, $attempt] = financialFixture(bookingStatus: 'payment_review');
    DB::table('payment_intents')->where('guid', $intent['guid'])->update(['status' => 'needs_review', 'applied_attempt_guid' => null]);
    DB::table('payment_events')->where('payment_attempt_guid', $attempt['guid'])->update(['disposition' => 'unapplied']);
    [, , , $accepted] = financialFixture();
    $duplicate = array_replace($accepted, ['guid' => (string) Str::uuid(), 'attempt_number' => 2,
        'merchant_reference' => (string) Str::uuid(), 'provider_request_id' => (string) Str::uuid(), 'provider_reference' => (string) Str::uuid()]);
    DB::table('payment_attempts')->insert($duplicate);
    financialEvent($duplicate, ['disposition' => 'duplicate_capture']);

    $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox', bearer(makeUser(2)))->assertOk()
        ->assertJsonPath('data.financial.gross_collected', 60000)->assertJsonPath('data.financial.gross_revenue', 20000)
        ->assertJsonPath('data.financial.unapplied_collected', 40000)->assertJsonPath('data.financial.successful_transactions', 1)
        ->assertJsonPath('data.financial.successful_payment_attempts', 3)->assertJsonPath('data.financial.tickets_sold', 2)
        ->assertJsonPath('data.financial.booking_conversion.paid_bookings', 1)->assertJsonPath('data.financial.booking_conversion.payment_eligible_bookings', 2)
        ->assertJsonPath('data.financial.booking_conversion.percent', 50);
});

it('rejects mismatched evidence as a source', function (array $changes) {
    $this->travelTo('2026-11-05 12:00:00');
    [, , , $attempt] = financialFixture();
    DB::table('payment_events')->where('payment_attempt_guid', $attempt['guid'])->update($changes);

    $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox', bearer(makeUser(2)))->assertOk()
        ->assertJsonPath('data.financial.availability', 'unavailable')->assertJsonPath('data.financial.gross_collected', null);
})->with([
    'wrong amount' => [['amount' => 1]], 'wrong currency' => [['currency' => 'USD']],
    'wrong environment' => [['environment' => 'production']], 'unverified' => [['verification' => 'unverified']],
    'wrong paid time' => [['provider_occurred_at' => '2026-11-05 12:00:01']],
    'orphan intent' => [['payment_intent_guid' => null]],
]);

it('reports verified partial refund receipts without deleting gross and keeps net null without fees', function () {
    $this->travelTo('2026-11-05 12:00:00');
    [, , , $attempt] = financialFixture();
    financialEvent($attempt, ['event_type' => 'refund', 'amount' => 5000]);
    financialEvent($attempt, ['event_type' => 'refund', 'amount' => 5000, 'disposition' => 'duplicate']);
    financialEvent($attempt, ['event_type' => 'refund', 'amount' => 9000, 'verification' => 'rejected']);
    financialEvent($attempt, ['event_type' => 'refund', 'amount' => 9000, 'currency' => 'USD']);

    $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox', bearer(makeUser(2)))->assertOk()
        ->assertJsonPath('data.financial.gross_revenue', 20000)->assertJsonPath('data.financial.gross_collected', 20000)
        ->assertJsonPath('data.financial.refunds', 5000)->assertJsonPath('data.financial.refund_date_basis', 'refund_utc')
        ->assertJsonPath('data.financial.net_revenue', null)->assertJsonPath('data.financial.gateway_fees', null)
        ->assertJsonPath('data.financial.refunds_basis', 'verified_receipts')->assertJsonPath('data.financial.refunds_complete', false)
        ->assertJsonPath('data.financial.missing_fields', ['gateway_fees', 'refund_completeness']);
});

it('requires approved history and applied capture evidence before recognizing booking revenue', function () {
    $this->travelTo('2026-11-05 12:00:00');
    [, $booking] = financialFixture();
    DB::table('booking_status_histories')->where('booking_guid', $booking)->where('new_status', 'awaiting_payment')->delete();
    [, , , $attempt] = financialFixture();
    DB::table('payment_events')->where('payment_attempt_guid', $attempt['guid'])->update(['disposition' => 'unapplied']);

    $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox', bearer(makeUser(2)))->assertOk()
        ->assertJsonPath('data.financial.gross_collected', 40000)->assertJsonPath('data.financial.gross_revenue', 0)
        ->assertJsonPath('data.financial.booking_conversion.paid_bookings', 0)
        ->assertJsonPath('data.financial.booking_conversion.payment_eligible_bookings', 1);
});

it('removes reversal recognition while retaining capture history and ignores unrelated adjustments', function () {
    $this->travelTo('2026-11-05 12:00:00');
    [, , , $attempt] = financialFixture();
    financialEvent($attempt, ['event_type' => 'reversal', 'currency' => 'USD']);
    $headers = bearer(makeUser(2));
    $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox', $headers)->assertOk()->assertJsonPath('data.financial.gross_revenue', 20000);
    financialEvent($attempt, ['event_type' => 'reversal']);

    $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox', $headers)->assertOk()
        ->assertJsonPath('data.financial.gross_collected', 20000)->assertJsonPath('data.financial.gross_revenue', 0)
        ->assertJsonPath('data.financial.tickets_sold', 0)->assertJsonPath('data.financial.unapplied_collected', 20000);
});

it('uses separate paid attempt-start and booking-created UTC cohorts including the entire final day', function () {
    $this->travelTo('2026-11-05 23:59:59');
    [, $booking, , $attempt] = financialFixture();
    DB::table('bookings')->where('guid', $booking)->update(['created_at' => '2026-11-01 10:00:00']);
    DB::table('payment_attempts')->where('guid', $attempt['guid'])->update(['started_at' => '2026-11-04 23:59:59']);
    $headers = bearer(makeUser(2));

    $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox&from=2026-11-05&to=2026-11-05', $headers)->assertOk()
        ->assertJsonPath('data.financial.gross_collected', 20000)->assertJsonPath('data.financial.payment_attempts', 0)
        ->assertJsonPath('data.financial.attempt_conversion.percent', null)->assertJsonPath('data.financial.booking_conversion.percent', null);
    $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox&from=2026-11-01&to=2026-11-01', $headers)->assertOk()
        ->assertJsonPath('data.financial.gross_collected', 0)->assertJsonPath('data.financial.booking_conversion.percent', 100)
        ->assertJsonPath('data.financial.booking_conversion.date_basis', 'booking_created_utc');
});

it('excludes paid labels without evidence from completed attempts and reports failures expiry and unknown separately', function () {
    $this->travelTo('2026-11-05 12:00:00');
    [, , , $attempt] = financialFixture();
    foreach (['paid', 'failed', 'expired', 'unknown'] as $index => $status) {
        DB::table('payment_attempts')->insert(array_replace($attempt, [
            'guid' => (string) Str::uuid(), 'attempt_number' => $index + 2, 'status' => $status, 'verified_paid_at' => null,
            'merchant_reference' => (string) Str::uuid(), 'provider_request_id' => (string) Str::uuid(), 'provider_reference' => null,
        ]));
    }

    $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox', bearer(makeUser(2)))->assertOk()
        ->assertJsonPath('data.financial.payment_attempts', 5)->assertJsonPath('data.financial.failed_attempts', 1)
        ->assertJsonPath('data.financial.expired_attempts', 1)->assertJsonPath('data.financial.unknown_attempts', 1)
        ->assertJsonPath('data.financial.attempt_conversion.successful_attempts', 1)
        ->assertJsonPath('data.financial.attempt_conversion.completed_attempts', 3)
        ->assertJsonPath('data.financial.attempt_conversion.percent', 33.33);
});

it('marks a method mix as truncated instead of silently hiding groups beyond its response limit', function () {
    $this->travelTo('2026-11-05 12:00:00');
    [, , , $attempt] = financialFixture();
    foreach (range(2, 101) as $number) {
        $additional = array_replace($attempt, [
            'guid' => (string) Str::uuid(), 'attempt_number' => $number, 'channel' => 'test-channel-'.$number,
            'merchant_reference' => (string) Str::uuid(), 'provider_request_id' => (string) Str::uuid(), 'provider_reference' => null,
        ]);
        DB::table('payment_attempts')->insert($additional);
        financialEvent($additional, ['disposition' => 'duplicate_capture']);
    }

    $this->getJson('/api/v1/admin/dashboard/financial?environment=sandbox', bearer(makeUser(2)))->assertOk()
        ->assertJsonCount(100, 'data.financial.method_mix')->assertJsonPath('data.financial.method_mix_limit', 100)
        ->assertJsonPath('data.financial.method_mix_truncated', true)->assertJsonPath('data.financial.gross_collected', 2020000)
        ->assertJsonPath('data.financial.gross_revenue', 20000);
});

it('returns 422 for unsupported finance filters and invalid date cohorts', function (string $query, string $error) {
    $this->getJson('/api/v1/admin/dashboard/financial?'.$query, bearer(makeUser(2)))
        ->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    'from only' => ['from=2026-11-05', 'to'], 'to only' => ['to=2026-11-05', 'from'],
    'invalid date' => ['from=not-a-date&to=2026-11-05', 'from'],
    'reverse range' => ['from=2026-11-05&to=2026-11-04', 'to'],
    '367 days' => ['from=2026-01-01&to=2027-01-02', 'to'],
    'environment' => ['environment=all', 'environment'], 'date basis' => ['date_basis=visit', 'date_basis'],
    'personal finance' => ['admin_guid=00000000-0000-0000-0000-000000000000', 'admin_guid'],
]);

it('accepts an inclusive 366 day finance period', function () {
    $this->getJson('/api/v1/admin/dashboard/financial?from=2026-01-01&to=2027-01-01', bearer(makeUser(2)))->assertOk()
        ->assertJsonPath('data.financial.period.from', '2026-01-01')->assertJsonPath('data.financial.period.to', '2027-01-01');
});

test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
