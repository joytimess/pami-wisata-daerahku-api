<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->startOfDay()->addHours(8));
});

afterEach(function () {
    $this->travelBack();
});

function postBooking($test, $user, array $payload, string $key = 'key-1')
{
    return $test->postJson('/api/v1/bookings', $payload, bearer($user) + ['Idempotency-Key' => $key]);
}

it('reports availability from real allocations without leaking bookers', function () {
    [$destination, $date] = seedDestination(capacity: 10);
    $user = makeUser();
    $other = makeUser();
    postBooking($this, $user, bookingPayload($destination, $date, 4))->assertOk();

    $response = $this->getJson("/api/v1/destinations/{$destination->guid}/availability?visit_date={$date}", bearer($other))->assertOk();
    $response->assertJsonPath('data.0.capacity', 10)->assertJsonPath('data.0.allocated', 4)
        ->assertJsonPath('data.0.remaining', 6)->assertJsonPath('data.0.status', 'open');
    expect($response->getContent())->not->toContain('visitor')->not->toContain($user->guid);

    $this->getJson("/api/v1/destinations/{$destination->guid}/availability", bearer($other))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/destinations/{$destination->guid}/availability")->assertUnauthorized();
});

it('derives availability status from window, lead time and remaining capacity', function () {
    [$destination, $date, $quota] = seedDestination(capacity: 2);
    $user = makeUser();
    $path = "/api/v1/destinations/{$destination->guid}/availability?visit_date={$date}";
    postBooking($this, $user, bookingPayload($destination, $date, 2))->assertOk();
    $this->getJson($path, bearer($user))->assertJsonPath('data.0.status', 'full')->assertJsonPath('data.0.remaining', 0);

    DB::table('destination_quotas')->where('guid', $quota)->update(['booking_opens_at' => now()->addHour()]);
    $this->getJson($path, bearer($user))->assertJsonPath('data.0.status', 'not_open_yet');
    DB::table('destination_quotas')->where('guid', $quota)->update(['booking_opens_at' => now()->subDay(), 'booking_closes_at' => now()->subHour()]);
    $this->getJson($path, bearer($user))->assertJsonPath('data.0.status', 'closed');

    [$near, $nearDate] = seedDestination(capacity: 5, days: 1);
    $this->getJson("/api/v1/destinations/{$near->guid}/availability?visit_date={$nearDate}", bearer($user))->assertJsonPath('data.0.status', 'too_close');

    $none = now()->addDays(30)->toDateString();
    $this->getJson("/api/v1/destinations/{$destination->guid}/availability?visit_date={$none}", bearer($user))
        ->assertJsonPath('data.0.status', 'unavailable')->assertJsonPath('data.0.remaining', 0);

    DB::table('destinations')->where('guid', $destination->guid)->update(['is_active' => false]);
    $this->getJson($path, bearer($user))->assertNotFound();
});

it('returns distinct structured codes for each booking conflict', function () {
    [$destination, $date, $quota] = seedDestination(capacity: 3);
    $user = makeUser();
    $codes = fn ($response) => $response->assertStatus(409)->json('code');

    expect($codes(postBooking($this, $user, bookingPayload($destination, now()->addDays(9)->toDateString()), 'k-a')))->toBe('quota_unavailable');
    expect($codes(postBooking($this, $user, bookingPayload($destination, $date, 4), 'k-b')))->toBe('insufficient_capacity');
    DB::table('destination_quotas')->where('guid', $quota)->update(['booking_closes_at' => now()->subMinute()]);
    expect($codes(postBooking($this, $user, bookingPayload($destination, $date, 1), 'k-c')))->toBe('booking_window_closed');
    DB::table('destination_quotas')->where('guid', $quota)->update(['booking_closes_at' => now()->addDay()]);

    [$near, $nearDate] = seedDestination(capacity: 5, days: 1);
    expect($codes(postBooking($this, $user, bookingPayload($near, $nearDate), 'k-d')))->toBe('visit_date_too_close');

    postBooking($this, $user, bookingPayload($destination, $date, 1), 'k-e')->assertOk();
    expect($codes(postBooking($this, $user, bookingPayload($destination, $date, 2), 'k-e')))->toBe('idempotency_conflict');
    expect(DB::table('bookings')->count())->toBe(1);
});

