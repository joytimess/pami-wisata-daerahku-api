<?php

use App\Jobs\DeliverBookingNotification;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\User;
use App\Services\BookingNotificationService;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('records booking notifications and durable deliveries once and retries unconfigured channels', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    DB::table('users')->where('guid', $admin->guid)->update(['role' => 1]);
    $device = (string) Str::uuid();
    DB::table('device_tokens')->insert(['guid' => $device, 'user_guid' => $admin->guid, 'session_guid' => (string) Str::uuid(), 'token' => 'test-device', 'platform' => 'android', 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $category = DestinationCategory::create(['name' => 'Nature', 'slug' => 'nature']);
    $destination = Destination::create(['destination_category_guid' => $category->guid, 'name' => 'Park', 'location' => 'Town', 'description' => 'Park', 'ticket_price' => 0, 'currency' => 'IDR', 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta']);
    $date = now()->addDays(5)->toDateString();
    DB::table('destination_quotas')->insert(['guid' => (string) Str::uuid(), 'destination_guid' => $destination->guid, 'visit_date' => $date, 'capacity' => 6, 'booking_opens_at' => now()->subDay(), 'booking_closes_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $input = ['idempotency_key' => 'first', 'destination_guid' => $destination->guid, 'visit_date' => $date, 'quantity' => 1, 'visitor_name' => 'Visitor', 'visitor_email' => 'visitor@example.com', 'visitor_phone' => '123'];
    $service = app(BookingService::class);
    $booking = $service->create($user, $input);
    $service->create($user, $input);
    expect(DB::table('notifications')->count())->toBe(1);
    expect(DB::table('notification_deliveries')->count())->toBe(2);
    $admin->refresh();
    $service->decide($admin, $booking->guid, true);
    expect(DB::table('notifications')->where('user_guid', $user->guid)->where('type', 'booking.approved')->exists())->toBeTrue();
    $push = DB::table('notification_deliveries')->where('channel', 'fcm')->first();
    (new DeliverBookingNotification($push->guid))->handle();
    expect(DB::table('notification_deliveries')->where('guid', $push->guid)->value('last_error_code'))->toBe('fcm_unconfigured');
    expect(DB::table('notification_deliveries')->where('guid', $push->guid)->value('status'))->toBe('pending');
    DB::table('device_tokens')->where('guid', $device)->delete();
    DB::table('notification_deliveries')->where('guid', $push->guid)->update(['next_attempt_at' => now()]);
    (new DeliverBookingNotification($push->guid))->handle();
    expect(DB::table('notification_deliveries')->where('guid', $push->guid)->value('status'))->toBe('cancelled');
});

it('rolls back notifications with the transaction that recorded them', function () {
    $user = User::factory()->create();
    $category = DestinationCategory::create(['name' => 'Nature', 'slug' => 'nature']);
    $destination = Destination::create(['destination_category_guid' => $category->guid, 'name' => 'Park', 'location' => 'Town', 'description' => 'Park', 'ticket_price' => 0, 'currency' => 'IDR', 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta']);
    $date = now()->addDays(5)->toDateString();
    DB::table('destination_quotas')->insert(['guid' => (string) Str::uuid(), 'destination_guid' => $destination->guid, 'visit_date' => $date, 'capacity' => 6, 'booking_opens_at' => now()->subDay(), 'booking_closes_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $input = ['idempotency_key' => 'first', 'destination_guid' => $destination->guid, 'visit_date' => $date, 'quantity' => 1, 'visitor_name' => 'Visitor', 'visitor_email' => 'visitor@example.com', 'visitor_phone' => '123'];
    $booking = app(BookingService::class)->create($user, $input);
    try {
        DB::transaction(function () use ($booking, $user) {
            app(BookingNotificationService::class)->record($booking->guid, $user->guid, 'approved');
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect(DB::table('notifications')->where('booking_guid', $booking->guid)->count())->toBe(0);
    expect(DB::table('notification_deliveries')->count())->toBe(0);
});
