<?php

use App\Services\BookingNotificationService;
use App\Services\BookingService;
use App\Services\QuotaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('normalizes international booking phones and fills only empty profiles', function ($original) {
    [$destination, $date] = seedDestination();
    $user = makeUser();
    DB::table('users')->where('guid', $user->guid)->update(['phone' => $original]);
    $headers = bearer($user) + ['Idempotency-Key' => 'phone-first'];
    $payload = array_replace(bookingPayload($destination, $date), ['visitor_phone' => '+62 896-5586-2781']);

    $guid = $this->postJson('/api/v1/bookings', $payload, $headers)->assertOk()->assertJsonPath('data.visitor_phone', '+6289655862781')->json('data.guid');
    $this->postJson('/api/v1/bookings', $payload, $headers)->assertOk()->assertJsonPath('data.guid', $guid);
    $this->assertDatabaseCount('bookings', 1);
    $this->assertDatabaseHas('users', ['guid' => $user->guid, 'phone' => '+6289655862781']);
    $this->getJson('/api/v1/auth/me', bearer($user))->assertOk()->assertJsonPath('data.phone', '+6289655862781');

    $this->patchJson('/api/v1/profile', ['phone' => '+14155552671'], bearer($user))->assertOk();
    $this->assertDatabaseHas('bookings', ['guid' => $guid, 'visitor_phone' => '+6289655862781']);
})->with([null, '', '   ', " \t\r\n "]);

it('preserves existing profile phone and ignores client identity fields', function () {
    [$destination, $date] = seedDestination();
    $user = makeUser();
    $other = makeUser();
    DB::table('users')->where('guid', $user->guid)->update(['phone' => 'legacy-profile']);
    $payload = array_replace(bookingPayload($destination, $date), ['visitor_phone' => '+1 415-555-2671', 'user_guid' => $other->guid, 'role' => 2, 'phone' => 'overwrite']);

    $this->postJson('/api/v1/bookings', $payload, bearer($user) + ['Idempotency-Key' => 'preserve'])->assertOk()->assertJsonPath('data.visitor_phone', '+14155552671');
    $this->assertDatabaseHas('users', ['guid' => $user->guid, 'phone' => 'legacy-profile', 'role' => 0]);
    $this->assertDatabaseHas('users', ['guid' => $other->guid, 'phone' => null]);
    $this->assertDatabaseHas('bookings', ['user_guid' => $user->guid, 'visitor_phone' => '+14155552671']);
});

it('rejects invalid booking phones with 422 and no persisted booking or profile write', function ($phone) {
    [$destination, $date] = seedDestination();
    $user = makeUser();
    $payload = array_replace(bookingPayload($destination, $date), ['visitor_phone' => $phone]);

    $this->postJson('/api/v1/bookings', $payload, bearer($user) + ['Idempotency-Key' => 'invalid-phone'])->assertUnprocessable()->assertJsonValidationErrors('visitor_phone');
    $this->assertDatabaseCount('bookings', 0);
    $this->assertDatabaseCount('idempotency_records', 0);
    $this->assertDatabaseHas('users', ['guid' => $user->guid, 'phone' => null]);
})->with(['081234567890', '+62', '+999123456789', '+62812abc34567890', '+12345678901234567890', '   ']);

it('does not fill phone when booking fails capacity validation', function () {
    [$destination, $date] = seedDestination(capacity: 0);
    $user = makeUser();

    $this->postJson('/api/v1/bookings', bookingPayload($destination, $date), bearer($user) + ['Idempotency-Key' => 'no-capacity'])->assertConflict()->assertJsonPath('code', 'insufficient_capacity');
    $this->assertDatabaseHas('users', ['guid' => $user->guid, 'phone' => null]);
    $this->assertDatabaseCount('bookings', 0);
});

it('returns every calendar date including leap years and empty months', function ($month, $count) {
    $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
    [$destination] = seedDestination();

    $response = $this->getJson("/api/v1/destinations/{$destination->guid}/availability?month={$month}", bearer(makeUser()))->assertOk()->assertJsonPath('data.month', $month)->assertJsonPath('data.requested_quantity', 1)->assertJsonPath('data.timezone', 'Asia/Jakarta')->assertJsonCount($count, 'data.dates');
    $dates = $response->json('data.dates');
    expect($dates[0]['date'])->toBe($month.'-01');
    expect($dates[$count - 1]['date'])->toBe($month.'-'.$count);
    expect($dates[0])->toMatchArray(['capacity' => null, 'remaining' => null, 'remaining_percent' => null, 'level' => 'none', 'status' => 'unconfigured', 'bookable' => false]);
    $this->travelBack();
})->with([['2027-02', 28], ['2028-02', 29], ['2026-11', 30], ['2027-01', 31]]);

