<?php

use App\Models\DestinationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function locationFields(): array
{
    $category = DestinationCategory::create(['name' => 'Location nature', 'slug' => 'location-'.Str::random(8)]);

    return ['destination_category_guid' => $category->guid, 'name' => 'Pinned destination', 'location' => 'Yogyakarta', 'latitude' => -7.8012345, 'longitude' => 110.3645678, 'description' => 'Pin', 'ticket_price' => 0, 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta', 'images' => [UploadedFile::fake()->image('pin.png', 8, 8)]];
}

it('saves location with multipart gallery and preserves atomic conflict and replay behavior', function () {
    Storage::fake('local');
    $headers = bearer(makeUser(1)) + ['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'];
    $fields = locationFields();
    $created = $this->post('/api/v1/destinations', $fields, $headers)->assertCreated()
        ->assertJsonPath('data.latitude', -7.8012345)->assertJsonPath('data.longitude', 110.3645678)->json('data');
    $url = '/api/v1/destinations/'.$created['guid'];
    $headers['Idempotency-Key'] = (string) Str::uuid();
    $update = ['_method' => 'PATCH', 'location' => 'Alamat manual', 'latitude' => '-8.1234567', 'longitude' => '111.2345678', 'gallery_version' => 1, 'gallery_manifest' => json_encode([['image_guid' => $created['images'][0]['guid']]])];

    $saved = $this->post($url, $update, $headers)->assertOk()->assertJsonPath('data.gallery_version', 2)
        ->assertJsonPath('data.latitude', -8.1234567)->json('data');
    $this->post($url, $update, $headers)->assertOk()->assertJsonPath('data', $saved);
    $this->post($url, array_replace($update, ['latitude' => '-9.1']), $headers)
        ->assertConflict()->assertJsonPath('code', 'gallery_idempotency_conflict');
    $headers['Idempotency-Key'] = (string) Str::uuid();
    $this->post($url, array_replace($update, ['location' => 'Must not persist', 'latitude' => '-9.1']), $headers)
        ->assertConflict()->assertJsonPath('code', 'gallery_version_conflict');

    $this->assertDatabaseCount('destinations', 1);
    $this->assertDatabaseHas('destinations', ['guid' => $created['guid'], 'location' => 'Alamat manual', 'latitude' => -8.1234567, 'longitude' => 111.2345678, 'gallery_version' => 2]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.latitude', -8.1234567)->assertJsonPath('data.location', 'Alamat manual');
    $this->getJson('/api/v1/admin/destinations/'.$created['guid'], $headers)->assertOk()->assertJsonPath('data.longitude', 111.2345678);
});

it('rejects missing invalid or partial create coordinates and invalid addresses with 422', function (array $overrides, array $errors) {
    Storage::fake('local');
    $fields = array_replace(locationFields(), $overrides);

    $this->post('/api/v1/destinations', $fields, bearer(makeUser(1)) + ['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors($errors);

    $this->assertDatabaseCount('destinations', 0);
})->with([
    'missing pair' => [['latitude' => null, 'longitude' => null], ['latitude', 'longitude']],
    'partial pair' => [['longitude' => null], ['longitude']],
    'range' => [['latitude' => 90.1, 'longitude' => -180.1], ['latitude', 'longitude']],
    'nonfinite' => [['latitude' => 'NaN', 'longitude' => '1e9999'], ['latitude', 'longitude']],
    'empty address' => [['location' => '   '], ['location']],
    'long address' => [['location' => str_repeat('x', 256)], ['location']],
]);

it('keeps legacy coordinates null on metadata edits and preserves a pin on address-only edits', function () {
    [$destination] = seedDestination();
    $headers = bearer(makeUser(1));
    $url = '/api/v1/destinations/'.$destination->guid;

    $this->patchJson($url, ['description' => 'Updated legacy'], $headers)->assertOk()
        ->assertJsonPath('data.latitude', null)->assertJsonPath('data.longitude', null);
    $this->patchJson($url, ['latitude' => -7.8, 'longitude' => 110.3], $headers)->assertOk();
    $this->patchJson($url, ['location' => 'Manual address'], $headers)->assertOk()
        ->assertJsonPath('data.latitude', -7.8)->assertJsonPath('data.longitude', 110.3);
    $this->patchJson($url, ['latitude' => -8], $headers)->assertUnprocessable()->assertJsonValidationErrors('longitude');
    $this->patchJson($url, ['latitude' => null, 'longitude' => null], $headers)->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);
    $this->patchJson($url, ['latitude' => -8, 'longitude' => 111], bearer(makeUser()))->assertForbidden();

    $this->assertDatabaseHas('destinations', ['guid' => $destination->guid, 'location' => 'Manual address', 'latitude' => -7.8, 'longitude' => 110.3]);
});

it('authorizes reverse geocoding and validates coordinates before contacting the provider', function () {
    Http::preventStrayRequests();
    $url = '/api/v1/admin/geocoding/reverse?latitude=-7.8&longitude=110.3';
    $admin = makeUser(1);
    $headers = bearer($admin);

    $this->getJson($url)->assertUnauthorized();
    $this->getJson($url, bearer(makeUser()))->assertForbidden();
    $this->getJson('/api/v1/admin/geocoding/reverse?latitude=91', $headers)->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);
    $admin->forceFill(['status' => 'inactive'])->save();
    $this->getJson($url, $headers)->assertUnauthorized();

    Http::assertNothingSent();
});

it('caches reverse addresses and enforces one shared provider interval across admins', function () {
    $this->freezeTime();
    Http::preventStrayRequests();
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response(['display_name' => 'Alamat OSM'])]);
    $url = '/api/v1/admin/geocoding/reverse?latitude=-7.8&longitude=110.3';
    $first = bearer(makeUser(1));
    $second = bearer(makeUser(2));

    $this->getJson($url, $first)->assertOk()->assertJsonPath('data', ['latitude' => -7.8, 'longitude' => 110.3, 'formatted_address' => 'Alamat OSM', 'provider' => 'nominatim']);
    $this->getJson($url, $second)->assertOk()->assertJsonPath('data.formatted_address', 'Alamat OSM');
    $this->getJson('/api/v1/admin/geocoding/reverse?latitude=-8&longitude=111', $second)->assertTooManyRequests()->assertHeader('Retry-After', '2');
    $this->travel(2)->seconds();
    $this->getJson('/api/v1/admin/geocoding/reverse?latitude=-8&longitude=111', $second)->assertOk();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->hasHeader('User-Agent', config('geocoding.user_agent')) && $request['format'] === 'jsonv2');
    $this->assertDatabaseCount('destinations', 0);
});

