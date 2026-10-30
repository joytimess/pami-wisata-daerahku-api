<?php

use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\User;
use App\Services\BookingDocumentService;
use App\Services\BookingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Run after the E2E suite, with DB_DATABASE pointing to a dedicated *_e2e database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.default') !== 'mysql' || ! str_ends_with((string) config('database.connections.mysql.database'), '_e2e')) {
    throw new RuntimeException('Only a dedicated MySQL *_e2e database is allowed.');
}
$owner = User::factory()->create();
$other = User::factory()->create();
$admin = User::factory()->create(['role' => 1]);
$category = DestinationCategory::create(['name' => 'Phase6 Flutter', 'slug' => 'flutter-'.Str::random(12)]);
$destination = Destination::create(['destination_category_guid' => $category->guid, 'name' => 'Flutter HTTP fixture', 'location' => 'Test', 'description' => 'Isolated integration fixture', 'ticket_price' => 0, 'currency' => 'IDR', 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta']);
$date = now()->addDays(6)->toDateString();
$quota = (string) Str::uuid();
DB::table('destination_quotas')->insert(['guid' => $quota, 'destination_guid' => $destination->guid, 'visit_date' => $date, 'capacity' => 10, 'booking_opens_at' => now()->subDay(), 'booking_closes_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
$service = app(BookingService::class);
$booking = $service->create($owner, ['destination_guid' => $destination->guid, 'visit_date' => $date, 'quantity' => 1, 'visitor_name' => 'Flutter Fixture', 'visitor_email' => $owner->email, 'visitor_phone' => '081234', 'notes' => null, 'idempotency_key' => 'flutter-'.Str::uuid()]);
$service->decide($admin, $booking->guid, true);
// The fixture reaches visit day without changing the application's real clock.
DB::table('destination_quotas')->where('guid', $quota)->update(['visit_date' => now($destination->timezone)->toDateString()]);
app(BookingDocumentService::class)->generate($booking->guid);
echo json_encode(['owner_email' => $owner->email, 'other_email' => $other->email, 'password' => 'password', 'booking_guid' => $booking->guid, 'destination_guid' => $destination->guid], JSON_THROW_ON_ERROR);