it('replays an identical request to the current booking state and never duplicates', function () {
    [$destination, $date] = seedDestination(capacity: 5);
    $user = makeUser();
    $admin = makeUser(1);
    $first = postBooking($this, $user, bookingPayload($destination, $date, 2))->assertOk()->assertJsonPath('data.status', 'pending_approval');
    $guid = $first->json('data.guid');
    $this->postJson("/api/v1/admin/bookings/{$guid}/approve", [], bearer($admin))->assertOk();

    postBooking($this, $user, bookingPayload($destination, $date, 2))->assertOk()
        ->assertJsonPath('data.guid', $guid)->assertJsonPath('data.status', 'confirmed');
    expect(DB::table('bookings')->count())->toBe(1);
});

it('enforces the seven-day retention explicitly instead of reopening an expired key', function () {
    [$destination, $date] = seedDestination(capacity: 5, days: 12);
    $user = makeUser();
    $guid = postBooking($this, $user, bookingPayload($destination, $date))->assertOk()->json('data.guid');

    $this->travel(7 * 24 * 60 + 1)->minutes();
    postBooking($this, $user, bookingPayload($destination, $date))->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_key_expired')->assertJsonPath('context.booking_guid', $guid);
    expect(DB::table('bookings')->count())->toBe(1);
    $this->getJson('/api/v1/booking-intents/key-1', bearer($user))->assertOk()
        ->assertJsonPath('data.state', 'completed')->assertJsonPath('data.key_expired', true)->assertJsonPath('data.replay_safe', false);
});

it('reconciles intents by user and key only', function () {
    [$destination, $date] = seedDestination(capacity: 5);
    $user = makeUser();
    $other = makeUser();
    $this->getJson('/api/v1/booking-intents/unknown-key', bearer($user))->assertOk()
        ->assertJsonPath('data.state', 'not_found')->assertJsonPath('data.replay_safe', true)->assertJsonPath('data.booking', null);

    $guid = postBooking($this, $user, bookingPayload($destination, $date))->assertOk()->json('data.guid');
    $this->getJson('/api/v1/booking-intents/key-1', bearer($user))->assertOk()
        ->assertJsonPath('data.state', 'completed')->assertJsonPath('data.booking.guid', $guid)->assertJsonPath('data.replay_safe', true);
    // Another user probing the same key learns nothing about this booking.
    $this->getJson('/api/v1/booking-intents/key-1', bearer($other))->assertOk()
        ->assertJsonPath('data.state', 'not_found')->assertJsonPath('data.booking', null);
    $this->getJson('/api/v1/booking-intents/key-1')->assertUnauthorized();
    $this->getJson('/api/v1/booking-intents/bad%20key', bearer($user))->assertUnprocessable();
});

it('abandons an unreconcilable intent so a late original request cannot create a booking', function () {
    [$destination, $date] = seedDestination(capacity: 5);
    $user = makeUser();
    $this->postJson('/api/v1/booking-intents/lost-key/abandon', [], bearer($user))->assertOk()->assertJsonPath('data.state', 'abandoned');
    $this->postJson('/api/v1/booking-intents/lost-key/abandon', [], bearer($user))->assertOk()->assertJsonPath('data.state', 'abandoned');
    postBooking($this, $user, bookingPayload($destination, $date), 'lost-key')->assertStatus(409)->assertJsonPath('code', 'idempotency_key_abandoned');
    expect(DB::table('bookings')->count())->toBe(0);

    $guid = postBooking($this, $user, bookingPayload($destination, $date), 'done-key')->assertOk()->json('data.guid');
    $this->postJson('/api/v1/booking-intents/done-key/abandon', [], bearer($user))->assertStatus(409)
        ->assertJsonPath('code', 'intent_already_completed')->assertJsonPath('context.booking_guid', $guid);
});

