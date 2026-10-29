<?php

use App\Models\DeviceToken;
use App\Models\InboxNotification;
use App\Models\User;
use App\Services\InboxService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('limits inbox access to its owner and transfers a device token to the current account', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $notification = InboxNotification::create(['user_guid' => $first->guid, 'event_key' => 'event-1', 'type' => 'booking', 'title' => 'Update', 'body' => 'Changed']);
    $service = app(InboxService::class);
    expect(fn () => $service->read($second, $notification->guid))->toThrow(ModelNotFoundException::class);
    expect($service->unread($first))->toBe(1);
    $service->read($first, $notification->guid);
    expect($service->unread($first))->toBe(0);
    $sessions = [];
    foreach ([$first, $second] as $user) {
        $session = (string) Str::uuid();
        DB::table('refresh_tokens')->insert(['guid' => (string) Str::uuid(), 'user_guid' => $user->guid, 'token_hash' => hash('sha256', $session), 'session_guid' => $session, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $sessions[] = $session;
    }
    $service->register($first, ['token' => 'device-token', 'platform' => 'android'], $sessions[0]);
    $service->register($second, ['token' => 'device-token', 'platform' => 'android'], $sessions[1]);
    $service->unregister($first, 'device-token');
    expect(DeviceToken::where('token', 'device-token')->first()->user_guid)->toBe($second->guid);
});
