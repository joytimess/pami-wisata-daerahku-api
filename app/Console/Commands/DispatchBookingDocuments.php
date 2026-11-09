<?php

namespace App\Console\Commands;

use App\Jobs\GenerateBookingDocuments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DispatchBookingDocuments extends Command
{
    protected $signature = 'documents:dispatch';

    protected $description = 'Queue missing approved-booking documents';

    public function handle(): int
    {
        DB::table('bookings')->leftJoin('invoices as i', 'i.booking_guid', '=', 'bookings.guid')
            ->whereIn('bookings.status', ['awaiting_payment', 'confirmed'])
            ->select(['bookings.guid', 'i.pdf_path as invoice_path'])
            ->chunkById(100, function ($bookings) {
                foreach ($bookings as $booking) {
                    if (! $booking->invoice_path || ! Storage::disk('local')->exists($booking->invoice_path)) {
                        GenerateBookingDocuments::dispatch($booking->guid);
                    }
                }
            }, 'bookings.guid', 'guid');

        return self::SUCCESS;
    }
}
