<?php

use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\DestinationImage;
use App\Models\User;
use App\Services\DestinationGalleryService;
use App\Services\DestinationImageProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function galleryFields(): array
{
    $category = DestinationCategory::create(['name' => 'Gallery nature', 'slug' => 'gallery-'.Str::random(8)]);

    return ['destination_category_guid' => $category->guid, 'name' => 'Gallery park', 'location' => 'Town', 'description' => 'Gallery description', 'ticket_price' => 1000, 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta'];
}

function galleryHeaders(User $user, ?string $key = null): array
{
    return bearer($user) + ['Accept' => 'application/json', 'Idempotency-Key' => $key ?? (string) Str::uuid()];
}

function galleryUpload(int $count = 1): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("photo-$i.png", 32, 24), range(1, $count));
}

it('creates optimized ordered galleries at the allowed count boundaries', function (int $count) {
    Storage::fake('local');
    $admin = makeUser(1);

    $response = $this->post('/api/v1/destinations', galleryFields() + ['images' => galleryUpload($count)], galleryHeaders($admin))
        ->assertCreated()->assertJsonCount($count, 'data.images')->assertJsonPath('data.gallery_version', 1)->assertJsonPath('data.images.0.is_cover', true);

    $images = DestinationImage::where('destination_guid', $response->json('data.guid'))->orderBy('sequence')->get();
    expect($images->pluck('sequence')->all())->toBe(range(1, $count));
    expect($response->json('data.cover_image_url'))->toBe($response->json('data.images.0.urls.display'));
    foreach ($images as $image) {
        foreach (['thumbnail_path', 'display_path', 'fullscreen_path'] as $field) {
            Storage::disk('local')->assertExists($image->$field);
            $info = getimagesizefromstring(Storage::disk('local')->get($image->$field));
            expect($info[0])->toBe(32)->and($info[1])->toBe(24);
        }
        expect($image->mime_type)->toBe('image/webp');
    }
    $this->assertDatabaseCount('destination_image_cleanups', 0);
})->with([1, 10]);