it('rejects malformed calendar filters and hides unavailable destinations', function () {
    [$destination] = seedDestination();
    $headers = bearer(makeUser());
    $path = "/api/v1/destinations/{$destination->guid}/availability";
    $this->getJson($path.'?month=2026-13', $headers)->assertUnprocessable()->assertJsonValidationErrors('month');
    $this->getJson($path.'?month=2026-1', $headers)->assertUnprocessable()->assertJsonValidationErrors('month');
    $this->getJson($path.'?month=2026-11&quantity=0', $headers)->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->getJson($path.'?quantity=1', $headers)->assertUnprocessable()->assertJsonValidationErrors('month');
    $this->getJson($path.'?month=2026-11&from=2026-11-01', $headers)->assertUnprocessable()->assertJsonValidationErrors('month');
    $this->getJson($path.'?month=2026-11')->assertUnauthorized();
    $destination->update(['is_active' => false]);
    $this->getJson($path.'?month=2026-11', $headers)->assertNotFound();
});

it('preserves pre-normalization request fingerprints for existing idempotency retries', function () {
    [$destination, $date] = seedDestination();
    $user = makeUser();
    $payload = array_replace(bookingPayload($destination, $date), ['visitor_phone' => '+62 896-5586-2781']);
    $service = app(BookingService::class);
    $booking = $service->create($user, $payload + ['idempotency_key' => 'legacy-fingerprint']);
    $payload['notes'] = null;
    ksort($payload);
    $legacyHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    $this->assertDatabaseHas('idempotency_records', ['key' => 'legacy-fingerprint', 'request_hash' => $legacyHash]);

    expect($service->create($user, $payload + ['idempotency_key' => 'legacy-fingerprint'])->guid)->toBe($booking->guid);
    $this->assertDatabaseCount('bookings', 1);
});

it('uses held allocations and exact availability thresholds without releasing overdue holds', function () {
    $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
    [$destination, , $quota] = seedDestination(capacity: 100);
    $user = makeUser();
    $service = app(BookingService::class);
    $booking = $service->create($user, bookingPayload($destination, '2026-11-06', 40) + ['idempotency_key' => 'threshold']);
    DB::table('bookings')->where('guid', $booking->guid)->update(['approval_expires_at' => now()->subHour()]);
    $path = "/api/v1/destinations/{$destination->guid}/availability?month=2026-11";
    $headers = bearer($user);

    foreach ([[0, 100, 'high', 'available'], [39, 61, 'high', 'available'], [40, 60, 'medium', 'available'], [65, 35, 'medium', 'available'], [80, 20, 'low', 'available'], [100, 0, 'none', 'sold_out']] as [$allocated, $remaining, $level, $status]) {
        DB::table('bookings')->where('guid', $booking->guid)->update(['quantity' => $allocated]);
        $row = collect($this->getJson($path, $headers)->assertOk()->json('data.dates'))->firstWhere('date', '2026-11-06');
        expect($row)->toMatchArray(['remaining' => $remaining, 'remaining_percent' => $remaining, 'level' => $level, 'status' => $status, 'bookable' => $remaining > 0]);
    }
    DB::table('bookings')->where('guid', $booking->guid)->update(['quantity' => 80]);
    $row = collect($this->getJson($path.'&quantity=21', $headers)->assertOk()->json('data.dates'))->firstWhere('date', '2026-11-06');
    expect($row)->toMatchArray(['remaining' => 20, 'level' => 'low', 'status' => 'insufficient_quantity', 'bookable' => false]);
    DB::table('bookings')->where('guid', $booking->guid)->update(['status' => 'expired']);
    $row = collect($this->getJson($path, $headers)->assertOk()->json('data.dates'))->firstWhere('date', '2026-11-06');
    expect($row['remaining'])->toBe(100);
    DB::table('destination_quotas')->where('guid', $quota)->update(['capacity' => 0]);
    $row = collect($this->getJson($path, $headers)->assertOk()->json('data.dates'))->firstWhere('date', '2026-11-06');
    expect($row)->toMatchArray(['capacity' => 0, 'remaining_percent' => null, 'status' => 'unavailable', 'bookable' => false]);
    $this->travelBack();
});

