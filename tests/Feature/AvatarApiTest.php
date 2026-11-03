<?php

use App\Models\User;
use App\Notifications\EmailVerificationOtp;
use App\Services\AuthService;
use App\Services\AvatarService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

function avatarGoogleIdentity(string $email, ?string $picture = null): void
{
    config(['api_tokens.firebase_project_id' => 'test-project', 'api_tokens.secret' => str_repeat('s', 64)]);
    app()->bind(AuthService::class, fn () => new class($email, $picture) extends AuthService
    {
        public function __construct(private string $email, private ?string $picture) {}

        protected function verifyFirebaseToken(string $project, string $idToken): array
        {
            if ($idToken !== 'verified') {
                throw new RuntimeException('Invalid token');
            }

            return ['sub' => 'avatar-sub', 'email' => $this->email, 'email_verified' => true, 'name' => 'Google visitor', 'picture' => $this->picture, 'firebase' => ['sign_in_provider' => 'google.com']];
        }
    });
    app()->bind(AvatarService::class, fn () => new class extends AvatarService
    {
        protected function publicAddress(string $host): string
        {
            return '142.250.1.1';
        }
    });
}

function avatarLink(User $user): void
{
    DB::table('external_identities')->insert(['guid' => (string) Str::uuid(), 'user_guid' => $user->guid, 'provider' => 'google', 'provider_subject' => 'avatar-sub', 'created_at' => now(), 'updated_at' => now()]);
}

function avatarPng(): string
{
    $file = UploadedFile::fake()->image('google.png', 40, 20);

    return file_get_contents($file->getRealPath());
}

it('registers default metadata without creating an image', function () {
    Storage::fake('local');
    Notification::fake();
    $this->postJson('/api/v1/auth/register', ['name' => 'Visitor', 'email' => 'avatar@example.org', 'password' => 'password123', 'password_confirmation' => 'password123'])
        ->assertSuccessful()->assertJsonPath('data.user.avatar_source', 'default')->assertJsonPath('data.user.avatar_url', null)->assertJsonPath('data.user.avatar_version', 0)->assertJsonPath('data.user.google_avatar_available', false);
    expect(Storage::disk('local')->allFiles())->toBe([]);
    expect(User::first()->avatar_manual_choice)->toBeFalse();
    Notification::assertSentTo(User::first(), EmailVerificationOtp::class);
});