it('cancels only the owner pending booking and releases capacity exactly once', function () {
    [$destination, $date] = seedDestination(capacity: 5);
    $user = makeUser();
    $other = makeUser();
    $admin = makeUser(1);
    $guid = postBooking($this, $user, bookingPayload($destination, $date, 3))->assertOk()->assertJsonPath('data.cancellable', true)->json('data.guid');
    $path = "/api/v1/destinations/{$destination->guid}/availability?visit_date={$date}";

    $this->postJson("/api/v1/bookings/{$guid}/cancel", [], bearer($other))->assertNotFound();
    $this->postJson("/api/v1/bookings/{$guid}/cancel", [], bearer($admin))->assertNotFound();
    $this->postJson("/api/v1/bookings/{$guid}/cancel", [], bearer($user))->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellable', false);
    $this->getJson($path, bearer($user))->assertJsonPath('data.0.allocated', 0)->assertJsonPath('data.0.remaining', 5);
    $this->postJson("/api/v1/bookings/{$guid}/cancel", [], bearer($user))->assertStatus(409)->assertJsonPath('code', 'booking_already_cancelled');
    $this->getJson($path, bearer($user))->assertJsonPath('data.0.remaining', 5);
    expect(DB::table('booking_status_histories')->where('booking_guid', $guid)->where('new_status', 'cancelled')->count())->toBe(1);
    // A cancelled booking cannot then be decided by an admin.
    $this->postJson("/api/v1/admin/bookings/{$guid}/approve", [], bearer($admin))->assertStatus(409)->assertJsonPath('code', 'booking_not_pending');
});

it('refuses to cancel processed bookings because no payment contract exists', function () {
    [$destination, $date] = seedDestination(capacity: 5, price: 1000);
    $user = makeUser();
    $admin = makeUser(1);
    $guid = postBooking($this, $user, bookingPayload($destination, $date))->assertOk()->json('data.guid');
    $this->postJson("/api/v1/admin/bookings/{$guid}/approve", [], bearer($admin))->assertOk()->assertJsonPath('data.status', 'awaiting_payment');
    $this->postJson("/api/v1/bookings/{$guid}/cancel", [], bearer($user))->assertStatus(409)->assertJsonPath('code', 'booking_not_cancellable');
    expect(DB::table('bookings')->where('guid', $guid)->value('status'))->toBe('awaiting_payment');
});

it('isolates booking data between users and filters history by status', function () {
    [$destination, $date] = seedDestination(capacity: 20);
    $a = makeUser();
    $b = makeUser();
    $admin = makeUser(1);
    $guidA = postBooking($this, $a, bookingPayload($destination, $date), 'a-1')->assertOk()->json('data.guid');
    postBooking($this, $a, bookingPayload($destination, $date), 'a-2')->assertOk();
    postBooking($this, $b, bookingPayload($destination, $date), 'b-1')->assertOk();
    $this->postJson("/api/v1/admin/bookings/{$guidA}/reject", ['reason' => 'Full'], bearer($admin))->assertOk();

    $this->getJson('/api/v1/bookings', bearer($a))->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/bookings?status=rejected', bearer($a))->assertJsonCount(1, 'data')->assertJsonPath('data.0.guid', $guidA);
    $this->getJson('/api/v1/bookings?status=nonsense', bearer($a))->assertUnprocessable();
    $this->getJson("/api/v1/bookings/{$guidA}", bearer($b))->assertNotFound();
    $this->getJson("/api/v1/bookings/{$guidA}/documents", bearer($b))->assertNotFound();
});

it('lets admins list, filter, paginate and read any booking while users cannot', function () {
    [$destination, $date] = seedDestination(capacity: 30);
    $user = makeUser();
    $admin = makeUser(1);
    foreach (range(1, 5) as $i) {
        postBooking($this, $user, bookingPayload($destination, $date), "k-$i")->assertOk();
    }
    $guid = DB::table('bookings')->value('guid');

    $page = $this->getJson('/api/v1/admin/bookings?per_page=2', bearer($admin))->assertOk()->assertJsonCount(2, 'data');
    expect($page->json('meta.total'))->toBe(5)->and($page->json('meta.last_page'))->toBe(3);
    $this->getJson('/api/v1/admin/bookings?status=pending_approval&per_page=100', bearer($admin))->assertJsonCount(5, 'data');
    $this->getJson('/api/v1/admin/bookings?status=confirmed', bearer($admin))->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/admin/bookings/{$guid}", bearer($admin))->assertOk()->assertJsonPath('data.guid', $guid);
    $this->getJson('/api/v1/admin/bookings/'.Str::uuid(), bearer($admin))->assertNotFound();

    $this->getJson('/api/v1/admin/bookings', bearer($user))->assertForbidden();
    $this->getJson("/api/v1/admin/bookings/{$guid}", bearer($user))->assertForbidden();
    $this->postJson("/api/v1/admin/bookings/{$guid}/approve", [], bearer($user))->assertForbidden();
    $this->getJson('/api/v1/admin/bookings')->assertUnauthorized();
});

