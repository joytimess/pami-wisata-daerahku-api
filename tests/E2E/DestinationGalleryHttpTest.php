<?php

use App\Models\DestinationCategory;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('saves and replays real multipart galleries over HTTP and serves optimized media', function () {
    if (config('database.default') !== 'mysql' || ! str_ends_with((string) config('database.connections.mysql.database'), '_e2e')) {
        $this->markTestSkipped('Requires isolated MySQL *_e2e.');
    }
    $base = rtrim((string) env('E2E_BASE_URL'), '/');
    try {
        Http::timeout(3)->get($base.'/up')->throw();
    } catch (Throwable) {
        $this->markTestSkipped('Start the isolated Laravel HTTP server first.');
    }
    Artisan::call('migrate:fresh', ['--force' => true]);
    $admin = User::factory()->create(['role' => 1, 'email' => 'gallery-http@example.test']);
    $category = DestinationCategory::create(['name' => 'Gallery HTTP', 'slug' => 'gallery-http']);
    $login = Http::acceptJson()->post($base.'/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password']);
    expect($login->status())->toBe(200);
    $token = $login->json('data.access_token');
    $request = fn () => Http::baseUrl($base.'/api/v1')->acceptJson()->withToken($token)->timeout(60);
    $image = imagecreatetruecolor(40, 30);
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    imagedestroy($image);
    $created = $request()->withHeader('Idempotency-Key', (string) Str::uuid())
        ->attach('images[0]', $png, 'first.png')->attach('images[1]', $png, 'second.png')
        ->post('destinations', [
            'destination_category_guid' => $category->guid, 'name' => 'HTTP Gallery',
            'location' => 'Local', 'description' => 'Real multipart parser and MySQL integration',
            'latitude' => '-7.8012345', 'longitude' => '110.3645678',
            'ticket_price' => '0', 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta',
        ]);
    expect($created->status())->toBe(201, $created->body());
    $data = $created->json('data');
    expect($data['images'])->toHaveCount(2);
    $guid = $data['guid'];
    $manifest = json_encode([
        ['image_guid' => $data['images'][1]['guid']], ['upload_key' => 'new-photo'],
        ['image_guid' => $data['images'][0]['guid']],
    ]);
    $key = (string) Str::uuid();
    $save = fn () => $request()->withHeader('Idempotency-Key', $key)->attach('new_images[new-photo]', $png, 'third.png')
        ->post('destinations/'.$guid, ['_method' => 'PATCH', 'name' => 'Updated atomically',
            'gallery_version' => (string) $data['gallery_version'], 'gallery_manifest' => $manifest]);

    $saved = $save();
    expect($saved->status())->toBe(200, $saved->body());
    $replayed = $save();
    expect($replayed->status())->toBe(200, $replayed->body());
    expect($replayed->json('data'))->toEqual($saved->json('data'));
    $read = Http::acceptJson()->get($base.'/api/v1/destinations/'.$guid);
    expect($read->status())->toBe(200);
    expect($read->json('data.name'))->toBe('Updated atomically');
    expect(array_column($read->json('data.images'), 'sequence'))->toBe([1, 2, 3]);
    expect($read->json('data.images.0.guid'))->toBe($data['images'][1]['guid']);
    foreach (['thumbnail', 'display', 'fullscreen'] as $variant) {
        $media = Http::get($base.'/api/v1/'.$read->json('data.images.1.urls.'.$variant));
        expect($media->status())->toBe(200);
        expect($media->header('Content-Type'))->toStartWith('image/');
        expect(getimagesizefromstring($media->body()))->not->toBeFalse();
    }
    $conflict = $request()->withHeader('Idempotency-Key', (string) Str::uuid())
        ->post('destinations/'.$guid, ['_method' => 'PATCH', 'gallery_version' => (string) $data['gallery_version'],
            'gallery_manifest' => json_encode([['image_guid' => $data['images'][0]['guid']]])]);
    expect($conflict->status())->toBe(409, $conflict->body());
});
