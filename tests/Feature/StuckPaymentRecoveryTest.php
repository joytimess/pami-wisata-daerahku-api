<?php

use App\Services\StuckPaymentRecoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/MidtransFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    mtxConfig();
    Http::preventStrayRequests();
    Queue::fake();
});

/** A booking an older release parked in the given status after the clock ran out, with one attempt in the given state. */
function srParked(string $attemptState, string $status = 'payment_review', array $attemptOverride = []): object
{
    [, $booking] = mtxPayable();
    $attempt = mtxAttempt($booking, $attemptState, override: $attemptOverride);
    test()->travel(40)->minutes();
    DB::table('bookings')->where('guid', $booking->guid)->update(['status' => $status]);

    return (object) ['booking' => $booking, 'attempt' => $attempt];
}

it('reports without changing anything on a dry run', function () {
    $closable = srParked('expired');
    $before = [(array) DB::table('bookings')->where('guid', $closable->booking->guid)->first(), DB::table('payment_events')->count()];

    $result = app(StuckPaymentRecoveryService::class)->run(true);

    expect($result)->toMatchArray(['closable' => 1, 'lookup' => 0, 'operator' => 0, 'closed' => 0])
        ->and((array) DB::table('bookings')->where('guid', $closable->booking->guid)->first())->toBe($before[0])
        ->and(DB::table('payment_events')->count())->toBe($before[1]);
});

it('releases a parked booking whose attempts are all terminal unpaid, through the normal expiry path', function () {
    $parked = srParked('expired', 'payment_verification');

    $result = app(StuckPaymentRecoveryService::class)->run(false);

    expect($result['closed'])->toBe(1)
        ->and(DB::table('bookings')->where('guid', $parked->booking->guid)->value('status'))->toBeIn(['awaiting_payment', 'expired'])
        ->and(DB::table('payment_attempts')->where('guid', $parked->attempt->guid)->value('status'))->toBe('expired');
});

it('only brings the provider lookup forward for an attempt that is still open', function () {
    $parked = srParked('pending', 'payment_review', ['next_reconcile_at' => now()->addHours(2)]);

    $result = app(StuckPaymentRecoveryService::class)->run(false);

    expect($result)->toMatchArray(['lookup' => 1, 'lookups_requested' => 1, 'closed' => 0])
        ->and(DB::table('bookings')->where('guid', $parked->booking->guid)->value('status'))->toBe('payment_review')
        ->and(CarbonImmutable::parse(DB::table('payment_attempts')->where('guid', $parked->attempt->guid)->value('next_reconcile_at'), 'UTC')->lte(now()))->toBeTrue();
});

it('leaves paid or exhausted evidence to an operator and never touches it', function () {
    $paid = srParked('paid');
    $exhausted = srParked('unknown', 'payment_review', ['recovery_exhausted_at' => now()]);
    $snapshot = [DB::table('bookings')->orderBy('guid')->get()->toArray(), DB::table('payment_attempts')->orderBy('guid')->get()->toArray()];

    $result = app(StuckPaymentRecoveryService::class)->run(false);

    expect($result['operator'])->toBe(2)->and($result['operator_guids'])->toContain($paid->booking->guid, $exhausted->booking->guid)
        ->and(DB::table('bookings')->orderBy('guid')->get()->toArray())->toEqual($snapshot[0])
        ->and(DB::table('payment_attempts')->orderBy('guid')->get()->toArray())->toEqual($snapshot[1]);
});

it('is exposed as a command with a dry-run mode', function () {
    srParked('expired');

    $this->artisan('payments:recover-stuck', ['--dry-run' => true])->expectsOutputToContain('closable=1')->assertSuccessful();
    expect(DB::table('bookings')->where('status', 'payment_review')->count())->toBe(1);
});
