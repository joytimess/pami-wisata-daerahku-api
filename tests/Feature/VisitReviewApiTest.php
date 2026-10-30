<?php

use App\Services\BookingService;
use App\Services\VisitService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->utc()->startOfDay()->addHours(8));
});

afterEach(function () {
    $this->travelBack();
});

function phase6VisitFixture(string $timezone = 'Asia/Jakarta', int $price = 0): array
{
    [$destination, $date, $quota] = seedDestination(price: $price, destination: ['timezone' => $timezone]);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date) + ['idempotency_key' => 'visit-fixture']);
    app(BookingService::class)->decide(makeUser(1), $booking->guid, true);

    return [$owner, $destination, $booking->guid, $date, $quota];
}

it('checks in a confirmed owner on the destination visit date without changing booking or quota', function () {
    [$owner, , $guid, $date, $quota] = phase6VisitFixture();
    $this->travelTo(CarbonImmutable::parse($date.' 10:00:00', 'Asia/Jakarta'));
    $headers = bearer($owner);
    $history = DB::table('booking_status_histories')->where('booking_guid', $guid)->count();
    $this->getJson("/api/v1/bookings/{$guid}", $headers)->assertOk()
        ->assertJsonPath('data.can_check_in', true)->assertJsonPath('data.can_review', false);
    $this->postJson("/api/v1/bookings/{$guid}/check-in", [], $headers)->assertOk()
        ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.can_check_in', false)
        ->assertJsonPath('data.can_review', true);
    expect(DB::table('check_ins')->where('booking_guid', $guid)->value('actor_guid'))->toBe($owner->guid);
    expect(DB::table('bookings')->where('destination_quota_guid', $quota)->sum('quantity'))->toBe(1);
    expect(DB::table('booking_status_histories')->where('booking_guid', $guid)->count())->toBe($history);
    $this->postJson("/api/v1/bookings/{$guid}/check-in", [], $headers)->assertStatus(409)
        ->assertJsonPath('code', 'check_in_duplicate');
    expect(DB::table('check_ins')->count())->toBe(1);
});

it('rejects unauthenticated or unrelated check-in but permits Laravel staff', function () {
    [$owner, , $guid, $date] = phase6VisitFixture();
    $this->travelTo(CarbonImmutable::parse($date.' 10:00:00', 'Asia/Jakarta'));
    $path = "/api/v1/bookings/{$guid}/check-in";
    $this->postJson($path)->assertUnauthorized();
    $this->postJson($path, ['user_guid' => $owner->guid], bearer(makeUser()))->assertNotFound();
    $staff = makeUser(1);
    $this->postJson($path, [], bearer($staff))->assertOk();
    expect(DB::table('check_ins')->value('actor_guid'))->toBe($staff->guid);
    $this->getJson('/api/v1/profile/statistics', bearer($owner))->assertJsonPath('data.visits', 1);
    $this->getJson('/api/v1/profile/statistics', bearer($staff))->assertJsonPath('data.visits', 0);
});

it('does not treat unpaid or other lifecycle statuses as eligible', function (string $status) {
    [$owner, , $guid, $date] = phase6VisitFixture(price: 1000);
    $this->travelTo(CarbonImmutable::parse($date.' 10:00:00', 'Asia/Jakarta'));
    DB::table('bookings')->where('guid', $guid)->update(['status' => $status]);
    $headers = bearer($owner);
    $this->postJson("/api/v1/bookings/{$guid}/check-in", [], $headers)->assertStatus(409)
        ->assertJsonPath('code', 'check_in_not_eligible');
    $this->postJson("/api/v1/bookings/{$guid}/review", ['rating' => 5, 'comment' => 'Visit'], $headers)->assertStatus(409)
        ->assertJsonPath('code', 'review_not_eligible');
    expect(DB::table('check_ins')->count())->toBe(0)->and(DB::table('reviews')->count())->toBe(0);
})->with(['pending_approval', 'awaiting_payment', 'payment_verification', 'payment_review', 'rejected', 'expired', 'cancelled']);

