<?php

use App\Services\BookingService;
use App\Services\PaymentEligibility;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function smartTicketFixture(int $quantity = 2): array
{
    [$destination, $date] = seedDestination(days: 7, destination: ['timezone' => 'Asia/Jayapura']);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, $quantity) + ['idempotency_key' => 'smart-ticket']);
    app(BookingService::class)->decide(makeUser(2), $booking->guid, true);

    return [$owner, $destination, $booking->guid, $date];
}

function verifiedTicketPayments(): PaymentEligibility
{
    $adapter = new class extends PaymentEligibility
    {
        public string $paymentStatus = 'paid';

        public function status(object $booking): string
        {
            return $this->paymentStatus;
        }
    };
    app()->instance(PaymentEligibility::class, $adapter);

    return $adapter;
}

it('creates quantity units once and fails closed without authoritative payment', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 08:00:00', 'UTC'));
    [$owner, $destination, $guid, $date] = smartTicketFixture();
    app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => 'smart-ticket']);
    $this->travelTo(CarbonImmutable::parse($date.' 10:00:00', 'Asia/Jayapura'));
    $headers = bearer($owner);
    $response = $this->getJson("/api/v1/bookings/{$guid}/tickets", $headers)->assertOk()
        ->assertJsonPath('data.ticket_state', 'awaiting_payment')->assertJsonPath('data.payment_status', 'unverified')
        ->assertJsonCount(2, 'data.tickets')->assertJsonPath('data.tickets.0.qr_available', false);
    expect($response->getContent())->not->toContain('qr_token')->not->toContain('WDR1.');
    expect(DB::table('booking_tickets')->count())->toBe(2);
    expect(DB::table('booking_tickets')->whereNotNull('qr_token_hash')->count())->toBe(0);
    $this->postJson("/api/v1/bookings/{$guid}/check-in", [], $headers)->assertStatus(410)->assertJsonPath('code', 'legacy_check_in_disabled');
    $this->getJson("/api/v1/bookings/{$guid}", $headers)->assertJsonPath('data.can_check_in', false);
    verifiedTicketPayments();
    $this->travelTo(CarbonImmutable::parse($date.' 10:00:00', 'Asia/Jayapura')->addDay());
    $this->getJson("/api/v1/bookings/{$guid}/tickets", $headers)->assertJsonPath('data.ticket_state', 'expired');
    expect(DB::table('booking_tickets')->whereNotNull('qr_token_hash')->count())->toBe(0);
    $this->travelBack();
});

