<?php

use App\Jobs\DeliverBookingNotification;
use App\Services\BookingNotificationService;
use App\Services\BookingService;
use App\Services\InboxService;
use App\Services\PaymentEligibility;
use App\Services\TicketCheckInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
});

function operationsFixture(): array
{
    [$destination, $date] = seedDestination(capacity: 50, price: 10000, days: 10);
    $owner = makeUser();
    $admin = makeUser(1);
    $other = makeUser(1);
    $super = makeUser(2);
    app(TicketCheckInService::class)->assign($super, $destination, [$admin->guid, $other->guid]);
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => 'operations-booking']);

    return [$destination, $owner, $admin, $other, $super, $booking->guid];
}

it('scopes admin reads and rejects visitor and inactive tokens', function () {
    [$destination, $owner, $admin, , $super, $guid] = operationsFixture();
    $unassigned = makeUser(1);
    $headers = bearer($unassigned);
    foreach (['bookings', 'destinations', 'approval-queue'] as $endpoint) {
        $this->getJson('/api/v1/admin/'.$endpoint, $headers)->assertOk()->assertJsonCount(0, 'data');
    }
    $this->getJson("/api/v1/admin/bookings/{$guid}", $headers)->assertNotFound();
    $this->getJson("/api/v1/admin/destinations/{$destination->guid}", $headers)->assertForbidden();
    $this->getJson("/api/v1/admin/bookings/{$guid}", bearer($admin))->assertOk();
    $this->getJson("/api/v1/admin/bookings/{$guid}", bearer($super))->assertOk();
    foreach (['overview', 'approvals', 'destinations', 'visitors', 'action-center'] as $endpoint) {
        $this->getJson('/api/v1/admin/dashboard/'.$endpoint, bearer($owner))->assertForbidden();
        $this->getJson('/api/v1/admin/dashboard/'.$endpoint, $headers)->assertOk();
    }
    DB::table('users')->where('guid', $unassigned->guid)->update(['status' => 'suspended']);
    $this->getJson('/api/v1/admin/dashboard/overview', $headers)->assertUnauthorized();
});

it('claims once replays without duplicate audit and blocks competing decisions', function () {
    [, , $admin, $other, , $guid] = operationsFixture();
    $path = "/api/v1/admin/bookings/{$guid}";
    $headers = bearer($admin) + ['Idempotency-Key' => 'claim-once'];
    $first = $this->postJson($path.'/claim', [], $headers)->assertSuccessful();
    $this->postJson($path.'/claim', [], $headers)->assertSuccessful()->assertJsonPath('data.claim_guid', $first->json('data.claim_guid'));
    expect(DB::table('booking_approval_claims')->where('booking_guid', $guid)->count())->toBe(1);
    expect(DB::table('booking_approval_claim_histories')->where('booking_guid', $guid)->where('event', 'claimed')->count())->toBe(1);
    $this->postJson($path.'/claim', [], bearer($other) + ['Idempotency-Key' => 'competing'])->assertConflict()->assertJsonPath('code', 'claim_owned');
    $this->postJson($path.'/approve', [], bearer($other))->assertConflict()->assertJsonPath('code', 'claim_owned');
    $this->postJson($path.'/approve', [], bearer($admin))->assertOk()->assertJsonPath('data.status', 'awaiting_payment');
    expect(DB::table('booking_status_histories')->where('booking_guid', $guid)->where('new_status', 'awaiting_payment')->value('actor_guid'))->toBe($admin->guid);
});

it('requires explicit claims in strict mode and auto claims in compat mode', function () {
    [, , $admin, , , $guid] = operationsFixture();
    config(['operations.claim_mode' => 'strict']);
    $this->postJson("/api/v1/admin/bookings/{$guid}/approve", [], bearer($admin))->assertConflict();
    $this->assertDatabaseHas('bookings', ['guid' => $guid, 'status' => 'pending_approval']);
    config(['operations.claim_mode' => 'compat']);
    $this->postJson("/api/v1/admin/bookings/{$guid}/reject", ['reason' => 'Unavailable'], bearer($admin))->assertOk()->assertJsonPath('data.status', 'rejected');
    expect(DB::table('booking_approval_claim_histories')->where('booking_guid', $guid)->where('event', 'completed')->count())->toBe(1);
});

