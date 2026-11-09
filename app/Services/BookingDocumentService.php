<?php

namespace App\Services;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookingDocumentService
{
    public function ensureInvoice(object $booking): object
    {
        $invoice = DB::table('invoices')->where('booking_guid', $booking->guid)->first();
        if ($invoice) {
            return $invoice;
        }
        $guid = (string) Str::uuid();
        DB::table('invoices')->insertOrIgnore(['guid' => $guid, 'booking_guid' => $booking->guid,
            'invoice_number' => 'INV-'.strtoupper(str_replace('-', '', $guid)), 'status' => 'issued',
            'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('invoices')->where('booking_guid', $booking->guid)->first();
    }

    public function generate(string $guid): void
    {
        $booking = DB::table('bookings')->where('guid', $guid)->first();
        if (! $booking || ! in_array($booking->status, ['awaiting_payment', 'confirmed'], true)) {
            return;
        }
        $invoice = $this->ensureInvoice($booking);
        $quota = DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->first();
        $disk = Storage::disk('local');
        if (! $invoice->pdf_path || ! $disk->exists($invoice->pdf_path)) {
            $path = 'booking-documents/'.Str::uuid().'.pdf';
            $html = '<h1>Booking confirmation invoice</h1><p>This document confirms a booking and is not proof of payment.</p>'
                .'<p>Invoice: '.e($invoice->invoice_number).'</p>'.$this->bookingHtml($booking, $quota);
            if (! $disk->put($path, Pdf::loadHTML($html)->output())) {
                throw new \RuntimeException('Could not store invoice.');
            }
            try {
                $published = DB::table('invoices')->where('guid', $invoice->guid)->where('pdf_path', $invoice->pdf_path)
                    ->update(['pdf_path' => $path, 'updated_at' => now()]);
            } catch (\Throwable $e) {
                $disk->delete($path);
                throw $e;
            }
            if (! $published) {
                $disk->delete($path);
            }
        }
        // No group entry token is minted any more: admission is per-visitor QR from TicketService. Legacy rows stay untouched.
    }

    private function bookingHtml(object $booking, object $quota): string
    {
        return '<p>Booking: '.e($booking->booking_number).'</p><p>Destination: '.e($booking->destination_name_snapshot).'</p>'
            .'<p>Visit: '.e($quota->visit_date).'</p><p>Visitor: '.e($booking->visitor_name).'</p>'
            .'<p>Quantity: '.e($booking->quantity).'</p><p>Total: '.e($booking->currency).' '.e($booking->total_amount).'</p>';
    }

    private function booking(User $user, string $guid): object
    {
        $query = DB::table('bookings')->where('bookings.guid', $guid)->select('bookings.*');
        if ((int) $user->role < 1) {
            $query->where('bookings.user_guid', $user->guid);
        } else {
            $query->join('destination_quotas', 'destination_quotas.guid', '=', 'bookings.destination_quota_guid');
            app(AdminDestinationScopeService::class)->apply($query, $user, 'destination_quotas.destination_guid');
        }

        return $query->first() ?? throw new ModelNotFoundException;
    }

    public function metadata(User $user, string $guid): array
    {
        $this->booking($user, $guid);

        $documents = [
            'invoice' => DB::table('invoices')->where('booking_guid', $guid)->first(['guid', 'booking_guid', 'invoice_number', 'status', 'issued_at', 'pdf_path']),
        ];
        $tickets = app(TicketService::class);
        $booking = $tickets->booking($guid);
        $state = $tickets->state($booking);
        $confirmed = $booking->status === 'confirmed' && $state !== 'revoked';
        // Pre-existing group-token row stays as history. Neither it nor the on-demand confirmation is an admission credential.
        $legacy = DB::table('tickets')->where('booking_guid', $guid)->first(['guid', 'booking_guid', 'status', 'issued_at', 'expires_at']);
        $documents['ticket'] = $legacy ? (object) ((array) $legacy + ['legacy' => true, 'admission' => false, 'download_available' => $confirmed])
            : ($confirmed ? (object) ['guid' => null, 'booking_guid' => $guid, 'status' => 'confirmation', 'issued_at' => null, 'expires_at' => null,
                'legacy' => false, 'admission' => false, 'download_available' => true] : null);
        $receipt = app(PaymentReceiptService::class)->data($guid);
        $documents['receipt'] = $receipt ? (object) ['booking_guid' => $guid, 'status' => $receipt['state'], 'invoice_number' => $receipt['invoice_number'],
            'download_available' => true] : null;
        foreach ($documents as $name => $document) {
            if ($document && property_exists($document, 'pdf_path')) {
                $document->download_available = $document->pdf_path && Storage::disk('local')->exists($document->pdf_path);
                unset($document->pdf_path);
            }
        }

        $documents['entry_tickets'] = ['state' => $state, 'quantity' => (int) $booking->quantity, 'entitlement' => $tickets->entitlement($booking),
            'qr_available_from' => CarbonImmutable::parse($booking->visit_date, $booking->destination_timezone)->subDays(PaymentReceiptService::ENTRY_QR_LEAD_DAYS)->toDateString()];

        return $documents;
    }

    /** Rendered on demand from the verified ledger, never stored, so it cannot outlive a payment state change. */
    private function receipt(string $guid): StreamedResponse
    {
        $receipts = app(PaymentReceiptService::class);
        $data = $receipts->data($guid, true) ?? throw new ModelNotFoundException;
        $pdf = Pdf::loadHTML($receipts->html($data))->output();

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf;
        }, 'receipt.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }

    /** Booking-confirmation PDF for the legacy "ticket" slot. It states that it is not an entry ticket and carries no token. */
    private function confirmation(string $guid): StreamedResponse
    {
        $tickets = app(TicketService::class);
        $booking = $tickets->booking($guid);
        if ($booking->status !== 'confirmed' || $tickets->state($booking) === 'revoked') {
            throw new ModelNotFoundException;
        }
        $from = CarbonImmutable::parse($booking->visit_date, $booking->destination_timezone)->subDays(PaymentReceiptService::ENTRY_QR_LEAD_DAYS)->toDateString();
        $html = '<h1>Konfirmasi Booking</h1><p><strong>Dokumen ini BUKAN tiket masuk.</strong></p>'
            .'<p>Booking: '.e($booking->booking_number).'</p><p>Destinasi: '.e($booking->destination_name_snapshot).'</p>'
            .'<p>Tanggal kunjungan: '.e($booking->visit_date).'</p><p>Jumlah pengunjung: '.e($booking->quantity).'</p>'
            .'<p>QR masuk satu per pengunjung tersedia di aplikasi mulai '.e($from).' (tanggal lokal destinasi).</p>';
        $pdf = Pdf::loadHTML($html)->output();

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf;
        }, 'ticket.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }

    public function download(User $user, string $guid, string $type): StreamedResponse
    {
        $this->booking($user, $guid);
        if ($type === 'receipt') {
            return $this->receipt($guid);
        }
        if ($type === 'ticket') {
            return $this->confirmation($guid);
        }
        $table = match ($type) {
            'invoice' => 'invoices', default => throw new ModelNotFoundException
        };
        $document = DB::table($table)->where('booking_guid', $guid)->first();
        if (! $document || ! $document->pdf_path || ! Storage::disk('local')->exists($document->pdf_path)) {
            throw new ModelNotFoundException;
        }

        return Storage::disk('local')->download($document->pdf_path, $type.'.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }
}
