<?php

use App\Exceptions\BookingException;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('holds capacity once on retry, rejects changed input and protects ownership', function () {
    $this->travelTo(now()->startOfDay());
    $user = User::factory()->create();
    $other = User::factory()->create();
    $category = DestinationCategory::create(['name' => 'Nature', 'slug' => 'nature']);
    $destination = Destination::create(['destination_category_guid' => $category->guid, 'name' => 'Park', 'location' => 'Town', 'description' => 'Park', 'ticket_price' => 1000, 'currency' => 'IDR', 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta']);
    $date = now()->addDays(4)->toDateString();
    DB::table('destination_quotas')->insert(['guid' => (string) Str::uuid(), 'destination_guid' => $destination->guid, 'visit_date' => $date, 'capacity' => 6, 'booking_opens_at' => now()->subDay(), 'booking_closes_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $input = ['idempotency_key' => 'first', 'destination_guid' => $destination->guid, 'visit_date' => $date, 'quantity' => 4, 'visitor_name' => 'Visitor', 'visitor_email' => 'visitor@example.com', 'visitor_phone' => '123'];
    $service = app(BookingService::class);
    $booking = $service->create($user, $input);
    expect($service->create($user, $input)->guid)->toBe($booking->guid);
    expect(DB::table('bookings')->count())->toBe(1);
    expect(fn () => $service->create($user, array_replace($input, ['quantity' => 2])))->toThrow(BookingException::class);
    expect(fn () => $service->create($other, array_replace($input, ['idempotency_key' => 'second'])))->toThrow(BookingException::class);
    expect(fn () => $service->show($other, $booking->guid))->toThrow(ModelNotFoundException::class);
    $this->travelBack();
});

it('rejects approval at the deadline and expires the hold', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    DB::table('users')->where('guid', $admin->guid)->update(['role' => 1]);
    $admin->refresh();
    $category = DestinationCategory::create(['name' => 'Nature', 'slug' => 'nature']);
    $destination = Destination::create(['destination_category_guid' => $category->guid, 'name' => 'Park', 'location' => 'Town', 'description' => 'Park', 'ticket_price' => 1000, 'currency' => 'IDR', 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta']);
    $date = now()->addDays(5)->toDateString();
    DB::table('destination_quotas')->insert(['guid' => (string) Str::uuid(), 'destination_guid' => $destination->guid, 'visit_date' => $date, 'capacity' => 6, 'booking_opens_at' => now()->subDay(), 'booking_closes_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $service = app(BookingService::class);
    $input = ['idempotency_key' => 'first', 'destination_guid' => $destination->guid, 'visit_date' => $date, 'quantity' => 1, 'visitor_name' => 'Visitor', 'visitor_email' => 'visitor@example.com', 'visitor_phone' => '123'];
    $booking = $service->create($user, $input);
    $this->travel(24)->hours();
    expect(fn () => $service->decide($admin, $booking->guid, true))->toThrow(BookingException::class);
    $service->expireDue();
    expect($service->show($user, $booking->guid)->status)->toBe('expired');
    $this->travelBack();
});