it('releases reassigns and expires claims without extending approval deadline', function () {
    [, , $admin, $other, $super, $guid] = operationsFixture();
    $path = "/api/v1/admin/bookings/{$guid}";
    $deadline = DB::table('bookings')->where('guid', $guid)->value('approval_expires_at');
    $this->postJson($path.'/claim', [], bearer($admin) + ['Idempotency-Key' => 'first'])->assertSuccessful();
    $this->postJson($path.'/release', [], bearer($other) + ['Idempotency-Key' => 'not-owner'])->assertConflict();
    $headers = bearer($admin) + ['Idempotency-Key' => 'release'];
    $this->postJson($path.'/release', [], $headers)->assertSuccessful();
    $this->postJson($path.'/release', [], $headers)->assertSuccessful();
    $this->postJson($path.'/claim', [], bearer($other) + ['Idempotency-Key' => 'second'])->assertSuccessful();
    $reassign = "/api/v1/superadmin/bookings/{$guid}/reassign";
    $body = ['admin_guid' => $admin->guid, 'reason' => 'Shift change'];
    $this->postJson($reassign, $body, bearer($other) + ['Idempotency-Key' => 'denied'])->assertForbidden();
    $headers = bearer($super) + ['Idempotency-Key' => 'reassign'];
    $this->postJson($reassign, $body, $headers)->assertSuccessful();
    $this->postJson($reassign, $body, $headers)->assertSuccessful();
    DB::table('booking_approval_claims')->where('booking_guid', $guid)->update(['expires_at' => now()->subSecond()]);
    $this->postJson($path.'/claim', [], bearer($other) + ['Idempotency-Key' => 'after-expiry'])->assertSuccessful();
    expect(DB::table('bookings')->where('guid', $guid)->value('approval_expires_at'))->toBe($deadline);
    expect(DB::table('booking_approval_claim_histories')->where('booking_guid', $guid)->where('event', 'reassigned')->count())->toBe(1);
});

it('revokes assignment before a claimed booking can be decided', function () {
    [$destination, , $admin, $other, $super, $guid] = operationsFixture();
    $this->postJson("/api/v1/admin/bookings/{$guid}/claim", [], bearer($admin) + ['Idempotency-Key' => 'before-revoke'])->assertSuccessful();
    app(TicketCheckInService::class)->assign($super, $destination, [$other->guid]);
    $this->postJson("/api/v1/admin/bookings/{$guid}/approve", [], bearer($admin))->assertNotFound();
    $this->assertDatabaseHas('bookings', ['guid' => $guid, 'status' => 'pending_approval']);
    expect(DB::table('destination_admin_assignment_histories')->where('destination_guid', $destination->guid)->where('action', 'removed')->count())->toBe(1);
    $this->postJson("/api/v1/admin/bookings/{$guid}/claim", [], bearer($other) + ['Idempotency-Key' => 'take-revoked'])->assertSuccessful()->assertJsonPath('data.owner_user_guid', $other->guid);
});

it('keeps payment preview gated owner scoped and read only without QR or gateway calls', function () {
    [, $owner, $admin, , , $guid] = operationsFixture();
    Http::preventStrayRequests();
    $path = "/api/v1/bookings/{$guid}/payment-preview";
    $this->getJson($path, bearer($owner))->assertNotFound();
    config(['operations.payment_preview_enabled' => true]);
    $this->getJson($path, bearer($owner))->assertOk()->assertJsonPath('data.availability', 'unavailable')->assertJsonPath('data.can_pay', false);
    $this->postJson("/api/v1/admin/bookings/{$guid}/approve", [], bearer($admin))->assertOk();
    $before = DB::table('bookings')->where('guid', $guid)->first();
    $count = DB::table('booking_status_histories')->count();
    $this->getJson($path, bearer(makeUser()))->assertNotFound();
    $this->getJson($path, bearer($owner))->assertOk()->assertJsonPath('data.can_pay', false)->assertJsonPath('data.integration_status', 'not_configured');
    expect(DB::table('bookings')->where('guid', $guid)->first())->toEqual($before);
    expect(DB::table('booking_status_histories')->count())->toBe($count);
    expect(DB::table('booking_tickets')->whereNotNull('qr_token_hash')->count())->toBe(0);
    expect(app(PaymentEligibility::class)->status($before))->toBe('unverified');
    expect(config('operations.doku_enabled'))->toBeFalse();
});