it('uses destination timezone rather than UTC and rejects earlier or later dates', function () {
    [$owner, , $guid, $date] = phase6VisitFixture('Asia/Jayapura');
    $path = "/api/v1/bookings/{$guid}/check-in";
    $this->postJson($path, [], bearer($owner))->assertStatus(409)->assertJsonPath('code', 'check_in_wrong_date');
    $this->travelTo(CarbonImmutable::parse($date.' 00:05:00', 'Asia/Jayapura'));
    expect(now()->utc()->toDateString())->not->toBe($date);
    $this->postJson($path, [], bearer($owner))->assertOk();

    [$lateOwner, , $lateGuid, $lateDate] = phase6VisitFixture('Asia/Jayapura');
    $this->travelTo(CarbonImmutable::parse($lateDate.' 00:00:00', 'Asia/Jayapura')->addDay());
    $this->postJson("/api/v1/bookings/{$lateGuid}/check-in", [], bearer($lateOwner))->assertStatus(409)
        ->assertJsonPath('code', 'check_in_wrong_date');
});

it('requires a real checked-in visit and owner for reviews, including admin actors', function () {
    [$owner, , $guid, $date] = phase6VisitFixture();
    $path = "/api/v1/bookings/{$guid}/review";
    $fields = ['rating' => 4, 'comment' => 'Beautiful'];
    $this->postJson($path, $fields)->assertUnauthorized();
    $this->postJson($path, $fields, bearer($owner))->assertStatus(409)->assertJsonPath('code', 'review_not_eligible');
    $this->travelTo(CarbonImmutable::parse($date.' 10:00:00', 'Asia/Jakarta'));
    app(VisitService::class)->checkIn($owner, $guid);
    foreach ([makeUser(), makeUser(1), makeUser(2)] as $other) {
        $this->postJson($path, $fields + ['user_guid' => $owner->guid], bearer($other))->assertNotFound();
    }
    $this->postJson($path, $fields, bearer($owner))->assertOk()->assertJsonPath('data.rating', 4)
        ->assertJsonPath('data.comment', 'Beautiful')->assertJsonPath('data.booking_guid', $guid);
    $this->postJson($path, $fields, bearer($owner))->assertStatus(409)->assertJsonPath('code', 'review_duplicate');
    $this->getJson("/api/v1/bookings/{$guid}", bearer($owner))->assertJsonPath('data.can_review', false)
        ->assertJsonPath('data.review.rating', 4)->assertJsonPath('data.review.comment', 'Beautiful');
    expect(DB::table('reviews')->count())->toBe(1);
});

it('validates review rating and comment before writing', function (array $fields) {
    [$owner, , $guid] = phase6VisitFixture();
    $this->postJson("/api/v1/bookings/{$guid}/review", $fields, bearer($owner))->assertUnprocessable();
    expect(DB::table('reviews')->count())->toBe(0);
})->with([
    [['rating' => 0, 'comment' => 'Visit']], [['rating' => 6, 'comment' => 'Visit']],
    [['rating' => 1.5, 'comment' => 'Visit']], [['rating' => 5, 'comment' => '   ']],
    [['rating' => 5, 'comment' => str_repeat('x', 2001)]], [['comment' => 'Visit']],
]);