it('keeps calendar query count constant and enforces destination-local cutoff', function () {
    $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
    [$destination, , $quota] = seedDestination();
    DB::table('destination_quotas')->where('guid', $quota)->update(['booking_opens_at' => now()->addDay()]);
    DB::enableQueryLog();
    DB::flushQueryLog();

    $calendar = app(QuotaService::class)->monthlyAvailability($destination, ['month' => '2026-11', 'quantity' => 1]);
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(2);
    DB::disableQueryLog();
    expect(collect($calendar['dates'])->firstWhere('date', '2026-11-06'))->toMatchArray(['level' => 'high', 'status' => 'closed', 'bookable' => false]);
    $this->travelBack();
});

it('restricts facility master changes to superadmins and preserves historical destination links', function () {
    [$destination] = seedDestination();
    [$other] = seedDestination();
    $super = bearer(makeUser(2));
    $admin = bearer(makeUser(1));
    $user = bearer(makeUser());
    $master = '/api/v1/superadmin/facilities';
    $assign = "/api/v1/admin/destinations/{$destination->guid}/facilities";
    $this->postJson($master, ['name' => 'Parkir'])->assertUnauthorized();
    foreach ([$admin, $user] as $headers) {
        $this->postJson($master, ['name' => 'Parkir'], $headers)->assertForbidden();
        $this->getJson($master, $headers)->assertForbidden();
    }
    $guid = $this->postJson($master, ['name' => '  Parkir  '], $super)->assertCreated()->assertJsonPath('data.name', 'Parkir')->assertJsonPath('data.icon_key', null)->json('data.guid');
    $this->postJson($master, ['name' => ' PARKIR '], $super)->assertUnprocessable();
    $this->postJson($master, ['name' => ' PARKIR ', 'slug' => 'different-slug'], $super)->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson($master, ['name' => 'Different Name', 'slug' => 'PARKIR'], $super)->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->patchJson($master.'/'.$guid, ['slug' => null], $super)->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->postJson($master, ['name' => 'Toilet', 'icon_key' => '<svg>'], $super)->assertUnprocessable()->assertJsonValidationErrors('icon_key');
    $this->getJson('/api/v1/facilities', $user)->assertForbidden();
    $this->getJson('/api/v1/facilities', $admin)->assertOk()->assertJsonCount(1, 'data');
    $this->putJson($assign, ['facility_guids' => [$guid]], $user)->assertForbidden();
    $this->putJson($assign, ['facility_guids' => [$guid]], $admin)->assertOk();
    $this->getJson("/api/v1/destinations/{$destination->guid}")->assertOk()->assertJsonPath('data.facilities.0.guid', $guid);
    $this->getJson("/api/v1/destinations/{$other->guid}")->assertOk()->assertJsonPath('data.facilities', []);

    $this->deleteJson($master.'/'.$guid, [], $admin)->assertForbidden();
    $this->patchJson($master.'/'.$guid, ['name' => 'Forbidden'], $user)->assertForbidden();
    $this->deleteJson($master.'/'.$guid, [], $super)->assertOk();
    $this->assertDatabaseHas('destination_facilities', ['destination_guid' => $destination->guid, 'facility_guid' => $guid]);
    $this->getJson("/api/v1/destinations/{$destination->guid}")->assertJsonPath('data.facilities', []);
    $this->getJson('/api/v1/facilities', $admin)->assertJsonCount(0, 'data');
    $this->getJson($master, $super)->assertJsonCount(1, 'data');
    $this->putJson($assign, ['facility_guids' => [$guid]], $admin)->assertUnprocessable();
    $this->patchJson($master.'/'.$guid, ['name' => 'Parkir Baru', 'is_active' => true], $super)->assertOk()->assertJsonPath('data.slug', 'parkir');
    $this->getJson("/api/v1/destinations/{$destination->guid}")->assertJsonPath('data.facilities.0.name', 'Parkir Baru');
    $this->assertDatabaseCount('destination_facilities', 1);
});

