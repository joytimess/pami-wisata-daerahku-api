<?php

use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\DestinationQuota;
use App\Models\User;
use App\Services\BookingDocumentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('generates private documents once for confirmed bookings and restricts access to the owner', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $other = User::factory()->create();
    $category = DestinationCategory::create(['name' => 'Nature', 'slug' => 'nature']);
    $destination = Destination::create(['destination_category_guid' => $category->guid, 'name' => 'Park', 'location' => 'Town', 'description' => 'Park', 'ticket_price' => 0, 'currency' => 'IDR', 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta']);
    $quota = DestinationQuota::create(['destination_guid' => $destination->guid, 'visit_date' => '2027-01-01', 'capacity' => 6, 'booking_opens_at' => now(), 'booking_closes_at' => now()->addDay()]);
    $guid = (string) Str::uuid();
    DB::table('bookings')->insert(['guid' => $guid, 'user_guid' => $user->guid, 'destination_quota_guid' => $quota->guid, 'booking_number' => 'B-1', 'quantity' => 4, 'visitor_name' => 'Visitor', 'visitor_email' => 'v@example.com', 'visitor_phone' => '123', 'destination_name_snapshot' => 'Park', 'unit_price' => 0, 'total_amount' => 0, 'currency' => 'IDR', 'status' => 'pending_approval', 'approval_expires_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $service = app(BookingDocumentService::class);
    $service->generate($guid);
    expect(DB::table('invoices')->count())->toBe(0);
    DB::table('bookings')->where('guid', $guid)->update(['status' => 'confirmed']);
    $service->generate($guid);
    $service->generate($guid);
    expect(DB::table('invoices')->count())->toBe(1)->and(DB::table('tickets')->count())->toBe(1);
    $invoice = DB::table('invoices')->first();
    $ticket = DB::table('tickets')->first();
    expect(Storage::disk('local')->get($invoice->pdf_path))->toStartWith('%PDF');
    expect(Storage::disk('local')->get($ticket->pdf_path))->toStartWith('%PDF');
    $metadata = $service->metadata($user, $guid);
    expect((array) $metadata['ticket'])->not->toHaveKeys(['pdf_path', 'token_hash']);
    expect(fn () => $service->download($other, $guid, 'ticket'))->toThrow(ModelNotFoundException::class);
    expect($service->download($user, $guid, 'ticket')->getStatusCode())->toBe(200);
});