it('applies decisions once and reports distinct codes for stale decisions', function () {
    [$free, $date] = seedDestination(capacity: 10, price: 0);
    [$paid, $paidDate] = seedDestination(capacity: 10, price: 5000);
    $user = makeUser();
    $admin = makeUser(1);
    $freeGuid = postBooking($this, $user, bookingPayload($free, $date), 'f')->json('data.guid');
    $paidGuid = postBooking($this, $user, bookingPayload($paid, $paidDate, 2), 'p')->json('data.guid');
    $rejectGuid = postBooking($this, $user, bookingPayload($free, $date), 'r')->json('data.guid');

    $this->postJson("/api/v1/admin/bookings/{$freeGuid}/approve", [], bearer($admin))->assertOk()->assertJsonPath('data.status', 'confirmed');
    $this->postJson("/api/v1/admin/bookings/{$paidGuid}/approve", [], bearer($admin))->assertOk()
        ->assertJsonPath('data.status', 'awaiting_payment')->assertJsonPath('data.total_amount', 10000);
    $this->postJson("/api/v1/admin/bookings/{$rejectGuid}/reject", ['reason' => 'No'], bearer($admin))->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->postJson("/api/v1/admin/bookings/{$freeGuid}/reject", [], bearer($admin))->assertStatus(409)
        ->assertJsonPath('code', 'booking_not_pending')->assertJsonPath('context.status', 'confirmed');
    expect(DB::table('booking_status_histories')->where('booking_guid', $freeGuid)->count())->toBe(2);
    $this->postJson("/api/v1/admin/bookings/{$rejectGuid}/approve", ['reason' => str_repeat('x', 256)], bearer($admin))->assertUnprocessable();
});

it('reports a lapsed approval deadline with its own code', function () {
    [$destination, $date] = seedDestination(capacity: 10, days: 9);
    $user = makeUser();
    $admin = makeUser(1);
    $guid = postBooking($this, $user, bookingPayload($destination, $date))->json('data.guid');
    DB::table('destination_quotas')->update(['booking_closes_at' => now()->addDays(3)]);
    $this->travel(25)->hours();
    DB::table('destination_quotas')->update(['booking_closes_at' => now()->addDays(3)]);
    $this->postJson("/api/v1/admin/bookings/{$guid}/approve", [], bearer($admin))->assertStatus(409)->assertJsonPath('code', 'approval_deadline_passed');
});

it('revokes access immediately when role or status changes', function () {
    [$destination, $date] = seedDestination(capacity: 10);
    $super = makeUser(2);
    $admin = makeUser(1);
    $headers = bearer($admin);
    $this->getJson('/api/v1/admin/bookings', $headers)->assertOk();
    $this->patchJson("/api/v1/admin/accounts/{$admin->guid}", ['role' => 0], bearer($super))->assertOk();
    $this->getJson('/api/v1/admin/bookings', $headers)->assertUnauthorized();
    $fresh = bearer($admin->fresh());
    $this->getJson('/api/v1/admin/bookings', $fresh)->assertForbidden();

    $this->patchJson("/api/v1/admin/accounts/{$admin->guid}", ['status' => 'suspended'], bearer($super))->assertOk();
    $this->getJson('/api/v1/bookings', $fresh)->assertUnauthorized();
});

it('lets a key whose earlier attempt failed be reused and reports it as not found', function () {
    [$destination, $date] = seedDestination(capacity: 2);
    $user = makeUser();
    // A failed first attempt can leave a `processing` claim behind (the claim commits outside the booking transaction).
    DB::table('idempotency_records')->insert(['guid' => (string) Str::uuid(), 'user_guid' => $user->guid, 'operation' => 'booking.create', 'key' => 'retry-key', 'request_hash' => str_repeat('a', 64), 'status' => 'processing', 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
    $this->getJson('/api/v1/booking-intents/retry-key', bearer($user))->assertOk()->assertJsonPath('data.state', 'not_found')->assertJsonPath('data.replay_safe', true);

    postBooking($this, $user, bookingPayload($destination, $date, 5), 'retry-key')->assertStatus(409)->assertJsonPath('code', 'insufficient_capacity');
    postBooking($this, $user, bookingPayload($destination, $date, 1), 'retry-key')->assertOk();
    expect(DB::table('bookings')->count())->toBe(1);
    // Once completed, a different payload under the same key is a genuine conflict.
    postBooking($this, $user, bookingPayload($destination, $date, 2), 'retry-key')->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
});
