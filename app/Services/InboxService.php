<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;

class InboxService
{
    public function list(User $user)
    {
        return InboxNotification::where('user_guid', $user->guid)->orderByDesc('created_at')->orderByDesc('guid')->paginate(20);
    }

    public function unread(User $user): int
    {
        return InboxNotification::where('user_guid', $user->guid)->whereNull('read_at')->count();
    }

    public function read(User $user, string $guid): InboxNotification
    {
        $notification = InboxNotification::where('user_guid', $user->guid)->findOrFail($guid);
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return $notification;
    }

    public function register(User $user, array $data, string $session): void
    {
        DB::transaction(function () use ($user, $data, $session) {
            User::whereKey($user->guid)->lockForUpdate()->firstOrFail();
            if (! DB::table('refresh_tokens')->where('user_guid', $user->guid)->where('session_guid', $session)->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
                throw new AuthenticationException;
            }
            DeviceToken::where('token', $data['token'])->delete();
            DeviceToken::create(['user_guid' => $user->guid, 'session_guid' => $session, 'token' => $data['token'], 'platform' => $data['platform'], 'last_seen_at' => now()]);
        }, 3);
    }

    public function unregister(User $user, string $token): void
    {
        DeviceToken::where('user_guid', $user->guid)->where('token', $token)->delete();
    }
}
