<?php

namespace App\Services;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookingDocumentService
{
    public function generate(string $guid): void
    {
        $written = [];
        try {
            DB::transaction(function () use ($guid, &$written) {
                $booking = DB::table('bookings')->where('guid', $guid)->lockForUpdate()->first();
                if (! $booking || $booking->status !== 'confirmed') {
                    return;
                }
                $invoice = DB::table('invoices')->where('booking_guid', $guid)->first();
                $ticket = DB::table('tickets')->where('booking_guid', $guid)->first();
                $quota = DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->first();
                $disk = Storage::disk('local');
                if (! $invoice) {
                    $invoiceGuid = (string) Str::uuid();
                    $number = 'INV-'.strtoupper(str_replace('-', '', $invoiceGuid));
                    $path = 'booking-documents/'.Str::uuid().'.pdf';
                    $html = '<h1>Booking confirmation invoice</h1><p>This document confirms a booking and is not proof of payment.</p>'
                        .'<p>Invoice: '.e($number).'</p>'.$this->bookingHtml($booking, $quota);
                    if (! $disk->put($path, Pdf::loadHTML($html)->output())) {
                        throw new \RuntimeException('Could not store invoice.');
                    }
                    $written[] = $path;
                    DB::table('invoices')->insert(['guid' => $invoiceGuid, 'booking_guid' => $guid, 'invoice_number' => $number, 'status' => 'issued', 'pdf_path' => $path, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                }
                if (! $ticket) {
                    $token = bin2hex(random_bytes(32));
                    $path = 'booking-documents/'.Str::uuid().'.pdf';
                    $html = '<h1>Group booking ticket</h1>'.$this->bookingHtml($booking, $quota).'<p>Ticket token: '.e($token).'</p>';
                    if (! $disk->put($path, Pdf::loadHTML($html)->output())) {
                        throw new \RuntimeException('Could not store ticket.');
                    }
                    $written[] = $path;
                    DB::table('tickets')->insert(['guid' => (string) Str::uuid(), 'booking_guid' => $guid, 'token_hash' => hash('sha256', $token), 'status' => 'issued', 'pdf_path' => $path, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                }
            }, 1);
        } catch (\Throwable $e) {
            foreach ($written as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    private function bookingHtml(object $booking, object $quota): string
    {
        return '<p>Booking: '.e($booking->booking_number).'</p><p>Destination: '.e($booking->destination_name_snapshot).'</p>'
            .'<p>Visit: '.e($quota->visit_date).'</p><p>Visitor: '.e($booking->visitor_name).'</p>'
            .'<p>Quantity: '.e($booking->quantity).'</p><p>Total: '.e($booking->currency).' '.e($booking->total_amount).'</p>';
    }

    private function booking(User $user, string $guid): object
    {
        $query = DB::table('bookings')->where('guid', $guid);
        if ($user->role < 1) {
            $query->where('user_guid', $user->guid);
        }

        return $query->first() ?? throw new ModelNotFoundException;
    }

    public function metadata(User $user, string $guid): array
    {
        $this->booking($user, $guid);

        return [
            'invoice' => DB::table('invoices')->where('booking_guid', $guid)->first(['guid', 'booking_guid', 'invoice_number', 'status', 'issued_at']),
            'ticket' => DB::table('tickets')->where('booking_guid', $guid)->first(['guid', 'booking_guid', 'status', 'issued_at', 'expires_at']),
        ];
    }

    public function download(User $user, string $guid, string $type): StreamedResponse
    {
        $this->booking($user, $guid);
        $table = match ($type) {
            'invoice' => 'invoices', 'ticket' => 'tickets', default => throw new ModelNotFoundException
        };
        $document = DB::table($table)->where('booking_guid', $guid)->first();
        if (! $document || ! $document->pdf_path || ! Storage::disk('local')->exists($document->pdf_path)) {
            throw new ModelNotFoundException;
        }

        return Storage::disk('local')->download($document->pdf_path, $type.'.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }
}
