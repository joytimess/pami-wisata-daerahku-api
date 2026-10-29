<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\FavoriteService;
use App\Services\QuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('does not lower quota below held or confirmed visitors', function () {
    $user = User::factory()->create();
    $category = DestinationCategory::create(['name' => 'Nature', 'slug' => 'nature']);
    $destination = Destination::create(['destination_category_guid' => $category->guid, 'name' => 'Park', 'location' => 'Town', 'description' => 'Park', 'ticket_price' => 1000, 'currency' => 'IDR', 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta']);
    $service = app(QuotaService::class);
    $input = ['visit_date' => '2027-01-01', 'capacity' => 6, 'booking_opens_at' => '2026-12-01T00:00:00Z', 'booking_closes_at' => '2026-12-31T00:00:00Z'];
    $quota = $service->upsert($destination, $input);
    DB::table('bookings')->insert(['guid' => (string) Str::uuid(), 'user_guid' => $user->guid, 'destination_quota_guid' => $quota->guid, 'booking_number' => 'B-1', 'quantity' => 4, 'visitor_name' => 'Visitor', 'visitor_email' => 'v@example.com', 'visitor_phone' => '123', 'destination_name_snapshot' => 'Park', 'unit_price' => 1000, 'total_amount' => 4000, 'currency' => 'IDR', 'status' => 'payment_review', 'approval_expires_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => $service->upsert($destination, array_replace($input, ['capacity' => 3])))->toThrow(ValidationException::class);
    expect($quota->fresh()->capacity)->toBe(6);
    expect($service->upsert($destination, array_replace($input, ['capacity' => 4]))->capacity)->toBe(4);
});

it('adds and removes favorites idempotently within the owner scope', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $category = DestinationCategory::create(['name' => 'Nature', 'slug' => 'nature']);
    $destination = Destination::create(['destination_category_guid' => $category->guid, 'name' => 'Park', 'location' => 'Town', 'description' => 'Park', 'ticket_price' => 0, 'currency' => 'IDR', 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta']);
    $service = app(FavoriteService::class);
    expect($service->add($user, $destination)->guid)->toBe($service->add($user, $destination)->guid);
    $service->add($other, $destination);
    $service->remove($user, $destination);
    $service->remove($user, $destination);
    expect($service->list($user, [])->total())->toBe(0);
    expect($service->list($other, [])->total())->toBe(1);
});

it('keeps the existing image and record when saving the replacement fails', function () {
    Storage::fake();
    Storage::put('destinations/old.jpg', 'original');
    $category = DestinationCategory::create(['name' => 'Nature', 'slug' => 'nature']);
    $destination = Destination::create(['destination_category_guid' => $category->guid, 'name' => 'Park', 'location' => 'Town', 'description' => 'Park', 'ticket_price' => 0, 'currency' => 'IDR', 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta', 'image_path' => 'destinations/old.jpg']);
    $image = Mockery::mock(UploadedFile::class);
    $image->shouldReceive('store')->once()->with('destinations')->andReturn(false);

    expect(fn () => app(CatalogService::class)->destinationSave(['name' => 'Changed', 'image' => $image], $destination))->toThrow(RuntimeException::class);
    expect($destination->fresh()->image_path)->toBe('destinations/old.jpg');
    expect($destination->fresh()->name)->toBe('Park');
    Storage::assertExists('destinations/old.jpg');
});

it('returns no inactive destination data when deleting an absent favorite repeatedly', function () {
    $user = User::factory()->create();
    $category = DestinationCategory::create(['name' => 'Nature', 'slug' => 'nature']);
    $destination = Destination::create(['destination_category_guid' => $category->guid, 'name' => 'Hidden park', 'location' => 'Town', 'description' => 'private description', 'ticket_price' => 0, 'currency' => 'IDR', 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta', 'is_active' => false]);
    $this->withoutMiddleware(AuthenticateApiToken::class)->actingAs($user);
    for ($i = 0; $i < 2; $i++) {
        $this->deleteJson('/api/v1/favorites/'.$destination->guid)
            ->assertOk()
            ->assertExactJson(['data' => ['message' => 'Favorite removed.']]);
    }
});
