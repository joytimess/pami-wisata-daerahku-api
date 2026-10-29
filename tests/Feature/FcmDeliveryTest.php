<?php

use App\Jobs\DeliverBookingNotification;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;

uses(RefreshDatabase::class);

function fcmDeliveryFixture(): array
{
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    DB::table('users')->where('guid', $admin->guid)->update(['role' => 1]);
    $device = (string) Str::uuid();
    DB::table('device_tokens')->insert(['guid' => $device, 'user_guid' => $admin->guid, 'session_guid' => (string) Str::uuid(), 'token' => 'test-device', 'platform' => 'android', 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $category = DestinationCategory::create(['name' => 'Nature', 'slug' => 'nature']);
    $destination = Destination::create(['destination_category_guid' => $category->guid, 'name' => 'Park', 'location' => 'Town', 'description' => 'Park', 'ticket_price' => 0, 'currency' => 'IDR', 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta']);
    $date = now()->addDays(5)->toDateString();
    DB::table('destination_quotas')->insert(['guid' => (string) Str::uuid(), 'destination_guid' => $destination->guid, 'visit_date' => $date, 'capacity' => 6, 'booking_opens_at' => now()->subDay(), 'booking_closes_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $booking = app(BookingService::class)->create($owner, ['idempotency_key' => 'fcm', 'destination_guid' => $destination->guid, 'visit_date' => $date, 'quantity' => 1, 'visitor_name' => 'Visitor', 'visitor_email' => 'visitor@example.com', 'visitor_phone' => '123']);

    return [$booking, DB::table('notification_deliveries')->where('channel', 'fcm')->first(), $device];
}

it('sends only notification and booking identifiers to the device', function () {
    [$booking, $delivery] = fcmDeliveryFixture();
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->once()->with(Mockery::on(function (CloudMessage $message) use ($booking, $delivery) {
        $content = $message->jsonSerialize();

        return $content['token'] === 'test-device' && $content['data'] === ['notification_guid' => $delivery->notification_guid, 'booking_guid' => $booking->guid];
    }))->andReturn(['name' => 'accepted']);
    app()->instance(Messaging::class, $messaging);
    (new DeliverBookingNotification($delivery->guid))->handle();
    expect(DB::table('notification_deliveries')->where('guid', $delivery->guid)->value('status'))->toBe('sent');
});

it('removes the token confirmed invalid', function () {
    [, $delivery, $device] = fcmDeliveryFixture();
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->once()->andThrow(NotFound::becauseTokenNotFound('test-device'));
    app()->instance(Messaging::class, $messaging);
    (new DeliverBookingNotification($delivery->guid))->handle();
    expect(DB::table('device_tokens')->where('guid', $device)->exists())->toBeFalse();
    expect(DB::table('notification_deliveries')->where('guid', $delivery->guid)->value('status'))->toBe('cancelled');
});

it('keeps the device and retries when the provider fails', function () {
    [, $delivery, $device] = fcmDeliveryFixture();
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->once()->andThrow(new RuntimeException('provider unavailable'));
    app()->instance(Messaging::class, $messaging);
    (new DeliverBookingNotification($delivery->guid))->handle();
    expect(DB::table('device_tokens')->where('guid', $device)->exists())->toBeTrue();
    expect(DB::table('notification_deliveries')->where('guid', $delivery->guid)->value('status'))->toBe('pending');
});