it('returns manual fallback for no address without creating a destination', function () {
    Http::preventStrayRequests();
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response(['error' => 'Unable to geocode'], 404)]);

    $this->getJson('/api/v1/admin/geocoding/reverse?latitude=0&longitude=0', bearer(makeUser(1)))
        ->assertOk()->assertJsonPath('data.formatted_address', null)->assertJsonPath('data.latitude', 0);

    Http::assertSentCount(1);
    $this->assertDatabaseCount('destinations', 0);
});

it('backs off provider limits and failures without retrying upstream', function (int $status) {
    $this->freezeTime();
    Http::preventStrayRequests();
    Http::fake(['nominatim.openstreetmap.org/reverse*' => Http::response([], $status, ['Retry-After' => '120'])]);
    $headers = bearer(makeUser(1));
    $url = '/api/v1/admin/geocoding/reverse?latitude=-7.8&longitude=110.3';

    $this->getJson($url, $headers)->assertStatus($status)->assertHeader('Retry-After', '120');
    $this->travel(2)->seconds();
    $this->getJson($url, $headers)->assertTooManyRequests()->assertHeader('Retry-After', '118');

    Http::assertSentCount(1);
})->with([429, 503]);

it('fails closed when the shared provider lock is busy or cache is not shared', function () {
    Http::preventStrayRequests();
    $headers = bearer(makeUser(1));
    $url = '/api/v1/admin/geocoding/reverse?latitude=-7.8&longitude=110.3';
    $lock = Cache::store('database')->lock('geocoding:provider-lock', 15);
    $lock->get();

    $this->getJson($url, $headers)->assertTooManyRequests();
    $lock->release();
    config(['geocoding.cache_store' => 'array']);
    $this->getJson($url, $headers)->assertServiceUnavailable();

    Http::assertNothingSent();
});

it('maps provider connection and malformed response failures to manual fallback', function (string $failure, int $status) {
    Http::preventStrayRequests();
    Http::fake(['nominatim.openstreetmap.org/reverse*' => $failure === 'connection' ? Http::failedConnection() : Http::response(['display_name' => ['invalid']])]);

    $this->getJson('/api/v1/admin/geocoding/reverse?latitude=-7.8&longitude=110.3', bearer(makeUser(1)))
        ->assertStatus($status)->assertJsonPath('message', $failure === 'connection' ? 'Geocoding unavailable. Enter the address manually.' : 'Geocoding returned an invalid address. Enter the address manually.');

    Http::assertSentCount(1);
})->with(['connection' => ['connection', 503], 'malformed' => ['malformed', 502]]);
