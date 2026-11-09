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
            $this->insert((string) $userGuid, $bookingGuid, 'booking:'.$bookingGuid.':'.$event, $event, $title, $body,
                ! in_array($event, ['demo_paid', 'sandbox_paid'], true));
        }
    }

    /**
     * One receipt event per applied verified payment. The key comes from the server-side attempt, never from a job id or
     * retry time, so duplicate callbacks, replays and retries all resolve to the same notification and delivery rows.
     * Called inside the transaction that applies the payment so a rollback leaves no email claiming the payment.
     */
    public function recordPaymentReceipt(string $bookingGuid, string $ownerGuid, string $attemptGuid): void
    {
        $receipts = app(PaymentReceiptService::class);
        $receipt = $receipts->data($bookingGuid, true);
        if ($receipt === null || $receipt['state'] !== 'paid') {
            return;
        }
        $this->insert($ownerGuid, $bookingGuid, 'booking:'.$bookingGuid.':payment_receipt:'.$attemptGuid, 'payment_receipt',
            $receipts->title($receipt), $receipts->emailBody($receipt), true);
    }

    /** Authenticated-app link to the ticket page; null unless a valid HTTPS origin is configured. It never carries a token or QR. */
    private function ticketLink(string $bookingGuid): ?string
    {
        $base = config('operations.ticket_link_base_url');
        if (! is_string($base) || ! str_starts_with($base, 'https://') || ! filter_var($base, FILTER_VALIDATE_URL)) {
            return null;
        }

        return rtrim($base, '/').route('tickets.link', ['booking' => $bookingGuid], false);
    }

    /**
     * Announces ready entry QR units. Carries no QR secret (a QR is a bearer credential and email copies cannot be revoked):
     * the codes are shown only inside the authenticated app. Everything else the customer needs is non-secret and included.
     */
    public function recordTicketIssued(string $bookingGuid, string $ownerGuid, int $quantity): void
    {
        $booking = DB::table('bookings')->join('destination_quotas as q', 'q.guid', '=', 'bookings.destination_quota_guid')->where('bookings.guid', $bookingGuid)
            ->first(['bookings.booking_number', 'bookings.destination_name_snapshot', 'q.visit_date']);
        $sandbox = DB::table('payment_intents')->where('booking_guid', $bookingGuid)->where('environment', '!=', 'production')->where('provider', 'doku')->exists();
        $body = implode("\n", array_filter([
            $sandbox ? '[SANDBOX / TEST ONLY] Ini transaksi uji DOKU.' : null,
            'QR tiket untuk '.$quantity.' pengunjung sudah tersedia di aplikasi.',
            $booking ? 'Booking: '.$booking->booking_number : null,
            $booking ? 'Destinasi: '.$booking->destination_name_snapshot : null,
            $booking ? 'Tanggal kunjungan: '.substr((string) $booking->visit_date, 0, 10) : null,
            'Jumlah pengunjung: '.$quantity,
            'Cara masuk: buka Tiket Saya di aplikasi dan tunjukkan satu QR per pengunjung kepada petugas di pintu masuk. Satu QR hanya berlaku sekali dan tidak dikirim lewat email.',
            $this->ticketLink($bookingGuid) ? 'Lihat tiket (perlu masuk ke akun Anda): '.$this->ticketLink($bookingGuid) : null,
            'Tiket berlaku hanya pada tanggal kunjungan dan mengikuti kebijakan pengelola destinasi.',
        ]));
        $this->insert($ownerGuid, $bookingGuid, 'booking:'.$bookingGuid.':ticket_issued', 'ticket_issued', ($sandbox ? '[SANDBOX] ' : '').'QR tiket tersedia', $body,
            ! DB::table('payment_intents')->where('booking_guid', $bookingGuid)->where('provider', 'demo')->exists());
    }

    private function insert(string $userGuid, string $bookingGuid, string $eventKey, string $event, string $title, string $body, bool $deliver): void
    {
        $now = now();
        $notificationGuid = (string) Str::uuid();
        $inserted = DB::table('notifications')->insertOrIgnore([
            'guid' => $notificationGuid, 'user_guid' => $userGuid, 'booking_guid' => $bookingGuid,
            'event_key' => $eventKey, 'type' => 'booking.'.$event,
            'title' => $title, 'body' => $body, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if (! $inserted || ! $deliver) {
            return;
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