it('normalizes supported uploads and serves private owner bytes', function (string $extension) {
    Storage::fake('local');
    $user = makeUser();
    $other = makeUser();
    $headers = bearer($user) + ['Accept' => 'application/json'];
    $response = $this->post('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('photo.'.$extension, 40, 20), 'avatar_version' => 0, 'user_guid' => $other->guid], $headers)
        ->assertOk()->assertJsonPath('data.avatar_source', 'custom')->assertJsonPath('data.avatar_version', 1)->assertJsonPath('data.avatar_url', 'profile/avatar?v=1');
    $user->refresh();
    expect($user->avatar_manual_choice)->toBeTrue();
    expect($other->fresh()->avatar_source)->toBe('default');
    expect($response->getContent())->not->toContain('avatar_custom_path', 'google_avatar_snapshot_path', 'avatars/');
    Storage::disk('local')->assertExists($user->avatar_custom_path);
    $binary = $this->get('/api/v1/profile/avatar?v=1', $headers)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($binary->headers->get('Cache-Control'))->toContain('private');
    $info = getimagesizefromstring($binary->getContent());
    expect([$info[0], $info[1], $info['mime']])->toBe([512, 512, 'image/png']);
    $this->get('/api/v1/profile/avatar', $headers + ['If-None-Match' => $binary->headers->get('ETag')])->assertNotModified();
    $this->get('/api/v1/profile/avatar?user_guid='.$user->guid, bearer($other))->assertNotFound();
    $this->getJson('/api/v1/auth/me', $headers)->assertOk()->assertJsonPath('data.avatar_version', 1);
    $this->patchJson('/api/v1/profile', ['name' => 'Updated', 'phone' => '+6281234567890'], $headers)->assertOk()->assertJsonPath('data.avatar_source', 'custom');
})->with(['jpg', 'png', 'webp']);

it('rejects unsafe uploads with 422 and preserves the active avatar', function (string $case) {
    Storage::fake('local');
    $user = app(AvatarService::class)->upload(makeUser(), UploadedFile::fake()->image('old.png'), 0);
    $old = $user->avatar_custom_path;
    $file = match ($case) {
        'corrupt' => UploadedFile::fake()->createWithContent('broken.png', "\x89PNG\r\n\x1a\ninvalid"),
        'spoof' => UploadedFile::fake()->createWithContent('script.jpg', '<?php unsafe();'),
        'gif' => UploadedFile::fake()->image('animated.gif'),
        'size' => UploadedFile::fake()->image('large.jpg')->size(5121),
        'dimension' => UploadedFile::fake()->image('wide.png', 8193, 1),
        'pixels' => UploadedFile::fake()->image('huge.png', 4001, 4000),
    };
    $this->post('/api/v1/profile/avatar', ['avatar' => $file, 'avatar_version' => 1], bearer($user) + ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('avatar');
    expect($user->fresh()->avatar_custom_path)->toBe($old);
    expect(Storage::disk('local')->allFiles())->toBe([$old]);
})->with(['corrupt', 'spoof', 'gif', 'size', 'dimension', 'pixels']);

it('requires JWT for every avatar operation with 401', function () {
    $this->getJson('/api/v1/profile/avatar')->assertUnauthorized();
    $this->postJson('/api/v1/profile/avatar', [])->assertUnauthorized();
    $this->putJson('/api/v1/profile/avatar/preference', ['source' => 'default', 'avatar_version' => 0])->assertUnauthorized();
});

it('returns 403 for avatar operations by admin roles', function (int $role) {
    $headers = bearer(makeUser($role));
    $this->getJson('/api/v1/profile/avatar', $headers)->assertForbidden();
    $this->postJson('/api/v1/profile/avatar', [], $headers)->assertForbidden();
    $this->putJson('/api/v1/profile/avatar/preference', ['source' => 'default', 'avatar_version' => 0], $headers)->assertForbidden();
})->with([1, 2]);

it('validates required version and allowed preferences with 422', function () {
    $headers = bearer(makeUser());
    $this->postJson('/api/v1/profile/avatar', [], $headers)->assertUnprocessable()->assertJsonValidationErrors(['avatar', 'avatar_version']);
    $this->putJson('/api/v1/profile/avatar/preference', ['source' => 'custom', 'avatar_version' => -1], $headers)->assertUnprocessable()->assertJsonValidationErrors(['source', 'avatar_version']);
});

it('returns 409 for stale uploads and preferences without leaking candidate files', function () {
    Storage::fake('local');
    $user = app(AvatarService::class)->upload(makeUser(), UploadedFile::fake()->image('old.png'), 0);
    $old = $user->avatar_custom_path;
    $headers = bearer($user) + ['Accept' => 'application/json'];
    $this->post('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('new.png'), 'avatar_version' => 0], $headers)->assertConflict()->assertJsonPath('code', 'avatar_version_conflict')->assertJsonPath('context.avatar_version', 1);
    $this->putJson('/api/v1/profile/avatar/preference', ['source' => 'default', 'avatar_version' => 0], $headers)->assertConflict()->assertJsonPath('code', 'avatar_version_conflict');
    expect($user->fresh()->avatar_custom_path)->toBe($old);
    expect(Storage::disk('local')->allFiles())->toBe([$old]);
    $this->assertDatabaseCount('avatar_file_cleanups', 1);
});

it('returns 409 when no Google snapshot is available', function () {
    $user = makeUser();
    $this->putJson('/api/v1/profile/avatar/preference', ['source' => 'google', 'avatar_version' => 0], bearer($user))->assertConflict()->assertJsonPath('code', 'google_snapshot_unavailable');
    expect($user->fresh()->avatar_version)->toBe(0);
});

it('imports a verified Google photo once for new and existing users', function (bool $existing) {
    Storage::fake('local');
    $email = 'google-avatar@example.org';
    if ($existing) {
        avatarLink(User::factory()->create(['email' => $email]));
    }
    $url = 'https://lh3.googleusercontent.com/photo';
    avatarGoogleIdentity($email, $url);
    Http::preventStrayRequests();
    Http::fake([$url => Http::response(avatarPng(), 200, ['Content-Type' => 'image/png'])]);
    $this->postJson('/api/v1/auth/google', ['id_token' => 'verified'])->assertOk()->assertJsonPath('data.user.avatar_source', 'google')->assertJsonPath('data.user.google_avatar_available', true);
    $user = User::where('email', $email)->firstOrFail();
    $snapshot = $user->google_avatar_snapshot_path;
    avatarGoogleIdentity($email, 'https://lh4.googleusercontent.com/changed');
    $this->postJson('/api/v1/auth/google', ['id_token' => 'verified'])->assertOk()->assertJsonPath('data.user.avatar_version', 1);
    expect($user->fresh()->google_avatar_snapshot_path)->toBe($snapshot);
    expect($user->fresh()->avatar_manual_choice)->toBeFalse();
    Storage::disk('local')->assertExists($snapshot);
    Http::assertSentCount(1);
})->with([false, true]);

it('keeps login successful on photo failure and retries next Google login', function () {
    Storage::fake('local');
    $url = 'https://lh3.googleusercontent.com/photo';
    avatarGoogleIdentity('retry-avatar@example.org', $url);
    Http::preventStrayRequests();
    Http::fake([$url => Http::sequence()->push('unavailable', 503)->push(avatarPng(), 200, ['Content-Type' => 'image/png'])]);
    $this->postJson('/api/v1/auth/google', ['id_token' => 'verified'])->assertOk()->assertJsonPath('data.user.avatar_source', 'default');
    $this->postJson('/api/v1/auth/google', ['id_token' => 'verified'])->assertOk()->assertJsonPath('data.user.avatar_source', 'google');
    Http::assertSentCount(2);
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('does not trust a photo hint when verified token has no picture', function () {
    avatarGoogleIdentity('no-photo@example.org');
    Http::preventStrayRequests();
    Http::fake();
    $this->postJson('/api/v1/auth/google', ['id_token' => 'verified', 'photo_url' => 'https://lh3.googleusercontent.com/hint'])->assertOk()->assertJsonPath('data.user.avatar_source', 'default');
    Http::assertNothingSent();
});

it('blocks unsafe Google URLs before outbound requests', function (string $url) {
    avatarGoogleIdentity('unsafe-avatar@example.org', $url);
    Http::preventStrayRequests();
    Http::fake();
    $this->postJson('/api/v1/auth/google', ['id_token' => 'verified'])->assertOk()->assertJsonPath('data.user.avatar_source', 'default');
    Http::assertNothingSent();
})->with(['http://lh3.googleusercontent.com/photo', 'https://127.0.0.1/photo', 'https://lh3.googleusercontent.com.evil.org/photo', 'https://user:pass@lh3.googleusercontent.com/photo', 'https://lh3.googleusercontent.com:444/photo', 'https://lh3.googleusercontent.com/photo#fragment']);

it('rejects unsafe Google responses without failing login', function (string $case) {
    Storage::fake('local');
    $url = 'https://lh3.googleusercontent.com/photo';
    avatarGoogleIdentity('bad-photo@example.org', $url);
    Http::preventStrayRequests();
    $response = match ($case) {
        'redirect' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private']),
        'mime' => Http::response(avatarPng(), 200, ['Content-Type' => 'text/html']),
        'oversize' => Http::response(str_repeat('x', 5 * 1024 * 1024 + 1), 200, ['Content-Type' => 'image/png']),
        'decode' => Http::response('invalid', 200, ['Content-Type' => 'image/png']),
    };
    Http::fake([$url => $response]);
    $this->postJson('/api/v1/auth/google', ['id_token' => 'verified'])->assertOk()->assertJsonPath('data.user.avatar_source', 'default');
    Http::assertSentCount(1);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['redirect', 'mime', 'oversize', 'decode']);

it('persists manual choices across Google login without fetching', function (string $source) {
    Storage::fake('local');
    $user = User::factory()->create(['email' => 'manual@example.org']);
    avatarLink($user);
    if ($source === 'custom') {
        app(AvatarService::class)->upload($user, UploadedFile::fake()->image('custom.png'), 0);
    } else {
        app(AvatarService::class)->preference($user, 'default', 0);
    }
    avatarGoogleIdentity($user->email, 'https://lh3.googleusercontent.com/photo');
    Http::preventStrayRequests();
    Http::fake();
    $this->postJson('/api/v1/auth/google', ['id_token' => 'verified'])->assertOk()->assertJsonPath('data.user.avatar_source', $source);
    Http::assertNothingSent();
    expect($user->fresh()->avatar_manual_choice)->toBeTrue();
})->with(['default', 'custom']);

it('restores the saved snapshot and deletes replaced custom files after commit', function () {
    Storage::fake('local');
    $user = makeUser();
    $snapshot = 'avatars/'.$user->guid.'/google.png';
    Storage::disk('local')->put($snapshot, avatarPng());
    $user->google_avatar_snapshot_path = $snapshot;
    $user->save();
    $user = app(AvatarService::class)->upload($user, UploadedFile::fake()->image('custom.png'), 0);
    $old = $user->avatar_custom_path;
    Http::preventStrayRequests();
    Http::fake();
    $this->putJson('/api/v1/profile/avatar/preference', ['source' => 'google', 'avatar_version' => 1], bearer($user))->assertOk()->assertJsonPath('data.avatar_source', 'google');
    Storage::disk('local')->assertMissing($old);
    Storage::disk('local')->assertExists($snapshot);
    expect($user->fresh()->avatar_custom_path)->toBeNull();
    $this->putJson('/api/v1/profile/avatar/preference', ['source' => 'default', 'avatar_version' => 2], bearer($user))->assertOk()->assertJsonPath('data.avatar_url', null);
    Storage::disk('local')->assertExists($snapshot);
    Http::assertNothingSent();
});

it('rolls back failed persistence and removes the candidate', function () {
    Storage::fake('local');
    $user = app(AvatarService::class)->upload(makeUser(), UploadedFile::fake()->image('old.png'), 0);
    $old = $user->avatar_custom_path;
    User::updating(function (User $model): void {
        if ($model->avatar_version === 2) {
            throw new RuntimeException('Persistence unavailable');
        }
    });
    expect(fn () => app(AvatarService::class)->upload($user, UploadedFile::fake()->image('new.png'), 1))->toThrow(RuntimeException::class);
    expect($user->fresh()->avatar_custom_path)->toBe($old);
    expect(Storage::disk('local')->allFiles())->toBe([$old]);
    $this->assertDatabaseCount('avatar_file_cleanups', 1);
});

it('keeps committed preferences on cleanup failure and retries durably', function () {
    Storage::fake('local');
    $user = app(AvatarService::class)->upload(makeUser(), UploadedFile::fake()->image('old.png'), 0);
    $old = $user->avatar_custom_path;
    $disk = Storage::disk('local');
    $manager = Storage::getFacadeRoot();
    $failing = Mockery::mock($disk)->makePartial();
    $failing->shouldReceive('delete')->once()->with($old)->andReturn(false);
    Storage::shouldReceive('disk')->with('local')->andReturn($failing);
    $this->putJson('/api/v1/profile/avatar/preference', ['source' => 'default', 'avatar_version' => 1], bearer($user))->assertOk()->assertJsonPath('data.avatar_source', 'default');
    expect($user->fresh()->avatar_custom_path)->toBeNull();
    $this->assertDatabaseHas('avatar_file_cleanups', ['path' => $old, 'attempts' => 1]);
    Storage::swap($manager);
    $this->travel(2)->minutes();
    $this->artisan('avatars:cleanup')->assertSuccessful();
    $disk->assertMissing($old);
    $this->assertDatabaseCount('avatar_file_cleanups', 0);
});

it('deletes replaced custom images and recovers unreferenced crash candidates', function () {
    Storage::fake('local');
    $user = app(AvatarService::class)->upload(makeUser(), UploadedFile::fake()->image('old.png'), 0);
    $old = $user->avatar_custom_path;
    $current = app(AvatarService::class)->upload($user, UploadedFile::fake()->image('new.png'), 1);
    Storage::disk('local')->assertMissing($old);
    Storage::disk('local')->assertExists($current->avatar_custom_path);
    $orphan = 'avatars/'.$user->guid.'/crash.png';
    Storage::disk('local')->put($orphan, avatarPng());
    DB::table('avatar_file_cleanups')->insert(['path' => $orphan, 'eligible_at' => now()->subMinute()]);

    $this->travel(2)->hours();
    $this->artisan('avatars:cleanup')->assertSuccessful();

    Storage::disk('local')->assertMissing($orphan);
    Storage::disk('local')->assertExists($current->avatar_custom_path);
    $this->assertDatabaseCount('avatar_file_cleanups', 1);
});

it('preserves the active image when storage fails before pointer update', function () {
    Storage::fake('local');
    $user = app(AvatarService::class)->upload(makeUser(), UploadedFile::fake()->image('old.png'), 0);
    $old = $user->avatar_custom_path;
    $disk = Storage::disk('local');
    $failing = Mockery::mock($disk)->makePartial();
    $failing->shouldReceive('put')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('local')->andReturn($failing);

    expect(fn () => app(AvatarService::class)->upload($user, UploadedFile::fake()->image('new.png'), 1))->toThrow(RuntimeException::class);

    expect($user->fresh()->avatar_custom_path)->toBe($old);
    expect($disk->allFiles())->toBe([$old]);
    $this->assertDatabaseCount('avatar_file_cleanups', 1);
});

it('strips JPEG metadata when reencoding uploaded photos', function () {
    Storage::fake('local');
    $file = UploadedFile::fake()->image('camera.jpg', 40, 30);
    $jpeg = file_get_contents($file->getRealPath());
    $metadata = "Exif\0\0GPS-private-location-marker";
    $jpeg = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($metadata) + 2).$metadata.substr($jpeg, 2);
    $upload = UploadedFile::fake()->createWithContent('camera.jpg', $jpeg);
    $user = makeUser();

    $this->post('/api/v1/profile/avatar', ['avatar' => $upload, 'avatar_version' => 0], bearer($user) + ['Accept' => 'application/json'])->assertOk();

    $encoded = Storage::disk('local')->get($user->fresh()->avatar_custom_path);
    expect($encoded)->not->toContain('GPS-private-location-marker', "Exif\0\0");
    expect(getimagesizefromstring($encoded)['mime'])->toBe('image/png');
});