it('hides revoked admin inbox entries and cancels their queued deliveries', function () {
    [$destination, , $admin, $other, $super, $guid] = operationsFixture();
    $unassigned = makeUser(1);
    app(BookingNotificationService::class)->record($guid, DB::table('bookings')->where('guid', $guid)->value('user_guid'), 'created');
    $this->assertDatabaseMissing('notifications', ['booking_guid' => $guid, 'user_guid' => $unassigned->guid]);
    $notification = DB::table('notifications')->where('booking_guid', $guid)->where('user_guid', $admin->guid)->first();
    expect($notification)->not->toBeNull();
    $inbox = app(InboxService::class);
    expect($inbox->unread($admin))->toBe(1);
    app(TicketCheckInService::class)->assign($super, $destination, [$other->guid]);
    expect($inbox->unread($admin))->toBe(0);
    expect($inbox->list($admin)->total())->toBe(0);
    $delivery = DB::table('notification_deliveries')->where('notification_guid', $notification->guid)->value('guid');
    (new DeliverBookingNotification($delivery))->handle();
    $this->assertDatabaseHas('notification_deliveries', ['guid' => $delivery, 'status' => 'cancelled']);
});

it('validates analytics bounds and denies staff and destination scope escalation', function () {
    [$destination, , $admin] = operationsFixture();
    [$foreign] = seedDestination();
    $headers = bearer($admin);
    $this->getJson('/api/v1/admin/dashboard/overview?from=bad', $headers)->assertUnprocessable();
    $this->getJson('/api/v1/admin/dashboard/overview?from=2020-01-01&to=2026-12-31', $headers)->assertUnprocessable();
    $this->getJson('/api/v1/admin/dashboard/overview?admin_guid='.$admin->guid, $headers)->assertForbidden();
    $response = $this->getJson('/api/v1/admin/dashboard/overview?destination_guid='.$foreign->guid, $headers);
    expect($response->status())->toBeIn([403, 404]);
    $this->getJson('/api/v1/admin/dashboard/overview?destination_guid='.$destination->guid, $headers)->assertOk()
        ->assertJsonPath('data.financial.availability', 'unavailable')->assertJsonPath('data.financial.gross_revenue', null)->assertJsonStructure(['meta' => ['generated_at']]);
    $this->getJson('/api/v1/admin/dashboard/visitors', $headers)->assertOk()->assertJsonPath('data.period.date_basis', 'visit');
    $this->getJson('/api/v1/admin/dashboard/destinations', $headers)->assertOk()->assertJsonPath('meta.period.date_basis', 'visit');
    $this->getJson('/api/v1/admin/approval-queue', $headers)->assertOk()->assertJsonPath('meta.period.date_basis', 'created');
    $this->getJson('/api/v1/admin/dashboard/action-center', $headers)->assertOk()
        ->assertJsonPath('meta.period.date_basis', 'visit')->assertJsonPath('meta.period.to', now()->addDays(30)->toDateString());
});

it('denies unassigned private documents media and destination mutations', function () {
    [$destination, , , , , $guid] = operationsFixture();
    $headers = bearer(makeUser(1));
    $this->getJson("/api/v1/bookings/{$guid}/documents", $headers)->assertNotFound();
    $this->getJson("/api/v1/admin/destinations/{$destination->guid}/image", $headers)->assertForbidden();
    $this->getJson("/api/v1/destinations/{$destination->guid}/quotas", $headers)->assertForbidden();
    $this->patchJson("/api/v1/destinations/{$destination->guid}", ['name' => 'Unauthorized'], $headers)->assertForbidden();
    $this->deleteJson("/api/v1/destinations/{$destination->guid}", [], $headers)->assertForbidden();
    $this->putJson("/api/v1/admin/destinations/{$destination->guid}/facilities", ['facility_guids' => []], $headers)->assertForbidden();
    $this->assertDatabaseHas('destinations', ['guid' => $destination->guid, 'is_active' => true]);
    $this->getJson("/api/v1/destinations/{$destination->guid}")->assertOk();
});