it('rejects missing and excessive galleries with 422 without creating destinations', function (int $count) {
    Storage::fake('local');
    $files = $count ? ['images' => galleryUpload($count)] : [];

    $this->post('/api/v1/destinations', galleryFields() + $files, galleryHeaders(makeUser(1)))
        ->assertUnprocessable()->assertJsonValidationErrors('images');

    $this->assertDatabaseCount('destinations', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([0, 11]);

it('rejects invalid image content extension size and dimensions with 422', function (string $case) {
    Storage::fake('local');
    $file = match ($case) {
        'corrupt' => UploadedFile::fake()->createWithContent('broken.png', "\x89PNG\r\n\x1a\ninvalid"),
        'spoof' => UploadedFile::fake()->createWithContent('script.jpg', '<?php echo "unsafe";'),
        'extension' => UploadedFile::fake()->image('image.gif', 20, 20),
        'size' => UploadedFile::fake()->image('large.jpg')->size(5121),
        'dimensions' => UploadedFile::fake()->image('wide.png', 12001, 1),
    };

    $this->post('/api/v1/destinations', galleryFields() + ['images' => [$file]], galleryHeaders(makeUser(1)))->assertUnprocessable();

    $this->assertDatabaseCount('destinations', 0);
    $this->assertDatabaseCount('destination_images', 0);
})->with(['corrupt', 'spoof', 'extension', 'size', 'dimensions']);

it('accepts JPEG PNG and WebP and generates bounded aspect preserving variants', function () {
    Storage::fake('local');
    $admin = makeUser(1);
    $files = [UploadedFile::fake()->image('photo.jpg', 3000, 1500), UploadedFile::fake()->image('photo.png', 30, 20), UploadedFile::fake()->image('photo.webp', 30, 20)];

    $data = $this->post('/api/v1/destinations', galleryFields() + ['images' => $files], galleryHeaders($admin))->assertCreated()->assertJsonCount(3, 'data.images')->json('data');

    $image = DestinationImage::findOrFail($data['images'][0]['guid']);
    foreach (['thumbnail_path' => [480, 240], 'display_path' => [1280, 640], 'fullscreen_path' => [2048, 1024]] as $field => $expected) {
        $info = getimagesizefromstring(Storage::disk('local')->get($image->$field));
        expect([$info[0], $info[1]])->toBe($expected);
    }
    expect([$image->width, $image->height])->toBe([2048, 1024]);
});

it('requires the operation key and update version even on the legacy upload route', function () {
    Storage::fake('local');
    $admin = makeUser(1);
    [$destination] = seedDestination();
    $url = '/api/v1/destinations/'.$destination->guid.'/image';

    $this->post($url, ['image' => UploadedFile::fake()->image('photo.png')], bearer($admin) + ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['gallery_version', 'operation_key']);
    $this->post($url, ['image' => UploadedFile::fake()->image('photo.png'), 'gallery_version' => 0], bearer($admin) + ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('operation_key');

    $this->assertDatabaseCount('destination_images', 0);
});

it('replays a completed save without requiring image processing or writable storage', function () {
    Storage::fake('local');
    $actor = makeUser(1);
    $input = galleryFields() + ['images' => galleryUpload(), 'operation_key' => (string) Str::uuid()];
    $saved = app(DestinationGalleryService::class)->save($input, $actor);
    $processor = Mockery::mock(DestinationImageProcessor::class);
    $processor->shouldNotReceive('process');

    $replayed = (new DestinationGalleryService($processor))->save($input, $actor);

    expect($replayed)->toBe(json_decode(json_encode($saved, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    $this->assertDatabaseCount('destinations', 1);
    $this->assertDatabaseCount('destination_images', 1);
});

it('requires authentication and administrative role for gallery mutations', function () {
    Storage::fake('local');
    $fields = galleryFields();

    $this->post('/api/v1/destinations', $fields + ['images' => galleryUpload()], ['Accept' => 'application/json'])->assertUnauthorized();
    $this->post('/api/v1/destinations', $fields + ['images' => galleryUpload()], galleryHeaders(makeUser()))->assertForbidden();
    $this->assertDatabaseCount('destinations', 0);
});

it('commits metadata add delete reorder and cover together and replays the same result', function () {
    Storage::fake('local');
    $admin = makeUser(1);
    $first = $this->post('/api/v1/destinations', galleryFields() + ['images' => galleryUpload(3)], galleryHeaders($admin))->assertCreated()->json('data');
    $removed = DestinationImage::findOrFail($first['images'][1]['guid']);
    $payload = ['_method' => 'PATCH', 'name' => 'Changed together', 'gallery_version' => 1, 'gallery_manifest' => json_encode([['image_guid' => $first['images'][2]['guid']], ['upload_key' => 'new-a'], ['image_guid' => $first['images'][0]['guid']]]), 'new_images' => ['new-a' => UploadedFile::fake()->image('new.png', 40, 30)]];
    $headers = galleryHeaders($admin);
    $url = '/api/v1/destinations/'.$first['guid'];

    $saved = $this->post($url, $payload, $headers)->assertOk()->assertJsonPath('data.name', 'Changed together')->assertJsonPath('data.gallery_version', 2)->assertJsonPath('data.images.0.guid', $first['images'][2]['guid'])->json('data');
    $this->post($url, $payload, $headers)->assertOk()->assertJsonPath('data', $saved);

    expect(DestinationImage::where('destination_guid', $first['guid'])->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2, 3]);
    $this->assertDatabaseMissing('destination_images', ['guid' => $removed->guid]);
    $this->assertDatabaseHas('destinations', ['guid' => $first['guid'], 'name' => 'Changed together', 'gallery_version' => 2]);
    Storage::disk('local')->assertExists($removed->display_path);
    $this->getJson($url)->assertOk()->assertJsonPath('data.images.0.guid', $first['images'][2]['guid']);
    $this->post($url, array_replace($payload, ['name' => 'Different']), $headers)->assertConflict()->assertJsonPath('code', 'gallery_idempotency_conflict');
});

it('rejects stale versions with 409 without changing metadata or gallery', function () {
    Storage::fake('local');
    $admin = makeUser(1);
    $data = $this->post('/api/v1/destinations', galleryFields() + ['images' => galleryUpload()], galleryHeaders($admin))->assertCreated()->json('data');

    $this->post('/api/v1/destinations/'.$data['guid'], ['_method' => 'PATCH', 'name' => 'Lost update', 'gallery_version' => 0, 'gallery_manifest' => json_encode([['image_guid' => $data['images'][0]['guid']]])], galleryHeaders($admin))->assertConflict()->assertJsonPath('code', 'gallery_version_conflict');

    $this->assertDatabaseHas('destinations', ['guid' => $data['guid'], 'name' => 'Gallery park', 'gallery_version' => 1]);
    $this->assertDatabaseCount('destination_images', 1);
});

it('retains contiguous sequence when a gallery is saved without reordering', function () {
    Storage::fake('local');
    $admin = makeUser(1);
    $data = $this->post('/api/v1/destinations', galleryFields() + ['images' => galleryUpload(3)], galleryHeaders($admin))->assertCreated()->json('data');
    $manifest = array_map(fn ($image) => ['image_guid' => $image['guid']], $data['images']);

    $this->post('/api/v1/destinations/'.$data['guid'], ['_method' => 'PATCH', 'gallery_version' => 1, 'gallery_manifest' => json_encode($manifest)], galleryHeaders($admin))->assertOk()->assertJsonPath('data.images.0.sequence', 1)->assertJsonPath('data.images.1.sequence', 2)->assertJsonPath('data.images.2.sequence', 3);

    expect(DestinationImage::where('destination_guid', $data['guid'])->orderBy('sequence')->pluck('guid')->all())->toBe(array_column($data['images'], 'guid'));
});

it('rejects foreign duplicate empty and mismatched manifests with 422', function (string $case) {
    Storage::fake('local');
    $admin = makeUser(1);
    $data = $this->post('/api/v1/destinations', galleryFields() + ['images' => galleryUpload()], galleryHeaders($admin))->assertCreated()->json('data');
    $own = ['image_guid' => $data['images'][0]['guid']];
    $manifest = match ($case) {
        'foreign' => [['image_guid' => (string) Str::uuid()]],
        'duplicate' => [$own, $own],
        'empty' => [],
        'missing-upload' => [['upload_key' => 'missing']],
        'orphan-upload' => [$own],
        'both-keys' => [$own + ['upload_key' => 'also']],
    };
    $payload = ['_method' => 'PATCH', 'name' => 'Must rollback', 'gallery_version' => 1, 'gallery_manifest' => json_encode($manifest)];
    if ($case === 'orphan-upload') {
        $payload['new_images'] = ['orphan' => UploadedFile::fake()->image('orphan.png')];
    }

    $this->post('/api/v1/destinations/'.$data['guid'], $payload, galleryHeaders($admin))->assertUnprocessable();

    $this->assertDatabaseHas('destinations', ['guid' => $data['guid'], 'name' => 'Gallery park', 'gallery_version' => 1]);
    $this->assertDatabaseCount('destination_images', 1);
})->with(['foreign', 'duplicate', 'empty', 'missing-upload', 'orphan-upload', 'both-keys']);

it('rejects malformed gallery upload and manifest shapes with 422 instead of crashing', function (string $case) {
    Storage::fake('local');
    [$destination] = seedDestination();
    $payload = ['_method' => 'PATCH', 'gallery_version' => 0, 'gallery_manifest' => json_encode([['upload_key' => 'new-a']])];
    $error = 'gallery_manifest.0';
    if ($case === 'scalar-file') {
        $payload['new_images'] = UploadedFile::fake()->image('photo.png');
        $error = 'new_images';
    } elseif ($case === 'scalar-upload') {
        $payload['new_images'] = 'invalid';
        $error = 'new_images';
    } else {
        $payload['gallery_manifest'] = json_encode([match ($case) {
            'string-entry' => 'invalid',
            'null-entry' => null,
            'integer-entry' => 12,
        }]);
    }

    $this->post('/api/v1/destinations/'.$destination->guid, $payload, galleryHeaders(makeUser(1)))->assertUnprocessable()->assertJsonValidationErrors($error);

    $this->assertDatabaseCount('destination_images', 0);
    $this->assertDatabaseCount('destination_gallery_operations', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['scalar-file', 'scalar-upload', 'string-entry', 'null-entry', 'integer-entry']);

it('serves variants only in their destination and active visibility scope', function () {
    Storage::fake('local');
    $admin = makeUser(2);
    $data = $this->post('/api/v1/destinations', galleryFields() + ['images' => galleryUpload()], galleryHeaders($admin))->assertCreated()->json('data');
    [$other] = seedDestination();
    $image = $data['images'][0];

    $this->get('/api/v1/'.$image['urls']['thumbnail'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get('/api/v1/destinations/'.$other->guid.'/images/'.$image['guid'].'/display')->assertNotFound();
    Destination::findOrFail($data['guid'])->update(['is_active' => false]);
    $this->get('/api/v1/'.$image['urls']['display'])->assertNotFound();
    $adminUrl = '/api/v1/admin/destinations/'.$data['guid'].'/images/'.$image['guid'].'/display';
    $this->get($adminUrl)->assertUnauthorized();
    $this->get($adminUrl, bearer(makeUser()))->assertForbidden();
    $this->get($adminUrl, bearer($admin))->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=86400, private');
});

it('backfills legacy images idempotently and never removes the original', function () {
    Storage::fake('local');
    $legacy = UploadedFile::fake()->image('legacy.png', 80, 60);
    Storage::disk('local')->put('destinations/legacy.png', file_get_contents($legacy->getRealPath()));
    [$destination] = seedDestination(destination: ['image_path' => 'destinations/legacy.png']);
    [$empty] = seedDestination();

    Artisan::call('destinations:backfill-images');
    Artisan::call('destinations:backfill-images');

    expect($destination->images()->count())->toBe(1);
    expect($destination->images()->first()->sequence)->toBe(1);
    expect($empty->images()->count())->toBe(0);
    Storage::disk('local')->assertExists('destinations/legacy.png');
    $this->getJson('/api/v1/destinations/'.$empty->guid)->assertOk()->assertJsonPath('data.images', [])->assertJsonPath('data.cover_image_url', null);
});

it('defers deleted image cleanup until commit and makes cleanup repeatable', function () {
    Storage::fake('local');
    $admin = makeUser(1);
    $data = $this->post('/api/v1/destinations', galleryFields() + ['images' => galleryUpload(2)], galleryHeaders($admin))->assertCreated()->json('data');
    $removed = DestinationImage::findOrFail($data['images'][1]['guid']);
    $retained = DestinationImage::findOrFail($data['images'][0]['guid']);

    $this->post('/api/v1/destinations/'.$data['guid'], ['_method' => 'PATCH', 'gallery_version' => 1, 'gallery_manifest' => json_encode([['image_guid' => $retained->guid]])], galleryHeaders($admin))->assertOk();
    Storage::disk('local')->assertExists($removed->display_path);
    Artisan::call('destinations:cleanup-images');
    Artisan::call('destinations:cleanup-images');

    Storage::disk('local')->assertMissing($removed->display_path);
    Storage::disk('local')->assertExists($retained->display_path);
    $this->assertDatabaseCount('destination_image_cleanups', 0);
});

it('rolls back metadata and gallery when database persistence fails after staging', function () {
    Storage::fake('local');
    $admin = makeUser(1);
    [$destination] = seedDestination();
    $name = $destination->name;
    DB::statement("CREATE TRIGGER gallery_failure BEFORE INSERT ON destination_images BEGIN SELECT RAISE(ABORT, 'gallery storage unavailable'); END");

    try {
        app(DestinationGalleryService::class)->save(['name' => 'Must not persist', 'gallery_version' => 0, 'gallery_manifest' => [['upload_key' => 'new']], 'new_images' => ['new' => UploadedFile::fake()->image('new.png')], 'operation_key' => (string) Str::uuid()], $admin, $destination);
        $this->fail('The database failure must propagate.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('gallery storage unavailable');
    } finally {
        DB::statement('DROP TRIGGER gallery_failure');
    }

    $this->assertDatabaseHas('destinations', ['guid' => $destination->guid, 'name' => $name, 'gallery_version' => 0]);
    $this->assertDatabaseCount('destination_images', 0);
    $this->assertDatabaseCount('destination_gallery_operations', 0);
    Artisan::call('destinations:cleanup-images');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('retries failed physical cleanup and protects shared and legacy files', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');
    $disk->put('destination-gallery/orphan/display.webp', 'orphan');
    $disk->put('destinations/legacy.png', 'legacy');
    [$destination] = seedDestination(destination: ['image_path' => 'destination-gallery/shared/display.webp']);
    $disk->put($destination->image_path, 'shared');
    foreach (['destination-gallery/orphan/display.webp', 'destinations/legacy.png', $destination->image_path] as $path) {
        DB::table('destination_image_cleanups')->insert(['storage_disk' => 'local', 'path' => $path, 'eligible_at' => now()]);
    }
    $failingDisk = Mockery::mock($disk)->makePartial();
    $failingDisk->shouldReceive('delete')->twice()->with('destination-gallery/orphan/display.webp')->andReturn(false, true);
    Storage::shouldReceive('disk')->with('local')->andReturn($failingDisk);

    expect(Artisan::call('destinations:cleanup-images'))->toBe(1);
    $this->assertDatabaseHas('destination_image_cleanups', ['path' => 'destination-gallery/orphan/display.webp', 'attempts' => 1]);
    expect($disk->exists('destinations/legacy.png'))->toBeTrue();
    expect($disk->exists($destination->image_path))->toBeTrue();
    expect(Artisan::call('destinations:cleanup-images'))->toBe(0);
    $this->assertDatabaseCount('destination_image_cleanups', 0);
});
