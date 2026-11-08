<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingNotificationService
{
    public function record(string $bookingGuid, string $ownerGuid, string $event): void
    {
        [$title, $body, $admin] = match ($event) {
            'created' => ['Booking baru', 'Ada booking baru yang menunggu keputusan.', true],
            'approved' => ['Booking disetujui', 'Booking Anda telah disetujui.', false],
            'rejected' => ['Booking ditolak', 'Booking Anda telah ditolak.', false],
            'cancelled' => ['Booking dibatalkan', 'Booking dibatalkan oleh pemesan.', true],
            'expired' => ['Booking kedaluwarsa', 'Booking Anda telah kedaluwarsa.', false],
            'payment_review' => ['Pembayaran perlu ditinjau', 'Status pembayaran booking perlu ditinjau.', true],
            'demo_paid' => ['DEMO pembayaran berhasil', 'Simulasi DEMO berhasil. Tidak ada pembayaran uang nyata.', false],
            'sandbox_paid' => ['SANDBOX pembayaran terverifikasi', 'Pembayaran uji DOKU terverifikasi. Tidak ada dana Production.', false],
            'doku_paid' => ['Pembayaran terverifikasi', 'Pembayaran booking Anda telah terverifikasi.', false],
        };
        $users = $admin
            ? DB::table('users')->where('status', 'active')->where(fn ($users) => $users->where('role', 2)->orWhere(fn ($users) => $users->where('role', 1)
                ->whereExists(fn ($assigned) => $assigned->selectRaw('1')->from('destination_admin_assignments')
                    ->join('destination_quotas', 'destination_quotas.destination_guid', '=', 'destination_admin_assignments.destination_guid')
                    ->join('bookings', 'bookings.destination_quota_guid', '=', 'destination_quotas.guid')
                    ->where('bookings.guid', $bookingGuid)->whereColumn('destination_admin_assignments.user_guid', 'users.guid'))))->pluck('guid')
            : collect([$ownerGuid]);
        foreach ($users as $userGuid) {
            $now = now();
            $notificationGuid = (string) Str::uuid();
            $inserted = DB::table('notifications')->insertOrIgnore([
                'guid' => $notificationGuid, 'user_guid' => $userGuid, 'booking_guid' => $bookingGuid,
                'event_key' => 'booking:'.$bookingGuid.':'.$event, 'type' => 'booking.'.$event,
                'title' => $title, 'body' => $body, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if (! $inserted) {
                continue;
            }
            if (in_array($event, ['demo_paid', 'sandbox_paid'], true)) {
                continue;
            }
            $targets = [['channel' => 'email', 'target_key' => $userGuid]];
            foreach (DB::table('device_tokens')->where('user_guid', $userGuid)->pluck('guid') as $deviceGuid) {
                $targets[] = ['channel' => 'fcm', 'target_key' => $deviceGuid];
            }
            foreach ($targets as $target) {
                DB::table('notification_deliveries')->insert([
                    'guid' => (string) Str::uuid(), 'notification_guid' => $notificationGuid,
                    'channel' => $target['channel'], 'target_key' => $target['target_key'],
                    'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }
}
