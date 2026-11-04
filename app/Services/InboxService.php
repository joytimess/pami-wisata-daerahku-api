<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class InboxService
{
    private function visible(User $user): Builder
    {
        return InboxNotification::where('user_guid', $user->guid)->where(function ($visible) use ($user) {
            $visible->whereNull('booking_guid')->orWhereNotIn('type', ['booking.created', 'booking.cancelled', 'booking.payment_review'])
                ->orWhereExists(function ($booking) use ($user) {
                    $booking->selectRaw('1')->from('bookings')->join('destination_quotas', 'destination_quotas.guid', '=', 'bookings.destination_quota_guid')
                        ->whereColumn('bookings.guid', 'notifications.booking_guid')
                        ->whereExists(fn ($users) => $users->selectRaw('1')->from('users')->where('users.guid', $user->guid)->where('users.status', 'active')
                            ->where(fn ($role) => $role->where('users.role', 2)->orWhere(fn ($role) => $role->where('users.role', 1)->whereExists(fn ($assigned) => $assigned->selectRaw('1')->from('destination_admin_assignments')->whereColumn('destination_admin_assignments.destination_guid', 'destination_quotas.destination_guid')->where('destination_admin_assignments.user_guid', $user->guid)))));
                });
        });
    }

    public function list(User $user)
    {
        return $this->visible($user)->orderByDesc('created_at')->orderByDesc('guid')->paginate(20);
    }

    public function unread(User $user): int
    {
        return $this->visible($user)->whereNull('read_at')->count();
    }

    public function read(User $user, string $guid): InboxNotification
    {
        $notification = $this->visible($user)->findOrFail($guid);
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
