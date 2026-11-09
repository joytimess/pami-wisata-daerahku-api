<?php

use App\Jobs\DeliverBookingNotification;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\User;
use App\Services\BookingNotificationService;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
    assignTestDestination($admin, $destination);
    DB::table('destination_quotas')->insert(['guid' => (string) Str::uuid(), 'destination_guid' => $destination->guid, 'visit_date' => $date, 'capacity' => 6, 'booking_opens_at' => now()->subDay(), 'booking_closes_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $input = ['idempotency_key' => 'first', 'destination_guid' => $destination->guid, 'visit_date' => $date, 'quantity' => 1, 'visitor_name' => 'Visitor', 'visitor_email' => 'visitor@example.com', 'visitor_phone' => '+6281234567890'];
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
    $input = ['idempotency_key' => 'first', 'destination_guid' => $destination->guid, 'visit_date' => $date, 'quantity' => 1, 'visitor_name' => 'Visitor', 'visitor_email' => 'visitor@example.com', 'visitor_phone' => '+6281234567890'];
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

function receiptDelivery(array $user = [], string $type = 'booking.payment_receipt'): array
{
    $owner = User::factory()->create($user);
    $notification = (string) Str::uuid();
    DB::table('notifications')->insert(['guid' => $notification, 'user_guid' => $owner->guid, 'booking_guid' => null, 'event_key' => 'k:'.$notification,
        'type' => $type, 'title' => 'Pembayaran terverifikasi', 'body' => 'Isi', 'created_at' => now(), 'updated_at' => now()]);
    $delivery = (string) Str::uuid();
    DB::table('notification_deliveries')->insert(['guid' => $delivery, 'notification_guid' => $notification, 'channel' => 'email', 'target_key' => $owner->guid,
        'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp']);
    Mail::fake();

    return [$owner, $delivery];
}

it('audits each email delivery attempt and sends a payment receipt to a verified owner once', function () {
    [, $delivery] = receiptDelivery(['email_verified_at' => now()]);

    (new DeliverBookingNotification($delivery))->handle();
    (new DeliverBookingNotification($delivery))->handle();

    expect(DB::table('notification_deliveries')->where('guid', $delivery)->first())->status->toBe('sent')->attempts->toBe(1);
    $attempts = DB::table('notification_delivery_attempts')->where('delivery_guid', $delivery)->get();
    expect($attempts)->toHaveCount(1)->and($attempts[0]->outcome)->toBe('sent')->and($attempts[0]->submitted_at)->not->toBeNull()
        ->and($attempts[0]->attempt_number)->toBe(1)->and($attempts[0]->error_code)->toBeNull();
});

it('never emails a payment receipt to an unverified address', function (string $type) {
    [, $delivery] = receiptDelivery(['email_verified_at' => null], $type);

    (new DeliverBookingNotification($delivery))->handle();

    expect(DB::table('notification_deliveries')->where('guid', $delivery)->first())->status->toBe('cancelled')->last_error_code->toBe('email_unverified');
    expect(DB::table('notification_delivery_attempts')->where('delivery_guid', $delivery)->first())->outcome->toBe('cancelled')->submitted_at->toBeNull();
    Mail::assertNothingSent();
})->with(['booking.payment_receipt', 'booking.ticket_issued']);

it('records failed attempts with a bounded code and marks an abandoned claim as ambiguous instead of hiding it', function () {
    [, $delivery] = receiptDelivery(['email_verified_at' => now()]);
    config(['mail.default' => 'log', 'mail.mailers.log.transport' => 'log']);

    (new DeliverBookingNotification($delivery))->handle();

    expect(DB::table('notification_deliveries')->where('guid', $delivery)->first())->status->toBe('pending')->last_error_code->toBe('mail_unconfigured');
    expect(DB::table('notification_delivery_attempts')->where('delivery_guid', $delivery)->first())->outcome->toBe('failed')->error_code->toBe('mail_unconfigured');

    config(['mail.default' => 'smtp']);
    DB::table('notification_deliveries')->where('guid', $delivery)->update(['status' => 'processing', 'updated_at' => now()->subMinutes(16)]);
    DB::table('notification_delivery_attempts')->where('delivery_guid', $delivery)->update(['outcome' => 'submitting', 'error_code' => null, 'finished_at' => null]);

    (new DeliverBookingNotification($delivery))->handle();

    $attempts = DB::table('notification_delivery_attempts')->where('delivery_guid', $delivery)->orderBy('attempt_number')->get();
    expect($attempts)->toHaveCount(2)->and($attempts[0]->outcome)->toBe('ambiguous')->and($attempts[0]->error_code)->toBe('worker_lost')
        ->and($attempts[1]->outcome)->toBe('sent')->and($attempts[1]->attempt_number)->toBe(2);
});

it('stops retrying a delivery after the attempt cap instead of looping forever', function () {
    [, $delivery] = receiptDelivery(['email_verified_at' => now()]);
    foreach (range(1, 10) as $number) {
        DB::table('notification_delivery_attempts')->insert(['guid' => (string) Str::uuid(), 'delivery_guid' => $delivery, 'attempt_number' => $number,
            'outcome' => 'ambiguous', 'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    (new DeliverBookingNotification($delivery))->handle();

    expect(DB::table('notification_deliveries')->where('guid', $delivery)->first())->status->toBe('failed')->last_error_code->toBe('max_attempts_exceeded')->next_attempt_at->toBeNull();
    Mail::assertNothingSent();
});
