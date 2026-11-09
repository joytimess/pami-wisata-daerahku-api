<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds payment receipt content from the persisted, verified ledger only. Nothing here trusts a client, a cached
 * app state or a redirect: a receipt exists only while PaymentEligibility says the booking is paid or has a verified adjustment.
 */
class PaymentReceiptService
{
    /** Entry QR becomes available this many days before the visit (destination calendar day). */
    public const ENTRY_QR_LEAD_DAYS = 5;

    /**
     * @return array{state: string, invoice_number: ?string, booking_number: string, destination: string, visit_date: string, quantity: int,
     *     total_amount: int, currency: string, method_label: string, reference_suffix: string, paid_at: string, timezone: string,
     *     environment: string, test_only: bool, adjusted_at: ?string, h5_date: string, entry_qr_open: bool}|null
     */
    public function data(string $bookingGuid, bool $ensureInvoice = false): ?array
    {
        $booking = DB::table('bookings')->join('destination_quotas as q', 'q.guid', '=', 'bookings.destination_quota_guid')
            ->join('destinations as d', 'd.guid', '=', 'q.destination_guid')->where('bookings.guid', $bookingGuid)
            ->first(['bookings.*', 'q.visit_date', 'd.timezone as destination_timezone']);
        $intent = $booking ? DB::table('payment_intents')->where('booking_guid', $bookingGuid)->where('provider', 'doku')->first() : null;
        $attempt = $intent && $intent->applied_attempt_guid ? DB::table('payment_attempts')->where('guid', $intent->applied_attempt_guid)->first() : null;
        if (! $attempt) {
            return null;
        }
        $status = app(PaymentEligibility::class)->status($booking);
        if (! in_array($status, ['paid', 'reversed'], true)) {
            return null;
        }
        $invoice = $ensureInvoice ? app(BookingDocumentService::class)->ensureInvoice($booking)
            : DB::table('invoices')->where('booking_guid', $bookingGuid)->first();
        $timezone = $booking->destination_timezone_snapshot ?? $booking->destination_timezone;
        $adjusted = $status === 'reversed' ? DB::table('payment_events')->where('payment_attempt_guid', $attempt->guid)->where('verification', 'verified')
            ->where('disposition', 'applied')->whereIn('event_type', ['refund', 'reversal'])->max('provider_occurred_at') : null;
        $h5 = CarbonImmutable::parse($booking->visit_date, $timezone)->startOfDay()->subDays(self::ENTRY_QR_LEAD_DAYS);

        return [
            'state' => $status === 'paid' ? 'paid' : 'adjusted', 'invoice_number' => $invoice?->invoice_number,
            'booking_number' => $booking->booking_number, 'destination' => $booking->destination_name_snapshot, 'visit_date' => $booking->visit_date,
            'quantity' => (int) $booking->quantity, 'total_amount' => (int) $intent->amount, 'currency' => $intent->currency,
            'method_label' => $this->methodLabel($attempt->method, $attempt->channel),
            'reference_suffix' => '****'.substr((string) $attempt->provider_reference, -4),
            'paid_at' => CarbonImmutable::parse($attempt->verified_paid_at, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i'),
            'timezone' => $timezone, 'environment' => $intent->environment, 'test_only' => $intent->environment !== 'production',
            'adjusted_at' => $adjusted ? CarbonImmutable::parse($adjusted, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i') : null,
            'h5_date' => $h5->toDateString(), 'entry_qr_open' => CarbonImmutable::now($timezone)->gte($h5),
        ];
    }

    /** @param array<string, mixed> $receipt */
    public function title(array $receipt): string
    {
        return ($receipt['test_only'] ? '[SANDBOX] ' : '').'Pembayaran terverifikasi';
    }

    /** @param array<string, mixed> $receipt */
    public function emailBody(array $receipt): string
    {
        $lines = [];
        if ($receipt['test_only']) {
            $lines[] = '[SANDBOX / TEST ONLY] Ini transaksi uji DOKU. Tidak ada dana Production.';
        }
        $lines[] = 'Pembayaran Anda telah terverifikasi.';
        $lines[] = 'Invoice: '.($receipt['invoice_number'] ?? '-');
        $lines[] = 'Booking: '.$receipt['booking_number'];
        $lines[] = 'Destinasi: '.$receipt['destination'];
        $lines[] = 'Tanggal kunjungan: '.$receipt['visit_date'];
        $lines[] = 'Jumlah pengunjung: '.$receipt['quantity'];
        $lines[] = 'Total dibayar: '.$this->money($receipt);
        $lines[] = 'Metode: '.$receipt['method_label'];
        $lines[] = 'Dibayar pada: '.$receipt['paid_at'].' ('.$receipt['timezone'].')';
        $lines[] = 'Referensi pembayaran: '.$receipt['reference_suffix'];
        $lines[] = $receipt['entry_qr_open']
            ? 'QR tiket per pengunjung akan tersedia di aplikasi segera setelah diterbitkan.'
            : 'QR tiket per pengunjung tersedia di aplikasi mulai '.$receipt['h5_date'].'.';
        $lines[] = 'Bukti pembayaran dapat diunduh dari aplikasi.';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $receipt */
    public function html(array $receipt): string
    {
        $row = fn (string $label, string $value): string => '<tr><td>'.e($label).'</td><td>'.e($value).'</td></tr>';
        $status = $receipt['state'] === 'paid' ? 'STATUS: LUNAS' : 'STATUS: PEMBAYARAN DIKEMBALIKAN ATAU DIBATALKAN';
        $html = '<h1>Bukti Pembayaran</h1>';
        if ($receipt['test_only']) {
            $html .= '<p><strong>SANDBOX / TEST ONLY</strong> - transaksi uji DOKU, bukan dana Production.</p>';
        }
        $html .= '<p><strong>'.e($status).'</strong></p><table>'
            .$row('Invoice', $receipt['invoice_number'] ?? '-').$row('Booking', $receipt['booking_number'])
            .$row('Destinasi', $receipt['destination']).$row('Tanggal kunjungan', $receipt['visit_date'])
            .$row('Jumlah pengunjung', (string) $receipt['quantity']).$row('Total', $this->money($receipt))
            .$row('Metode', $receipt['method_label']).$row('Referensi pembayaran', $receipt['reference_suffix'])
            .$row('Pernah diverifikasi dibayar', $receipt['paid_at'].' ('.$receipt['timezone'].')');
        if ($receipt['adjusted_at'] !== null) {
            $html .= $row('Pengembalian/pembatalan terverifikasi', $receipt['adjusted_at'].' ('.$receipt['timezone'].')');
        }

        return $html.'</table>'.($receipt['state'] === 'paid' ? '' : '<p>Dokumen ini bukan bukti pembayaran yang berlaku.</p>');
    }

    /** @param array<string, mixed> $receipt */
    private function money(array $receipt): string
    {
        return ($receipt['currency'] === 'IDR' ? 'Rp ' : $receipt['currency'].' ').number_format($receipt['total_amount'], 0, ',', '.');
    }

    private function methodLabel(string $method, string $channel): string
    {
        return match ($method) {
            'virtual_account' => 'Virtual Account '.strtoupper($channel),
            'qris' => 'QRIS',
            'ewallet' => 'E-wallet '.strtoupper($channel),
            default => ucfirst($method),
        };
    }
}