it('rejects idempotency key reuse for another booking and invalid claim inputs', function () {
    [$destination, $owner, $admin, , $super, $guid] = operationsFixture();
    $date = DB::table('destination_quotas')->where('destination_guid', $destination->guid)->value('visit_date');
    $second = app(BookingService::class)->create($owner, bookingPayload($destination, $date) + ['idempotency_key' => 'second-booking']);
    $headers = bearer($admin) + ['Idempotency-Key' => 'bound-key'];
    $this->postJson("/api/v1/admin/bookings/{$guid}/claim", [], bearer($admin))->assertUnprocessable();
    $this->postJson("/api/v1/admin/bookings/{$guid}/claim", [], $headers)->assertSuccessful();
    $this->postJson("/api/v1/admin/bookings/{$second->guid}/claim", [], $headers)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    $this->postJson("/api/v1/superadmin/bookings/{$guid}/reassign", ['admin_guid' => $admin->guid], bearer($super) + ['Idempotency-Key' => 'missing-reason'])->assertUnprocessable();
    $this->assertDatabaseMissing('booking_approval_claims', ['booking_guid' => $second->guid]);
});

it('closes claim when cancelled and refuses claims past the original deadline', function () {
    [, $owner, $admin, , , $guid] = operationsFixture();
    $this->postJson("/api/v1/admin/bookings/{$guid}/claim", [], bearer($admin) + ['Idempotency-Key' => 'to-cancel'])->assertSuccessful();
    $this->postJson("/api/v1/bookings/{$guid}/cancel", [], bearer($owner))->assertOk();
    $this->assertDatabaseHas('booking_approval_claims', ['booking_guid' => $guid, 'claimed_by_user_guid' => null]);
    $this->postJson("/api/v1/admin/bookings/{$guid}/claim", [], bearer($admin) + ['Idempotency-Key' => 'after-cancel'])->assertConflict()->assertJsonPath('code', 'booking_not_pending');
});

it('counts actual decision actors and ticket attendance without assignment or legacy duplication', function () {
    [$destination, $owner, $admin, $other, , $guid] = operationsFixture();
    $date = DB::table('destination_quotas')->where('destination_guid', $destination->guid)->value('visit_date');
    $second = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => 'metrics-second']);
    $this->travel(10)->minutes();
    app(BookingService::class)->decide($admin, $guid, true);
    $this->travel(20)->minutes();
    app(BookingService::class)->decide($other, $second->guid, false);
    $filters = '?from='.now()->startOfMonth()->toDateString().'&to='.now()->addDays(20)->toDateString();
    $this->getJson('/api/v1/admin/dashboard/approvals'.$filters, bearer($admin))->assertOk()
        ->assertJsonPath('data.team_operational_totals.approved_decisions', 1)
        ->assertJsonPath('data.team_operational_totals.rejected_decisions', 1)
        ->assertJsonPath('data.team_operational_totals.approval_rate_percent', 50)
        ->assertJsonPath('data.team_operational_totals.avg_response_seconds', 1200)
        ->assertJsonPath('data.team_operational_totals.sla_compliance_percent', 100)
        ->assertJsonPath('data.personal.approved_decisions', 1)
        ->assertJsonPath('data.personal.rejected_decisions', 0);
    $ticket = DB::table('booking_tickets')->where('booking_guid', $guid)->orderBy('sequence')->value('guid');
    DB::table('bookings')->whereIn('guid', [$guid, $second->guid])->update(['status' => 'confirmed']);
    DB::table('booking_tickets')->where('guid', $ticket)->update(['checked_in_at' => now()]);
    foreach ([$guid, $second->guid] as $bookingGuid) {
        DB::table('check_ins')->insert(['guid' => (string) Str::uuid(), 'booking_guid' => $bookingGuid, 'actor_guid' => $admin->guid, 'checked_in_at' => now()]);
    }
    DB::table('booking_tickets')->where('booking_guid', $second->guid)->delete();
    $this->getJson('/api/v1/admin/dashboard/visitors'.$filters, bearer($admin))->assertOk()
        ->assertJsonPath('data.metrics.checked_in_tickets', 3)
        ->assertJsonPath('data.metrics.no_show_count', null)
        ->assertJsonPath('data.metrics.reason', 'insufficient_verified_data');
    $this->getJson('/api/v1/admin/dashboard/destinations'.$filters, bearer($admin))->assertOk()->assertJsonCount(1, 'data');
});