it('rejects invalid facility replacements atomically and clears only on explicit empty assignment', function () {
    [$destination] = seedDestination();
    $super = bearer(makeUser(2));
    $admin = bearer(makeUser(1));
    $guid = $this->postJson('/api/v1/superadmin/facilities', ['name' => 'Toilet'], $super)->assertCreated()->json('data.guid');
    $path = "/api/v1/admin/destinations/{$destination->guid}/facilities";
    $this->putJson($path, ['facility_guids' => [$guid]], $admin)->assertOk();

    foreach ([[$guid, $guid], [$guid, strtoupper($guid)], [$guid, (string) Str::uuid()], ['bad-guid']] as $guids) {
        $this->putJson($path, ['facility_guids' => $guids], $admin)->assertUnprocessable();
        $this->assertDatabaseCount('destination_facilities', 1);
        $this->assertDatabaseHas('destination_facilities', ['destination_guid' => $destination->guid, 'facility_guid' => $guid]);
    }
    $this->patchJson("/api/v1/destinations/{$destination->guid}", ['name' => 'Updated'], $admin)->assertOk();
    $this->assertDatabaseCount('destination_facilities', 1);
    $this->putJson($path, ['facility_guids' => []], $admin)->assertOk();
    $this->assertDatabaseCount('destination_facilities', 0);
});

it('does not expose inactive facilities through selector filters or accept malformed names', function () {
    $super = bearer(makeUser(2));
    $admin = bearer(makeUser(1));
    $path = '/api/v1/superadmin/facilities';
    foreach ([[], ['nested' => 'Name'], 123] as $name) {
        $this->postJson($path, ['name' => $name], $super)->assertUnprocessable()->assertJsonValidationErrors('name');
    }
    $guid = $this->postJson($path, ['name' => 'Inactive', 'is_active' => false], $super)->assertCreated()->json('data.guid');
    $this->getJson('/api/v1/facilities?status=all', $admin)->assertOk()->assertJsonCount(0, 'data');
    $this->assertDatabaseHas('facilities', ['guid' => $guid, 'is_active' => false]);
});

it('rolls back profile phone and booking when creation fails after insertion', function () {
    [$destination, $date] = seedDestination();
    $user = makeUser();
    $this->mock(BookingNotificationService::class, function ($mock) {
        $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Simulated notification persistence failure'));
    });

    expect(fn () => app(BookingService::class)->create($user, bookingPayload($destination, $date) + ['idempotency_key' => 'rollback']))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('bookings', 0);
    $this->assertDatabaseHas('users', ['guid' => $user->guid, 'phone' => null]);
});

it('keeps every held lifecycle status in monthly capacity until persistently released', function ($status, $remaining) {
    $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
    [$destination, $date] = seedDestination(capacity: 10);
    $booking = app(BookingService::class)->create(makeUser(), bookingPayload($destination, $date, 3) + ['idempotency_key' => 'status']);
    DB::table('bookings')->where('guid', $booking->guid)->update(['status' => $status, 'approval_expires_at' => now()->subDay()]);

    $calendar = app(QuotaService::class)->monthlyAvailability($destination, ['month' => '2026-11']);
    expect(collect($calendar['dates'])->firstWhere('date', $date)['remaining'])->toBe($remaining);
    $this->travelBack();
})->with([['pending_approval', 7], ['awaiting_payment', 7], ['payment_verification', 7], ['payment_review', 7], ['confirmed', 7], ['rejected', 10], ['cancelled', 10], ['expired', 10]]);

it('closes monthly dates exactly at the destination local booking cutoff', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 15:45:00', 'UTC'));
    [$destination, , $quota] = seedDestination();
    DB::table('destination_quotas')->where('guid', $quota)->update(['visit_date' => '2026-11-06', 'booking_closes_at' => '2026-11-05 17:00:00']);

    $row = collect(app(QuotaService::class)->monthlyAvailability($destination, ['month' => '2026-11'])['dates'])->firstWhere('date', '2026-11-06');
    expect($row)->toMatchArray(['remaining' => 10, 'level' => 'high', 'status' => 'closed', 'bookable' => false]);
    $this->travelBack();
});