it('serves own review history and statistics without cross-account or public booking leaks', function () {
    [$owner, $destination, $guid, $date] = phase6VisitFixture();
    $this->travelTo(CarbonImmutable::parse($date.' 10:00:00', 'Asia/Jakarta'));
    $visits = app(VisitService::class);
    $visits->checkIn($owner, $guid);
    $visits->review($owner, $guid, ['rating' => 3, 'comment' => 'Good']);
    $this->getJson('/api/v1/profile/statistics', bearer($owner))->assertOk()
        ->assertJsonPath('data', ['bookings' => 1, 'reviews' => 1, 'visits' => 1]);
    $this->getJson('/api/v1/reviews?per_page=1', bearer($owner))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
    $other = makeUser();
    $this->getJson('/api/v1/reviews', bearer($other))->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/profile/statistics', bearer($other))->assertJsonPath('data.bookings', 0)
        ->assertJsonPath('data.reviews', 0)->assertJsonPath('data.visits', 0);
    $this->getJson('/api/v1/reviews')->assertUnauthorized();
    $this->getJson('/api/v1/profile/statistics')->assertUnauthorized();
    $public = $this->getJson("/api/v1/destinations/{$destination->guid}/reviews?per_page=1")->assertOk()
        ->assertJsonPath('data.0.rating', 3)->assertJsonPath('meta.total', 1);
    expect($public->getContent())->not->toContain('booking_guid')->not->toContain('user_guid')
        ->not->toContain('visitor_name')->not->toContain($owner->email)->not->toContain($guid);
    $this->getJson("/api/v1/destinations/{$destination->guid}/reviews?per_page=101")->assertUnprocessable();
    $this->getJson('/api/v1/reviews?page=-1', bearer($owner))->assertUnprocessable();
    $destination->update(['is_active' => false]);
    $this->getJson("/api/v1/destinations/{$destination->guid}/reviews")->assertNotFound();
    $this->getJson('/api/v1/reviews', bearer($owner))->assertJsonCount(1, 'data');
});

it('computes ratings from submitted reviews only on detail list and favorites', function () {
    [$owner, $destination, $guid, $date] = phase6VisitFixture();
    $second = makeUser();
    $secondBooking = app(BookingService::class)->create($second, bookingPayload($destination, $date) + ['idempotency_key' => 'second-rating']);
    app(BookingService::class)->decide(makeUser(1), $secondBooking->guid, true);
    $path = "/api/v1/destinations/{$destination->guid}";
    $this->getJson($path)->assertJsonPath('data.rating_average', null)->assertJsonPath('data.rating_count', 0);
    $this->travelTo(CarbonImmutable::parse($date.' 10:00:00', 'Asia/Jakarta'));
    app(VisitService::class)->checkIn($owner, $guid);
    app(VisitService::class)->review($owner, $guid, ['rating' => 4, 'comment' => 'Nice']);
    $this->getJson($path)->assertJsonPath('data.rating_average', 4)->assertJsonPath('data.rating_count', 1);
    $this->getJson('/api/v1/destinations')->assertJsonPath('data.0.rating_average', 4)->assertJsonPath('data.0.rating_count', 1);
    $this->putJson("/api/v1/favorites/{$destination->guid}", [], bearer($owner))->assertSuccessful()
        ->assertJsonPath('data.destination.rating_average', 4);
    $this->getJson('/api/v1/favorites', bearer($owner))->assertJsonPath('data.0.destination.rating_average', 4);
    app(VisitService::class)->checkIn($second, $secondBooking->guid);
    app(VisitService::class)->review($second, $secondBooking->guid, ['rating' => 1, 'comment' => 'Difficult']);
    $this->getJson($path)->assertJsonPath('data.rating_average', 2.5)->assertJsonPath('data.rating_count', 2);
    $this->getJson('/api/v1/favorites', bearer($owner))->assertJsonPath('data.0.destination.rating_average', 2.5)
        ->assertJsonPath('data.0.destination.rating_count', 2);
});

it('rolls back check-in if its transaction fails after insertion', function () {
    [$owner, , $guid, $date] = phase6VisitFixture();
    $this->travelTo(CarbonImmutable::parse($date.' 10:00:00', 'Asia/Jakarta'));
    $bookings = Mockery::mock(BookingService::class);
    $bookings->shouldReceive('show')->once()->andThrow(new RuntimeException('read failed'));
    expect(fn () => (new VisitService($bookings))->checkIn($owner, $guid))->toThrow(RuntimeException::class, 'read failed');
    expect(DB::table('check_ins')->where('booking_guid', $guid)->exists())->toBeFalse();
    app(VisitService::class)->checkIn($owner, $guid);
    expect(DB::table('check_ins')->where('booking_guid', $guid)->count())->toBe(1);
});