it('issues stable distinct private QR at H5 and confirms individual tickets once on local H', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 08:00:00', 'UTC'));
    [$owner, $destination, $guid, $date] = smartTicketFixture();
    verifiedTicketPayments();
    $ownerHeaders = bearer($owner);
    $staff = makeUser(2);
    $staffHeaders = bearer($staff);
    $path = "/api/v1/bookings/{$guid}/tickets";
    $visit = CarbonImmutable::parse($date.' 00:05:00', 'Asia/Jayapura');
    $this->travelTo($visit->subDays(6));
    $this->getJson($path, $ownerHeaders)->assertJsonPath('data.ticket_state', 'scheduled');
    $this->travelTo($visit->subDays(5));
    $this->artisan('tickets:issue-qr')->assertSuccessful();
    $this->getJson($path, $ownerHeaders)->assertJsonPath('data.ticket_state', 'available');
    $tickets = DB::table('booking_tickets')->where('booking_guid', $guid)->orderBy('sequence')->get();
    expect($tickets[0]->qr_token_hash)->not->toBe($tickets[1]->qr_token_hash);
    $payload = Crypt::decryptString($tickets[0]->qr_token_encrypted);
    expect($payload)->toMatch('/^WDR1\.[A-Za-z0-9_-]{43}$/')->not->toContain('BK-');
    $qrPath = $path.'/'.$tickets[0]->guid.'/qr';
    $svg = $this->get($qrPath, $ownerHeaders)->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    expect($svg->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    expect($svg->getContent())->toContain('<svg')->not->toContain($payload);
    $this->get($qrPath, $ownerHeaders)->assertContent($svg->getContent());
    $this->get($qrPath, bearer(makeUser()))->assertForbidden();
    expect(DB::table('booking_tickets')->where('guid', $tickets[0]->guid)->value('qr_token_hash'))->toBe($tickets[0]->qr_token_hash);
    $this->travelTo($visit->subDay());
    $this->postJson('/api/v1/admin/ticket-check-ins/preview', ['qr_payload' => $payload], $staffHeaders)
        ->assertConflict()->assertJsonPath('code', 'wrong_visit_date');
    $this->travelTo($visit);
    expect(now()->utc()->toDateString())->not->toBe($date);
    $fields = ['qr_payload' => $payload];
    $this->postJson('/api/v1/admin/ticket-check-ins/preview', $fields, $staffHeaders)->assertOk()->assertJsonPath('data.can_confirm', true);
    expect(DB::table('ticket_check_ins')->count())->toBe(0);
    $headers = $staffHeaders + ['Idempotency-Key' => (string) Str::uuid()];
    $first = $this->postJson('/api/v1/admin/ticket-check-ins/confirm', $fields, $headers)->assertOk()->assertJsonPath('data.checked_in_count', 1)->json();
    $this->postJson('/api/v1/admin/ticket-check-ins/confirm', $fields, $headers)->assertExactJson($first);
    $this->postJson('/api/v1/admin/ticket-check-ins/confirm', ['qr_payload' => 'WDR1.'.str_repeat('A', 43)], $headers)
        ->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    $this->postJson('/api/v1/admin/ticket-check-ins/confirm', ['qr_payload' => Crypt::decryptString($tickets[1]->qr_token_encrypted)], $headers)
        ->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    $this->postJson('/api/v1/admin/ticket-check-ins/confirm', $fields, $staffHeaders + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertConflict()->assertJsonPath('code', 'ticket_already_checked_in');
    $this->getJson("/api/v1/bookings/{$guid}", $ownerHeaders)->assertJsonPath('data.checked_in_count', 1)
        ->assertJsonPath('data.is_partially_checked_in', true)->assertJsonPath('data.can_review', true);
    $this->getJson('/api/v1/profile/statistics', $ownerHeaders)->assertJsonPath('data.visits', 1);
    $this->getJson($path, $ownerHeaders)->assertJsonPath('data.tickets.0.qr_available', false)->assertJsonPath('data.tickets.1.qr_available', true);
    $this->postJson("/api/v1/bookings/{$guid}/review", ['rating' => 4, 'comment' => 'Visit'], $ownerHeaders)->assertOk();
    expect(DB::table('ticket_check_ins')->count())->toBe(1);
    DB::table('booking_tickets')->where('guid', $tickets[1]->guid)->update(['qr_token_encrypted' => 'corrupt']);
    $this->get($path.'/'.$tickets[1]->guid.'/qr', $ownerHeaders)->assertStatus(503);
    expect(DB::table('booking_tickets')->where('guid', $tickets[1]->guid)->value('qr_token_hash'))->toBe($tickets[1]->qr_token_hash);
    $this->travelTo($visit->addDay());
    $this->postJson('/api/v1/admin/ticket-check-ins/preview', ['qr_payload' => Crypt::decryptString($tickets[1]->qr_token_encrypted)], $staffHeaders)
        ->assertConflict()->assertJsonPath('code', 'wrong_visit_date');
    $this->travelBack();
});

it('enforces active staff assignments and revalidates payment and assignment after preview', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 08:00:00', 'UTC'));
    [$owner, $destination, $guid, $date] = smartTicketFixture();
    $payments = verifiedTicketPayments();
    $this->travelTo(CarbonImmutable::parse($date.' 12:00:00', 'Asia/Jayapura'));
    app(TicketService::class)->listing($owner, $guid);
    $payload = Crypt::decryptString(DB::table('booking_tickets')->where('booking_guid', $guid)->value('qr_token_encrypted'));
    $admin = makeUser(1);
    $adminHeaders = bearer($admin);
    $superHeaders = bearer(makeUser(2));
    $this->getJson('/api/v1/admin/ticket-check-ins/scope', $adminHeaders)->assertOk()->assertJsonPath('data.destinations', []);
    $fields = ['qr_payload' => $payload];
    $this->postJson('/api/v1/admin/ticket-check-ins/preview', $fields, bearer($owner))->assertForbidden();
    $this->postJson('/api/v1/admin/ticket-check-ins/preview', $fields, $adminHeaders)->assertForbidden()->assertJsonPath('code', 'ticket_out_of_scope');
    $assignment = "/api/v1/admin/destinations/{$destination->guid}/admins";
    $this->putJson($assignment, ['user_guids' => [$admin->guid]], $adminHeaders)->assertForbidden();
    $this->putJson($assignment, ['user_guids' => [$admin->guid]], $superHeaders)->assertOk()->assertJsonPath('data.user_guids.0', $admin->guid);
    $this->postJson('/api/v1/admin/ticket-check-ins/preview', $fields, $adminHeaders)->assertOk();
    DB::table('bookings')->where('guid', $guid)->update(['status' => 'pending_approval']);
    $this->postJson('/api/v1/admin/ticket-check-ins/confirm', $fields, $adminHeaders + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertConflict()->assertJsonPath('code', 'booking_not_approved');
    DB::table('bookings')->where('guid', $guid)->update(['status' => 'confirmed']);
    $payments->paymentStatus = 'refunded';
    $this->postJson('/api/v1/admin/ticket-check-ins/confirm', $fields, $adminHeaders + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertConflict()->assertJsonPath('code', 'ticket_revoked');
    $payments->paymentStatus = 'paid';
    $this->putJson($assignment, ['user_guids' => []], $superHeaders)->assertOk();
    $this->postJson('/api/v1/admin/ticket-check-ins/confirm', $fields, $adminHeaders + ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
    $this->postJson('/api/v1/admin/ticket-check-ins/preview', ['qr_payload' => 'BK-example'], $superHeaders)->assertUnprocessable()->assertJsonPath('code', 'invalid_qr');
    expect(DB::table('ticket_check_ins')->count())->toBe(0);
    expect(DB::table('destination_admin_assignment_histories')->count())->toBe(2);
    $this->travelBack();
});

it('preserves legacy group visits and reviews through repeatable backfill without issuing QR', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 08:00:00', 'UTC'));
    [$owner, , $guid] = smartTicketFixture();
    DB::table('booking_tickets')->where('booking_guid', $guid)->delete();
    $checkedAt = now()->utc()->subDay()->toDateTimeString();
    DB::table('check_ins')->insert(['guid' => (string) Str::uuid(), 'booking_guid' => $guid, 'actor_guid' => $owner->guid, 'checked_in_at' => $checkedAt]);
    $headers = bearer($owner);
    $this->getJson("/api/v1/bookings/{$guid}", $headers)->assertJsonPath('data.checked_in_count', 2)->assertJsonPath('data.can_review', true);
    $this->getJson('/api/v1/profile/statistics', $headers)->assertJsonPath('data.visits', 1);
    $this->artisan('tickets:backfill')->assertSuccessful();
    $this->artisan('tickets:backfill')->assertSuccessful();
    expect(DB::table('booking_tickets')->count())->toBe(2);
    expect(DB::table('ticket_check_ins')->count())->toBe(2);
    expect(DB::table('booking_tickets')->whereNotNull('qr_token_hash')->count())->toBe(0);
    expect(DB::table('booking_tickets')->where('checked_in_at', $checkedAt)->count())->toBe(2);
    $this->getJson('/api/v1/profile/statistics', $headers)->assertJsonPath('data.visits', 1);
    $this->getJson("/api/v1/bookings/{$guid}/tickets", $headers)->assertJsonPath('data.ticket_state', 'fully_checked_in');
    $this->postJson("/api/v1/bookings/{$guid}/review", ['rating' => 5, 'comment' => 'Legacy visit'], $headers)->assertOk();
    $this->travelBack();
});
